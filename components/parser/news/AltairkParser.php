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

class AltairkParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 0;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "https://altairk.ru/news/?page={$pageNumber}";
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

            $previewNewsXPath = '//div[@class="news"]//div[contains(@class,"item")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $titleCrawler = $newsPreview->filterXPath('//h3/a');
                    $title = $titleCrawler->text();
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                    $publishedAtString = $newsPreview->filterXPath('//div[@class="date"]')->text();
                    $publishedAtString = explode(' ', $publishedAtString);
                    if ($publishedAtString[1] === 'сегодня') {
                        $publishedAtString = $publishedAtString[0].' '.date('d m Y');
                    } else {
                        $publishedAtString[2] = $this->convertStringMonthToNumber($publishedAtString[2]);
                        $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2].' '.$publishedAtString[3];
                    }
                    $timezone = new DateTimeZone('Asia/Irkutsk');
                    $publishedAt = DateTimeImmutable::createFromFormat('H:i, d m Y', $publishedAtString, $timezone);
                    $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                    $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, null);
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    public function convertStringMonthToNumber($stringMonth): int
    {
        $stringMonth = mb_strtolower($stringMonth);
        $monthsList = [
            "янв" => 1,
            "фев" => 2,
            "мар" => 3,
            "апр" => 4,
            "мая" => 5,
            "июн" => 6,
            "июл" => 7,
            "авг" => 8,
            "сен" => 9,
            "окт" => 10,
            "ноя" => 11,
            "дек" => 12,
        ];
        return $monthsList[$stringMonth];
    }

    protected function getSiteUrl(): string
    {
        return 'https://altairk.ru/';
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@class="full_news"]');

        $mainImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $previewNewsDTO->setDescription(null);

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[@class="share_block"] | //div[@class="news news-block"]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
