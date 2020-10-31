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
class Pg21Parser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://pg21.ru';
    public const NEWSLIST_URL = 'https://pg21.ru/rss';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

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
            'http://pg21.ru/news' => false,
            'http://pg21.ru/auto' => false,
            'http://pg21.ru/afisha' => false,
            'http://pg21.ru/cityfaces' => false,
            'http://pg21.ru/peoplecontrol' => false,
            'http://pg21.ru/sendnews' => false,
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
            'ad_place_smi24' => false,
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

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMG);

            $contentNode = ($node->attr('turbo') == 'true') ? '//turbo:content' : '//yandex:full-text';

            $html = html_entity_decode(static::filterNode($node, $contentNode)->html());

            $articleCrawler = new Crawler('<body><div>'.$html.'</div></body>');

            static::parse($articleCrawler);

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }
}
