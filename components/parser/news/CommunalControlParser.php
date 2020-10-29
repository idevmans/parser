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

class CommunalControlParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://communal-control.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news?NewsSearch%5Bcategory_id%5D=1&page={$pageNumber}";
            $uriPreviewPage = UriResolver::resolve($urn, $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsXPath = '//div[@id="w1"]//div[@class="block"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $titleCrawler = $newsPreview->filterXPath('//div[@class="content"]//a');
                $title = $titleCrawler->text();
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                $publishedAtString = $newsPreview->filterXPath('//div[@class="content"]//div[@class="date"]')->text();
                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $description = null;
                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), $publishedAtUTC, $title, $description);
            });
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@id="content-block"]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[@class="image"]//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $previewNewsDTO->setDescription(null);

        $contentCrawler = $newsPostCrawler->filterXPath('//div[@class="text"]')->first();
        $this->removeDomNodes($contentCrawler,'//div[@class="tags"] | //div[@class="share"]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
