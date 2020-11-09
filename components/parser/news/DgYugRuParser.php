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

class DgYugRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://www.dg-yug.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?page={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('.post-chunk .post-sm > .body');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.title a')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('.title a')->attr('href'), $this->getSiteUrl());

                $publishedAt = Text::trim($newsPreview->filter('.date')->text());
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAt, new DateTimeZone('Europe/Moscow'));
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = rawurldecode($previewNewsDTO->getUri());

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $descriptionCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article")][1]//div[@itemprop="description"]/div[contains(@class,"row")][1]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($newsPageCrawler, '//div[contains(@class,"article")][1]//div[@itemprop="description"]');
            }
        }

        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article")][1]//div[@itemprop="articleBody"]');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"article")][1]//div[@itemprop="image"]//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"article")][1]//div[@itemprop="image"]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
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
        if ($node->hasAttribute('data-fancybox')) {
            $childNode = $node->childNodes->item(1);
            if ($childNode instanceof DOMElement && $childNode->tagName === 'img') {
                $childNode->setAttribute('src', '');
                $newsPostItem = NewsPostItemDTO::createImageItem($link);
            }
        }

        if (!$newsPostItem) {
            $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);
        }

        $this->getNodeStorage()->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }
}
