<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use linslin\yii2\curl\Curl;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ItcrumbsParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://itcrumbs.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed";
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"entry-content")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"stream-item")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"post-bottom-meta")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    protected function factoryCurl(): Curl
    {
        $curl = parent::factoryCurl();
        $userAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $curl->setOption(CURLOPT_USERAGENT, $userAgent);

        return $curl;
    }

    protected function getImageLinkFromNode(DOMElement $node): string
    {
        $dataSrc = $node->getAttribute('data-src');
        if($dataSrc !== '' && $dataSrc !== null){
            return $dataSrc;
        }

        return $node->getAttribute('src');
    }

}