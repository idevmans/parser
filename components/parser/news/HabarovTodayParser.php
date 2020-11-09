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

class HabarovTodayParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://habarov.today/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?type=1&page={$pageNumber}&ajax", $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getJsonContent($uriPreviewPage)['text'];
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler->filter('.news_top_wrap > a')
                ->each(function (Crawler $newsPreview) use (&$previewList) {
                    $titleCrawler = $newsPreview->filter('p.right_news_text_1');
                    $this->removeDomNodes($newsPreview, '//*[contains(@class,"what_time")]');
                    $title = Text::trim($this->normalizeSpaces($titleCrawler->text()));
                    $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());

                    $previewList[] = new PreviewNewsDTO($uri, null, $title);
                });

            $previewNewsCrawler->filter('.small_news_block_wrap .small_news_wrap p.small_news_text > a')
                ->each(function (Crawler $newsPreview) use (&$previewList) {
                    $title = Text::trim($this->normalizeSpaces($newsPreview->text()));
                    $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());

                    $previewList[] = new PreviewNewsDTO($uri, null, $title);
                });

            $previewNewsCrawler->filter('.index_bottom_block_wrap a.index_bottom_block')
                ->each(function (Crawler $newsPreview) use (&$previewList) {
                    $titleCrawler = $newsPreview->filter('p.text_wrap_p');
                    $this->removeDomNodes($newsPreview, '//*[contains(@class,"what_time")]');
                    $title = Text::trim($this->normalizeSpaces($titleCrawler->text()));
                    $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());

                    $previewList[] = new PreviewNewsDTO($uri, null, $title);
                });

            $previewNewsCrawler->filter('.news_bottom_block .news_bottom_item a')
                ->each(function (Crawler $newsPreview) use (&$previewList) {
                    $title = Text::trim($this->normalizeSpaces($newsPreview->text()));
                    $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());

                    $previewList[] = new PreviewNewsDTO($uri, null, $title);
                });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('.content .n_c_l_w');
        $previewNewsDTO->setPublishedAt($this->getPublishedAt($contentCrawler));
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"news_card_h")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"block_l_name")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"i_l_small")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"soc_icons_wrap")]');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"instagram-media")]');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $publishedAt = $crawler->filter('.n_c_h_date')->text();
        $publishedAtString = str_replace([...$months, 'в '], [...array_keys($months), ''], $publishedAt);

        $publishedAt = DateTimeImmutable::createFromFormat('d m Y H:i', $publishedAtString, new DateTimeZone('Asia/Vladivostok'));
        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

        return $publishedAt;
    }
}
