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

class AtkarskGazetaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://atkarskgazeta.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageOffset = 0;
        $step = 20;

        while (count($previewList) < $maxNewsCount) {
            $url = "/AjaxLoad/newsajax?page=&pageOne={$pageOffset}&pageStep={$step}";
            $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());
            $pageOffset += $step;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//a[contains(@class,"link-covers")]/parent::div');
            if(!$this->crawlerHasNodes($previewNewsCrawler)){
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filterXPath('//h1[contains(@class,"tape__news__content-title")]')->text();
                $uri = $newsPreview->filterXPath('//a[contains(@class,"link-covers")]')->attr('href');
                $uri = UriResolver::resolve($uri, "{$this->getSiteUrl()}/novosti");
                $uri = $this->encodeUri($uri);

                $timezone = new DateTimeZone('Europe/Saratov');
                $publishedAtString = $newsPreview->filterXPath('//span[contains(@class,"today__content-time")]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y, H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//section[contains(@class,"onenews")]');

        $contentXPath = '//div[contains(@class,"content__container")]/span[contains(@class,"onenews__content-postdate")]/parent::*';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXPath);
        $this->removeDomNodes($contentCrawler, '//span[contains(@class,"onenews__content__autor")]');
        $this->removeDomNodes($contentCrawler, '//span[contains(@class,"onenews__content-postdate")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"onenews__content__feedback")]');


        $sliderImages = [];
        $newsPageCrawler
            ->filterXPath('//div[contains(@class,"onenews__content__slider__item")]')
            ->each(function (Crawler $crawler) use ($uri, &$sliderImages) {
                preg_match('/(?:\([\'\"]?)(.*?)(?:[\'\"]?\))/u', $crawler->attr('style'), $matches);
                if (!isset($matches[1]) || $matches[1] === '') {
                    return;
                }

                $imageLink = UriResolver::resolve($matches[1], $uri);

                $sliderImages[] = NewsPostItemDTO::createImageItem($imageLink);
            });

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        $newsPostItemDTOList = array_merge($sliderImages, $newsPostItemDTOList);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

}