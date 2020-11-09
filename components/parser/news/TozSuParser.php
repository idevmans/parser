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

class TozSuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://toz.su/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?PAGEN_1={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('.news-list > .news-title > a');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->text()));
                $uri = $this->encodeUri(UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl()));

                $previewList[] = new PreviewNewsDTO($uri, null, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = rawurldecode($previewNewsDTO->getUri());

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('.news_big_img_1');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"image-block")]');

        $publishedAtString = $contentCrawler->filterXPath('//div[contains(@class,"news-date-time")]/b[1]')->text();
        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, new DateTimeZone('Asia/Vladivostok'));
        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAtUTC);
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"news-date-time")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
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
