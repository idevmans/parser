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

class KioskSoftParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://kiosksoft.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news?page={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//div[@class="newsstream"]//div[@class="card card-horizontal"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//a[@class="card-link"]');
                $title = $titleCrawler->filterXPath('//h4')->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $description = null;
                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), null, $title, $description);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"col-md-9 article")]')->first();

        $mainImageCrawler = $newsPostCrawler->filterXPath('//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler,'//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $publishedAtString = $newsPageCrawler->filterXPath('//div[@class="headstat"]//div[@class="stats"][1]')->text();
        $publishedAtString = explode(' ', $publishedAtString);
        $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
        $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2];
        $timezone = new DateTimeZone('Europe/Moscow');
        $publishedAt = DateTimeImmutable::createFromFormat('d m H:i', $publishedAtString, $timezone);
        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAtUTC);

        $previewNewsDTO->setDescription(null);

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"news-article js-mediator-article")]');

        $this->removeDomNodes($contentCrawler,'//div[@class="news-image"]//p[1]');
        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    public function convertStringMonthToNumber($stringMonth): int
    {
        $stringMonth = mb_strtolower(str_replace(',','',$stringMonth));
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
