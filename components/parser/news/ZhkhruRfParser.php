<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ZhkhruRfParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://www.xn--f1aismi.xn--p1ai/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve('/gkh_news.html', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsContent = str_replace(['<dt class="date">', '</dd>'], ['<div class="custom-item"><dt class="date">', '</dd></div>'], $previewNewsContent);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filter('.content .custom-item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $uriPreviewPage) {
            $publishedAt = $this->getPublishedAt($newsPreview);
            $linkCrawler = $newsPreview->filterXPath('//a[1]');
            if ($this->crawlerHasNodes($linkCrawler)) {
                $uri = UriResolver::resolve($linkCrawler->attr('href'), $this->getSiteUrl());
            } else {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('dd')->text()));
                $uri = UriResolver::resolve('#' . md5($title), $uriPreviewPage);
            }

            $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title ?? null, $title ?? null);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();
        $title = $previewNewsDTO->getTitle();
        $publishedAt = $previewNewsDTO->getPublishedAt();

        if ($title && $description) {
            return new NewsPost(static::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, null);
        }

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('.gkh-content .content');
        $title = Text::trim($this->normalizeSpaces($newsPageCrawler->filter('title')->text()));
        $previewNewsDTO->setTitle($title);
        $this->removeDomNodes($contentCrawler, '//h1[1]');
        $this->removeDomNodes($contentCrawler, '//div[starts-with(@class,"ban")][last()]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[starts-with(@class,"ban")]');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getPublishedAt(Crawler $crawler): DateTimeImmutable
    {
        $publishedAtString = Text::trim($crawler->filter('.date')->text());
        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAtString, new DateTimeZone('Europe/Moscow'));
        $publishedAt = $publishedAt->setTime(0, 0, 0);

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
