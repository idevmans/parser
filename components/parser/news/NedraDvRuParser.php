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

class NedraDvRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://nedradv.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/nedradv/ru/news";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div/h3/a');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = $newsPreview->text();
            $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article[descendant::h1]');

        try {
            $previewNewsItem->setDescription($newsPageCrawler->filterXPath('//blockquote[1]//p')->text());
        } catch (\Throwable $th) {
            //throw $th;
        }

        try {
            $publishedAtString = $newsPostCrawler->filterXPath('//header//li')->text();
            $publishedAtArray = explode(' ', $publishedAtString);
            $publishedAtArray[1] = $this->RuMonthToFormat($publishedAtArray[1]);
            $publishedAtString = implode(' ', $publishedAtArray);
            $publishedAt = DateTimeImmutable::createFromFormat('d m Y', $publishedAtString, new DateTimeZone('Europe/Moscow'));
        } catch (\Throwable $th) {
            $publishedAt = new DateTimeImmutable();
        }

        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsItem->setPublishedAt($publishedAt);

        $image = null;
        $mainImageCrawler = $newsPostCrawler->filterXPath('//a[child::img][1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('href');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        // $descriptionCrawler = $newsPostCrawler->filterXPath('//h2');
        // if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
        //     $previewNewsItem->setDescription($descriptionCrawler->text());
        // }

        $contentCrawler = $newsPostCrawler;

        $this->removeDomNodes($contentCrawler, '//*[contains(text(), "Фото:")]
        | //header
        | //blockquote[1]
        | //*[following-sibling::*[contains(concat(" ",normalize-space(@class)," ")," ya-share2 ")]][last()]/following-sibling::*');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    private function RuMonthToFormat(string $date, string $type = 'm')
    {
        $date = mb_strtolower($date);

        $ruMonth = [
            'янв' => '01',
            'февр' => '02',
            'мар' => '03',
            'апр' => '04',

            'мая' => '05',
            'май' => '05',

            'июн' => '06',
            'июл' => '07',
            'авг' => '08',
            'сент' => '09',
            'окт' => '10',
            'нояб' => '11',
            'дек' => '12'
        ];
        $dateToReturn = null;
        foreach ($ruMonth as $key => $value) {
            if (preg_match('/' . $key . '/m', $date)) {
                $dateToReturn = $value;
            }
        }

        return $dateToReturn;
    }
}