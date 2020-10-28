<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class GroznyParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://grozny.tv';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news?page={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;
            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//article');
            if (!$this->crawlerHasNodes($previewNewsCrawler)) {
                break;
            }

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $uriCrawler = $newsPreview->filterXPath('//a');
                $uri = UriResolver::resolve($uriCrawler->attr('href'), "{$this->getSiteUrl()}/news");
                $title = $newsPreview->filterXPath('//h3')->text();

                $timezone = new DateTimeZone('Europe/Moscow');
                $publishedAtString = $newsPreview->filterXPath('//li[contains(@class,"meta-news__date")]')->text();

                $publishedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishedAtString, $timezone);
                if($publishedAt === false){
                    $publishedAt = $this->parseHumanDateTime($publishedAtString, $timezone);
                }
                $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
                $preview = null;

                $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
            });
        }

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null);
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"page-news")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"page-news__images")]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            preg_match('/(?:\([\'\"]?)(.*?)(?:[\'\"]?\))/u', $mainImageCrawler->attr('style'), $matches);
            if (isset($matches[1])) {
                $image = $matches[1];
            }
        }
        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"page-news__content")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
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

    private function parseHumanDateTime(string $dateTime, DateTimeZone $timeZone): DateTimeInterface
    {
        $formattedDateTime = mb_strtolower(trim($dateTime));
        $now = new DateTimeImmutable('now', $timeZone);

        if ($formattedDateTime === 'только что') {
            return $now;
        }

        if (str_contains($formattedDateTime, 'час') && str_contains($formattedDateTime, 'назад')) {
            $numericTime = preg_replace('/\bчас\b/u', '1', $formattedDateTime);
            $hours = preg_replace('/[^0-9]/u', '', $numericTime);
            return $now->sub(new DateInterval("PT{$hours}H"));
        }

        if (str_contains($formattedDateTime, 'вчера')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone)->sub(new DateInterval("P1D"));
        }

        if (str_contains($formattedDateTime, 'сегодня')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone);
        }

        throw new RuntimeException("Не удалось распознать дату: {$dateTime}");
    }
}