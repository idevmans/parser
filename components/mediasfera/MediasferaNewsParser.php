<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */

namespace app\components\mediasfera;

use app\components\Helper;
use DateTime;
use DateTimeZone;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * @version 1.1.6
 *
 * @property NewsPostWrapper $post
 *
 * */

class MediasferaNewsParser
{
    public const DEBUG = false;

    const PAGE_TRY_COUNT = 2;
    const PAGE_TRY_INTERVAL = [0.5, 1]; //Random interval in seconds

    /**
     * @see https://www.php.net/manual/ru/datetimezone.construct.php
     *
     * */
    public const TIMEZONE = null;

    /**
     * @see https://www.php.net/manual/ru/datetime.format.php
     *
     * */
    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const IS_CURRENT_TIME = false;

    public const ATTR_IMAGE = 'src';
    public const ATTR_VIDEO_IFRAME = 'src';

    public const CHECK_CHARS =       " \t\n\r\0\x0B\xA0";
    public const CHECK_EMPTY_CHARS = " \t\n\r\0\x0B\xC2\xA0";

    public const CURL_OPTIONS = [
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
    ];

    // Skip elements. If element value is true, stop parsing article
    public const ARTICLE_BREAKPOINTS = [
        'name' => [
            'table' => false,
        ],
//        'text' => [],
//        'class' => [],
//        'id' => [],
//        'src' => [],
    ];


    protected static array $breakpoints = [];

    /**
     * @var NewsPostWrapper $post
     */


    public static function getBreakpoints() : array
    {
        if(!static::$breakpoints) {
            static::$breakpoints = array_replace_recursive(self::ARTICLE_BREAKPOINTS, static::ARTICLE_BREAKPOINTS);
        }

        return static::$breakpoints;
    }


    protected static function parse(Crawler $node, ?string $filter = null) : void
    {
        static $notice = true;

        if(static::DEBUG && $notice) {
            $notice = false;
            echo '<div style="background-color: red; color: white;text-align: center">=== !!!DEBUG MODE!!! ===</div>';
        }

        $node = static::filterNode($node, $filter);
        $node = static::clearNode($node);

        static::parseNode($node);
    }


    protected static function checkNode(Crawler $node) : bool
    {
        if(static::$post->stopParsing) {
            return false;
        }

        foreach (static::getBreakpoints() as $key => $array) {

            $values = [];

            switch ($key) {
                case 'name' :
                    $values[] = $node->nodeName();
                    break;
                case 'text' :
                    $values[] = $node->text();
                    break;
                case 'class' :
                    $values = explode(' ', $node->attr($key));
                    break;
                default :
                    $values[] = $node->attr($key);
            }

            $values = array_filter($values);

            if(!$values) {
                continue;
            }

            foreach ($values as $value) {
                if(array_key_exists($value, $array)) {

                    if($array[$value]) {
                        static::$post->stopParsing();
                    }

                    return false;
                }
            }
        }

        return true;
    }


    protected static function clearNode(Crawler $crawler, bool $recursive = true, $filter = null) : Crawler
    {
        $crawler = static::filterNode($crawler, $filter);

        $crawler->children()->each(function (Crawler $node) use (&$recursive) {

            $remove = false;

            $names = [
                'script',
                'noscript',
                'style',
            ];

            if(!static::checkNode($node)) {
                $remove = true;
            }

            if(static::$post->stopParsing) {
                $remove = true;
            }

            if(in_array($node->nodeName(), $names)) {
                $remove = true;
            }

            if($recursive && !static::$post->stopParsing && $node->children()->count()) {
                static::clearNode($node, $recursive);
            }

            if($remove) {
                $self = $node->getNode(0);
                $self->parentNode->removeChild($self);
            }
        });

        return $crawler;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        $nodeName = $node->nodeName();

        switch ($nodeName)
        {
            case 'br' :
            case 'hr' :
            case 'meta' :
            case 'link' :
                break;

            case 'html' :
            case 'body' :
            case 'center' :
            case 'menu' :
            case 'header' :
            case 'article' :
            case 'div' :
            case 'figcaption' :
            case 'p' :
            case 'b' :
            case 'span' :
            case 'strong' :
                static::parseSection($node);
                break;

            case 'h1' :
            case 'h2' :
            case 'h3' :
            case 'h4' :
            case 'h5' :
            case 'h6' :
                static::$post->itemHeader = [
                    $node->text(),
                    substr($nodeName, 1, 1)
                ];
                break;

            case 'img' :
                static::$post->itemImage = [
                    $node->attr('alt') ?? $node->attr('title') ?? null,
                    static::getNodeImage(static::ATTR_IMAGE, $node)
                ];
                break;

            case 'picture' :
                if($node->filter('img')->count()) {
                    static::$post->itemImage = [
                        $node->filter('img')->attr('alt') ?? $node->filter('img')->attr('title') ?? null,
                        static::getNodeImage(static::ATTR_IMAGE, $node->filter('img'))
                    ];
                }
                break;

            case 'figure' :
                if($node->filter('img')->count() == 1) {
                    static::$post->itemImage = [
                        $node->text() ?? $node->filter('img')->attr('alt') ?? $node->filter('img')->attr('title') ?? null,
                        static::getNodeImage(static::ATTR_IMAGE, $node->filter('img'))
                    ];
                }
                else {
                    static::parseSection($node);
                }
                break;

            case 'a' :
                if($node->filter('img')->count() == 1) {
                    static::$post->itemImage = [
                        $node->attr('alt') ?? $node->attr('title') ?? $node->text() ?? null,
                        static::getNodeImage(static::ATTR_IMAGE, $node->filter('img')),
                    ];
                }
                else {
                    $videoID = static::getNodeVideoId($node->attr('href'));

                    if($videoID) {
                        static::$post->itemVideo = $videoID;
                    }
                    else {
                        static::$post->itemLink = [
                            $node->text() ?? $node->attr('href'),
                            static::getNodeLink('href', $node)
                        ];
                    }
                }
                break;

            case 'iframe' :
                static::$post->itemVideo = static::getNodeVideoId($node->attr(static::ATTR_VIDEO_IFRAME));
                break;
            case 'video' :
                if($node->filter('source')->count()) {
                    static::$post->itemVideo = static::getNodeVideoId($node->filter('source')->first()->attr('src'));
                }
                else {
                    static::$post->itemVideo = static::getNodeVideoId($node->attr('src'));
                }
                break;

            case 'blockquote' :
            case 'q' :
                static::parseQuote($node);
                break;

            case 'ul' :
            case 'ol' :
                static::parseList($node);
                break;
            default :
                static::parseSection($node);
                if(static::DEBUG) {
                    echo '<div style="background-color: orange; color: white;text-align: center">
                            Unknown tag: "' . $nodeName . '" ' .
                            'Post: ' . static::$post->original .
                        '</div>';
                }
        }
    }


    protected static function parseSection(Crawler $node) : void
    {
        $html = $node->html();

        $allow_tags = [
            'div',
            'br',
            'a',
            'img',
            'q',
            'blockquote',
            'iframe',
        ];

        $tags = [
            'p',
            'blockquote',
            'b',
            'span',
            'strong',
            'i',
            'em',
        ];

        if (in_array($node->nodeName(), $tags)) {
            $html = strip_tags($html, $allow_tags);
        }

        $_html = '<body><div>' . $html . '</div></body>';

        $node = new Crawler($_html);

        $node->children('body > div > *')->reduce(function (Crawler $node) use (&$html, &$items) {

            $nodeHtml = $node->outerHtml();

            $chunks = explode($nodeHtml, $html, 2);

            static::$post->itemText = trim(strip_tags(array_shift($chunks)));

            static::parseNode($node);

            $html = array_shift($chunks);
        });

        static::$post->itemText = trim(strip_tags($html));
    }


    protected static function parseQuote(Crawler $node) : void
    {
        $result = [];

        $html = $node->html();

        $allow_tags = [
            'br',
            'em',
            'p',
        ];

        $html = strip_tags($html, $allow_tags);

        $_html = '<body><div>' . $html . '</div></body>';

        $node = new Crawler($_html);

        $node->children('body > div > *')->reduce(function (Crawler $node) use (&$html, &$result) {

            $nodeHtml = $node->outerHtml();

            $chunks = explode($nodeHtml, $html, 2);

            $result[] = trim(strip_tags(array_shift($chunks)));
            $result[] = trim(strip_tags($node->text()));

            $html = array_shift($chunks);
        });

        $result[] = trim(strip_tags($html));

        $result = array_filter($result);

        static::$post->itemQuote = implode(PHP_EOL, $result);
    }


    protected static function parseList(Crawler $node) : void
    {
        if(!$node->text()) {
            static::parseSection($node);
            return;
        }
        else if($node->filter('img')->count()) {
            static::parseSection($node);
            return;
        }
        else if($node->filter('li')->count() == $node->filter('li a')->count() && $node->filter('li')->text() == $node->filter('li a')->text()) {
            static::parseSection($node);
            return;
        }

        $result = [];

        $node->filter('li')->each(function ($node) use (&$result) {
            $result[] = $node->text();
        });

        $result = array_filter($result);

        if (count($result)) {
            static::$post->itemText = '- ' . implode(PHP_EOL . '- ', $result);
        }
    }


    protected static function filterNode(Crawler $node, ?string $filter) : Crawler
    {
        if(!$filter) {
            return $node;
        }

        if (strpos($filter, "//") === 0) {
            return $node->filterXPath($filter);
        } else {
            return $node->filter($filter);
        }
    }


    protected static function getNodeData(string $data, Crawler $node, ?string $filter = null) : ?string
    {
        $node = static::filterNode($node, $filter);

        if($node->count()) {
            switch ($data)
            {
                case 'text' :
                    return $node->text();
                default :
                    return $node->attr($data);
            }
        }

        return '';
    }


    protected static function getNodeDate(string $data, Crawler $node, ?string $filter = null) : ?string
    {
        $text = static::getNodeData($data, $node, $filter);

        if(!$text) {
            return '';
        }

        $date = static::fixDate($text);

        if($date) {
            return $date;
        } else {
            return $text;
        }
    }


    public static function fixDate(string $date) : ?string
    {
        if(static::TIMEZONE !== null) {
            $dateTime = DateTime::createFromFormat(static::DATEFORMAT, $date, new DateTimeZone(static::TIMEZONE));
        }
        else {
            $dateTime = DateTime::createFromFormat(static::DATEFORMAT, $date);
        }

        if ($dateTime) {
            if(static::IS_CURRENT_TIME) {
                return $dateTime->format('Y-m-d') . date(' H:i:s');
            }
            else {
                $dateTime->setTimezone(new DateTimeZone('UTC'));
                return $dateTime->format('Y-m-d H:i:s');
            }
        }

        throw new \Exception('Wrong date format! Format: "' . static::DATEFORMAT . '" Date string: "' . $date . '"', E_USER_ERROR);
    }


    public static function getNodeLink(string $data, Crawler $node, ?string $filter = null) : ?string
    {
        $href = static::getNodeData($data, $node, $filter);

        return static::resolveUrl($href);
    }


    public static function getNodeImage(string $data, Crawler $node, ?string $filter = null) : ?string
    {
        $node = static::filterNode($node, $filter);

        switch ($data)
        {
            case 'style' :
                $style = $node->attr('style');

                if(!$style) {
                    return '';
                }

                $pattern = '/(background-image|background)\s*:\s*url\((?\'img\'[^)]*)\)/';

                preg_match_all($pattern, $style, $matches);

                if(count($matches['img'])) {
                    $src = trim(end($matches['img']), ' \'"');
                } else {
                    $src = '';
                }
                break;
            default :
                $src = static::getNodeData($data, $node) ?? $node->attr('src') ?? $node->attr('data-src');
        }

        return static::resolveUrl($src);
    }


    public static function getImageFromCSS(string $css, string $selector) : ?string
    {
        if(!$css || !$selector) {
            return '';
        }

        $pattern = '/'.str_replace('.', '\.', $selector).'\s*\{\s*(background-image|background)\s*:\s*url\((?\'img\'[^)]*)\)[^}]*/m';

        preg_match_all($pattern, $css, $matches);

        if(count($matches['img'])) {
            $src = trim(end($matches['img']), ' \'"');
            return static::resolveUrl($src);
        }

        return '';
    }


    public static function getNodeVideoId(?string $src) : ?string
    {
        if(!$src) {
            return '';
        }

        $pattern = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i';

        if (preg_match($pattern, $src, $match)) {
            return $match[1];
        }

        return '';
    }


    protected static function resolveUrl(?string $url) : string
    {
        if(!$url) {
            return '';
        }

        $url = UriResolver::resolve($url, static::SITE_URL);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {

            $parts = parse_url($url);

            if(!$parts) {
                return '';
            }

            if(isset($parts['host'])) {
                if(strpos($parts['host'], '%') !== false) {
                    $parts['host'] = urldecode($parts['host']);
                }

                $parts['host'] = idn_to_ascii($parts['host']);
            }

            $url = static::buildUrl($parts);
        }

        return $url;
    }


    public static function buildUrl(array $parts) : string
    {
        $scheme   = isset($parts['scheme']) ? ($parts['scheme'] . '://') : '';

        $host     = ($parts['host'] ?? '');
        $port     = isset($parts['port']) ? (':' . $parts['port']) : '';

        $user     = ($parts['user'] ?? '');

        $pass     = isset($parts['pass']) ? (':' . $parts['pass'])  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';

        $path     = ($parts['path'] ?? '');
        $query    = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

        return implode('', [$scheme, $user, $pass, $host, $port, $path, $query, $fragment]);
    }


    public static function getPage(string $url, ?int $try_count = null) : ?string
    {
        if($try_count === null) {
            $try_count = static::PAGE_TRY_COUNT;
        }

        $curl = Helper::getCurl();

        $curl->setOptions(static::CURL_OPTIONS);

        $content = $curl->get($url);

        $code = $curl->responseCode ?? null;

        if (empty($content) || $code < 200 || $code >= 400) {
            if($try_count > 0) {
                usleep(rand(static::PAGE_TRY_INTERVAL[0] * 1000000, static::PAGE_TRY_INTERVAL[1] * 1000000));
                return static::getPage($url, ($try_count - 1));
            }
            else {
                throw new \Exception('Can\'t open url ' . $curl->getUrl());
            }
        }

        return $content;
    }
}
