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
class InKolomnaRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'http://in-kolomna.ru/';
    public const NEWSLIST_URL = 'http://in-kolomna.ru/novosti';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd m Y г., H:i';

    public const NEWSLIST_POST =  '.archive-main > .list-yii-wrapper .news-itm';
    public const NEWSLIST_TITLE = '.news-itm__title';
    public const NEWSLIST_LINK =  '.news-itm__title a';
    public const NEWSLIST_DATE =  '.news-itm__date';
    public const NEWSLIST_IMAGE = '.news-itm__img img';

    public const ARTICLE_GALLERY =  '.b-page__main .photo_gallery_container';
    public const ARTICLE_TEXT =  '.b-page__main .b-page__content';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'print' => true,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeImage('src', $node, self::NEWSLIST_IMAGE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                if($articleCrawler->filter(self::ARTICLE_GALLERY)->count()) {
                    self::parse($articleCrawler->filter(self::ARTICLE_GALLERY));
                    self::$post->stopParsing = false;
                }

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $text = trim($node->text());
            if(strpos($text, '- ') === 0 && substr_count($text, ' - ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }


    public static function fixDate(string $date) : ?string
    {
        $replace = [
            'янв.' => '01',
            'фев.' => '02',
            'мар.' => '03',
            'апр.' => '04',
            'мая.' => '05',
            'июн.' => '06',
            'июл.' => '07',
            'авг.' => '08',
            'сен.' => '09',
            'окт.' => '10',
            'ноя.' => '11',
            'дек.' => '12',
        ];

        $date = trim(str_ireplace(array_keys($replace), $replace, $date));

        return parent::fixDate($date);
    }
}
