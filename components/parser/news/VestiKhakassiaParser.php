<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMElement;
use DOMNode;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class VestiKhakassiaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://xn----8sbafpsdo3dff2b1j.xn--p1ai';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $url = "/news/?page={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"news-list-element")]');
            if (!$this->crawlerHasNodes($previewNewsCrawler)) {
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
                $titleCrawler = $newsPreview->filterXPath('//a[contains(@class,"title")]');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl() . $url);
                $title = trim($titleCrawler->text());

                $timezone = new DateTimeZone('Asia/Krasnoyarsk');
                $publishedAtString = $newsPreview->filterXPath('//p[contains(@class,"small lite")]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d F Y',
                    $this->translateDateToEng($publishedAtString), $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
            });
        }

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@itemtype="http://schema.org/Article"]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsItem->setImage($image);
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[@itemprop="description"] | //div[contains(@class,"responsive-video-block")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
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