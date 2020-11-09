<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class RkKareliaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://rk.karelia.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed/";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();
            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $preview = null;

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $descriptionCrawler = $newsPostCrawler->filterXPath('//header//h2');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsItem->setDescription($descriptionCrawler->text());
        }


        $contentXpath = '//header/following-sibling::div[contains(@class,"media-holder")]/iframe  | //div[contains(@class,"entry-content")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXpath);
        $this->removeDomNodes($contentCrawler, '//footer');
        $this->removeDomNodes($contentCrawler, '//noscript');
        $this->removeDomNodes($contentCrawler, '//p[contains(@class,"wp-caption-text")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"tiled-gallery-caption")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    protected function getImageLinkFromNode(DOMElement $node): string
    {
        if ($node->hasAttribute('data-orig-file')) {
            $src = $node->getAttribute('data-orig-file');
            if ($src !== '' && $src !== null) {
                return $src;
            }
        }

        if ($node->hasAttribute('srcset')) {
            $srcset = $node->getAttribute('srcset');
            $parts = explode(', ',$srcset);
            $maxSize = null;
            $maxSizeSrc = null;
            foreach ($parts as $srcString){
                $delimiterPosition =mb_strrpos($srcString,' ');
                $size = (int) mb_substr($srcString,$delimiterPosition);
                if($maxSize < $size){
                    $maxSize = $size;
                    $maxSizeSrc= mb_substr($srcString,0,$delimiterPosition);
                }
            }

            if ($maxSizeSrc !== '' && $maxSizeSrc !== null) {
                return $maxSizeSrc;
            }
        }

        return $node->getAttribute('src');
    }

}