<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class JournalRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://7x7-journal.ru/';

    protected static $posts = [];

    public static function run(): array
    {
        $html = self::request(self::SITE_URL.'anews');

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, self::SITE_URL.'anews');

        $crawler->filter('.material-teaser-body a')->each(function ($node) {
            self::loadPost($node->link()->getUri());
        });

        return static::$posts;
    }

    protected static function loadPost($url)
    {
        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);
        $image   = $crawler->filter('.article__header-row-illustration')->first();

        $dt = new DateTime($crawler->filter('meta[itemprop="datePublished"]')->attr('content'));

        $post = new NewsPost(
            self::class,
            html_entity_decode($crawler->filterXpath('//meta[@property="og:title"]/@content')->text()),
            '~',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? Url::encode($image->image()->getUri()) : null
        );

        self::$posts[] = (new Parser())->fill(
            $post,
            $crawler->filterXpath('//div[contains(@class, "article__body text")]/node()')
        );
    }
}
