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
class BloknotNovorossiyskRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://bloknot-novorossiysk.ru/';
    public const NEWSLIST_URL = 'https://bloknot-novorossiysk.ru/rss_news.php';

    public const CURL_OPTIONS = [
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ];

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_TEXT = '#news-detail article .news-text';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'hideme' => false,
            'clear' => false,
            'read-more' => false,
        ],
        'id' => [
            'read-more' => false,
            'pollFrame' => true,
        ],
        'src' => [
            '//polls.bloknot.ru/js/porthole.min.js' => true,
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

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                $text = ltrim($item->text, static::CHECK_CHARS);

                if($item->type == NewsPostItem::TYPE_TEXT) {
                    if(strpos($text, '-') === 0 && substr_count($text, ',- ')) {
                        $newsPost->items[$key]->type = NewsPostItem::TYPE_QUOTE;
                    }
                    elseif (strpos($text, 'Источник фото:') === 0) {
                        unset($newsPost->items[$key]);
                    }
                    elseif (strpos($text, 'Источник:') === 0) {
                        unset($newsPost->items[$key]);
                    }
                    else if(strpos($text, 'Ранее мы сообщали') !== false) {
                        unset($newsPost->items[$key]);
                        $removeNext = true;
                    }
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
