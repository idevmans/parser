<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use Closure;
use DateTimeImmutable;
use DOMElement;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class KommersantRuRegions78Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://kommersant.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $subMonth = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            if ($subMonth > 12) {
                break;
            }

            $monthForUrl = date('Y-m', strtotime("-{$subMonth} month")) . '-01';

            $uriPreviewPage = UriResolver::resolve("/archive/news/78/month/{$monthForUrl}", $this->getSiteUrl());

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.archive .archive_date_result');

            $previewNewsCrawler->each(function (Crawler $daysCrawler) use (&$previewNewsDTOList, $maxNewsCount) {
                $closure = Closure::fromCallable(function (Crawler $crawler) use (&$previewNewsDTOList) {
                    $this->removeDomNodes($crawler, '//time');
                    $url = UriResolver::resolve($crawler->filter('a')->attr('href'), $this->getSiteUrl());
                    $title = Text::trim($this->normalizeSpaces($crawler->filter('a')->text()));
                    $previewNewsDTOList[] = new PreviewNewsDTO($url, null, $title);
                });

                $daysCrawler->filter('li.archive_date_result__item')->each($closure);

                if (count($previewNewsDTOList) >= $maxNewsCount) {
                    return;
                }

                $lazyButtonCrawler = $daysCrawler->filter('button.ui_button--load_content');
                if ($this->crawlerHasNodes($lazyButtonCrawler)) {
                    $lazyloadUrl = UriResolver::resolve($lazyButtonCrawler->attr('data-lazyload-url'), $this->getSiteUrl());
                    $lazyContent = $this->getPageContent($lazyloadUrl);
                    $lazyCrawler = new Crawler($lazyContent);
                    $lazyCrawler->filter('li.archive_date_result__item')->each($closure);
                }
            });
            $subMonth++;
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

        $publishedAtString = $newsPageCrawler->filter('article time.title__cake')->attr('datetime');
        $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $publishedAtString, new \DateTimeZone('Europe/Moscow'));
        $publishedAt = $publishedAt->setTimezone(new \DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAt);

        $contentCrawler = $newsPageCrawler->filter('.article_text_wrapper');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"b-incut__photogallery")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"b-article-media")]//img[contains(@class,"fallback_image")][1]');

        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"b-article-media")]//img[contains(@class,"fallback_image")][1]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $descriptionCrawler = $contentCrawler->filterXPath('//h3[1]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $description = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            $this->removeDomNodes($contentCrawler, '//h3[1]');
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
