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

class KuzkomParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://kuzkom.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 2;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/page1/category/novosti/page/{$pageNumber}/";
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

            $previewNewsXPath = '//div[@id="content"]/div[@class="links-list"]/p';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $title = $newsPreview->filterXPath('//a')->text();
                $uri = UriResolver::resolve($newsPreview->filterXPath('//a')->attr('href'), $this->getSiteUrl());
                $description = null;
                $previewNewsDTOList[] = new PreviewNewsDTO($this->encodeUri($uri), null, $title,
                    $description);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@id="post-full"]');

        $mainImageCrawler = $newsPostCrawler->filterXPath('//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPostCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $publishedAtString = $newsPageCrawler->filterXPath('//p[contains(@class,"news-date")]')->text();
        $publishedAtString = $this->translateDateToEng($publishedAtString);
        $timezone = new DateTimeZone('Asia/Novokuznetsk');
        $publishedAt = DateTimeImmutable::createFromFormat('d F Y \| H:i:s', $publishedAtString, $timezone);
        $previewNewsDTO->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $contentCrawler = $newsPostCrawler;
        $this->removeDomNodes($contentCrawler, '//div[@id="post-info"]');

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

        $imageLink = $this->getImageLinkFromNode($node);

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

        $parentNode = $node->parentNode;
        if ($parentNode->tagName === 'a' && $parentNode->hasAttribute('data-fancybox')) {
            $src = $parentNode->getAttribute('href');
            if ($src !== '' && $src !== null) {
                $imageLink = $src;
            }
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
            $this->getNodeStorage()->removeAll($this->getNodeStorage());
            $this->getNodeStorage()->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }

    protected function isLink(DOMNode $node): bool
    {
        if($node instanceof DOMElement && $node->hasAttribute('data-fancybox')){
            return false;
        }

        return parent::isLink($node);
    }

    private function translateDateToEng(string $date)
    {
        $date = mb_strtolower($date);

        $monthRegex = [
            '/янв[\S.]*/iu' => 'January',
            '/фев[\S.]*/iu' => 'February',
            '/мар[\S.]*/iu' => 'March',
            '/апр[\S.]*/iu' => 'April',
            '/май[\S.]*/iu' => 'May',
            '/июн[\S.]*/iu' => 'June',
            '/июл[\S.]*/iu' => 'July',
            '/авг[\S.]*/iu' => 'August',
            '/сен[\S.]*/iu' => 'September',
            '/окт[\S.]*/iu' => 'October',
            '/ноя[\S.]*/iu' => 'November',
            '/дек[\S.]*/iu' => 'December'
        ];

        foreach ($monthRegex as $regex => $enMonth) {
            $date = preg_replace($regex, $enMonth, $date);
        }

        return $date;
    }
}
