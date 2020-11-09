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

class AdvisRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://www.advis.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve('/', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsContent = mb_convert_encoding($previewNewsContent, 'UTF-8', 'windows-1251');
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $this->removeDomNodes($previewNewsCrawler, '//div[contains(@class,"calendar_ajax")]/following-sibling::*');
        $this->removeDomNodes($previewNewsCrawler, '//div[contains(@class,"calendar_ajax")]');
        $this->removeDomNodes($previewNewsCrawler, '//div[contains(@class,"mainContent")]//div[contains(@class,"networks")]');
        $previewNewsCrawler = $previewNewsCrawler->filter('.mainContent .article4');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            try {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('a')->text()));
            } catch (\Exception $exception) {
                dd($newsPreview->html(), 1);
            }
            $uri = UriResolver::resolve($newsPreview->filter('a')->attr('href'), $this->getSiteUrl());

            $publishedAtString = Text::trim($newsPreview->filter('.date')->text());
            $publishedAtString = str_replace(' в', '', $publishedAtString);
            $publishedAt = DateTimeImmutable::createFromFormat('d.m H:i', $publishedAtString, new DateTimeZone('Europe/Moscow'));
            $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = rawurldecode($previewNewsDTO->getUri());

        $newsPage = $this->getPageContent($uri);
        $newsPage = mb_convert_encoding($newsPage, 'UTF-8', 'windows-1251');

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('#font_text');

        if (!$previewNewsDTO->getImage()) {
            $image = null;
            $mainImageCrawler = $contentCrawler->filter('meta[property="og:image"]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('content');
            }

            if ($image !== null && $image !== '') {
                $image = UriResolver::resolve($image, $uri);
                $previewNewsDTO->setImage($this->encodeUri($image));
            }
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
