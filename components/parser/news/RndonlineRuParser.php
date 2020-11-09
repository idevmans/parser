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
 * @rss_html
 */
class RndonlineRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    const PAGE_TRY_COUNT = 3;
    const PAGE_TRY_INTERVAL = [1.3, 2];

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.rndonline.ru/';
    public const NEWSLIST_URL = 'https://www.rndonline.ru/news/rss/';

    public const CURL_OPTIONS = [
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36'
    ];

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';

    public const ARTICLE_IMAGE = '#top-news .news-additional-info-block img[name="news-photo"]';
    public const ARTICLE_TEXT = '#top-news .news-text';

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        echo "\033[01;31m WARNING! Сайт нестабильно отдает контент возможны задержки в выводе результата! \033[0m";

        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                $contentNode = $articleCrawler->filter(self::ARTICLE_TEXT);

                self::parse($contentNode);

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p' && strpos($node->text(), '- Ростов Онлайн.')) {
            return;
        }
        elseif(strpos($node->text(), 'Хотите узнавать об интересных событиях первыми?') === 0) {
            return;
        }

        parent::parseNode($node);
    }
}
