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

class ChaikNetRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://chaiknet.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/news/rss";
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

        $newsPage = $this->getPageContent($this->getSiteUrl() . '/news');
        $newsPageCrawler = new Crawler($newsPage);
        $links = $newsPageCrawler
                                ->filterXPath('//*[@id="page-content"]//div[@class="page-drugie"][position() < 11]//a[@class="events-link"]')
                                ->each(function (Crawler $anchorLink, $i) {
            return $anchorLink->attr('href');
        });

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item[position() < 11]');

        $previewNewsCrawler->each(function (Crawler $newsPreview, $i) use (&$previewList, $links) {
            $title = $newsPreview->filterXPath('//title')->text();
            $uri = UriResolver::resolve($links[$i], $this->getSiteUrl());

            $publishedAtString = $newsPreview->filterXPath('//pubDate | //pubdate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = null;

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[@id="event-text"]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//*[@class="item-preview-image"]//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $descriptionCrawler = $newsPostCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," announce-text ")]');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsItem->setDescription($descriptionCrawler->text());
                $this->removeDomNodes($newsPostCrawler, '//div[contains(concat(" ",normalize-space(@class)," ")," announce-text ")]');
        }

        $contentCrawler = $newsPostCrawler;

        $this->removeDomNodes($contentCrawler, '//text()[contains(., "Фото:")]
        | //text()[contains(., "Источник:")]
        | //text()[contains(., "Источник информации и фото:")]
        | //*[@class="bbcode-img-description"]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}