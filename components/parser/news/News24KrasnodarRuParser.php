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
class News24KrasnodarRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://24krasnodar.ru/';
    public const NEWSLIST_URL = 'https://24krasnodar.ru/';

    public const IS_CURRENT_TIME = true;
    public const DATEFORMAT = 'd.m.Y';

    public const NEWSLIST_POST = '.gridArticle .polovina ul > li';
    public const NEWSLIST_LINK = 'a';
    public const NEWSLISTDATE = 'span.hk';

    public const ARTICLE_TITLE = '.gridArticle .padding5 h1:first-of-type';
    public const ARTICLE_DESC = '.gridArticle .padding5 p:first-of-type';
    public const ARTICLE_IMAGE = 'a.gallery > img';
    public const ARTICLE_TEXT =  '.gridArticle .div_h1 + .padding5';

    public const ARTICLE_BREAKPOINTS = [
        'src' => [
            '//yandex.st/share/share.js' => true,
        ],
        'class' => [
            'hk' => false,
            'yashare-auto-init' => true,
            'b-share_theme_counter' => true,
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

            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLISTDATE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

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
            if(strpos($text, 'Узнавай о новостях первым в') === 0) {
                return;
            }
            elseif(strpos($text, '— ') === 0 && substr_count($text, ', — ')) {
                static::$post->itemQuote = $text;
                return;
            }
        }

        parent::parseNode($node);
    }
}
