<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use Exception;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * News parser from site http://glagolurfo.com/
 * @author jcshow
 */
class GlagolurfoParser implements ParserInterface
{
    public const USER_ID = 2;

    public const FEED_ID = 2;

    public const SITE_URL = 'http://glagolurfo.com';

    /** @var array */
    protected static $parsedEntities = ['a', 'img', 'blockquote'];

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        return self::getNewsData();
    }

    /**
     * Function get fixed news count data
     * 
     * @return array
     * @throws Exception
     */
    public static function getNewsData(): array
    {
        /** Вырубаем нотисы */
        error_reporting(E_ALL & ~E_NOTICE);

        /** Get RSS news list */
        $curl = Helper::getCurl();
        $newsList = $curl->get(static::SITE_URL . "/atom.xml");
        if (! $newsList) {
            throw new Exception('Can not get news data');
        }

        /** Parse news from RSS */
        $articleListCrawler = new Crawler($newsList);
        $news = $articleListCrawler->filterXPath('//entry');

        $result = [];
        foreach ($news as $item) {
            try {
                $post = self::getPostDetail($item);
            } catch (Exception $e) {
                continue;
            }

            $result[] = $post;
        }

        return $result;
    }

    /**
     * Function get post detail data
     * 
     * @param DOMElement $item
     * 
     * @return NewPost
     */
    public static function getPostDetail(DOMElement $item): NewsPost
    {
        $itemCrawler = new Crawler($item);

        /** Get item detail link */
        $link = $itemCrawler->filterXPath('//link')->getNode(0)->getAttribute('href');

        /** Get title */
        $title = $itemCrawler->filterXPath('//title')->getNode(0)->textContent;

        /** Get item datetime */
        $createdAt = new DateTime($itemCrawler->filterXPath('//updated')->getNode(0)->textContent);
        $createdAt->setTimezone(new DateTimeZone('UTC'));
        $createdAt = $createdAt->format('c');

        /** Get description */
        $description = self::cleanText($itemCrawler->filterXPath('//summary')->getNode(0)->textContent);

        /** @var NewsPost */
        $post = new NewsPost(static::class, $title, $description, $createdAt, $link, null);

        //Get post content
        $content = $itemCrawler->filterXPath('//content')->getNode(0);

        $contentCrawler = new Crawler($content->textContent);
        foreach ($contentCrawler->getNode(0)->childNodes as $item) {
            self::parseNode($post, $item);
        }

        return $post;
    }
    
    /**
     * Function parse single children of full text block and appends NewsPostItems founded
     * 
     * @param NewsPost $post
     * @param DOMNode $node
     * @param bool $skipText
     * 
     * @return void
     */
    public static function parseNode(NewsPost $post, DOMNode $node, bool $skipText = false): void
    {
        //Get non-empty quotes from nodes
        if (self::isQuoteType($node) && self::hasText($node)) {
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_QUOTE, $node->textContent));
            return;
        }

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
            if ($skipText === false && self::hasText($node)) {
                $textContent = self::cleanText($node->textContent);
                if (empty(trim($textContent)) === true) {
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
        if ($skipText === false && $needRecursive === false) {
            $textContent = self::cleanText($node->textContent);
            if (empty(trim($textContent)) === true) {
                return;
            }
            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $textContent));
        } else {
            foreach($node->childNodes as $child) {
                self::parseNode($post, $child, $skipText);
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