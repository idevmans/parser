<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class MM21Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://21mm.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $urn = "/news/?PAGEN_1={$pageNumber}";
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

            $previewNewsXPath = '//ul[@class="diary-list "]//li[@class="diary-item"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewNewsXPath);

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                $title = $newsPreview->filter('.diary-item-link .diary-item-name')->text();
                $uri = UriResolver::resolve($newsPreview->filter('.diary-item-link')->attr('href'), $this->getSiteUrl());

                $publishedAtString = Text::trim($this->normalizeSpaces($newsPreview->filterXPath('//span[@class="most-popular__date"]')->text()));
                $publishedAtString .= ' ' . Text::trim($this->normalizeSpaces($newsPreview->filterXPath('//span[@class="most-popular__time"]')->text()));
                $publishedAt = $this->replacePublishedAt($publishedAtString);

                $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
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
        $description = $this->getDescriptionFromContentText($newsPageCrawler);
        $contentCrawler = $newsPageCrawler->filter('.article-detail-text');

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $contentCrawler = $contentCrawler->filterXPath('//*[@itemprop="articleBody"]');

        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"mobile-slider")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"google-auto-placed")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"ya-share2")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"content-block")]');
        $this->removeDomNodes($contentCrawler, '//div[@class="articles-links-add"]');

        $this->removeDomNodes($contentCrawler, '//span[@itemprop="name"]');
        $this->removeDomNodes($newsPageCrawler, '//p[contains(@class,"element-invisible no-mobile")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getDescriptionFromContentText(Crawler $crawler): ?string
    {
        $descriptionCrawler = $crawler->filterXPath('//div[contains(@class,"article-epilog")]');

        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));

            if ($descriptionText) {
                $this->removeDomNodes($crawler, '//div[contains(@class,"article-epilog")]');
                return $descriptionText;
            }
        }

        return null;
    }

    private function replacePublishedAt(string $publishedAtString): DateTimeImmutable
    {
        $publishedAtString = mb_strtolower($publishedAtString);
        $monthsList = [
            1 => "января",
            2 => "февраля",
            3 => "марта",
            4 => "апреля",
            5 => "мая",
            6 => "июня",
            7 => "июля",
            8 => "августа",
            9 => "сентября",
            10 => "октября",
            11 => "ноября",
            12 => "декабря",
        ];

        $publishedAtString = str_replace($monthsList, array_keys($monthsList), $publishedAtString);

        $timezone = new DateTimeZone('Europe/Moscow');
        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, $timezone);
        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
