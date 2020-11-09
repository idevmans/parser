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
class GradusProParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'http://gradus.pro/';
    public const NEWSLIST_URL = 'http://gradus.pro/category/srochno/';

    public const IS_CURRENT_TIME = true;
    public const DATEFORMAT = 'd.m.Y';

    public const NEWSLIST_POST =  '.stati_block .inner_news_list';
    public const NEWSLIST_TITLE = '.inner_news_title';
    public const NEWSLIST_LINK =  null;
    public const NEWSLIST_DATE =  '.date .date_s';

    public const ARTICLE_DESC =  '.main_news_item .news_full_text p:first-of-type';
    public const ARTICLE_TEXT = '.main_news_item .news_full_text';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'mistape_caption' => true,
            'mistape-link-wrap' => true,
            'essb_message_above_share' => true,
            'tag_ul' => true,
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
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);


            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

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
            $text = $node->text();
            if(strpos($text, '— ') === 0 && substr_count($text, ' — ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }
}
