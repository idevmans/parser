<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Avangard93RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://avangard-93.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?page{$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('#allEntries .nPostNews');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.nTitle a')->text()));
                $uri = $this->encodeUri(UriResolver::resolve($newsPreview->filter('.nTitle a')->attr('href'), $this->getSiteUrl()));

                $previewList[] = new PreviewNewsDTO($uri, null, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $publishedAtCrawler = $newsPageCrawler->filter('meta[itemprop="datePublished"]');

        if ($this->crawlerHasNodes($publishedAtCrawler)) {
            $publishedAtString = $publishedAtCrawler->attr('content');
            $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $publishedAtString, new DateTimeZone('Europe/Moscow'));
            $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));
            $previewNewsDTO->setPublishedAt($publishedAt);
        }

        $contentCrawler = $newsPageCrawler->filter('.newsSTR .pMessage');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
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

    protected function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = [
            'strong' => true,
            'b' => true,
            's' => true,
            'i' => true,
            'a' => true,
            'em' => true
        ];

        return isset($formattingTags[$node->nodeName]);
    }
}
