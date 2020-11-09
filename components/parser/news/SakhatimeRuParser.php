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

class SakhatimeRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://sakhatime.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve('/', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filter('.list-02 .list-02-item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $title = Text::trim($this->normalizeSpaces($newsPreview->filter('.list-02-item-name a')->text()));
            $uri = UriResolver::resolve($newsPreview->filter('.list-02-item-name a')->attr('href'), $this->getSiteUrl());

            $publishedAt = Text::trim($newsPreview->filter('.list-02-item-date-time')->text());
            $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAt, new DateTimeZone('Asia/Yakutsk'));
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

        $contentCrawler = $newsPageCrawler->filter('.main .main-block');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"crp_related")]');

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//div[contains(@class,"main-block-img")]//img[1]/parent::a[@data-fancybox]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('href');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"main-block-img")]//img[1]/parent::a[@data-fancybox]');
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        $description = $this->getDescriptionFromContentText($contentCrawler);
        $contentCrawler = $contentCrawler->filter('.main-block-detail-text');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getDescriptionFromContentText(Crawler $crawler): ?string
    {
        $descriptionCrawler = $crawler->filterXPath('//div[contains(@class,"main-block-preview-text")]');

        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));

            if ($descriptionText) {
                $this->removeDomNodes($crawler, '//div[contains(@class,"main-block-preview-text")]');
                return $descriptionText;
            }
        }

        return null;
    }
}
