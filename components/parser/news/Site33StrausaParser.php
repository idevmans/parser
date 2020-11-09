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

class Site33StrausaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://www.33strausa.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 0;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/obshenie/categories/listings/9-новости.html?start={$pageNumber}";
            if ($pageNumber === 0) {
                $urn = "/obshenie/categories/listings/9-новости.html";
            }
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber += 25;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//div[@id="ezblog-posts"]//div[contains(@class,"blog-post")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//h2/a');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $publishedAtString = $newsPreview->filterXPath('//span[@class="data"]')->text();
                $publishedAtString = explode(' ', $publishedAtString);
                $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
                //[1] - day // [2] - month // [3] - year
                $publishedAtString = $publishedAtString[0].'.'.$publishedAtString[1].'.'.$publishedAtString[2].' '.$publishedAtString[4];
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
                $description = null;
                $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $description);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"blog-read")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $previewNewsDTO->setDescription(null);

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"blog-text")]')->first();

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    public function convertStringMonthToNumber($stringMonth): string
    {
        $stringMonth = mb_strtolower($stringMonth);
        $monthsList = [
            "января" => 1,
            "февраля" => 2,
            "марта" => 3,
            "апреля" => 4,
            "мая" => 5,
            "июня" => 6,
            "июля" => 7,
            "августа" => 8,
            "сентября" => 9,
            "октября" => 10,
            "ноября" => 11,
            "декабря" => 12,
        ];
        return $monthsList[$stringMonth];
    }
}
