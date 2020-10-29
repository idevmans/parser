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

class LukiNewsRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://luki-news.ru/';

    protected static $posts = [];

    public static function run(): array
    {
        for ($i = 1; $i < 3; ++$i) {
            $url = self::SITE_URL.'news';

            if ($i > 1) {
                $url .= '/page'.$i.'.html';
            }

            $html = self::request($url, [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]);

            if (!$html) {
                throw new Exception('Не удалось загрузить сайт.');
            }

            $crawler = new Crawler($html, $url);

            $crawler->filter('.news-snippet__name')->each(function ($node) {
                self::loadPost($node->link()->getUri());
            });
        }

        return static::$posts;
    }

    protected static function loadPost($url)
    {
        if (!$html = self::request($url, [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ])
        ) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $data = $crawler->filter('.news-item[data-data]')->first();
        $data = json_decode($data->attr('data-data'), true);

        $date  = $data['date_published'];
        $image = $crawler->filter('.page-img img')->first();

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($date, $tz);

        $post = new NewsPost(
            self::class,
            html_entity_decode($crawler->filterXpath('//meta[@property="og:title"]/@content')->text()),
            html_entity_decode($crawler->filterXpath('//meta[@property="og:description"]/@content')->text()),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $image->count() ? $image->image()->getUri() : null,
        );

        $items = (new Parser())->setJoinText(false)->parseMany($crawler->filterXpath('//div[@class="news-item__blocks"]/node()'));

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
