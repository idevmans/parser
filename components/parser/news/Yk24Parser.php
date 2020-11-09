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

class Yk24Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://yk24.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $url = "/lenta?page={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"newsfeedpage__item")]');
            if (!$this->crawlerHasNodes($previewNewsCrawler)) {
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $uriCrawler = $newsPreview->filterXPath('//a[contains(@class,"newsfeedpage__link")]');
                $title = $newsPreview->filterXPath('//p[contains(@class,"newsfeedpage__title")]')->text();
                $uri = UriResolver::resolve($uriCrawler->attr('href'), $this->getSiteUrl());
                $uri = $this->encodeUri($uri);

                $publishedAtUTC = null;

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@class="content__left"]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $timezone = new DateTimeZone('Asia/Yakutsk');
        $publishedAtString = $newsPostCrawler->filterXPath('//div[contains(@class,"news__label")]')->text();
        $publishedAt = DateTimeImmutable::createFromFormat('d.m.y H:i', mb_substr($publishedAtString,0,14), $timezone);
        $previewNewsItem->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $contentXPath = '//div[contains(@class,"news__video")] | //div[contains(@class,"ms2Gallery")]//img | //div[contains(@class,"news__content")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXPath);
        $this->removeDomNodes($contentCrawler, '//img[contains(@src,"digital-3x6-1.-09-04-2020.jpg")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"btn-group")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

}