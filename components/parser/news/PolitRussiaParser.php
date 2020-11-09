<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class PolitRussiaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://politrussia.com';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = $this->getSiteUrl();

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"owc-item")]');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = $newsPreview->filterXPath('//div[contains(@class,"title")]')->text();
            $uri = $newsPreview->filterXPath('//a[contains(@class,"link-more")]')->attr('href');

            $uri = $this->encodeUri(UriResolver::resolve($uri, $this->getSiteUrl()));

            $timezone = new DateTimeZone('Europe/Saratov');
            $publishedAtString = $newsPreview->filterXPath('//div[contains(@class,"date")]')->text();
            $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAtString, $timezone);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = null;
            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        usort($previewList, function (PreviewNewsDTO $firstNews, PreviewNewsDTO $secondNews) {
            $firstNewsTimestamp = $firstNews->getPublishedAt();
            $secondNewsTimestamp = $secondNews->getPublishedAt();

            if ($firstNewsTimestamp === $secondNewsTimestamp) {
                return 0;
            }

            return ($firstNewsTimestamp > $secondNewsTimestamp) ? -1 : 1;
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);
        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler;

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null) {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $time = mb_substr($newsPostCrawler->filterXPath('//div[contains(@class,"date")]')->text(), 0, 5);
        $date = $previewNewsItem->getPublishedAt()->format('d.m.Y');
        $timezone = new DateTimeZone('Europe/Moscow');
        $dateTime = DateTimeImmutable::createFromFormat('d.m.Y H:i', $date .' '. $time, $timezone);
        if($dateTime !== false){
            $previewNewsItem->setPublishedAt($dateTime);
        }

        $contentXPath = '//div[contains(@class,"article-vibox")] | //div[contains(@class,"article-body")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXPath);

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

}