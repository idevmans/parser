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

class MoeTambovRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://moe-tambov.ru/';

    protected static $posts = [];
    protected static $post;

    public static function run(): array
    {
        $xml = self::request(self::SITE_URL.'rss');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler($xml);
        $feed->filter('item')->each(function ($node) {
            $dt = new DateTime($node->filter('pubDate')->text());
            $url = $node->filter('link')->text();
            $image = $node->filter('enclosure')->first();

            self::$post = new NewsPost(
                self::class,
                html_entity_decode($node->filter('title')->text()),
                html_entity_decode($node->filter('description')->text()),
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $url,
                $image->count() ? self::parseImage($image) : null,
            );

            self::loadPost($url);
        });

        return static::$posts;
    }

    protected static function loadPost($url)
    {
        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);
        $items   = (new Parser())->parseMany($crawler->filterXpath('//div[contains(@class, "photo-video-block")]/node()'));

        foreach ($items as $item) {
            if (!self::$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                self::$post->image = $item['image'];

                continue;
            }

            self::$post->addItem(new NewsPostItem(...array_values($item)));
        }

        $items = (new Parser())->parseMany($crawler->filterXpath('//div[contains(@class, "app_in_text")]/node()'));

        foreach ($items as $item) {
            if (!self::$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                self::$post->image = $item['image'];

                continue;
            }

            self::$post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = self::$post;
    }

    protected static function parseImage($node)
    {
        $src = $node->attr('url');

        if (false !== $pos = strpos($src, 'https://img.youtube.com')) {
            $src = substr($src, $pos);
        }

        return $src;
    }
}
