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

class MichPravdaParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 0;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "news?page={$pageNumber}";
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

            $previewNewsXPath = '//div[@class="view-content"]//article[contains(@class,"node-news")]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $titleCrawler = $newsPreview->filterXPath('//h2/a');
                    $title = $titleCrawler->text();
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());

                    $publishedAtString = $newsPreview->filterXPath('//span[@class="front_day_created"]')->text();
                    $timezone = new DateTimeZone('Europe/Moscow');
                    $publishedAtString = explode(' ', $publishedAtString);
                    if ($publishedAtString[0] === 'сегодня') {
                        $publishedAtString = date('d m Y').' '.$publishedAtString[1];
                    } else {
                        $publishedAtString[1] = $this->convertStringMonthToNumber($publishedAtString[1]);
                        $publishedAtString = $publishedAtString[0].' '.$publishedAtString[1].' '.$publishedAtString[2] . $publishedAtString[3];
                        $publishedAtString = str_replace(',',' ',$publishedAtString);
                    }
                    $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, $timezone);
                    $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                    $description = null;
                    $previewNewsDTOList[] = new PreviewNewsDTO(
                        $this->encodeUri($uri),
                        $publishedAtUTC,
                        $title,
                        $description
                    );
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function getSiteUrl(): string
    {
        return 'https://michpravda.ru/';
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler->filterXPath('//section[@id="block-system-main"]');

        $descriptionCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"field-name-field-announcement")]');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsDTO->setDescription($descriptionCrawler->text());
        }

        $contentXpath = '//div[contains(@class,"tgf-slide-item")] | //div[contains(@class,"field field-name-body field-type-text")]';
        $contentCrawler = $newsPostCrawler->filterXPath($contentXpath);
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"mobile-slider")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    public function convertStringMonthToNumber($stringMonth): int
    {
        $stringMonth = mb_strtolower($stringMonth);
        $monthsList = [
            "января" => 1,
            "февраля" => 2,
            "марта" => 3,
            "апреля" => 4,
            "мая" => 5,
            "июня" => 6,
            "июля" => 7,
            "августа" => 8,
            "сентября" => 9,
            "октября" => 10,
            "ноября" => 11,
            "декабря" => 12,
        ];
        return $monthsList[$stringMonth];
    }
}
