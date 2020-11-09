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
class MallsParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.malls.ru/';
    public const NEWSLIST_URL = 'https://www.malls.ru/news.rss';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_TEXT = 'article.article';

    public const ARTICLE_BREAKPOINTS = [
        'itemprop' => [
            'name' => false,
            'datePublished' => false,
            'description' => false,
            'publisher' => false,
        ],
        'class' => [
            'addthis_inline_share_toolbox' => false,
            'at4-jumboshare' => false,
            'place' => false,
        ],
        'id' => [
            'content_rb_122089' => false,
            'atstbx' => false,
        ],
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            $title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);

            /**
             * статья с агрегацией новостей
             */
            if(strpos($title, 'Новости недели:') === 0) {
                return;
            }

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title = $title;

            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMG);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($item->type == NewsPostItem::TYPE_IMAGE && basename($item->image) == basename(self::$post->image)){
                    unset($newsPost->items[$key]);
                }

                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                $text = ltrim($item->text, static::CHECK_EMPTY_CHARS);

                if(
                    strpos($text, 'Фото:') === 0 ||
                    strpos($text, 'Подписывайтесь на') === 0 ||
                    strpos($text, 'Подробнее про') === 0 ||
                    strpos($text, 'Источник') === 0
                ) {
                    unset($newsPost->items[$key]);
                    $removeNext = true;
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
