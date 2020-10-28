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

class GtrkKurskParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function convertMonth($stringRuMonth) : string
    {
        $months_name = [
            'января' => '1',
            'февраля' => '2',
            'марта' => '3',
            'апреля' => '4',
            'мая' => '5',
            'июня' => '6',
            'июля' => '7',
            'августа' => '8',
            'сентября' => '9',
            'октября' => '10',
            'ноября' => '11',
            'декабря' => '12',
        ];

        return $months_name[$stringRuMonth];
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 0;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "news?page={$pageNumber}";
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

            $previewNewsXPath = '//ul[@class="media-list"]//li[@class="media"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $titleCrawler = $newsPreview->filterXPath('//h4[@class="media-heading"]/a');
                    $title = $titleCrawler->text();
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                    $this->removeDomNodes($newsPreview, '//h4[@class="media-heading"]');
                    $publishedAtString = $newsPreview->filterXPath('//div[@class="media-body"]')->text();
                    $publishedAtString = preg_split("/[\s,-]+/", $publishedAtString);
                    unset($publishedAtString[0]);
                    $dayPublish = $publishedAtString[1];
                    $monthPublish = $this->convertMonth($publishedAtString[2]);
                    $yearPublish = $publishedAtString[3];
                    $timePublish = $publishedAtString[4];
                    $publishedAtString = $dayPublish.'.'.$monthPublish.'.'.$yearPublish.' '.$timePublish;
                    $timezone = new DateTimeZone('Europe/Moscow');
                    $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
                    $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                    $description = null;
                    $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, $description);
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function getSiteUrl(): string
    {
        return 'https://gtrkkursk.ru/';
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//section[contains(@id,"block-system-main")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

//        $description = $newsPostCrawler->filterXPath('//div[contains(@class,"field-item even")]')->text();
        $description = null;
        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"pad")]')->first();
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"soc-links-hor pad")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);
        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
