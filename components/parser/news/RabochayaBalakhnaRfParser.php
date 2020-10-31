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

class RabochayaBalakhnaRfParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'http://xn----7sbabaacc5gvaev8eva5j.xn--p1ai/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 0;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("?start_from={$pageNumber}", $this->getSiteUrl());
            $pageNumber += 25;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//h1/following-sibling::noindex/table');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filterXPath('//tr[2]//a[1]')->text()));
                $uri = UriResolver::resolve($newsPreview->filterXPath('//tr[2]//a[1]')->attr('href'), $this->getSiteUrl());

                $publishedAtText = $newsPreview->filterXPath('//tr[1]/td/text()')->text();
                $publishedAtText = Text::trim($this->normalizeSpaces(mb_substr($publishedAtText, 0, mb_strpos($publishedAtText, '|'))));
                $publishedAt = DateTimeImmutable::createFromFormat('H:i d.m.Y', $publishedAtText, new DateTimeZone('Europe/Moscow'));
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

        $contentCrawler = $newsPageCrawler->filterXPath('//h1/following-sibling::noindex/table//tr[2]');
        $this->removeDomNodes($contentCrawler, '//h1[1]');
        $this->removeDomNodes($contentCrawler, '//noindex');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
