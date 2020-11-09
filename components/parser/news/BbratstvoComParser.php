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

class BbratstvoComParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://bbratstvo.com/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve("/news", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filter('#main .view-content > .views-row');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.views-field-title a')->text()));
            $uri = UriResolver::resolve($newsPreview->filter('.views-field-title a')->attr('href'), $this->getSiteUrl());

            $publishedAt = $this->getPublishedAt($newsPreview);

            $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);
        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('.content .node__content');

        $image = null;
        $mainImageCrawler = $contentCrawler->filterXPath('//div[contains(@class,"field--name-field-image")]//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"field--name-field-image")]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $publishedAtString = Text::trim($this->normalizeSpaces($crawler->filter('.views-field-created span')->text()));
        $publishedAtString = str_replace([...$months, ','], [...array_keys($months), ''], $publishedAtString);

        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, new DateTimeZone('Europe/Moscow'));

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
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

        if (trim($link) === trim($linkText)) {
            $linkText = null;
        }

        $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);

        $this->getNodeStorage()->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

}
