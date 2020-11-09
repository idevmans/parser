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

class ProvinceRuPenzaParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://www.province.ru/penza/';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'component/obrss/penza-provintsiya-ru.feed';
        $html = self::request($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, $url);

        $crawler->filter('item')->each(function ($node) {
            self::loadPost($node);
        });

        return static::$posts;
    }

    protected static function loadPost($node)
    {
        $url = Url::encode($node->filter('link')->text());

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler(Text::decode($html), $url);

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($node->filter('pubDate')->text(), $tz);

        $image = $crawler->filter('.itemImage img')->first();

        $post = new NewsPost(
            self::class,
            html_entity_decode($node->filter('title')->first()->text()),
            html_entity_decode($crawler->filter('.itemIntroText')->first()->text()) ?: '~',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? Url::encode($image->image()->getUri()) : null
        );

        self::$posts[] = (new Parser())->fill(
            $post,
            $crawler->filterXpath('//div[@class="itemFullText"]/node()[not(self::google)]')
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
