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

class GazetaParmaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://xn--80aaaalnn7bzals.xn--p1ai';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $urn = "/archives/category/новости/page/{$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//article');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//h2[contains(@class,"entry-title")]/a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());
                $title = $titleCrawler->text();

                $publishedAtString = $newsPreview->filterXPath('//time')->attr('datetime');
                $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $publishedAtString);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $newsPostDTO): NewsPost
    {
        $uri = $newsPostDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@id="content"]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"featured-image")]/img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $newsPostDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"entry-content")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $newsPostDTO);

        return $this->factoryNewsPost($newsPostDTO, $newsPostItemDTOList);
    }
}