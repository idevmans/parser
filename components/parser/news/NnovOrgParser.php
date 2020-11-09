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

class NnovOrgParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'http://www.nnov.org/';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'news/nn/';
        $html = self::request($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, $url);

        $crawler->filter('.contentCont .newsItem')->each(function ($node) {
            self::loadPost($node);
        });

        return static::$posts;
    }

    protected static function loadPost($node)
    {
        $url = Url::encode($node->filter('h3 a')->first()->link()->getUri());

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime(self::parseDate($crawler->filter('.fulltime')->text()), $tz);

        $post = new NewsPost(
            self::class,
            $node->filter('h3 a')->first()->text(),
            '~',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            null
        );

        self::$posts[] = (new Parser())->fill(
            $post,
            $crawler->filterXpath('//div[@class="txt"]/node()')
        );
    }

    protected static function parseDate($string)
    {
        $re = '/((?<today>Сегодня)|(?<yesterday>Вчера)|(?<third>Позавчера)|(?<day>\d{1,2})\.(?<month>\d{1,2})\.(?<year>\d{4})) в (?<hours>\d{1,2}):(?<minutes>\d{1,2})/';

        if (!preg_match($re, Text::trim($string), $m)) {
            throw new Exception("Не удалось разобрать дату '{$string}'");
        }

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime('now', $tz);

        if ($m['today']) {
            list($m['year'], $m['month'], $m['day']) = explode('-', $dt->format('Y-m-d'));
        } elseif ($m['yesterday']) {
            list($m['year'], $m['month'], $m['day']) = explode('-', $dt->modify('-1 day')->format('Y-m-d'));
        } elseif ($m['third']) {
            list($m['year'], $m['month'], $m['day']) = explode('-', $dt->modify('-2 day')->format('Y-m-d'));
        }

        return sprintf('%d-%02d-%02d %02d:%02d:00',
            $m['year'], $m['month'], $m['day'], $m['hours'], $m['minutes']
        );
    }
}
