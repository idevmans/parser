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



class TulapressaParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.tulapressa.ru/';
    public const NEWSLIST_URL = 'https://www.tulapressa.ru/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd.m.Y, H:i';

    public const ATTR_IMAGE = 'data-lazy-src';
    public const ATTR_VIDEO_IFRAME = 'data-lazy-src';

    public const NEWSLIST_POST = '.main-content .right__lenta .last__news_item';
    public const NEWSLIST_TITLE = 'a';
    public const NEWSLIST_LINK = 'a';

    public const ARTICLE_DATE = '.single-content .content .print_date_show';
    public const ARTICLE_DESC = '.single-content .content .preview';
    public const ARTICLE_IMAGE =  '.single-content .content .images img';
    public const ARTICLE_TEXT =   '.single-content .content .post';

    public const ARTICLE_BREAKPOINTS = [];

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

                self::$post->image = self::getNodeImage('data-lazy-src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }
}
