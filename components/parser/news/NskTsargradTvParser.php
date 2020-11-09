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
class NskTsargradTvParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://nsk.tsargrad.tv/';
    public const NEWSLIST_URL = 'https://nsk.tsargrad.tv/news';

    public const DATEFORMAT = 'Y-m-d\TH:iP';

    public const NEWSLIST_POST =  '.news__listing > .news__listing-list > li > a';
    public const NEWSLIST_TITLE = null;
    public const NEWSLIST_LINK =  null;

    public const ARTICLE_DATE =  'head meta[property="article:published_time"]';
    public const ARTICLE_IMAGE = '.article__content .article__gallery .active img';
    public const ARTICLE_DESC =  '.article__content .article__intro';
    public const ARTICLE_TEXT =  '.article__content .only__text';

    public const ARTICLE_BREAKPOINTS = [
        'text' => [
            'Уважаемые читатели Царьграда!' => true,
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
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

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
            if(strpos($text, 'Уважаемые читатели Царьграда!') === 0 || strpos($text, 'Присоединяйтесь к нам в соцсетях') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
