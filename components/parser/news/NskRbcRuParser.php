<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DOMElement;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class NskRbcRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://nsk.rbc.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $offset = 0;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/v10/ajax/get-news-by-filters/?region=nsk&offset={$offset}&limit=20", $this->getSiteUrl());
            $offset += 20;

            try {
                $previewNewsContent = $this->getJsonContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent['html']);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.item_image-mob');

            $previewNewsCrawler->each(function (Crawler $newsCrawler) use (&$previewNewsDTOList) {
                $url = UriResolver::resolve($newsCrawler->filter('a.item__link')->attr('href'), $this->getSiteUrl());
                $title = Text::trim($this->normalizeSpaces($newsCrawler->filter('a.item__link')->text()));

                $previewNewsDTOList[] = new PreviewNewsDTO($url, null, $title);
            });
        }

        if (count($previewNewsDTOList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $publishedAtString = $newsPageCrawler->filter('.article__header__date')->attr('content');
        $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $publishedAtString);
        $publishedAt = $publishedAt->setTimezone(new \DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAt);

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//img[contains(@class,"article__main-image__image")][1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPageCrawler, '//img[contains(@class,"article__main-image__image")][1]');
        }

        $contentCrawler = $newsPageCrawler->filter('.article__text');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"banner")]');

        if (!$image) {
            $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($newsPageCrawler, '//img[1]');
            }
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"article__main-image")]');

        $descriptionCrawler = $contentCrawler->filterXPath('//p[1]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//p[1]');
            }
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function getPageContent(string $uri): string
    {
        $content = $this->getCurl()->get($uri);
        $this->checkResponseCode($this->getCurl());

        return $this->decodeGZip($content);
    }

    protected function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        if ($this->isImageType($node)) {
            return null;
        }

        if ($node->nodeName === '#text' || !$this->isLink($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isLink($parentNode);
            });
            $node = $parentNode ?: $node;
        }


        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $newsPostDTO->getUri());
        $link = $this->encodeUri($link);
        if ($link === null) {
            return null;
        }

        if ($this->getNodeStorage()->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = $this->hasText($node) ? $this->normalizeText($node->textContent) : null;
        if ($link && $link === $linkText) {
            $linkText = null;
        }
        $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);

        $this->getNodeStorage()->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }
}
