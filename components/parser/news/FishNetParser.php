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

class FishNetParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'www.fishnet.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve("/news/rss.xml", $this->getSiteUrl());

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"content")]');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage(Helper::encodeUrl($image));
        }

        $description = null;
        if($description && $description !== ''){
            $previewNewsDTO->setDescription($description);
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[@class="boxed"]');

        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler, '//a[starts-with(@href, "javascript")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@style,"text-align:center;")]/a');
        $this->removeDomNodes($contentCrawler, '//div[@class="h3"]');
        $this->removeDomNodes($contentCrawler, '//div[@class="body"]/following-sibling::text()[contains(., "•")]');
        $this->removeDomNodes($contentCrawler, '//div[@class="control"] | //div[@class="control"]//following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[@class="error"] | //div[@class="error"]//following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[@class="body"]/following-sibling::text()');
        $this->removeDomNodes($contentCrawler, '//script | //video');
        $this->removeDomNodes($contentCrawler, '//table');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}