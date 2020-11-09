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
 * @rss_html
 */
class SovaParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://sova.info';
    public const NEWSLIST_URL = 'https://sova.info/data/rss/2801/';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_IMAGE = '.articleContent .generalContent .justify-content-center div:first-child img:last-of-type';
    public const ARTICLE_TEXT =  '.articleContent .generalContent .justify-content-center div:first-child';

    public const ARTICLE_BREAKPOINTS = [
        'href' => [
            '#gallerySlider' => false,
        ],
        'src' => [
            'https://jsn.24smi.net/smi.js' => false,
        ],
        'class' => [
            'content_our-partners' => false,
            'smi24__informer' => false,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        if(strpos($listContent, '<?xml version="1.0" encoding="utf-8"?>') !== 0) {
            $listContent = '<?xml version="1.0" encoding="utf-8"?>' . $listContent;
        }

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = html_entity_decode(self::getNodeData('text', $node, self::NEWSLIST_TITLE));
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                if($image) {
                    self::$post->image = $image;
                }

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $lastKey = array_key_last($newsPost->items);

            if($newsPost->items[$lastKey]->type == NewsPostItem::TYPE_TEXT && strpos($newsPost->items[$lastKey]->text, 'Фото:') === 0) {
                unset($newsPost->items[$lastKey]);
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
