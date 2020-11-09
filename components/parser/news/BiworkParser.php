<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class BiworkParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://www.biwork.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $url = "/news?view=_news_simple&page={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"news--col-1")]');
            if (!$this->crawlerHasNodes($previewNewsCrawler)) {
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//h2/parent::a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $timezone = new DateTimeZone('Asia/Krasnoyarsk');
                $publishedAtString = $newsPreview->filterXPath('//time')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('H:i, d.m.Y', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;
                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
            });
        }


        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//h1/parent::div[contains(@class,"b-article")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '' && !$this->crawlerHasNodes($newsPostCrawler->filterXPath('//div[contains(@class,"b-article__slider-container")]'))) {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $images = [];
        $imagesXpath = '//div[contains(@class,"b-article__slider-container")]//a[contains(@class,"b-article__slider-item")]/img';
        $newsPostCrawler->filterXPath($imagesXpath)->each(function (Crawler $crawler) use (&$images) {
            $src = $crawler->attr('src');
            if ($src !== '' && $src !== null) {
                $images[] = NewsPostItemDTO::createImageItem(UriResolver::resolve($src, $this->getSiteUrl()));
            }
        });


        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"news--col-1")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"b-article__")]');
        $this->removeDomNodes($contentCrawler, '//h1');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);
        $newsPostItemDTOList = array_merge($images, $newsPostItemDTOList);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}