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
class Kp40RuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.kp40.ru/';
    public const NEWSLIST_URL = 'https://www.kp40.ru/xml/news.xml';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMAGE = '//enclosure';

    public const ARTICLE_IMAGE = '.news-article-wrapper .news-body-img img';
    public const ARTICLE_TEXT = '.news-article-wrapper .news-body';


    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'news-view-tags' => true,
            'news-view-author-newsinfo' => true,
        ],
        'id' => [
            'ctl00_InfoPlaceHolder_TimeLabel' => false,
            'ctl00_InfoPlaceHolder_DateLabel' => false,
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
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMAGE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                /**
                * Значит переадресация
                * @see https://www.kp40.ru/news/perekrestok/75869/
                * */
                if(!$articleCrawler->filter(self::ARTICLE_TEXT)->count()) {
                    return;
                }

                $image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                if($image) {
                    self::$post->image = $image;
                }

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $newsPost = self::$post->getNewsPost();

                $removeNext = false;

                foreach ($newsPost->items as $key => $item) {
                    if($removeNext) {
                        unset($newsPost->items[$key]);
                        continue;
                    }

                    if($item->type == NewsPostItem::TYPE_TEXT) {
                        if(strpos($item->text, '-') === 0 && substr_count($item->text, '-') > 1) {
                            $newsPost->items[$key]->type = NewsPostItem::TYPE_QUOTE;
                        }

                        if(strpos($item->text, 'Тем временем') !== false) {
                            unset($newsPost->items[$key]);
                            $removeNext = true;
                        }
                    }

                }

                $posts[] = $newsPost;
            }
        });

        return $posts;
    }
}
