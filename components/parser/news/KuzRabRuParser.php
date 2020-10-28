<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\nai4rus\DOMNodeRecursiveIterator;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMElement;
use DOMNode;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class KuzRabRuParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;
    public const SITE_URL = 'https://kuzrab.ru';

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

        $uriPreviewPage = UriResolver::resolve('/feed', $this->getSiteUri());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            // if (str_contains($previewNewsContent, 'CDATA')) {
            //     $previewNewsContent = $this->removeCData($previewNewsContent);
            // }
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $uri = $newsPreview->filterXPath('//link')->text();
            $title = $newsPreview->filterXPath('//title')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate | //pubdate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = $newsPreview->filterXPath('//description')->text();

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }


    private function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $title = $previewNewsItem->getTitle();
        $publishedAt = $previewNewsItem->getDateTime();
        $description = $previewNewsItem->getPreview();
        $image = null;

        $description = 'temp';

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $description = html_entity_decode($description);

        $this->removeDomNodes($newsPageCrawler, '//p[following-sibling::div[contains(concat(" ",normalize-space(@class)," ")," in-detail ")]][last()]/following-sibling::*');
        
        
        $newsPostCrawler = $newsPageCrawler->filterXPath('//*[@class="main"]');
        

        $this->removeDomNodes($newsPostCrawler, '//*[contains(text(), "Фото:")]
        | //h1
        | //div[contains(concat(" ",normalize-space(@class)," ")," page-with-block-title ")]');
        $this->removeDomNodes($newsPostCrawler, '//a[starts-with(@href, "javascript")]');
        $this->removeDomNodes($newsPostCrawler, '//script | //video | //style | //form');
        $this->removeDomNodes($newsPostCrawler, '//table');

        if (trim($description) === '') {
            $description = $title;
        }

        $newsPost = new NewsPost(self::class, $title, $description, $publishedAt->format('Y-m-d H:i:s'), $uri, $image);

        $contentCrawler = $newsPostCrawler;


        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $k => $node) {
                $newsPostItem = $this->parseDOMNode($node, $previewNewsItem);
                if (!$newsPostItem) {
                    continue;
                }

                if ($newsPostItem->type === NewsPostItem::TYPE_IMAGE && $newsPost->image === null) {
                    $newsPost->image = $newsPostItem->image;
                    continue;
                }

                if ($newsPostItem->type === NewsPostItem::TYPE_TEXT) {
                    if ($newsPost->description === 'temp') {
                        $newsPost->description = $newsPostItem->text;
                        continue;
                    }

                    $isTextContainsDescription = str_contains($newsPostItem->text, $description);
                    $isDescriptionContainsText = str_contains($description, $newsPostItem->text);

                    if ($isDescriptionContainsText) {
                        continue;
                    }
                    
                    if ($isTextContainsDescription) {
                        $replacedText = trim(str_replace($description, '', $newsPostItem->text));

                        // try {
                        //     $replacedText = trim(html_entity_decode($replacedText), " \t\n\r\0\x0B\xC2\xA0");
                        // } catch (\Throwable $th) {
                        // }

                        if($replacedText === '') {
                            continue;
                        } else {
                            $newsPostItem->text = $replacedText;
                        }
                    }
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
                return null;
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

        if ((!$this->isQuoteType($node) || !$this->hasText($node)) && !preg_match('/(^[«].*[»], - .*\.)/m', $node->textContent)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent);

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

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_HEADER, $node->textContent, null, null, $headingLevel);
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

        try {
            $link = UriResolver::resolve($node->getAttribute('href'), $previewNewsItem->getUri());
            $link = Helper::encodeUrl($link);
        } catch (\Throwable $th) {
            return null;
        }

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
                return $parentNode->nodeName === 'iframe';
            }, 3);
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

        if ($imageLink === '' || preg_match('/\.webp/m', $imageLink)) {
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

        $newsPostItem = new NewsPostItem(NewsPostItem::TYPE_TEXT, $node->textContent);

        $this->nodeStorage->attach($attachNode, $newsPostItem);

        return $newsPostItem;
    }


    private function removeParentsFromStorage(
        DOMNode $node,
        int $maxLevel = 5,
        array $exceptNewsPostItemTypes = null
    ): void {
        if ($maxLevel <= 0 || !$node->parentNode) {
            return;
        }

        if ($exceptNewsPostItemTypes === null) {
            $exceptNewsPostItemTypes = [NewsPostItem::TYPE_HEADER, NewsPostItem::TYPE_QUOTE, NewsPostItem::TYPE_LINK];
        }

        if ($this->nodeStorage->contains($node)) {
            /** @var NewsPostItem $newsPostItem */
            $newsPostItem = $this->nodeStorage->offsetGet($node);

            if (in_array($newsPostItem->type, $exceptNewsPostItemTypes, true)) {
                return;
            }

            $this->nodeStorage->detach($node);
            return;
        }

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

    private function parseHumanDateTime(string $dateTime, DateTimeZone $timeZone): DateTimeInterface
    {
        $formattedDateTime = mb_strtolower(trim($dateTime));
        $now = new DateTimeImmutable('now', $timeZone);

        if ($formattedDateTime === 'только что') {
            return $now;
        }

        if (str_contains($formattedDateTime, 'час') && str_contains($formattedDateTime, 'назад')) {
            $numericTime = preg_replace('/\bчас\b/u', '1', $formattedDateTime);
            $hours = preg_replace('/[^0-9]/u', '', $numericTime);
            return $now->sub(new DateInterval("PT{$hours}H"));
        }

        if (str_contains($formattedDateTime, 'вчера')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone)->sub(new DateInterval("P1D"));
        }

        if (str_contains($formattedDateTime, 'сегодня')) {
            $time = preg_replace('/[^0-9:]/u', '', $formattedDateTime);
            return DateTimeImmutable::createFromFormat('H:i', $time, $timeZone);
        }

        throw new RuntimeException("Не удалось распознать дату: {$dateTime}");
    }


    private function getJsonContent(string $uri): array
    {
        $result = $this->curl->get($uri, false);
        $this->checkResponseCode($this->curl);

        return $result;
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
            'blockquote' => true
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

    private function crawlerHasNodes(Crawler $crawler): bool
    {
        return $crawler->count() >= 1;
    }

    private function translateDateToEng(string $date)
    {
        $date = mb_strtolower($date);

        $ruMonth = [
            'январь',
            'февраль',
            'март',
            'апрель',
            'май',
            'июнь',
            'июль',
            'август',
            'сентябрь',
            'октябрь',
            'ноябрь',
            'декабрь'
        ];
        $ruMonthShort = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        $enMonth = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $date = str_replace($ruMonth, $enMonth, $date);
        $date = str_replace($ruMonthShort, $enMonth, $date);

        return $date;
    }

    private function getYoutubeVideoId(string $link): ?string
    {
        $youtubeRegex = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu';
        preg_match($youtubeRegex, $link, $matches);

        return $matches[5] ?? null;
    }

    private function getSiteUri(): string
    {
        return self::SITE_URL;
    }

    private function removeCData(string $string): string
    {
        $simpleXml = simplexml_load_string($string, null, LIBXML_NOCDATA);
        return $simpleXml->asXML();
    }
}