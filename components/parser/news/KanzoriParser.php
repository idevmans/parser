<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class KanzoriParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://kanzori.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve("/feed", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $description = null;

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $description);
        });

        $previewNewsDTOList = array_slice($previewList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }


    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"main-post-content")]');

        $mainImageCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"post-thumbnail")]/a')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('href');
        }
        if ($image !== null && $image !== '') {
            $previewNewsDTO->setImage(UriResolver::resolve($image, $uri));
        }

        $descriptionCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"desc")]');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsDTO->setDescription($descriptionCrawler->text());
        }


        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"post-detail")]');

        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"post-thumbnail")]');
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"addtoany_share_save_container")]//following-sibling::*');
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"addtoany_share_save_container")]');


        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
