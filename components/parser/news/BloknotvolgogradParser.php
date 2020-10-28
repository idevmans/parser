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
class BloknotvolgogradParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://bloknot-volgograd.ru/';
    public const NEWSLIST_URL = 'https://bloknot-volgograd.ru/rss_news.php';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_DESC = '#news-detail article .news-text b:first-of-type';
    public const ARTICLE_TEXT = '#news-detail article .news-text';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'hideme' => false,
            'clear' => false,
        ],
        'src' => [
            '//polls.bloknot.ru/js/porthole.min.js' => true,
        ],
        'id' => [
            'pollFrame' => true,
        ]
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
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeImage('url', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $contentNode = $articleCrawler->filter(self::ARTICLE_TEXT);

                /**
                 * Переадресация на страницу без новости
                 * @see https://bloknot-volgograd.ru/news/uslugi-dlya-vas-perekhodite-v-spravochnik-1274797
                 */
                if(!$contentNode->count()) {
                    return;
                }

                self::parse($contentNode);

                /**
                 * Это не новость
                 * @see https://bloknot-volgograd.ru/news/uslugi-dlya-vas-perekhodite-v-spravochnik-1274797
                 */
                if(sizeof(self::$post->items) < 1 && !self::$post->description) {
                    return;
                }

                self::$post->description = self::getNodeData('text', $contentNode, self::ARTICLE_DESC);

                $newsPost = self::$post->getNewsPost();

                foreach ($newsPost->items as $key => $item) {
                    $text = trim($item->text);
                    if($item->type == NewsPostItem::TYPE_TEXT && strpos($text, '-') === 0) {
                        $newsPost->items[$key]->type = NewsPostItem::TYPE_QUOTE;
                    }
                }

                $posts[] = $newsPost;
            }
        });

        return $posts;
    }
}
