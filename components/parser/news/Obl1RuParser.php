<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use linslin\yii2\curl\Curl;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;
use yii\web\ForbiddenHttpException;

class Obl1RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://obl1.ru';
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewNewsDTOList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $newsPostDTO */
        foreach ($previewList as $key => $newsPostDTO) {
            try {
                $newsList[] = $this->parseNewsPage($newsPostDTO);
            } catch (ForbiddenHttpException $exception) {
                continue;
            }

            $this->getNodeStorage()->removeAll($this->getNodeStorage());

            if ($key % $this->getPageCountBetweenDelay() === 0) {
                usleep($this->getMicrosecondsDelay());
            }
        }

        $this->getCurl()->reset();
        return $newsList;
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news?page={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.news > a');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($newsPreview->filterXPath('//*[contains(@class,"news__item-title")]')->text());
                $uri = UriResolver::resolve($newsPreview->filterXPath('a')->attr('href'), $this->getSiteUrl());

                $publishedAt = $this->getPublishedAt($newsPreview);

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();


        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"content")]//div[contains(@class,"container")]//div[contains(@class,"row")][last()]//div[contains(@class,"col-xl-11")]');

        $image = null;
        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $newsPostCrawler = $newsPageCrawler->filter('.list-sections__list .js-mediator-article');
        $this->removeDomNodes($newsPostCrawler, '//div[contains(@class,"piktowrapper-embed")]');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList, 50);
    }

    private function getPublishedAt(Crawler $crawler): DateTimeImmutable
    {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $publishedAt = Text::trim($crawler->filterXPath('//div[contains(@class,"news__item-date")]')->text());
        $publishedAtString = str_replace($months, array_keys($months), $publishedAt);

        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, new DateTimeZone('Europe/Moscow'));
        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

        return $publishedAt;
    }

    protected function factoryNewsPost(PreviewNewsDTO $newsPostDTO, array $newsPostItems, int $descLength = 200): NewsPost
    {
        $uri = $newsPostDTO->getUri();
        $image = $newsPostDTO->getImage();

        $title = $newsPostDTO->getTitle();
        if (!$title) {
            throw new InvalidArgumentException('Объект NewsPostDTO не содержит заголовка новости');
        }

        $publishedAt = $newsPostDTO->getPublishedAt() ?: new DateTimeImmutable();
        $publishedAtFormatted = $publishedAt->format('Y-m-d H:i:s');

        $emptyDescriptionKey = 'EmptyDescription';
        $autoGeneratedDescription = '';
        $description = $newsPostDTO->getDescription() ?: $emptyDescriptionKey;

        $newsPost = new NewsPost(static::class, $title, $description, $publishedAtFormatted, $uri, $image);


        foreach ($newsPostItems as $newsPostItemDTO) {
            if ($newsPost->image === null && $newsPostItemDTO->isImage()) {
                $newsPost->image = $newsPostItemDTO->getImage();
                continue;
            }

            if ($newsPostItemDTO->isImage() && $newsPost->image === $newsPostItemDTO->getImage()) {
                continue;
            }

            if ($newsPost->description !== $emptyDescriptionKey) {
                $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
                continue;
            }

            if (!$newsPostItemDTO->isImage() && mb_strlen($autoGeneratedDescription) < $descLength) {
                $autoGeneratedDescription .= $newsPostItemDTO->getText() ?: '';
                continue;
            }

            $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
        }

        if ($newsPost->description === $emptyDescriptionKey) {
            if ($autoGeneratedDescription !== '') {
                $newsPost->description = $autoGeneratedDescription;
                return $newsPost;
            }

            $newsPost->description = $newsPost->title;
        }

        $newsPost->items = array_values($newsPost->items);

        if (count($newsPost->items) && $newsPost->items[0]->text === $description) {
            unset($newsPost->items[0]);
            $newsPost->items = array_values($newsPost->items);
        }

        return $newsPost;
    }

    protected function getPageContent(string $uri): string
    {
        $encodedUri = Helper::encodeUrl($uri);
        $content = $this->getCurl()->get($encodedUri);

        $this->checkResponseCode($this->getCurl());

        return $this->decodeGZip($content);
    }


    protected function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode === 401) {
            throw new ForbiddenHttpException();
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }

}
