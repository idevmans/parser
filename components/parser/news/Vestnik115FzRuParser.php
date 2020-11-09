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

class Vestnik115FzRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://vestnik115fz.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/p/{$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('article.content > div');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filterXPath('//a[1]')->text();
                $uri = $this->encodeUri(UriResolver::resolve($newsPreview->filterXPath('//a[1]')->attr('href'), $this->getSiteUrl()));

                $publishedAt = $newsPreview->filterXPath('//div/span[1]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAt, new DateTimeZone('Europe/Moscow'));
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

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

        $contentCrawler = $newsPageCrawler->filterXPath('//article[contains(@class,"content")]/div[1]//p');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"n-thumb")]');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
