<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Ural24ComParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://ural24.com/';
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

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[starts-with(@id,"entryID")]');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.vidmattd-2 a.vidmattit')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('.vidmattd-2 a.vidmattit')->attr('href'), $this->getSiteUrl());
                $publishedAt = $this->getPublishedAt($newsPreview);

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

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('#content .eText');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            if ($contentCrawler->filterXPath('//img[1]/parent::a[contains(@class,"ulightbox")]')->count()) {
                $this->removeDomNodes($contentCrawler, '//img[1]/parent::a[contains(@class,"ulightbox")]');
            } else {
                $this->removeDomNodes($contentCrawler, '//img[1]');
            }
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

    private function getPublishedAt(Crawler $crawler): DateTimeImmutable
    {
        $publishedAtString = mb_strtolower(Text::trim($crawler->filter('.datebbmat-2')->text()));
        if (in_array($publishedAtString, ['сегодня', 'вчера'], true)) {
            $publishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($publishedAtString === 'вчера') {
                $publishedAt = $publishedAt->modify('-1day');
            }
        } else {
            $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAtString, new DateTimeZone('Asia/Yekaterinburg'));
        }

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
