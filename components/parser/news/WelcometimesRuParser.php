<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\helper\metallizzer\Text;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class WelcometimesRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://welcometimes.ru';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'/rss.xml';
        $html = self::request($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $page = new Crawler($html, $url);

        $page->filter('item')->each(function ($item) {
            self::loadPost($item);
        });

        return static::$posts;
    }

    protected static function loadPost($item)
    {
        $url = Url::encode($item->filter('link')->text());

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $page = new Crawler(Text::decode($html), $url);

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($item->filter('pubDate')->first()->text(), $tz);

        $image = $page->filter('.content-head .img-full picture img');

        $post = new NewsPost(
            self::class,
            html_entity_decode($item->filter('title')->first()->text()),
            $page->filterXpath('//div[@class = "content-body "]/div[2]')->text(),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? Url::encode($image->image()->getUri()) : null
        );

        self::$posts[] = (new Parser())->fill(
            $post,
            $page->filterXpath('//div[@itemprop = "articleBody"]/node()')
        );
    }

    protected static function parseDate($string)
    {
        $re = '/((?<today>Сегодня)|(?<day>\d{1,2})\.(?<month>\d{1,2})\.(?<year>\d{4})) в (?<hours>\d{1,2}):(?<minutes>\d{1,2})/';

        if (!preg_match($re, Text::trim($string), $m)) {
            throw new Exception('Не удалось разобрать дату');
        }

        if ($m['today']) {
            $tz = new DateTimeZone('Europe/Moscow');
            $dt = new DateTime('now', $tz);

            list($m['year'], $m['month'], $m['day']) = explode('-', $dt->format('Y-m-d'));
        }

        return sprintf('%d-%02d-%02d %02d:%02d:00',
            $m['year'], $m['month'], $m['day'], $m['hours'], $m['minutes']
        );
    }
}
