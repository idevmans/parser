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

class VolganetNetParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://volganet.net/';
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

        $contentCrawler = $newsPageCrawler->filter('#content .post');

        $descriptionCrawler = $contentCrawler->filterXPath('//h2[contains(@class,"entry-subtitle")]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//h2[contains(@class,"entry-subtitle")]');
            }
        }

        $contentCrawler = $contentCrawler->filter('.entry-essentials');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
            $this->removeDomNodes($contentCrawler, '//figure[contains(@class,"entry-featured-media-main")]');
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

    protected function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        if ($node->hasAttribute('data-orig-file')) {
            $imageLink = $node->getAttribute('data-orig-file');
        }

        if (empty($imageLink) || !$imageLink) {
            $imageLink = $node->getAttribute('data-src') ?: $node->getAttribute('src');
        }

        if ($isPicture) {
            if ($this->getNodeStorage()->contains($node->parentNode)) {
                throw new RuntimeException('Тег уже сохранен');
            }

            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        if ($imageLink === '' || mb_stripos($imageLink, 'data:') === 0) {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $newsPostDTO->getUri());
        if ($imageLink === null) {
            return null;
        }

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;

        $newsPostItem = NewsPostItemDTO::createImageItem($imageLink, $alt);

        if ($isPicture) {
            $this->getNodeStorage()->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }
}
