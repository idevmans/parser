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

class KurganinskiyeIzvestiyaRfParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://xn----7sbhblcmfacdnd4bb7bwitd4y.xn--p1ai/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve("/index.php/lenta-novostej-ofitsialnogo-sajta-gazety-kurganinskie-izvestiya", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filter('.newsfeed ol li');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.feed-link a')->text()));
            $uri = UriResolver::resolve($newsPreview->filter('.feed-link a')->attr('href'), $this->getSiteUrl());

            $previewList[] = new PreviewNewsDTO($uri, null, $title);
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

        $publishedAtString = $newsPageCrawler->filter('.published time')->attr('datetime');
        $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $publishedAtString);
        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAtUTC);

        $contentCrawler = $newsPageCrawler->filter('.article-full .article-content-main');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"content_rating")]');

        $image = null;

        $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
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
}
