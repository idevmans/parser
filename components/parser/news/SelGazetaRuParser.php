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

class SelGazetaRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://selgazeta.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/lenta-novostey/lenta-novostey/ja-magz-ii-xblog.html";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $pageOne = $this->getPageContent($uriPreviewPage);
            $pageTwoLink = (new Crawler($pageOne))->filterXPath('//a[@title="Вперед"]')->attr('href');
            $pageTwoLink = UriResolver::resolve($pageTwoLink, $this->getSiteUrl());
            $pageTwo = $this->getPageContent($pageTwoLink);
            preg_match_all('/<article>.*?<\/article>/ms', $pageOne . $pageTwo, $matches, PREG_SET_ORDER, 0);
            array_walk($matches, function(&$value, $key) {
                $value = $value[0];
            });
            $previewNewsContent = implode($matches);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//article');

        $previewNewsCrawler->each(function (Crawler $newsPreview, $i) use (&$previewList) {
            $anchorLink = $newsPreview->filterXPath('//*[@class="article-title"]//a');
            $title = $anchorLink->attr('title');
            $uri = UriResolver::resolve($anchorLink->attr('href'), $this->getSiteUrl());

            $publishedAtUTC = new DateTimeImmutable();

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

        $publishedAtString = $newsPageCrawler->filterXPath('//*[@itemprop="datePublished"]')->attr('datetime');
        $publishedAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ISO8601, $publishedAtString);
        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

        $previewNewsItem->setPublishedAt($publishedAt);

        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," article-image-full ")]//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('data-src');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        // $descriptionCrawler = $newsPostCrawler->filterXPath('//h2');
        // if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
        //     $previewNewsItem->setDescription($descriptionCrawler->text());
        // }

        $contentCrawler = $newsPostCrawler->filterXPath('//*[@itemprop="articleBody"]');

        $this->removeDomNodes($contentCrawler, '//p[last()][contains(text(), "Фото ")]
        | //*[contains(concat(" ",normalize-space(@class)," ")," jllikeproSharesContayner ")]
        | //*[contains(concat(" ",normalize-space(@class)," ")," highslide-caption ")]
        | //img[contains(@src, "blank.gif")]
        | //text()[contains(., "blank.gif")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}