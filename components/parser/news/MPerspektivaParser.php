<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class MPerspektivaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://www.mperspektiva.ru';
    }

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(10, 50);
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news/?PAGEN_1={$pageNumber}&ajax=Y;";
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

            $previewNewsXPath = '//div[contains(@class,"c-articles-grid__item")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $uriCrawler = $newsPreview->filterXPath('//a[contains(@class,"c-articles-grid__link")]');
                $title = $uriCrawler->filterXPath('//div[contains(@class,"c-articles-grid__title")]')->text();
                $uri = UriResolver::resolve($uriCrawler->attr('href'), $this->getSiteUrl());

                $dateString = $newsPreview->filterXPath('//span[contains(@class,"date")]')->text();
                $yearString = $newsPreview->filterXPath('//span[contains(@class,"time")]')->text();
                $publishedAtString = $this->translateDateToEng($dateString) . ' ' . $yearString;

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d F Y H:i', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $description = $newsPreview->filterXPath('//p[contains(@class,"c-articles-grid__text")]')->text();
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"c-news-content__ct")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"c-news-content__subtitle")]/preceding-sibling::*');
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"c-news-content__subtitle")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function translateDateToEng(string $date)
    {
        $date = mb_strtolower($date);
        $monthRegex = [
            '/янв[\S.]*/iu' => 'January',
            '/фев[\S.]*/iu' => 'February',
            '/мар[\S.]*/iu' => 'March',
            '/апр[\S.]*/iu' => 'April',
            '/май[\S.]*/iu' => 'May',
            '/июн[\S.]*/iu' => 'June',
            '/июл[\S.]*/iu' => 'July',
            '/авг[\S.]*/iu' => 'August',
            '/сен[\S.]*/iu' => 'September',
            '/окт[\S.]*/iu' => 'October',
            '/ноя[\S.]*/iu' => 'November',
            '/дек[\S.]*/iu' => 'December'
        ];
        foreach ($monthRegex as $regex => $enMonth) {
            $date = preg_replace($regex, $enMonth, $date);
        }
        return $date;
    }
}
