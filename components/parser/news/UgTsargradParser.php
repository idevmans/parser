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

class UgTsargradParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://ug.tsargrad.tv';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 0;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/ajax/morenews?page={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"news__item-info")]/parent::*');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//a[contains(@class,"news__listing-link")]');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), "{$this->getSiteUrl()}/news");

                $timezone = new DateTimeZone('Europe/Moscow');
                $dateCrawler = $newsPreview->filterXPath('//time');
                $publishedAtString = $dateCrawler->attr('datetime') . ' ' . mb_substr($dateCrawler->text(), -5);
                $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
            });
        }
        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article__content")]');

        $description = $newsPostCrawler->filterXPath('//div[contains(@class,"article__intro")]')->text();
        if ($description !== null && $description !== '') {
            $previewNewsItem->setDescription($description);
        }

        $contentXpath = '//ul[contains(@class,"article__gallery-main-list")]//li | //div[contains(@class,"only__text")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXpath);

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}