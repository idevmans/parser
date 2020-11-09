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

class SibreporterInfoParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://sibreporter.info/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 0;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/novosti?page={$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('#content .view-news .views-row');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $this->removeDomNodes($newsPreview, '//li[1]');

                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('li .nws a')->text()));
                $uri = $this->encodeUri(UriResolver::resolve($newsPreview->filter('li .nws a')->attr('href'), $this->getSiteUrl()));

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

        $contentCrawler = $newsPageCrawler->filter('#content article .art-text');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        $descriptionCrawler = $contentCrawler->filterXPath('//p[contains(@class,"introduction")]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//p[contains(@class,"introduction")]');
            }
        }

        $contentCrawler = $contentCrawler->filter('.wysiwyg .field-item');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $this->removeDomNodes($crawler, '//span[contains(@class,"nws")]/following-sibling::*');
        $this->removeDomNodes($crawler, '//span[contains(@class,"nws")]');

        $publishedAtString = Text::trim($crawler->filter('li')->text());
        $publishedAtString = str_replace([...$months, '/ '], [...array_keys($months), ''], $publishedAtString);

        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, new DateTimeZone('Asia/Novosibirsk'));
        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

        return $publishedAt;
    }
}
