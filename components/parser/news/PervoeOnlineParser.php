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

class PervoeOnlineParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static(200000, 1);

        return $parser->parse(10, 15);
    }

    protected function factoryCurl(): Curl
    {
        $curl = parent::factoryCurl();
        $curl->setHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        return $curl;
    }

    protected function getSiteUrl(): string
    {
        return 'https://pervoe.online/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?PAGEN_1={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('.container .card-cover-small');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.card-cover-small__title')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('a.card-link')->attr('href'), $this->getSiteUrl());

                $this->removeDomNodes($newsPreview, '//div[contains(@class,"publications-datetime__clock-icon")]');
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

        $contentCrawler = $newsPageCrawler->filter('.page-block .page__content.page__text');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
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
        $publishedAt = Text::trim($this->normalizeSpaces($crawler->filter('.publications-datetime')->text()));
        $publishedAtString = str_replace($months, array_keys($months), $publishedAt);

        $publishedAt = DateTimeImmutable::createFromFormat('d m H:i', $publishedAtString, new DateTimeZone('Europe/Moscow'));

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
