<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class InruzaRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'http://inruza.ru/';

    protected static $posts = [];

    public static function run(): array
    {
        $url  = self::SITE_URL.'novosti';
        $html = self::request($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, $url);

        $crawler->filter('.news-itm__title a')->each(function ($node) {
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

        $date  = self::parseDate($crawler->filter('.b-page__single-date')->first()->text());
        $image = $crawler->filter('.b-page__image img')->first();

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($date, $tz);

        $path = '//div[@class="b-page__content"]/node()[not(@class = "print")]';

        if (!$description = $crawler->filter('.b-page__start')->first()->text()) {
            $path = '//div[@class="b-page__content"]/node()[position()>2 and not(@class = "print")]';

            $description = $crawler->filterXpath('//div[@class="b-page__content"]/node()[position() = 2]')->text();
        }

        $post = new NewsPost(
            self::class,
            html_entity_decode($crawler->filter('title')->first()->text()),
            html_entity_decode($description),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? $image->image()->getUri() : null,
        );

        $items = (new Parser())->parseMany($crawler->filterXpath($path));

        foreach ($items as $item) {
            if (!$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                $post->image = $item['image'];

                continue;
            }

            $post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = $post;
    }

    protected static function parseDate($string)
    {
        $re = '/^(?<day>\d{1,2}) (?<month>[^ ]+) (?<year>\d{4}) г\., (?<hours>\d{1,2}):(?<minutes>\d{1,2})$/';

        if (!preg_match($re, trim($string), $m)) {
            throw new Exception("Не удалось разобрать дату '{$string}'");
        }

        $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

        foreach ($months as $key => $name) {
            if (strpos($m['month'], $name) === 0) {
                $month = $key + 1;

                break;
            }
        }

        return sprintf('%d-%02d-%02d %02d:%02d:00',
            $m['year'], $month, $m['day'], $m['hours'], $m['minutes']
        );
    }
}
