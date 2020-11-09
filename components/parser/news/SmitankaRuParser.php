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
class SmitankaRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://smitanka.ru';
    public const NEWSLIST_URL = 'https://smitanka.ru';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd.m.Y H:i:s';

    public const NEWSLIST_POST = '#product-board .news.product';
    public const NEWSLIST_TITLE = '.title';
    public const NEWSLIST_LINK = '.title a';

    public const ARTICLE_DATE =  '.product-main .content';
    public const ARTICLE_DESC =  '.product-main .content .lead';
    public const ARTICLE_IMAGE = '.product-main .content .img-responsive';
    public const ARTICLE_TEXT =  '.product-main .content';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'img-responsive' => false,
            'Favorites' => false,
            'lead' => false,
            'LikeDislike' => false,
            'like_block' => false,
            'ajax_banner_in' => false,
            'Count' => true,
            'onl_socialButtons' => true,
            'onl_login' => true,
        ],
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

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                self::$post->createDate = self::getNodeDate('data-start', $articleCrawler, self::ARTICLE_DATE);
                self::$post->image = self::getNodeImage('url', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }
}
