<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class UldeloRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://uldelo.ru';
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewNewsDTOList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $newsPostDTO */
        foreach ($previewList as $key => $newsPostDTO) {
            $newsList[] = $this->parseNewsPage($newsPostDTO);

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

            $previewNewsCrawler = $previewNewsCrawler->filter('#main .content .news-block');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('a h4')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('a')->attr('href'), $this->getSiteUrl());

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
        $contentCrawler = $newsPageCrawler->filter('.longread-container');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"metablock")]');

        $descriptionCrawler = $contentCrawler->filterXPath('//div[@class="lid"][1]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $description = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            $this->removeDomNodes($contentCrawler, '//div[@class="lid"][1]');
        }

        $image = $this->getMainImage($contentCrawler);
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $contentCrawler->filter('.material-container');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"material-card")]');
        $this->removeDomNodes($contentCrawler, '//p[last()]//a[contains(@href,"t.me/uldeloru")]/parent::p');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getMainImage(Crawler $crawler): ?string
    {
        $image = null;
        $mainImageCrawler = $crawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $crawler->filterXPath('//img[1]')->attr('src');
            if ($crawler->filterXPath('//img[1]/parent::p[@class="preview"]')->count()) {
                $this->removeDomNodes($crawler, '//img[1]/parent::p[@class="preview"]');
            } else {
                $this->removeDomNodes($crawler, '//img[1]');
            }
        }

        return $image;
    }

    private function getPublishedAt(Crawler $crawler): DateTimeImmutable
    {
        $publishedAtString = Text::trim($crawler->filterXPath('//div[contains(@class,"text-block")]/div[contains(@class,"meta")]/a[1]')->text());

        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAtString, new DateTimeZone('UTC'));
        $publishedAt = $publishedAt->setTime(0, 0, 0);

        return $publishedAt;
    }
}
