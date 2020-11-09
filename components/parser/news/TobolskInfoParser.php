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

class TobolskInfoParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://tobolsk.info/';
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

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//default:item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//default:title')->text();
            $uri = $this->encodeUri($newsPreview->filterXPath('//default:link')->text());

            $publishedAtString = $newsPreview->filterXPath('//default:pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $image = null;
            $imageCrawler = $newsPreview->filterXPath('//default:enclosure');
            if ($this->crawlerHasNodes($imageCrawler)) {
                $image = $imageCrawler->attr('url') ?: null;
            }

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null, $image);
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

        $contentCrawler = $newsPageCrawler->filter('#yt_component .item-page');
        $this->removeDomNodes($contentCrawler, '//h2[contains(@class,"contentheading")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"item-headinfo")]');
        $this->removeDomNodes($contentCrawler, '//strong[contains(text(),"Понравилась статья?")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"shareinit")]/following-sibling::*');
        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"shareinit")]');

        if (!$previewNewsDTO->getImage()) {
            $image = null;
            $mainImageCrawler = $newsPageCrawler->filter('meta[property="og:image"]')->first();
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('content');
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

    protected function getDescriptionFromContentText(Crawler $crawler): ?string
    {
        $descriptionCrawler = $crawler->filterXPath('//p[contains(@class,"lead")]');

        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));

            if ($descriptionText) {
                $this->removeDomNodes($crawler, '//p[contains(@class,"lead")]');
                return $descriptionText;
            }
        }

        return null;
    }
}
