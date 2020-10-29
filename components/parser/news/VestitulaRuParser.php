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

class VestitulaRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(10, 100);
    }

    protected function getSiteUrl(): string
    {
        return 'https://vestitula.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/feed', $this->getSiteUrl());

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
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null);
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

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPageCrawler->filter('#content .entry-content');

        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"autors")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"postcontent-footer")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"article__main-image")]');

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

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
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
