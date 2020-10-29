<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\NewsPostItemDTO;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class AnaparegionRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://anaparegion.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/rss', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $description = Text::trim($this->normalizeSpaces(html_entity_decode($newsPreview->filterXPath('//description')->text())));

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $description);
        });

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        if (str_contains($previewNewsDTO->getTitle(), '...')) {
            $previewNewsDTO->setTitle($newsPageCrawler->filter('meta[property="og:title"]')->attr('content'));
        }

        $contentCrawler = $newsPageCrawler->filter('#content .store');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"metablock")]');

        $descriptionCrawler = $contentCrawler->filterXPath('//div[@class="lead"][1]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $description = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            $this->removeDomNodes($contentCrawler, '//div[@class="lead"][1]');
        }

        $image = $this->getMainImage($contentCrawler);
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        $contentCrawler = $contentCrawler->filter('.text');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        $newsPost = $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);

        $galleryCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"gzip_gallery")]//a[contains(@class,"highslide")]');
        if ($this->crawlerHasNodes($galleryCrawler)) {
            $galleryCrawler->each(function (Crawler $crawler) use ($newsPost) {
                $href = $crawler->attr('href');
                if (!$href) {
                    return;
                }

                $imageLink = UriResolver::resolve($crawler->attr('href'), $this->getSiteUrl());
                $text = Text::trim($crawler->attr('title'));

                $newsPost->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $text, $imageLink));
            });
        }

        return $newsPost;
    }

    private function getMainImage(Crawler $crawler): ?string
    {
        $image = null;
        $mainImageCrawler = $crawler->filterXPath('//img[1]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $crawler->filterXPath('//img[1]')->attr('src');
            $this->removeDomNodes($crawler, '//img[1]');
        }

        return $image;
    }
}
