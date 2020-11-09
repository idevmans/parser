<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class RberegaInfoParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://rberega.info/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/rss', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            if (!$publishedAt) {
                $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s.u O', $publishedAtString);
            }
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $image = null;
            $imageCrawler = $newsPreview->filterXPath('//enclosure');
            if ($this->crawlerHasNodes($imageCrawler)) {
                $image = $imageCrawler->attr('url') ?: null;
            }

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null, $image);
        });

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('#main-content .post');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"entry-thumb")]');
        }

        $mainImageCrawler = $contentCrawler->filterXPath('//div[contains(@class,"entry-thumb")]//img[1]');
        if (!$image) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"entry-thumb")]');
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        $contentCrawler = $contentCrawler->filter('.elements-box');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"kama-inline-ads")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"sharedaddy")]');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"wp-embedded-content")]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"wp-embedded-content")]');

        $descriptionCrawler = $contentCrawler->filterXPath('//p[1]/strong');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//p[1]/strong');
            }
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);
        $images = [];
        /** @var NewsPostItemDTO $newsPostItem */
        foreach ($newsPostItemDTOList as $key => $newsPostItem) {
            if (in_array($newsPostItem->getHash(), $images, true)) {
                unset($newsPostItemDTOList[$key]);
            } elseif ($newsPostItem->getImage()) {
                $images[] = $newsPostItem->getHash();
            }
        }
        $newsPostItemDTOList = array_values($newsPostItemDTOList);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
