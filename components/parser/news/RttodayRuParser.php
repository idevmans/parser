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
class RttodayRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://rttoday.ru/';
    //public const NEWSLIST_URL = 'https://rttoday.ru/feed/rss'; // переадресация на главную
    public const NEWSLIST_URL = 'https://rttoday.ru/?s=';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST = '#archive-list-wrap ul > li.infinite-post a';
    public const NEWSLIST_TITLE = 'h2';
    public const NEWSLIST_LINK = null;

    public const ARTICLE_DATE =    'head script[class="yoast-schema-graph"]';

    public const ARTICLE_IMAGE = '.mvp-post-img-hide[itemprop="image"] meta[itemprop="url"]';
    public const ARTICLE_TEXT =  'article #content-area #content-main';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'mvp-post-img-hide' => false,
            'flat_pm_end' => true,
            'mvp-org-wrap' => true,
            'posts-nav-link' => true,
            'article-ad' => true,
            'post-tags' => true,
            'post-meta' => true,
            'IRPP_kangoo' => true,
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

                $json = json_decode(self::getNodeData('text', $articleCrawler, self::ARTICLE_DATE), true);
                self::$post->createDate = self::fixDate($json['@graph'][2]['datePublished']);

                self::$post->image = self::getNodeImage('content', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }
}
