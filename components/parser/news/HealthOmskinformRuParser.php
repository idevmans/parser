<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class HealthOmskinformRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://health.omskinform.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 1;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/all/{$pageNumber}", $this->getSiteUrl());
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

            $previewNewsCrawler = $previewNewsCrawler->filter('#news1_div > .n_news');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('a.n_cap_lnk')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('a.n_cap_lnk')->attr('href'), $this->getSiteUrl());

                $preview = $newsPreview->filterXPath('//div[contains(@class,"item-details")]//div[contains(@class,"td-excerpt")]');
                $preview = $this->crawlerHasNodes($preview) ? Text::trim(strip_tags($this->normalizeSpaces($preview->text()))) : null;

                $publishedAtString = Text::trim($newsPreview->filter('.n_text .n_date')->text());
                $publishedAt = DateTimeImmutable::createFromFormat('d.m.y H:i', $publishedAtString, new DateTimeZone('Asia/Omsk'));
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

                $previewList[] = new PreviewNewsDTO($uri, $publishedAt, $title, $preview);
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

        $contentCrawler = $newsPageCrawler->filter('article .n_text_lnk');

        if (!$previewNewsDTO->getImage()) {
            $image = null;
            $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($contentCrawler, '//img[1]');
            }

            if ($image !== null && $image !== '') {
                $image = UriResolver::resolve($image, $this->getSiteUrl());
                $previewNewsDTO->setImage($this->encodeUri($image));
            }
        }

        $descriptionCrawler = $contentCrawler->filterXPath('//p[1]/strong');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//p[1]/strong');
            }
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = [
            'strong' => true,
            'b' => true,
            's' => true,
            'i' => true,
            'a' => true,
            'em' => true
        ];

        return isset($formattingTags[$node->nodeName]);
    }

}
