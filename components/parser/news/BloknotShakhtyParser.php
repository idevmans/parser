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

class BloknotShakhtyParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://bloknot-shakhty.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $categories = [
            '/news/society/',
            '/news/incident/',
            '/news/policy/',
            '/news/sport/',
            '/news/economy/',
            '/news/i_want_to_say/',
            '/news/letter_to_the_editor/',
            '/news/officials_of_the_city/',
            '/news/culture/',
            '/news/museum/',
        ];

        foreach ($categories as $urn) {
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[@class="catitem"]');
            if ($previewNewsCrawler->count() < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//a[contains(@class,"linksys")]');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'),$this->getSiteUrl());

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAtString = $newsPreview->filterXPath('//span[contains(@class,"botinfo")]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', trim($publishedAtString), $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, $preview);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsItem->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"news-text")]');
        $this->removeDomNodes($contentCrawler, '//b[contains(@class,"hideme")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}