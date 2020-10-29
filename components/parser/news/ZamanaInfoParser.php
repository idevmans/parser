<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class ZamanaInfoParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://zamana.info/';

    protected static $posts = [];

    public static function run(): array
    {
        $html = self::request(self::SITE_URL.'itemlist?format=feed&type=rss');

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, self::SITE_URL);

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

        $crawler = new Crawler($html, $url);
        $image   = $crawler->filter('.itemImageBlock .itemImage img, .nivoSlider img')->first();

        $dt = new DateTime($node->filter('pubDate')->text());

        $post = new NewsPost(
            self::class,
            html_entity_decode($crawler->filter('h1.itemTitle')->text()),
            html_entity_decode($crawler->filterXpath('//meta[@name="description"]/@content')->text()),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? $image->image()->getUri() : null
        );

        $items = (new Parser())->parseMany($crawler->filterXpath('//div[contains(@class, "itemFullText")]/node()'));

        foreach ($items as $item) {
            if (!$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                $post->image = $item['image'];

                continue;
            }

            $post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = $post;
    }
}
