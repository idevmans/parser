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

class ZanMskParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://zanmsk.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news/";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//main[@id="main"]//article';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//h2[@class="entry-title"]/a');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $publishedAtString = $newsPreview->filterXPath('//span[@class="posted-on"]')->text();
                $publishedAtString = explode(' ', $publishedAtString);
                $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1];
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $description = null;
                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, $description);
            });
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $mainImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler,'//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $previewNewsDTO->setDescription(null);


        $contentCrawler = $newsPostCrawler->filterXPath('//div[@class="entry-content"]')->first();
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"mobile-slider")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
