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
class KaliningradrbcParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://kaliningrad.rbc.ru';
    public const NEWSLIST_URL = 'https://kaliningrad.rbc.ru/kaliningrad/';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const NEWSLIST_POST = '.g-overflow .l-row .item__wrap';
    public const NEWSLIST_TITLE = '.item__title';
    public const NEWSLIST_LINK = 'a.item__link';

    public const ARTICLE_IMG =   'img.article__main-image__image';
    public const ARTICLE_DATE =   'span.article__header__date';
    public const ARTICLE_DESC =  '.article .article__text .article__text__overview';
    public const ARTICLE_TEXT =   '.article .article__text';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'article__text__overview' => false,
            'article__main-image__author' => false,
            'article__main-image' => false,
            'banner' => false,
            'pro-anons' => false,
            'article__authors' => true,
            'article__tags' => true,
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
            self::$post->original = self::getNodeData('href', $node, self::NEWSLIST_LINK);


            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMG);

                $articleCrawler->filter(self::ARTICLE_TEXT)->each(function ($node) {
                    self::parse($node);
                    self::$post->stopParsing = false;
                });
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }
}
