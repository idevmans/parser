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
class RadiomayakRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://radiomayak.ru/';
    public const NEWSLIST_URL = 'https://radiomayak.ru/news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd m Y H:i';

    public const NEWSLIST_POST =  '.b-news__list > .b-news__item > .b-news__text';
    public const NEWSLIST_TITLE = '.b-news__head';
    public const NEWSLIST_LINK =  '.b-news__link';
    public const NEWSLIST_DESC =  '.b-news__anons';

    public const ARTICLE_DATE =  '.b-content .b-news .b-news__inner .b-news__news-img';
    public const ARTICLE_IMAGE = '.b-content .b-news .b-news__inner .b-news__news-img img';
    public const ARTICLE_TEXT =  '.b-content .b-news .b-news__inner .b-news__news-info .b-news__news-data';

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }


    public static function fixDate(string $date) : ?string
    {
        $replace = [
            'Января'   => '01',
            'Февраля'  => '02',
            'Марта'    => '03',
            'Апреля'   => '04',
            'Мая'      => '05',
            'Июня'     => '06',
            'Июля'     => '07',
            'Августа'  => '08',
            'Сентября' => '09',
            'Октября'  => '10',
            'Ноября'   => '11',
            'Декабря'  => '12',
        ];

        $date = trim(str_ireplace(array_keys($replace), $replace, $date));

        return parent::fixDate($date);
    }
}
