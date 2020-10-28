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

class VlgMediaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://vlg-media.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $url = "/news/page/{$pageNumber}/";
            $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"default-postbox-col")]');
            if(!$this->crawlerHasNodes($previewNewsCrawler)){
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
                $titleCrawler = $newsPreview->filterXPath('//h2[contains(@class,"blog-default-title")]/a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl() . $url);
                $title = trim($titleCrawler->text());

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"newskit-blog-content")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null) {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $publishedAtString = $newsPageCrawler->filterXPath('//meta[@property="article:published_time"]')->attr('content');
        $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $publishedAtString);
        $previewNewsItem->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $description = null;

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"entry-summary")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"code-block code-block-1")]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"code-block code-block-1")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}