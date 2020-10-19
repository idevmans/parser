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

class NovostimbRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID    = 2;
    const FEED_ID    = 2;
    const SITE_URL   = 'https://novostimb.ru/';
    const SKIP_FIRST = true; // Пропускать первый параграф, он совпадает с описанием

    protected static $posts = [];

    public static function run(): array
    {
        $xml = self::request(self::SITE_URL.'news/feed');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler(html_entity_decode($xml));

        $feed->filter('item')->each(function ($item, $i) {
            $url = $item->filter('link')->text();

            $tz = new DateTimeZone('UTC');
            $dt = new DateTime($item->filter('pubDate')->text(), $tz);

            if (!$html = self::request($url)) {
                throw new Exception("Не удалось загрузить страницу '{$url}'");
            }

            $crawler = new Crawler($html, $url);
            $image = $crawler->filter('article .attachment-post-thumbnail');

            $post = new NewsPost(
                self::class,
                $title = $item->filter('title')->text(),
                $item->filter('description')->text() ?: '~',
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $url,
                $image->count() ? Url::encode($image->first()->image()->getUri()) : null
            );

            $node = $crawler->filterXpath('//main/article/div[contains(@class, "entry-content")]/node()[not(contains(@class, "yarpp-related"))]');
            $items = (new Parser())->parseMany($node, $i);

            foreach ($items as $item) {
                if ($post->description === '~' && $item['type'] == NewsPostItem::TYPE_TEXT) {
                    $post->description = $item['text'];

                    if (self::SKIP_FIRST) {
                        continue;
                    }
                }

                $post->addItem(new NewsPostItem(...array_values($item)));
            }

            if ($post->description == '~') {
                $post->description = $post->title;
            }

            self::$posts[] = $post;
        });

        return self::$posts;
    }
}
