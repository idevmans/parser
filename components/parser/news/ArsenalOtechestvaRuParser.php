<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ArsenalOtechestvaRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://arsenal-otechestva.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/index.php?option=com_ninjarsssyndicator&feed_id=1&format=raw', $this->getSiteUrl());

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

        $contentCrawler = $newsPageCrawler->filterXPath('//div[@itemprop="articleBody"]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"likes")]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"likes")]');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
        }
        if (str_contains($uri, 'aviadarts-2020-chast-2')) {
            $descriptionCrawler = $contentCrawler->filterXPath('//div[@dir="auto"][2]');
            if ($this->crawlerHasNodes($descriptionCrawler)) {
                $description = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
                $this->removeDomNodes($contentCrawler, '//div[@dir="auto"][2]');
            }
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function parseDOMNode(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        try {
            $newsPostItem = $this->searchImageNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchQuoteNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchHeadingNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchLinkNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchYoutubeVideoNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchTextNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }


            if ($node->nodeName === 'br') {
                $this->removeParentsFromStorage($node->parentNode);
                return null;
            }
        } catch (RuntimeException $exception) {
            return null;
        }
        return null;
    }

}
