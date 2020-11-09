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
 */
class Katun24RuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://katun24.ru/';
    public const NEWSLIST_URL = 'https://katun24.ru/k24-news';

    public const TIMEZONE = '+0700';
    public const DATEFORMAT = 'd m Y, H:i';

    public const NEWSLIST_POST =  '#block-system-main .view-content > div';
    public const NEWSLIST_LINK =  '.news-main-block-title-line a';

    public const ARTICLE_TITLE =   '.main-content-class .news-view-title h1';
    public const ARTICLE_DATE =    '.main-content-class .news-view-date';
    public const ARTICLE_DESC =    '.main-content-class .news-view-anons';
    public const ARTICLE_IMAGE =   '.main-content-class .news-view-image img';
    public const ARTICLE_TEXT =    '.main-content-class .news-view-text';
    public const ARTICLE_GALLERY = '.main-content-class .paragraphs-items';

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                if($articleCrawler->filter(self::ARTICLE_GALLERY)->count()) {
                    self::$post->stopParsing = false;
                    self::parse($articleCrawler->filter(self::ARTICLE_GALLERY));
                }

                $posts[] = self::$post->getNewsPost();
            }
        });

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

        return parent::fixDate($date);
    }
}
