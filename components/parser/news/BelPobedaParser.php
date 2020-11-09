<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class BelPobedaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(10, 20);
    }

    protected function getSiteUrl(): string
    {
        return 'https://bel-pobeda.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $url = "/edw/api/data-marts/32/entities.json?offset=0&limit={$maxNewsCount}";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getJsonContent($uriPreviewPage);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }
        $newsPageObjects = $previewNewsContent['results']['objects'];

        if (!isset($newsPageObjects) || count($newsPageObjects) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        foreach ($newsPageObjects as $object) {
            $title = $object['entity_name'];
            $uri = $object['entity_url'];
            $uri = $this->encodeUri($uri);

            $publishedAtString = $object['extra']['created_at'];
            $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = $object['extra']['short_subtitle'];
            if ($preview === '') {
                $preview = null;
            }

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        }

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }
        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsContent = $this->getJsonContent($uri);
        $uri = UriResolver::resolve($newsContent['detail_url'], $this->getSiteUrl());
        $publishedAt = $previewNewsItem->getPublishedAt();
        $title = $previewNewsItem->getTitle();
        $description = $previewNewsItem->getDescription();
        $previewNewsItem = new PreviewNewsDTO($uri, $publishedAt, $title, $description);

        $newsPage = $this->getPageContent($uri);
        $newsPostCrawler = new Crawler($newsPage);

        $contentXPath = '//div[contains(@class,"theme-default")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXPath);

        $galleryImages = [];
        foreach ($newsContent['gallery'] as $imageObject) {
            $imageLink = UriResolver::resolve($imageObject['image'], $this->getSiteUrl());
            $galleryImages[] = NewsPostItemDTO::createImageItem($imageLink);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        $newsPostItemDTOList = array_merge($newsPostItemDTOList, $galleryImages);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}
