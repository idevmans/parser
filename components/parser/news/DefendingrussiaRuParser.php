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

use app\components\Helper;
use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;


/**
 * @fullhtml
 */
class DefendingrussiaRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://defendingrussia.ru/';
    public const NEWSLIST_URL = 'https://defendingrussia.ru/a/cat/news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'H:i d M, Y';

    public const NEWSLIST_POST =  '#articles .publication:not(.not-count)';
    public const NEWSLIST_TITLE = 'a.link .title';
    public const NEWSLIST_LINK =  'a.link';
    public const NEWSLIST_DATE =  'a.link .date';

    public const ARTICLE_IMAGE = '.main_news .image img';
    public const ARTICLE_DESC =  '.page .container .row .content p:first-child';
    public const ARTICLE_TEXT =  '.page .container .row .content';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'tags_wrapper' => true,
            'news_widget__smi2' => true,
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

            $date = self::getNodeData('text', $node, self::NEWSLIST_DATE);

            self::$post->createDate = self::fixDate(substr_replace($date, ' ', 5, 0));

            $articleContent = self::getArticlePage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                if($articleCrawler->filter(self::ARTICLE_TEXT)->count()) {

                    self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                    self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                    self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                    $posts[] = self::$post->getNewsPost();
                }
            }
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $text = trim($node->text());
            if(strpos($text, '«') === 0 && substr_count($text, '», — ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }


    public static function getArticlePage(string $url, ?int $try_count = null) : ?string
    {
        if($try_count === null) {
            $try_count = static::PAGE_TRY_COUNT;
        }

        $curl = Helper::getCurl();

        $curl->setOptions(static::CURL_OPTIONS);

        $content = $curl->get($url);

        if (empty($content)) {
            if($try_count > 0) {
                usleep(rand(static::PAGE_TRY_INTERVAL[0] * 1000000, static::PAGE_TRY_INTERVAL[1] * 1000000));
                return static::getPage($url, ($try_count - 1));
            }
            else {
                throw new \Exception('Can\'t open url ' . $curl->getUrl());
            }
        }

        return $content;
    }
}
