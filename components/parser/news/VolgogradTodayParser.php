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
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @fullrss
 */
class VolgogradTodayParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.volgograd.today/';
    public const NEWSLIST_URL = 'https://www.volgograd.today/blog-feed.xml';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_IMG = '//enclosure';
    public const NEWSLIST_CONTENT = '//content:encoded';

    public const ARTICLE_DESC = 'p:first-of-type';
    public const ARTICLE_IMAGE = 'figure:first-of-type img';

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
            self::$post->original = self::getNodeLink('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeImage('url', $node, self::NEWSLIST_IMG);

            $content = self::getNodeData('text', $node, self::NEWSLIST_CONTENT);

            $contentCrawler = new Crawler('<body><div>' . $content . '</div></body>');

            self::$post->description = self::getNodeData('text', $contentCrawler, self::ARTICLE_DESC);
            self::$post->image = self::getNodeImage('src', $contentCrawler, self::ARTICLE_IMAGE) ?? self::$post->image;

            self::parse($contentCrawler->filter('body > div'));

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function resolveUrl(?string $url) : string
    {
        if(in_array(pathinfo($url, PATHINFO_EXTENSION), ['png', 'jpg', 'jpeg', 'webp'])) {
            $parts = explode('/v1/', $url);

            return parent::resolveUrl(reset($parts));
        }

        return parent::resolveUrl($url);
    }
}
