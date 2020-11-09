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
class GorsiteRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://gorsite.ru/';
    public const NEWSLIST_URL = 'https://gorsite.ru/news/';

    public const TIMEZONE = '+0700';
    public const DATEFORMAT = 'd m Y , H:i';

    public const NEWSLIST_POST =  '.news-listed .news-itemed .plate_info .news-item .news-text a.black_link';
    public const NEWSLIST_TITLE = null;
    public const NEWSLIST_LINK =  null;

    public const ARTICLE_DESC =  '.news-detail p:first-of-type';
    public const ARTICLE_IMAGE = '.news-detail img.detail_picture';
    public const ARTICLE_DATE = '.news-detail .headernews .news-date-time';
    public const ARTICLE_TEXT = '.news-detail';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'headernews' => false,
            'rubricked' => false,
            'ribricked_name' => false,
            'tagsedet' => true,
            'fotbox' => true,
            'commentine' => true,
            'comment' => true,
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

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                $dateNode = self::clearNode($articleCrawler, true, self::ARTICLE_DATE);

                self::$post->createDate = self::getNodeDate('text', $dateNode);


                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
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


    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $text = trim($node->text());
            if(strpos($text, '— ') === 0 && substr_count($text, ', — ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }
}
