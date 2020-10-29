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

class ProufuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://proufu.ru/';
    public const NEWSLIST_URL = 'https://proufu.ru/rss.php';

//    public const TIMEZONE = '+0500';
    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';
    public const NEWSLIST_CONTENT = '//yandex:full-text';

    public const ARTICLE_BREAKPOINTS = [];

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

            $html = html_entity_decode(static::filterNode($node, self::NEWSLIST_CONTENT)->html());

            $articleCrawler = new Crawler('<body><div>'.$html.'</div></body>');

            static::parse($articleCrawler);

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }
}
