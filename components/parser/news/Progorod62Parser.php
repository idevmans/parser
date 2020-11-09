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
 * @fullrss
 */
class Progorod62Parser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://progorod62.ru/';
    public const NEWSLIST_URL = 'https://progorod62.ru/rss';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';
    public const NEWSLIST_CONTENT = '//content:encoded';

    public const ARTICLE_TEXT = '.article__main.article__container';

    public const ARTICLE_BREAKPOINTS = [
        'name' => [
            'Ext24smiWidget' => false,
            'menu' => false,
        ],
        'href' => [
            '/news' => false,
            '/auto' => false,
            '/afisha' => false,
            '/cityfaces' => false,
            '/peoplecontrol' => false,
            '/sendnews' => false,
            'http://progorod62.ru/news' => false,
            'http://progorod62.ru/auto' => false,
            'http://progorod62.ru/afisha' => false,
            'http://progorod62.ru/cityfaces' => false,
            'http://progorod62.ru/peoplecontrol' => false,
            'http://progorod62.ru/sendnews' => false,
        ],
        'class' => [
            'article__insert' => true,
            'adsbygoogle' => false,
        ],
        'id' => [
            'adv' => false,
        ],
        'data-turbo-ad-id' => [
            'ad_place' => false,
            'ad_place_context' => false,
        ],
        'data-block' => [
            'share' => true,
        ],
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $contentNode = $articleCrawler->filter(self::ARTICLE_TEXT);

                self::parse($contentNode);

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }
}
