<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class VladtvParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://vladtv.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve("/rss/", $this->getSiteUrl());

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

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $titleCrawler = $newsPreview->filterXPath('//title');
            $uriCrawler = $newsPreview->filterXPath('//link');
            $uri = UriResolver::resolve($uriCrawler->text(), $this->getSiteUrl());
            $title = $titleCrawler->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = null;

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"inner-page")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"img_block")]/img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null) {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"panel-body")]');

        $this->removeDomNodes($contentCrawler,
            '//div[contains(@class,"img_block")]/preceding-sibling::* | //div[contains(@class,"img_block")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"row")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    protected function getPageContent(string $uri): string
    {
        $encodedUri = Helper::encodeUrl($uri);
        $content = $this->getCurl()->get($encodedUri);
        $this->checkResponseCode($this->getCurl());

        if (str_contains($content, 'bpc=')) {
            preg_match('/bpc=[^;]*/iu', $content, $matches);
            $cookie = 'Cookie: ' . array_shift($matches);

            $this->getCurl()->setHeader(CURLOPT_HTTPHEADER, $cookie);
            $content = $this->getCurl()->get($encodedUri);
            $this->checkResponseCode($this->getCurl());
        }

        return $this->decodeGZip($content);
    }
}