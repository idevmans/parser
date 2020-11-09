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
class KommersantRegions36Parser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.kommersant.ru/';
    //public const SITE_URL = 'https://www.kommersant.ru/regions/36';
    public const NEWSLIST_URL = 'https://www.kommersant.ru/regions/36';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const ATTR_IMAGE = 'data-lazyimage-src';

    public const MAINPOST_LINK =  'article.uho_main .uho_main__text h4.uho_main__name a';
    public const MAINPOST_IMG =   'article.uho_main .uho_main__photo img';

    public const NEWSLIST_POST =  '.b-indetail .indetail__item .uho_norm';
    public const NEWSLIST_LINK =  '.uho__name.uho_norm__name a';
    public const NEWSLIST_IMG =   '.uho__photo img';

    public const ARTICLE_TITLE = 'article.b-article .article_name';
    public const ARTICLE_DESC =  'article.b-article .article_subheader';
    public const ARTICLE_DATE =  'article.b-article meta[itemprop="datePublished"]';
    public const ARTICLE_TEXT =  'article.b-article .article_text_wrapper';


    public const ARTICLE_BREAKPOINTS = [
        'class' => [
//            'b-article__intro' => false,
            'document_authors' => true,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        // Main post
        self::$post = new NewsPostWrapper();
        self::$post->isPrepareItems = false;

        self::$post->original = self::getNodeLink('href', $listCrawler, self::MAINPOST_LINK);
        self::$post->image = self::getNodeImage('data-lazyimage-src', $listCrawler, self::MAINPOST_IMG);

        $articleContent = self::getPage(self::$post->original);

        if (!empty($articleContent)) {

            $articleCrawler = new Crawler($articleContent);

            self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
            self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

            $date = str_replace('00:00:00+03:00', date('H:i:sP'), self::getNodeData('content', $articleCrawler, self::ARTICLE_DATE));

            self::$post->createDate = self::fixDate($date);

            self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
        }

        $posts[] = self::$post->getNewsPost();

        // Posts list
        $limit = self::NEWS_LIMIT - 1;

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, $limit)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->image = self::getNodeImage('data-lazyimage-src', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                $date = str_replace('00:00:00+03:00', date('H:i:sP'),self::getNodeData('content', $articleCrawler, self::ARTICLE_DATE));

                self::$post->createDate = self::fixDate($date);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }
}
