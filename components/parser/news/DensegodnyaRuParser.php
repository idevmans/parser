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
class DensegodnyaRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://densegodnya.ru/';
    public const NEWSLIST_URL = 'https://densegodnya.ru/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd.m.Y H:i';

    public const NEWSLIST_POST =  '.content-inner .art_list > article';
    public const NEWSLIST_TITLE = '.art-tpl__title';
    public const NEWSLIST_LINK =  '.art-tpl__title a';
    public const NEWSLIST_DESC =  '.art-tpl__note p';

    public const ARTICLE_IMAGE = '.content-inner .art-tpl__thumb img';
    public const ARTICLE_DATE =  '.shop2-product-tags + b';
    public const ARTICLE_TEXT =  '.content-inner td';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'art-tpl__thumb' => false,
            'articleIn' => true,
            'shop2-product-tags' => true,
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
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);

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
            if(strpos($text, '«') === 0 && substr_count($text, '», — ')) {
                static::$post->itemQuote = $text;
                return;
            }
            elseif (strpos($text, 'фото:') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
