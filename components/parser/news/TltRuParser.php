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
class TltRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://tlt.ru/';
    public const NEWSLIST_URL = 'https://tlt.ru/feed/';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//media:thumbnail';
    public const NEWSLIST_CONTENT = '//content:encoded';

    public const ARTICLE_IMAGE = '.single-page .post-img img';

    public const ARTICLE_BREAKPOINTS = [];

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

            $desc = explode('[…]', html_entity_decode(self::getNodeData('text', $node, self::NEWSLIST_DESC)));

            self::$post->description = array_shift($desc);

            $content = self::getNodeData('text', $node, self::NEWSLIST_CONTENT);

            $contentCrawler = new Crawler('<body><div>' . $content . '</div></body>');

            self::parse($contentCrawler->filter('body > div'));

            if(!self::$post->image) {
                $articleContent = self::getPage(self::$post->original);

                if (!empty($articleContent)) {
                    $articleCrawler = new Crawler($articleContent);
                    self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                }

            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'a') {

            $url = self::resolveUrl($node->attr('href'));

            if(!$node->text() && self::isImage($url) ) {
                static::$post->itemImage = [
                    $node->attr('alt') ?? $node->attr('title') ?? null,
                    $url,
                ];

                return;
            }
        }
        elseif (strpos($node->text(), 'Сообщение') === 0 && strpos($node->text(), 'появились сначала на')) {
            return;
        }

        parent::parseNode($node);
    }


    protected static function isImage(?string $url): bool
    {
        if(!$url) {
            return false;
        }

        $headers = get_headers($url, 1);

        if (strpos($headers['Content-Type'], 'image/') !== false) {
            return true;
        }
        else {
            return false;
        }
    }
}
