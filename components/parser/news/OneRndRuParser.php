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
class OneRndRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.1rnd.ru/';
    public const NEWSLIST_URL = 'https://www.1rnd.ru/news';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST =  '.c-news-block > .c-news-block__body .c-news-block__title';
    public const NEWSLIST_TITLE = null;
    public const NEWSLIST_LINK =  null;

    public const ARTICLE_DATE =  'meta[itemprop="datePublished"]';
    public const ARTICLE_IMAGE = '.article-photo--main img';
    public const ARTICLE_TEXT =  '.col-sm-9 > [class^="article-"]';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'article-subinfo__keyword' => false,
            'findmystake' => true,
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

                self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);
                self::$post->image = self::getNodeImage('data-src', $articleCrawler, self::ARTICLE_IMAGE);

                $articleCrawler->filter(self::ARTICLE_TEXT)->each(function ($node) {
                    self::parse($node);
                    self::$post->stopParsing = false;
                });

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
            if(strpos($text, '- ') === 0 && substr_count($text, ' - ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }
}
