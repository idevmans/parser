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

class NevWorkerParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://www.nevworker.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/articles/?PAGEN_2={$pageNumber}";
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

            $previewNewsXPath = '//div[@class="l-section__items"]//div[@class="b-section-item b-section-item--bighalf b-section-item--biggest"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//h3[@class="b-section-item__title"]/a');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $dateXpath = '//div[@class="b-section-item__meta"]//div[@class="b-meta-item"][last()]//span[last()]';
                $publishedAtString =$newsPreview->filterXPath($dateXpath)->text();
                $publishedAtString = explode(' ', $publishedAtString);
                $this->convertStringMonthToNumber($publishedAtString[1]);
                $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
                //[1] - day // [2] - month // [3] - year
                $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2];
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d m Y', $publishedAtString, $timezone);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"b-news-detail-body js-news-detail")]');

        $this->removeDomNodes($newsPostCrawler,'//*[contains(@class,"instagram-media")]');
        $this->removeDomNodes($newsPostCrawler,'//*[contains(@class,"instagram-media instagram-media-rendered")]');
        $this->removeDomNodes($newsPostCrawler,'//*[@id="instagram-embed-0"]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
            $this->removeDomNodes($newsPostCrawler,'//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }
        $previewNewsDTO->setDescription(null);

        $contentCrawler = $newsPostCrawler;

        $this->removeDomNodes($contentCrawler,'//div[@class="google-auto-placed ap_container"]');

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
