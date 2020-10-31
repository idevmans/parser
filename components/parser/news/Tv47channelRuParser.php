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
class Tv47channelRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://47channel.ru/';
    public const NEWSLIST_URL = 'https://47channel.ru/news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd m Y года, H:i';

    public const NEWSLIST_POST =  '.article-preview__list > .article-preview__item';
    public const NEWSLIST_TITLE = '.article-preview__title';
    public const NEWSLIST_LINK =  'a';
    public const NEWSLIST_DESC =  '.article-preview__lead';

    public const ARTICLE_DATE =  '.article__content .article__meta .article__meta__date';
    public const ARTICLE_IMAGE = '.article__content .article__meta .article__meta__poster img';
    public const ARTICLE_TEXT =  '.article__content .article__wysiwyg';

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
        $array = explode(',', $date);
        $time = trim(array_pop($array));

        if(strpos($date, 'Сегодня') !== false) {
            $date = date('d m Y') . ' года, ' . $time;
        }
        elseif (strpos($date, 'Вчера') !== false) {
            $date = date('d m Y', strtotime('-1 day')) . ' года, ' . $time;
        }
        elseif (strpos($date, 'года') === false) {
            $date = trim(array_shift($array)) . ' ' . date('Y') . ' года, ' . $time;

        }

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
