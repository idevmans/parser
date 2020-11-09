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

class WsinformParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://wsinform.com';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/desc/rss/";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $preview = null;
            $previewList[] = new PreviewNewsDTO($uri, null, $title, $preview);
        });

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@id="content-inner"]');

        $timezone= new DateTimeZone('Europe/Moscow');
        $publishedAtString = $newsPostCrawler->filterXPath('//h4[contains(@class,"date")]')->text();
        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', trim($publishedAtString),$timezone);
        $previewNewsItem->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $this->removeDomNodes($newsPostCrawler, '//div[contains(@class,"zakladkiinit")]/parent::*/following-sibling::*');
        $this->removeDomNodes($newsPostCrawler, '//div[contains(@class,"zakladkiinit")]/parent::*');
        $this->removeDomNodes($newsPostCrawler, '//h4[contains(@class,"date")]/preceding-sibling::*');
        $this->removeDomNodes($newsPostCrawler, '//h4[contains(@class,"date")]');

        $contentCrawler = $newsPostCrawler->filterXPath('//tr')->first();

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}