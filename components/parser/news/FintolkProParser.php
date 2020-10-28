<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\helper\metallizzer\Text;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class FintolkProParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://fintolk.pro/';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'news/feed/';
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
        $url = Url::encode($node->filter('link')->first()->text());

        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($node->filter('pubDate')->first()->text());

        $image = $crawler->filter('img.articles-img__image')->first();

        $post = new NewsPost(
            self::class,
            html_entity_decode($node->filter('title')->first()->text()),
            html_entity_decode($crawler->filterXpath('//div[@class="articles-container__content"]/node()[2]')->first()->text()),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? Url::encode($image->image()->getUri()) : null
        );

        $items = (new Parser())->parseMany($crawler->filterXpath('//div[@class="articles-container__content"]/node()[position()>2 and not(@class="likebtn_container") and not(contains(@class, "wp-block-embed-wordpress"))]'));

        foreach ($items as $item) {
            if (!$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                $post->image = $item['image'];

                continue;
            }

            // if ($item['type'] === NewsPostItem::TYPE_TEXT && $item['text'] == $post->description) {
            //     continue;
            // }

            $post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = $post;
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
