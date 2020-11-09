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
class PsyhRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.psyh.ru/';
    public const NEWSLIST_URL = 'https://www.psyh.ru/feed/';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const ATTR_IMAGE = 'data-src';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_CONTENT = '//content:encoded';

    public const ARTICLE_IMAGE = '.post-image img';

    public const ARTICLE_BREAKPOINTS = [];

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
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);

            $desc = explode('...', self::getNodeData('text', $node, self::NEWSLIST_DESC));

            self::$post->description = trim(array_shift($desc)) . '...';

            $content = self::getNodeData('text', $node, self::NEWSLIST_CONTENT);

            $contentCrawler = new Crawler('<body><div>' . $content . '</div></body>');

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {
                $articleCrawler = new Crawler($articleContent);
                self::$post->image = self::getNodeImage('data-src', $articleCrawler, self::ARTICLE_IMAGE);
            }

            self::parse($contentCrawler->filter('body > div'));

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        $text = $node->text();

        if(strpos($text, 'Сообщение') === 0 && strpos($text, 'появились сначала на')) {
            return;
        }

        parent::parseNode($node);
    }
}
