<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Trk27RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://27trk.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uri = $pageNumber > 1 && !empty($bxajaxid) ? "/news/?bxajaxid={$bxajaxid}&PAGEN_2={$pageNumber}" : '/news/';
            $uriPreviewPage = UriResolver::resolve($uri, $this->getSiteUrl());
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

            if (empty($bxajaxid)) {
                $bxajaxid = $previewNewsCrawler->filter('a.more-link')->attr('data-ajax-id');
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.news-list');

            $previewNewsCrawler->filter('.content-block')->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filter('.content > a')->text();
                $uri = UriResolver::resolve($newsPreview->filter('.content > a')->attr('href'), $this->getSiteUrl());

                $publishedAt = Text::trim($newsPreview->filterXPath('//div[contains(@class,"date-block")]/i[contains(@class,"fa-clock-o")]/following-sibling::text()')->text());
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAt, new DateTimeZone('UTC'));

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
            });

            $previewNewsCrawler->filter('.content-border')->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filter('a.title-link')->text();
                $uri = UriResolver::resolve($newsPreview->filter('a.title-link')->attr('href'), $this->getSiteUrl());

                $publishedAt = Text::trim($newsPreview->filterXPath('//div[contains(@class,"date-content")]/i[contains(@class,"fa-clock-o")]/following-sibling::text()[1]')->text());
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y', $publishedAt, new DateTimeZone('UTC'));

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
        $contentCrawler = $newsPageCrawler->filter('.news-detail .content-block');

        $image = null;
        $mainImageCrawler = $contentCrawler->filterXPath('//div[contains(@class,"picture")]/img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"picture")]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $contentCrawler->filter('.content');

        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"sign")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"tags")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"ya-share2")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"block-telegram")]');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
