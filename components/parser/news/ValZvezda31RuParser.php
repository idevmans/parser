<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ValZvezda31RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://val-zvezda31.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve("edw/api/data-marts/30/entities.json?&limit={$maxNewsCount}", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getJsonContent($uriPreviewPage);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        if (!is_array($previewNewsContent) || empty($previewNewsContent['results']) || empty($previewNewsContent['results']['objects'])) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        foreach ($previewNewsContent['results']['objects'] as $object) {
            $title = $object['entity_name'];
            $uri = UriResolver::resolve($object['extra']['url'], $this->getSiteUrl());
            $preview = $object['extra']['short_subtitle'];

            $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $object['extra']['created_at']);
            $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAt, $title, $preview);
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

        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"content-body")]//div[contains(@class,"theme-default")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"topic_image")]/img');

        $image = null;
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"topic_image")]/img');
        }

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
}
