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
class Regnews33RuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://regnews33.ru/';
    public const NEWSLIST_URL = 'https://regnews33.ru/';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST = '.content-column .listing article.type-post';
    public const NEWSLIST_TITLE = '.title';
    public const NEWSLIST_LINK = '.title a';
    public const NEWSLIST_DATE = '.time .post-published';
    public const NEWSLIST_IMAGE = '.img-holder';

    public const ARTICLE_DESC = '.post .entry-content p:first-of-type';
    public const ARTICLE_TEXT =  '.post .entry-content';

    public const ARTICLE_BREAKPOINTS = [
        'id' => [
            'toc_container' => false,
        ],
        'class' => [
            'bs-irp' => false,
        ],
        'text' => [
            'Источник' => false,
        ],
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
            self::$post->createDate = self::getNodeDate('datetime', $node, self::NEWSLIST_DATE);
            self::$post->image = self::getNodeImage('data-src', $node, self::NEWSLIST_IMAGE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }
}
