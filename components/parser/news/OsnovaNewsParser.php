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

class OsnovaNewsParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://osnova.news/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $subDay = 0;

        while (count($previewList) < $maxNewsCount) {
            $date = date('Ymd', strtotime("-{$subDay} day Europe/Moscow"));
            $uriPreviewPage = UriResolver::resolve("/news/day/$date/", $this->getSiteUrl());

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('._news .no-gutters > .no-gutters > div');
            if ($previewNewsCrawler->text() === 'Публикаций не найдено') {
                $subDay++;
                continue;
            }

            $time = null;
            $title = null;
            $uri = null;

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, &$time, &$title, $uri, $subDay) {
                if (str_contains($newsPreview->attr('class'), '_nlz')) {
                    $title = Text::trim($this->normalizeSpaces($newsPreview->filterXPath('//a[1]')->text()));
                    $uri = UriResolver::resolve($newsPreview->filterXPath('//a[1]')->attr('href'), $this->getSiteUrl());
                } else {
                    $date = date('Y-m-d', strtotime("-{$subDay} day Europe/Moscow"));
                    $time = DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$newsPreview->text()}", new DateTimeZone('Europe/Moscow'));
                    $time = $time->setTimezone(new DateTimeZone('UTC'));
                }

                if ($time && $uri && $title) {
                    $previewList[] = new PreviewNewsDTO($uri, $time, $title);
                    unset($time, $title, $uri);
                }
            });

            $subDay++;
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = rawurldecode($previewNewsDTO->getUri());

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('._news .content');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"share_bot")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"alert")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"_dopinfo")]');

        if (!$previewNewsDTO->getImage()) {
            $image = null;

            $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($contentCrawler, '//img[1]');
            }

            if ($image !== null && $image !== '') {
                $image = UriResolver::resolve($image, $uri);
                $previewNewsDTO->setImage($this->encodeUri($image));
            }
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
