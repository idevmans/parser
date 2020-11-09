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
class GosnewsRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.gosnews.ru/';
    public const NEWSLIST_URL = 'https://www.gosnews.ru/';
    //public const NEWSLIST_URL = 'https://www.gosnews.ru/news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd m Y H:i';

    public const NEWSLIST_POST =  '.sidebar-item .sidebar-text > .news-item';
    public const NEWSLIST_TITLE = 'a.news-text';
    public const NEWSLIST_LINK =  'a.news-text';
    public const NEWSLIST_DATE =  'time';


    public const ARTICLE_IMAGE = '.detail-pic-frame img';
    public const ARTICLE_DESC = '.article-text .annotation';
    public const ARTICLE_TEXT =  '.article-text #main_news_text';

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

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

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
