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

class ZnamenkaInfoParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://znamenka.info/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/news/rss/', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//default:item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//default:title')->text();
            $uri = $newsPreview->filterXPath('//default:link')->text();

            $publishedAtString = $newsPreview->filterXPath('//default:pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $image = null;
            $imageCrawler = $newsPreview->filterXPath('//default:enclosure');
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

        $contentCrawler = $newsPageCrawler->filter('.content__big-news .big-news__text');

        $image = $previewNewsDTO->getImage();
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"big-news__photo-block")]/img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = Text::trim($this->normalizeSpaces($mainImageCrawler->attr('src')));
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"big-news__photo-block")]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        if ($this->isImageType($node)) {
            return null;
        }

        if ($node->nodeName === '#text' || !$this->isLink($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isLink = $this->isLink($parentNode);

                if ($this->getRootContentNodeStorage()->contains($parentNode) && !$isLink) {
                    return null;
                }

                return $isLink;
            });
            $node = $parentNode ?: $node;
        }


        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $newsPostDTO->getUri());
        if ($link === null) {
            return null;
        }

        if ($this->getNodeStorage()->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = null;

        if ($this->hasText($node) && trim($node->textContent, " /\t\n\r\0\x0B") !== trim($link, " /\t\n\r\0\x0B")) {
            $linkText = $this->normalizeSpaces($node->textContent);
        }

        $newsPostItem = null;
        if (str_contains($node->getAttribute('class'), 'fancybox')) {
            foreach ($node->childNodes as $childNode) {
                if (!$childNode instanceof DOMElement || !$childNode->tagName === 'img') {
                    continue;
                }
                $childNode->setAttribute('src', $node->getAttribute('href'));
                $node->setAttribute('href', '');
                return null;
            }
        }

        $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);

        $this->getNodeStorage()->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }
}
