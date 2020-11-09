<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Ivan4Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://ivan4.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?PAGEN_1={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//ul[contains(@class,"articles")]/li');
            if (!$this->crawlerHasNodes($previewNewsCrawler)) {
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//h4/a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());


                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAtString = $newsPreview->filterXPath('//span[contains(@class, "date")]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
            });
        }
        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }
        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"holder")]/div')->first();

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"info")]/preceding-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"sharet")]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"info")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"sharet")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}