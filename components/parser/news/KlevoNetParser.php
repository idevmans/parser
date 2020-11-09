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

class KlevoNetParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://klevo.net/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/page/{$pageNumber}/", $this->getSiteUrl());
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

            $this->removeDomNodes($previewNewsCrawler, '//div[contains(@class,"inline-ad")]');
            $previewNewsCrawler = $previewNewsCrawler->filter('.td-ss-main-content .td_module_wrap');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                try {
                    $title = Text::trim($this->normalizeSpaces($newsPreview->filter('h3 a')->text()));
                } catch (\Exception $exception) {
                    dd($newsPreview->html());
                }
                $uri = UriResolver::resolve($newsPreview->filter('h3 a')->attr('href'), $this->getSiteUrl());

                $publishedAt = Text::trim($newsPreview->filter('time.entry-date')->attr('datetime'));
                $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $publishedAt);
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
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

        $contentCrawler = $newsPageCrawler->filter('.td-ss-main-content .td-post-content');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"td-a-rec")]');

        $image = null;
        $mainImageCrawler = $contentCrawler->filterXPath('//div[contains(@class,"td-post-featured-image")]//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"td-post-featured-image")]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
