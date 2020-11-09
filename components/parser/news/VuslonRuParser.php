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
class VuslonRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'http://vuslon.ru';
    public const NEWSLIST_URL = 'http://vuslon.ru/news';

    public const CURL_OPTIONS = [
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36'
    ];

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd m Y - H:i';

    public const NEWSLIST_POST =  '.layout__body > .container-underline-list > .underline-list > li';
    public const NEWSLIST_TITLE = '.media-list__head';
    public const NEWSLIST_LINK =  '.media-list__head';
    public const NEWSLIST_DATE =  '.media-list__date';

    public const ARTICLE_IMAGE = '.page-main__img';
    public const ARTICLE_DESC =  '.page-main__lead';
    public const ARTICLE_TEXT =  '.page-main__text';

    public const ARTICLE_BREAKPOINTS = [
//        'class' => [
//            'article-subinfo__keyword' => false,
//            'findmystake' => true,
//        ]
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

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('data-src', $articleCrawler, self::ARTICLE_IMAGE);

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
            $text = $node->text();
            if(strpos($text, '- ') === 0 && substr_count($text, ' - ')) {
                static::$post->itemQuote = $text;
                return;
            }
            elseif(strpos($text, '«') === 0 && substr_count($text, ' - ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
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
