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

use app\components\Helper;
use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;


/**
 * @ajax_html
 */
class BryanskNewsParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://bryansk.news/';
    public const NEWSLIST_URL = 'https://bryansk.news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'Y d m H:i';

    public const NEWSLIST_POST =  '.lenta-item';
    public const NEWSLIST_TITLE = 'a';
    public const NEWSLIST_LINK =  'a';

    public const ARTICLE_DESC =  '.news-subtitle';
    public const ARTICLE_IMAGE = 'img.max-width';
    public const ARTICLE_DATE = '.meta .datetime';
    public const ARTICLE_TEXT = '.news-content';

    public const ARTICLE_BREAKPOINTS = [];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::ajax();

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->description = self::getNodeData('text', $node, self::ARTICLE_DESC);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $yar = explode('/', str_replace(self::SITE_URL, '', self::$post->original))[0];
                $date = $yar .' '. self::getNodeData('text', $articleCrawler, self::ARTICLE_DATE);

                self::$post->createDate = self::fixDate($date);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function ajax() : string
    {
        $result = '';

        $url = 'https://bryansk.news/ajax/loadnews.php';

        $options = [
            CURLOPT_HTTPHEADER => [
                'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'X-Requested-With: XMLHttpRequest'
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0',
            CURLOPT_REFERER        => 'https://bryansk.news/',
            CURLOPT_VERBOSE        => 1,
            CURLOPT_NOBODY         => 0,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => 0,
        ];

        $newsPerPage = 8;
        $pages = ceil(self::NEWS_LIMIT / $newsPerPage);

        for ($i = 0; $i < $pages; $i++) {
            $curl = Helper::getCurl();
            $curl->setOptions($options);

            $requestUrl = $i ? $url . '?numOfPreload=' . $i * $newsPerPage : $url;

            $result .= $curl->get($requestUrl);
        }

        return $result;
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
