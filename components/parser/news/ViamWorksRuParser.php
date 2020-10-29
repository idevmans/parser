<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DOMNode;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class ViamWorksRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(5, 50);
    }

    protected function getSiteUrl(): string
    {
        return 'http://viam-works.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve("/ru/last-number", $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"art-article")]//div[contains(@class,"j_read")]/a[1]');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $uri = $this->encodeUri(UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl()));
            $previewList[] = new PreviewNewsDTO($uri);
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

        $contentCrawler = $newsPageCrawler->filter('.art-postcontent .art-article');
        $titleXPath = '//div[contains(@class,"j_artname")][1]';
        $this->removeDomNodes($contentCrawler, "{$titleXPath}/preceding-sibling::*");
        $titleCrawler = $contentCrawler->filterXPath($titleXPath);
        if ($this->crawlerHasNodes($titleCrawler)) {
            $title = Text::trim($this->normalizeSpaces($titleCrawler->text()));
            $previewNewsDTO->setTitle($title);
            $this->removeDomNodes($contentCrawler, $titleXPath);
        }

        $this->removeDomNodes($contentCrawler, '//div[contains(@id,"show_comments")]');

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

    protected function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = [
            'strong' => true,
            'b' => true,
            'span' => true,
            's' => true,
            'i' => true,
            'a' => true,
            'em' => true,
            'sup' => true,
        ];

        return isset($formattingTags[$node->nodeName]);
    }
}
