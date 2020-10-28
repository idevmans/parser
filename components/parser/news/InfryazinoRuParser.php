<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class InfryazinoRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return Helper::encodeUrl('http://infryazino.ru');
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/novosti?page={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.list-yii-wrapper .news-itm');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filterXPath('//*[contains(@class,"news-itm__title")]/a')->text();
                $uri = UriResolver::resolve($newsPreview->filterXPath('//*[contains(@class,"news-itm__title")]/a')->attr('href'), $this->getSiteUrl());

                $publishedAt = $this->searchPublishedAt($newsPreview);

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title, null);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);
        $newsPageCrawler = new Crawler($newsPage);

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"b-page__image")]//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($newsPageCrawler, '//div[contains(@class,"b-page__image")]//img[1]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"b-page__content")]');

        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form | //table');
        $this->removeDomNodes($contentCrawler, '//p[contains(@class,"print")] | //span[contains(@class,"quote")]');

        $descriptionCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"b-page__start")]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $description = Text::trim($descriptionCrawler->text());
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function searchPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $crawler = $crawler->filter('p.news-itm__date')->first();
        if (!$this->crawlerHasNodes($crawler)) {
            return null;
        }

        $publishedAt = Text::trim($crawler->text());

        $months = [
            1 => 'янв.',
            2 => 'февр.',
            3 => 'марта',
            4 => 'апр.',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'авг.',
            9 => 'сент.',
            10 => 'окт.',
            11 => 'нояб.',
            12 => 'дек.',
        ];

        $publishedAt = str_replace([...$months, ' г., '], [...array_keys($months), ''], $publishedAt);
        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAt, new DateTimeZone('Europe/Moscow'));

        if (!$publishedAt) {
            return null;
        }

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
