<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */

namespace app\components\parser\news;

use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @fullhtml
 * @nonstd
 */
class Vesti35RfParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    //public const SITE_URL = 'https://вести35.рф/';
    public const SITE_URL = 'https://xn--35-dlcmp7ch.xn--p1ai/';
    public const NEWSLIST_URL = 'https://xn--35-dlcmp7ch.xn--p1ai/news';

    public const DATEFORMAT = 'd m Y H:i';

    public const NEWSLIST_POST =  '.news_list_block .news_list_item';
    public const NEWSLIST_TITLE = '.news_list_item_content a';
    public const NEWSLIST_LINK =  '.news_list_item_content a';
    public const NEWSLIST_DESC =  '.news_list_item_content .news_list_item_text';
    public const NEWSLIST_IMAGE =  '.news_list_item_image img';

    public const ARTICLE_IMAGE = '.news_main_block .news_body .news_media img.news_image';
    public const ARTICLE_DATE = '.news_main_block .news_header .datetime';
    public const ARTICLE_TEXT = '.news_main_block .news_body';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'item_info_block' => false,
            'news_item_info_block' => false,
        ],
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $newsList = [];

        $listContent = self::getPage(self::NEWSLIST_URL);
        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->each(function (Crawler $node) use (&$newsList) {

            $item = new \stdClass();

            $item->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            $item->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            $item->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            $item->image = self::getNodeImage('data-src', $node, self::NEWSLIST_IMAGE);

            $newsList[] = $item;

        });

        $lastDay = -1;
        $maxLoop = 25;

        while (count($newsList) < self::NEWS_LIMIT && $maxLoop--) {

            $url = self::NEWSLIST_URL . date('/Y/m/d', strtotime($lastDay . ' day'));
            $lastDay--;

            $listContent = self::getPage($url);
            $listCrawler = new Crawler($listContent);

            $listCrawler->filter(self::NEWSLIST_POST)->each(function (Crawler $node) use (&$newsList) {

                $item = new \stdClass();

                $item->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
                $item->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
                $item->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
                $item->image = self::getNodeImage('src', $node, self::NEWSLIST_IMAGE);

                $newsList[] = $item;

            });
        }

        $posts = [];

        $newsCount = min(count($newsList),self::NEWS_LIMIT);

        for ($i = 0; $i < $newsCount; $i++) {
            self::$post = new NewsPostWrapper();

            self::$post->title = $newsList[$i]->title;
            self::$post->original = $newsList[$i]->original;
            self::$post->description = $newsList[$i]->description;
            self::$post->image = $newsList[$i]->image;

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                if($image) {
                    self::$post->image = $image;
                }

                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        }

        return $posts;
    }


    public static function fixDate(string $date) : ?string
    {
        $replace = [
            'января'   => '01',
            'февраля'  => '02',
            'марта'    => '03',
            'апреля'   => '04',
            'мая'      => '05',
            'июня'     => '06',
            'июля'     => '07',
            'августа'  => '08',
            'сентября' => '09',
            'октября'  => '10',
            'ноября'   => '11',
            'декабря'  => '12',
        ];

        $date = trim(str_ireplace(array_keys($replace), $replace, $date));

        if(strlen($date) < 16) {
            $date = substr_replace($date, ' ' . date('Y'), 5, 0);
        }

        return parent::fixDate($date);
    }
}
