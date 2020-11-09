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

class Kungur59RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://kungur59.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/news/rss.html";
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
            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $preview = null;

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," jumbotron ")]//div[@class="col-md-12"][descendant::p[@class="text-left"]]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $newsPostCrawler;

        $removeSelector = '//a[img]
        | //p[@class="text-left"]
        | //*[following-sibling::a[contains(@href, "hashtag")]][last()]/following-sibling::*
        | //a[contains(@href, "hashtag")]';
        $hasNodesToRemove = $this->crawlerHasNodes($contentCrawler->filterXPath($removeSelector));
        if ($hasNodesToRemove) {
            $this->removeDomNodes($contentCrawler, $removeSelector);
        }

        $descriptionCrawler = $contentCrawler->filterXPath('//p[1][count(text()) > 0]');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsItem->setDescription($descriptionCrawler->text());
            $this->removeDomNodes($contentCrawler, '//p[1][count(text()) > 0]');
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}