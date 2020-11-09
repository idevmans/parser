<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

class Otradny24Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://www.otradny24.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $categoriesUrnList = [
            '/news/93/',
            '/news/494/',
            '/news/497/',
            '/news/498/',
            '/news/95/',
            '/news/499/',
            '/news/131/',
            '/news/96/'
        ];

        foreach ($categoriesUrnList as $urn) {
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());

            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);


            $previewNewsXPath = '//div[contains(@class,"other_news")]//div[contains(@class,"item")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//a[contains(@class,"title")]');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $publishedAtString = $newsPreview->filterXPath('//span[contains(@class,"date")]')->text();

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $desc = $newsPreview->filterXPath('//p')->text();
                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, $desc);
            });
        }

        usort($previewNewsDTOList, static function (PreviewNewsDTO $firstNews, PreviewNewsDTO $secondNews) {
            $firstNewsDate = $firstNews->getPublishedAt();
            $secondNewsDate = $secondNews->getPublishedAt();

            if ($firstNewsDate === $secondNewsDate) {
                return 0;
            }

            return ($firstNewsDate > $secondNewsDate) ? -1 : 1;
        });

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"page_content")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//span[contains(@class,"date")]/preceding-sibling::img');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[@style]')->first();

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function normalizeText(string $string): string
    {
        $string = str_replace('­','',$string);

        return parent::normalizeText($string);
    }
}