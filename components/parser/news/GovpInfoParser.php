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

class GovpInfoParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://govp.info/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve("/", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filter('.content_center_news > .content_center_first_news');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = Text::trim($this->normalizeSpaces($newsPreview->filterXPath('//a[contains(@class,"first_news_header")][last()]')->text()));
            $uri = UriResolver::resolve($newsPreview->filterXPath('//a[contains(@class,"first_news_header")][last()]')->attr('href'), $this->getSiteUrl());
            $uri = $this->encodeUri($uri);

            $publishedAt = Text::trim($newsPreview->filterXPath('//div[contains(@class,"first_news_share")][last()]')->text());
            $publishedAt = DateTimeImmutable::createFromFormat('d/m/Y', $publishedAt, new DateTimeZone('Asia/Yekaterinburg'));
            $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('#main_news_article');

        $image = null;
        $mainImageCrawler = $contentCrawler->filterXPath('//img[contains(@class,"news_mgu")]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[contains(@class,"news_mgu")]');
        }
        if ($image !== null) {
            $previewNewsDTO->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $contentCrawler->filter('.news_mgu_text');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
