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

class NgsochiComParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://ngsochi.com/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/news.feed?type=rss', $this->getSiteUrl());

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
        $this->removeDomNodes($contentCrawler, '//a[contains(@id,"mc-link")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"mc-container")]');
        $this->removeDomNodes($contentCrawler, '//span[contains(@class,"sigProCaption")] | //span[contains(@class,"sigProPseudoCaption")] | //div[contains(@class,"sigProPrintMessage")]');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"item-image")]/img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"item-image")]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        $description = $this->getDescriptionFromContentText($contentCrawler);

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getDescriptionFromContentText(Crawler $crawler): ?string
    {
        $descriptionCrawler = $crawler->filterXPath('//p[1]/strong');

        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));

            if ($descriptionText) {
                $this->removeDomNodes($crawler, '//p[1]/strong');
                return $descriptionText;
            }
        }

        return null;
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
        if (str_contains($node->getAttribute('class'), 'sigProLink')) {
            foreach ($node->childNodes as $childNode) {
                if (!$childNode instanceof DOMElement || $childNode->tagName !== 'img') {
                    continue;
                }
                $childNode->setAttribute('src', $node->getAttribute('href'));
                $node->setAttribute('href', '');
                return null;
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
