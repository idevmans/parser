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

class ArmyStandardParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 50): array
    {
        $newsPosts = parent::parse($minNewsCount, $maxNewsCount);

        usort($newsPosts, static function (NewsPost $firstNewsPost, NewsPost $secondNewsPost) {
            if ($firstNewsPost->createDate === $secondNewsPost->createDate) {
                return 0;
            }

            return ($firstNewsPost->createDate > $secondNewsPost->createDate) ? -1 : 1;
        });

        return $newsPosts;
    }

    protected function getSiteUrl(): string
    {
        return 'https://armystandard.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = $this->getSiteUrl();

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[@class="as-item"]');
        if (!$this->crawlerHasNodes($previewNewsCrawler)) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $uriCrawler = $newsPreview->filterXPath('//a[contains(@class,"as-item__link")]');
            $title = $newsPreview->filterXPath('//span[contains(@class,"as-item__title")]')->text();
            $uri = UriResolver::resolve($uriCrawler->attr('href'), $this->getSiteUrl());
            $uri = $this->encodeUri($uri);

            $publishedAtUTC = null;

            $preview = null;

            $previewList[$uri] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);
        $previewList = array_values($previewList);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);
        preg_match('/var inner_img = \[([^;]*)\];/iu', $newsPage, $matches);
        if (isset($matches[1])) {
            $jsonString = '[' . str_replace('\'', '"', $matches[1]) . ']';
            $positionOfLastComma = mb_strrpos($jsonString,',');

            $jsonString = mb_substr($jsonString,0,$positionOfLastComma).mb_substr($jsonString,$positionOfLastComma+1);
            $jsonString = str_replace(['\xc2','\xa0'], ' ', $jsonString);

            $imagesInfoList = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
            foreach ($imagesInfoList as $key => $image){
                $newsPage = str_replace("<span class=\"replaced_{$key}\"></span>","<img src='{$image['path']}'>",$newsPage);
            }
        }

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $descriptionCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"anons desktop")]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $description = $descriptionCrawler->text();
            if ($description !== null && $description !== '') {
                $previewNewsItem->setDescription($description);
            }
        }

        $publishedAtString = $newsPageCrawler->filterXPath('//meta[@name="mediator_published_time"]')->attr('content');
        $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $publishedAtString);
        $previewNewsItem->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $contentXPath = '//div[contains(@class,"glav_img")] | //div[contains(@class,"glav_text")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXPath);

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

}