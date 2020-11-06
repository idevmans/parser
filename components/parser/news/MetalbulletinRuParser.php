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

class MetalbulletinRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://metalbulletin.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("news/page/{$pageNumber}/", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('.one_news > table tr');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                if (!$newsPreview->filter('a')->count()) {
                    return;
                }

                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('a.normal_news1')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('a.normal_news1')->attr('href'), $this->getSiteUrl());


                $time = Text::trim($newsPreview->filterXPath('//td[1]')->text());
                [$h, $m] = explode(':', $time);

                $publishedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
                $publishedAt = $publishedAt->setTime($h, $m, 0);

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();
        $publishedAt = $previewNewsDTO->getPublishedAt();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"one_news")]/div[contains(@class,"text1")][1]');

        $publishedAtCrawler = $newsPageCrawler->filter('.one_news .category');
        if ($this->crawlerHasNodes($publishedAtCrawler)) {
            $publishedAtText = Text::trim($this->normalizeSpaces($publishedAtCrawler->text()));
            [$day, $month, $year] = explode('.', $publishedAtText);
            if ($publishedAt instanceof DateTimeImmutable) {
                $publishedAt = $publishedAt->setDate($year, $month, $day);
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));
                $previewNewsDTO->setPublishedAt($publishedAt);
            }
        }

        $image = $this->getMainImage($contentCrawler);
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getMainImage(Crawler $crawler): ?string
    {
        $image = null;
        $mainImageCrawler = $crawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $crawler->filterXPath('//img[1]')->attr('src');
            $this->removeDomNodes($crawler, '//img[1]');
        }

        return $image;
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
