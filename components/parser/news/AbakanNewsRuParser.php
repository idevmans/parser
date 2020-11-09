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

class AbakanNewsRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://abakan-news.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            preg_match_all('/<item>.*?<\/item>/ms', $previewNewsContent, $matches, PREG_SET_ORDER, 0);
            array_walk($matches, function(&$value, $key) {
                $value = $value[0];
            });

            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        foreach ($matches as $item) {
            preg_match('/<title>(.*?)<\/title>/s', $item, $matches);
            $title = $matches[1];

            preg_match('/<link>(.*?)<\/link>/s', $item, $matches);
            $uri = $matches[1];

            preg_match('/<pubDate>(.*?)<\/pubDate>/s', $item, $matches);
            $publishedAtString = $matches[1];
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = null;

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        }

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
 
        $curl = Helper::getCurl();
        $newsPage = $curl->get($uri);
        
        // $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//*[@class="entry-thumb"]//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        // $descriptionCrawler = $newsPostCrawler->filterXPath('//*[@class="b-article-element-caption__subtitle"]');
        // if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
        //     $previewNewsItem->setDescription($descriptionCrawler->text());
        //     $this->removeDomNodes($newsPostCrawler, '//*[@class="b-article-element-caption__subtitle"]');
        // }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}