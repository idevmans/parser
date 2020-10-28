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
 * @rss_html
 */
class Ia19rusParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://19rus.info/';
    public const NEWSLIST_URL = 'https://19rus.info/index.php/component/sdrsssyndicator/?feed_id=13&format=raw';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST = '//feed/entry';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//published';

    public const ARTICLE_TEXT = '.itemView .itemBody .itemFullText';
    public const ARTICLE_IMAGE = '.itemView .itemImage img';
    public const ARTICLE_GALLERY = '.itemView .itemImageGallery';

    public const ARTICLE_BREAKPOINTS = [
        'text' => [
            'Загрузка...' => false,
        ],
        'class' => [
            'sigProPrintMessage' => true,
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
            self::$post->original = self::getNodeData('href', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                if($articleCrawler->filter(self::ARTICLE_GALLERY)->count()) {
                    self::$post->stopParsing = false;
                    self::parse($articleCrawler->filter(self::ARTICLE_GALLERY));
                }
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }

    protected static function parseList(Crawler $node) : void
    {
        if(!$node->filter('img')->count()) {
            parent::parseList($node);
            return;
        }

        $galleryClasses = [
            'sigProContainer',
            'sigProClassic',
        ];

        $nodeClasses = array_filter(explode(' ', $node->attr('class')));

        $isGallery = false;

        foreach ($nodeClasses as $class) {
            if(in_array($class, $galleryClasses)) {
                if($node->filter('a.sigProLink')->count()) {
                    $isGallery = true;
                    break;
                }
            }
        }

        if($isGallery) {
            $node->filter('a.sigProLink')->each(function (Crawler $item) {
                static::$post->itemImage = [
                    $item->text(),
                    static::getNodeImage('src', $item, 'img')
                ];
            });

            return;
        }

        parent::parseList($node);
    }
}
