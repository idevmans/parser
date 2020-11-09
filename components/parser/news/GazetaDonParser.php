<?php

namespace app\components\parser\news;

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

class GazetaDonParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://gazeta-don.ru';
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
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"paragraph_cont")]');
            if(!$this->crawlerHasNodes($previewNewsCrawler)){
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $titleCrawler = $newsPreview->filterXPath('//div[contains(@class,"articles_cont_title")]/a');
                $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());


                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAtString = $newsPreview->filterXPath('//div[contains(@class,"articles_data")]')->text();
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $publishedAtString, $timezone);
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
            });
        }
        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }
        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@class="news-detail"]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//img[contains(@class,"detail_picture")]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null) {
            $previewNewsDTO->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler,'//h3/preceding-sibling::*');
        $this->removeDomNodes($contentCrawler,'//h3');
        $this->removeDomNodes($contentCrawler,'//div[contains(@class,"news-detail-share")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }


    protected function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        $imageLink = $node->getAttribute('src');

        if ($isPicture) {
            if ($this->getNodeStorage()->contains($node->parentNode)) {
                throw new RuntimeException('Тег уже сохранен');
            }

            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        $parent = $node->parentNode;
        if ($parent->tagName === 'a' && $parent->hasAttribute('data-fancybox') && $parent->hasAttribute('href')) {
            $imageLink = $parent->getAttribute('href');
        }

        if ($imageLink === '' || mb_stripos($imageLink, 'data:') === 0) {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $newsPostDTO->getUri());
        if ($imageLink === null) {
            return null;
        }

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;

        $newsPostItem = NewsPostItemDTO::createImageItem($imageLink, $alt);

        if ($isPicture) {
            $this->getNodeStorage()->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }
}