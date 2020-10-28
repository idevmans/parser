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

class MM21Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news/?PAGEN_1={$pageNumber}";
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

            $previewNewsXPath = '//ul[@class="diary-list "]//li[@class="diary-item"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $titleCrawler = $newsPreview->filterXPath('//a[@class="diary-item-link"]');
                    $title = $titleCrawler->text();
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                    $publishedAtString = $newsPreview->filterXPath('//span[@class="most-popular__date"]')->text();
                    $publishedAtString = explode(' ', $publishedAtString);
                    $this->convertStringMonthToNumber($publishedAtString[1]);
                    $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
                    //[1] - day // [2] - month // [3] - year
                    $publishedAtString = $publishedAtString[0] . ' ' . $publishedAtString[1] . ' ' . $publishedAtString[2];
                    $timezone = new DateTimeZone('Europe/Moscow');
                    $publishedAt = DateTimeImmutable::createFromFormat('d m Y', $publishedAtString, $timezone);
                    $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                    $description = null;
                    $previewNewsDTOList[] = new PreviewNewsDTO(
                        $this->encodeUri($uri),
                        $publishedAtUTC,
                        $title,
                        $description
                    );
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function getSiteUrl(): string
    {
        return 'http://21mm.ru/';
    }

    public function convertStringMonthToNumber($stringMonth): int
    {
        $stringMonth = strtolower($stringMonth);
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

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article-text article-detail-text")]');

        $mainImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $previewNewsDTO->setDescription($newsPageCrawler->filterXPath('//div[@class="article-epilog"]')->text());

        $contentCrawler = $newsPostCrawler->filterXPath('//div[@class="detail-text-div"]');

        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler, '//b[last()]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"google-auto-placed ap_container")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"ya-share2")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"content-block")]');
        $this->removeDomNodes($contentCrawler, '//div[@class="articles-links-add"]');
        $this->removeDomNodes($contentCrawler, '//span[@itemprop="name"]');
        $this->removeDomNodes($newsPageCrawler, '//p[contains(@class,"element-invisible no-mobile")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
