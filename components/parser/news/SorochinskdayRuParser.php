<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class SorochinskdayRuParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;
    private const SITE_URL = 'https://sorochinskday.ru';

    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;
    private SplObjectStorage $nodeStorage;
    private Curl $curl;

    public function __construct(int $microsecondsDelay = 1000000, int $pageCountBetweenDelay = 3)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
        $this->nodeStorage = new SplObjectStorage();

        $this->curl = Helper::getCurl();
    }

    public static function run(): array
    {
        $parser = new self(200000, 10);

        return $parser->parse(10, 100);
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = $this->getPreviewList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $previewNewsItem */
        foreach ($previewList as $key => $previewNewsItem) {
            $newsList[] = $this->parseNewsPage($previewNewsItem);
            $this->nodeStorage = new SplObjectStorage();

            if ($key % $this->pageCountBetweenDelay === 0) {
                usleep($this->microsecondsDelay);
            }
        }

        $this->curl->reset();
        return $newsList;
    }

    private function getPreviewList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $pageNumber = 0;

        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve('/news?page_start=' . $pageNumber * 20, $this->getSiteUri());
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

            $previewNewsCrawler = $previewNewsCrawler->filterXPath('//div[contains(@class,"lColumn")]/div[contains(@class,"news-block")]/div[contains(@class,"nb-type1")]');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = $newsPreview->filterXPath('//h3/a')->text();
                $uri = UriResolver::resolve($newsPreview->filterXPath('//h3/a')->attr('href'), $this->getSiteUri());

                $preview = $newsPreview->filterXPath('//div[contains(@class,"lead")]');
                $preview = $this->crawlerHasNodes($preview) ? Text::trim(strip_tags($this->normalizeSpaces($preview->text()))) : null;

                $previewList[] = new PreviewNewsDTO($uri, null, $title, $preview);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $description = $previewNewsItem->getPreview();
        $title = $previewNewsItem->getTitle();
        $image = null;

        $newsPage = $this->getPageContent($uri);
        $newsPage = str_replace('src="assets/', 'src="/assets/', $newsPage);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"lColumn")]/div[contains(@class,"news-block")]/div[contains(@class,"st-page")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"bsys_place")] | //span[contains(@class,"B_crumbBox")]');

        $publishedAt = $this->getPublishedAtFromContent($contentCrawler);

        $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');

        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//img[1]');
        }

        if ($image !== null) {
            $image = UriResolver::resolve($image, $uri);
            $image = Helper::encodeUrl($image);
        }

        $contentCrawler = $contentCrawler->filterXPath('//div[contains(@class,"content")]');

        if (!$description) {
            foreach (['p', 'div'] as $tag) {
                $i = 1;
                while ($i < 10) {
                    $descriptionCrawler = $contentCrawler->filterXPath("//{$tag}[{$i}]");
                    if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text()) {
                        $description = $descriptionCrawler->text();
                        $this->removeDomNodes($contentCrawler, "//{$tag}[{$i}]");
                        break 2;
                    }
                    $i++;
                }
            }
        }

        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form | //table');

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, $image);

        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $k => $node) {
                $newsPostItem = $this->parseDOMNode($node, $previewNewsItem);
                if (!$newsPostItem) {
                    continue;
                }

                if ($newsPostItem->type === NewsPostItem::TYPE_IMAGE && $newsPost->image === null) {
                    $newsPost->image = $newsPostItem->image;
                }

                $newsPost->addItem($newsPostItem);
            }
        }

        return $newsPost;
    }

    private function parseDOMNode(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        try {
            $newsPostItem = $this->searchQuoteNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchHeadingNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchLinkNewsItem($node, $previewNewsItem);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchYoutubeVideoNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchImageNewsItem($node, $previewNewsItem);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchTextNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            if ($node->nodeName === 'br') {
                $this->removeParentsFromStorage($node->parentNode);
            }
        } catch (RuntimeException $exception) {
            return null;
        }
        return null;
    }

    private function searchQuoteNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isQuoteType($parentNode);
            });

            $node = $parentNode ?: $node;
        }

        if (!$this->isQuoteType($node) || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_QUOTE, $this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchHeadingNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->getHeadingLevel($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        $headingLevel = $this->getHeadingLevel($node);

        if (!$headingLevel || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_HEADER, $this->normalizeSpaces($node->textContent), null, null, $headingLevel);
        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {

        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return $this->isLink($parentNode);
            });
            $node = $parentNode ?: $node;
        }

        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $previewNewsItem->getUri());
        $link = Helper::encodeUrl($link);

        if (!$link || $link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = $this->hasText($node) ? $node->textContent : null;
        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    private function searchYoutubeVideoNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                return !$parentNode->nodeName === 'iframe';
            });
            $node = $parentNode ?: $node;
        }

        if (!$node instanceof DOMElement || $node->nodeName !== 'iframe') {
            return null;
        }

        $youtubeVideoId = $this->getYoutubeVideoId($node->getAttribute('src'));
        if (!$youtubeVideoId) {
            return null;
        }

        return new NewsPostItem(NewsPostItem::TYPE_VIDEO, null, null, null, null, $youtubeVideoId);
    }

    private function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $previewNewsItem): ?NewsPostItem
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        $imageLink = $node->getAttribute('src');

        if ($isPicture) {
            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        if ($imageLink === '') {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $previewNewsItem->getUri());
        $imageLink = Helper::encodeUrl($imageLink);

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;


        return new NewsPostItem(NewsPostItem::TYPE_IMAGE, $alt, $imageLink);
    }

    private function searchTextNewsItem(DOMNode $node): ?NewsPostItem
    {
        if ($node->nodeName === '#comment' || !$this->hasText($node)) {
            return null;
        }

        $ignoringTags = [
            'strong' => true,
            'b' => true,
            'span' => true,
            's' => true,
            'i' => true,
            'a' => true,
        ];

        $attachNode = $node;
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) use ($ignoringTags) {
                return isset($ignoringTags[$parentNode->nodeName]);
            }, 3);

            $attachNode = $parentNode ?: $node->parentNode;
        }

        if (isset($ignoringTags[$attachNode->nodeName])) {
            $attachNode = $attachNode->parentNode;
        }

        if ($this->nodeStorage->contains($attachNode)) {
            /** @var NewsPostItem $parentNewsPostItem */
            $parentNewsPostItem = $this->nodeStorage->offsetGet($attachNode);
            $parentNewsPostItem->text .= $node->textContent;

            throw new RuntimeException('Контент добавлен к существующему объекту NewsPostItem');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_TEXT, $this->normalizeSpaces($node->textContent));

        $this->nodeStorage->attach($attachNode, $newsPostItem);

        return $newsPostItem;
    }

    private function removeParentsFromStorage(DOMNode $node, int $maxLevel = 5): void
    {
        if ($maxLevel <= 0 || !$node->parentNode) {
            return;
        }

        $this->nodeStorage->detach($node);

        $maxLevel--;

        $this->removeParentsFromStorage($node->parentNode, $maxLevel);
    }

    private function getRecursivelyParentNode(DOMNode $node, callable $callback, int $maxLevel = 5): ?DOMNode
    {
        if ($callback($node)) {
            return $node;
        }

        if ($maxLevel <= 0 || !$node->parentNode) {
            return null;
        }

        $maxLevel--;

        return $this->getRecursivelyParentNode($node->parentNode, $callback, $maxLevel);
    }

    private function getPageContent(string $uri): string
    {
        $result = $this->curl->get($uri);
        $this->checkResponseCode($this->curl);

        return $result;
    }

    private function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }

    private function isPictureType(DOMNode $node): bool
    {
        return $node->nodeName === 'source' && $node->parentNode->nodeName === 'picture';
    }

    private function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img';
    }

    private function isLink(DOMNode $node): bool
    {
        if ($node instanceof DOMElement && $node->getAttribute('href') === '') {
            return false;
        }

        return $node->nodeName === 'a';
    }

    private function hasText(DOMNode $node): bool
    {
        return trim($node->textContent, "⠀ \t\n\r\0\x0B\xC2\xA0") !== '';
    }

    private function isQuoteType(DOMNode $node): bool
    {
        $quoteTags = [
            'q' => true,
            'blockquote' => true,
        ];

        return $quoteTags[$node->nodeName] ?? false;
    }

    private function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }

    private function removeDomNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }

    private function getSiteUri(): string
    {
        return Helper::encodeUrl(self::SITE_URL);
    }

    private function crawlerHasNodes(Crawler $crawler): bool
    {
        return $crawler->count() >= 1;
    }

    private function getYoutubeVideoId(string $link): ?string
    {
        $youtubeRegex = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu';
        preg_match($youtubeRegex, $link, $matches);

        return $matches[5] ?? null;
    }

    private function normalizeSpaces(string $string): string
    {
        return preg_replace('/\s+/u', ' ', $string);
    }

    private function getPublishedAtFromContent(Crawler $contentCrawler): DateTimeImmutable
    {
        $publishedAtCrawler = $contentCrawler->filterXPath('//span[contains(@class,"time")]');
        $this->removeDomNodes($publishedAtCrawler, '//span | //div');

        $months = [
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            12 => 'декабря',
        ];

        $publishedAt = str_replace($months, array_keys($months), $publishedAtCrawler->text());

        $publishedAt = DateTimeImmutable::createFromFormat('d m Y, H:i', $publishedAt, new DateTimeZone('Asia/Yekaterinburg'));
        $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));

        return $publishedAt;
    }
}
