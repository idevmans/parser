<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class InfoshosRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'http://www.infoshos.ru/';

    protected static $posts = [];
    protected static $post;

    public static function run(): array
    {
        $xml = self::request(self::SITE_URL.'rss/rss_ru.xml');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler($xml);
        $feed->filter('item')->each(function ($node) {
            self::loadPost($node);
        });

        return static::$posts;
    }

    protected static function loadPost($node)
    {
        $url = $node->filter('link')->text();

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $dt   = new DateTime($node->filter('pubDate')->text());
        $post = new NewsPost(
            self::class,
            html_entity_decode($node->filter('title')->text()),
            '~',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            null,
        );

        $crawler = new Crawler($html, $url);

        $image = $crawler->filter('.center-column-wrap td.text .border-round div')->first();

        if ($image->count()
            && preg_match('/background: url\(([^\)]+)\)/', $image->attr('style'), $m)
        ) {
            $url = trim($m[1], '\'"');

            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                $url = self::SITE_URL.ltrim($url, '/');
            }

            $post->image = $url;
        }

        self::$posts[] = (new Parser())->fill(
            $post,
            $crawler->filter('.center-column-wrap td.text > *')
        );
    }
}
