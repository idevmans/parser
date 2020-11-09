<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ProVolskRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://pro-volsk.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/rss";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();
            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $preview = null;

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);

        $document = new DOMDocument();
        @$document->loadHTML($newsPage);
        $newsPageCrawler = new Crawler($document);

        // $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@class="mbl"]/p/ancestor::*[1]');

        $video = $newsPageCrawler->filterXPath('//*[@class="mbl"]//div[contains(concat(" ",normalize-space(@class)," ")," embed-responsive ")]//iframe');
        if ($this->crawlerHasNodes($video)) {
            try {
                $iframe = $document->createElement('iframe');
                $iframe->setAttribute('src', $video->attr('src'));
                $parent = $newsPostCrawler->getNode(0);
                $parent->appendChild($iframe);
            } catch (\Throwable $th) {
            }
        }

        $imageGallery = $newsPageCrawler->filterXPath('//div[@id="afisha-media-content"]');
        if ($this->crawlerHasNodes($imageGallery)) {
            try {
                $imageGallery = $imageGallery->filterXPath('//div[@data-target="#lightbox"][@data-href]');
                $imageGallery->each(function (Crawler $crawler, $i) use ($newsPostCrawler, $document) {
                    $img = $document->createElement('img');
                    $img->setAttribute('src', $crawler->attr('data-href'));
                    $img->setAttribute('alt', $crawler->attr('data-title'));
    
                    $parent = $newsPostCrawler->getNode(0);
                    $parent->appendChild($img);
                });
            } catch (\Throwable $th) {
            }
        }

        $image = null;
        // $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        // if ($this->crawlerHasNodes($mainImageCrawler)) {
        //     $image = $mainImageCrawler->attr('content');
        // }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        // $descriptionCrawler = $newsPostCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," article__lead-content ")]');
        // if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
        //     $previewNewsItem->setDescription($descriptionCrawler->text());
        //         $this->removeDomNodes($newsPostCrawler, '//div[contains(concat(" ",normalize-space(@class)," ")," article__lead-content ")]');
        // }

        $contentCrawler = $newsPostCrawler;

        $this->removeDomNodes($contentCrawler, '//*[contains(translate(substring(text(), 0, 14), "ФОТО", "фото"), "фото")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}