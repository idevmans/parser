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

class GtrkKostroma extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://gtrk-kostroma.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/?PAGEN_2={$pageNumber}";
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

            $previewNewsXPath = '//div[@class="row news-list news-list--lenta"]//div[contains(@class,"news news--lenta")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $title = $newsPreview->filterXPath('//span[@class="news__name"]')->text();
                $uriCrawler = $newsPreview->filterXPath('//a[1]')->attr('href');
                $uri = UriResolver::resolve($uriCrawler, $this->getSiteUrl());

                $publishedAtString = $newsPreview->filterXPath('//span[@class="news__date"]')->text();
                $publishedAtString = explode(' ', $publishedAtString);
                if ($publishedAtString[1] === 'часа' || $publishedAtString[1] === 'назад') {
                    $publishedAtString = date('d m Y, H:i');
                } else {
                    $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
                    $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2].' '.$publishedAtString[3];
                }
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d m Y, H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, null);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@class="detail"]');

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

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"inner-content inner-content--min-height")]');

        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"tag-list-simple")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    public function convertStringMonthToNumber($stringMonth): int
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
