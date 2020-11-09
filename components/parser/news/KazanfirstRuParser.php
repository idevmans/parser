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
class KazanfirstRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://kazanfirst.ru/';
    public const NEWSLIST_URL = 'https://kazanfirst.ru/news';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST =  '.content > .column-list a.post-item';
    public const NEWSLIST_LINK =  null;

    public const ARTICLE_DATE =    'head script[type="application/ld+json"]';

    public const ARTICLE_TITLE =   '.single-page:first-child h1.content__title';
    public const ARTICLE_DESC =    '.single-page:first-child h2.content__lead';
    public const ARTICLE_IMAGE =   '.single-page:first-child .content-img_box img';
    public const ARTICLE_TEXT =    '.single-page:first-child';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'jp-my-controls' => false,
            'jp-video-play' => false,

            'horiz-banner' => false,
            'banners-aside__item' => false,
            'banner-adfox-rendering' => false,
            'js-banner-portabled' => false,

            'banners-before' => false,
            'content-preview' => false,
            'content-img_box' => false,
            'content__title' => false,
            'content__lead' => false,

            'content-tags' => true,
            'content-share' => true,
            'comments-block-wrap' => true,
            'banners-inner' => true,
        ],
        'id' => [
            'unit_96628' => false,
            'jp_poster_0' => false,
        ],
        'href' => [
            'https://smi2.ru/' => false,
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
            self::$post->isPrepareItems = false;

            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                $json = json_decode(self::getNodeData('text', $articleCrawler, self::ARTICLE_DATE));
                self::$post->createDate = self::fixDate($json->datePublished);

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
            $text = trim($node->text());
            if(strpos($text, 'Читайте также:') === 0) {
                return;
            }
        }

//        if($node->nodeName() == 'div') {
//            if(strpos($node->attr('class'), 'video-container') !== false) {
//                return;
//            }
//        }

        parent::parseNode($node);
    }
}
