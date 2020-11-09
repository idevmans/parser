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

class GazetaTulaRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://gazeta-tula.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?PAGEN_3={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('.content .paragraph ul li');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.articles_cont_title a')->text()));
                $uri = $this->encodeUri(UriResolver::resolve($newsPreview->filter('.articles_cont_title a')->attr('href'), $this->getSiteUrl()));

                $publishedAt = Text::trim($newsPreview->filter('.articles_data')->text());
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $publishedAt, new DateTimeZone('Europe/Moscow'));
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

        $contentCrawler = $newsPageCrawler->filter('.full-content .news-detail');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"news-date-time")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"news-detail-share")]');
        $this->removeDomNodes($contentCrawler, '//h3[1]');

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
}
