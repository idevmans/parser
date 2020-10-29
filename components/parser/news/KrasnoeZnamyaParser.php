<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use lanfix\parser\Parser;
use lanfix\parser\src\Element;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site http://krasnoe-znamya.info/
 * @author jcshow
 */
class KrasnoeZnamyaParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://krasnoe-znamya.info';

    /** @var array */
    protected static $parsedEntities = ['a', 'img'];

    /** @var int */
    protected static $parsedCount = 0;

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get news data
     * 
     * @param int $limit
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(int $limit = 20): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        $page = 1;
        $posts = [];
        while (self::$parsedCount < $limit) {
            /** Get page */
            $curl = Helper::getCurl();
            $page = $curl->get(static::SITE_URL . "/?module=news&action=list&page=$page");
            if (! $page) {
                continue;
            }

            $pageParser = new Parser($page, true);
            $pageBody = $pageParser->document->getBody();
            $newsList = $pageBody->findOne('#list-news');
            foreach ($newsList->find('.list-news-item') as $item) {
                $posts[] = self::getPostDetail($item);
            }

            $page++;
        }

        return $posts;
    }

    /**
     * Function get post detail data
     * 
     * @param Element $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(Element $item): NewsPost
    {
        //Get block with title and link
        $titleBlock = $item->findOne('.list-news-item-name a');

        /** Get item detail link */
        $link = UriResolver::resolve($titleBlock->getAttribute('href'), static::SITE_URL);

        /** Get title */
        $title = $titleBlock->asText();

        /** Get item datetime */
        $dateBlock = $item->findOne('.list-news-item-date');
        $dateTime = preg_replace('/\/\//', '', $dateBlock->asText());
        $createdAt = new DateTime($dateTime, new DateTimeZone('Europe/Moscow'));
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get description */
        $description = $item->findOne('.list-news-item-annonce')->asText();

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, htmlspecialchars_decode($description), $createdAt, $link, null);

        /** Detail page parser creation */
        $curl = Helper::getCurl();
        $curlResult = $curl->get($link);
        $crawler = new Crawler($curlResult);

        $detailPage = $crawler->filterXPath('//div[contains(@class, "publication-left")]')->getNode(0);

        self::removeNodes($crawler, '//p[contains(@class, "date")]');
        self::removeNodes($crawler, '//h2');

        // parse detail page for texts
        foreach ($detailPage->childNodes as $node) {
            self::parseNode($post, $node);
        }

        self::$parsedCount++;
        return $post;
    }

    /**
     * Function parse single children of full text block and appends NewsPostItems founded
     * 
     * @param NewsPost $post
     * @param DOMNode $node
     * 
     * @return void
     */
    public static function parseNode(NewsPost $post, DOMNode $node): void
    {
        //Get non-empty images from nodes
        if (self::isImageType($node)) {
            $imageLink = $node->getAttribute('src');

            if ($imageLink === '') {
                return;
            }

            $imageLink = UriResolver::resolve($imageLink, static::SITE_URL);

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, $node->getAttribute('alt'), $imageLink));
            return;
        }

        //Get non-empty links from nodes
        if (self::isLinkType($node) && self::hasText($node)) {
            $link = self::cleanUrl($node->getAttribute('href'));
            if ($link && $link !== '' && filter_var($link, FILTER_VALIDATE_URL)) {
                $linkText = self::hasText($node) ? $node->textContent : null;
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_LINK, $linkText, null, $link));
            }
            return;
        }

        //Get direct text nodes
        if (self::isText($node)) {
            if (self::hasText($node)) {
                $textContent = htmlspecialchars_decode($node->textContent);
                if (empty(preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $textContent)) === true) {
                    return;
                }
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
            }
            return;
        }

        //Check if some required to parse entities exists inside node
        $needRecursive = false;
        foreach (self::$parsedEntities as $entity) {
            if ($node->getElementsByTagName("$entity")->length > 0) {
                $needRecursive = true;
                break;
            }
        }

        //Get entire node text if we not need to parse any special entities, go recursive otherwise
        if ($needRecursive === false) {
            $textContent = htmlspecialchars_decode($node->textContent);
            if (empty(preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $textContent)) === true) {
                return;
            }
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child);
            }
        }
    }

    /**
     * Function cleans text from bad symbols
     * 
     * @param string $text
     * 
     * @return string|null
     */
    protected static function cleanText(string $text): ?string
    {
        $transformedText = preg_replace('/\r\n/', '', $text);
        $transformedText = preg_replace('/\<script.*\<\/script>/', '', $transformedText);
        $transformedText = html_entity_decode($transformedText);
        return preg_replace('/^\p{Z}+|\p{Z}+$/u', '', htmlspecialchars_decode($transformedText));
    }

    /**
     * Function clean dangerous urls
     * 
     * @param string $url
     * 
     * @return string
     */
    protected static function cleanUrl(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_ENCODED|FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasActualText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node text content not empty
     * 
     * @param DOMNode $node
     * 
     * @return bool
     */
    protected static function hasText(DOMNode $node): bool
    {
        return trim($node->textContent) !== '';
    }

    /**
     * Function check if node is <p></p>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isParagraphType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'p';
    }

    /**
     * Function check if node is quote
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isQuoteType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && in_array($node->tagName, ['blockquote']);
    }

    /**
     * Function check if node is <a></a>
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isLinkType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'a';
    }

    /**
     * Function check if node is image
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isImageType(DOMNode $node): bool
    {
        return isset($node->tagName) === true && $node->tagName === 'img';
    }

    /**
     * Function check if node is #text
     * 
     * @param DOMNode
     * 
     * @return bool
     */
    protected static function isText(DOMNode $node): bool
    {
        return $node->nodeName === '#text';
    }

    /**
     * Function remove useless specified nodes
     * 
     * @param Crawler $crawler
     * @param string $xpath
     * 
     * @return void
     */
    protected static function removeNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }
} 