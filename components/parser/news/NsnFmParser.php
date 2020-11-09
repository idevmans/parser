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
class NsnFmParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://nsn.fm/';
    public const NEWSLIST_URL = 'https://nsn.fm/news';

    public const CURL_OPTIONS = [
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36'
    ];

    public const DATEFORMAT = 'Y-m-d\TH:i:s.vP';

    public const NEWSLIST_POST =  '._1wEZ9 ._2dAM8';
    public const NEWSLIST_TITLE = '._1Vtjg a._16i0q';
    public const NEWSLIST_LINK =  '._1Vtjg a._16i0q';

    public const ARTICLE_IMAGE = 'meta[itemprop="thumbnailUrl"]';
    public const ARTICLE_DATE =  'div[itemprop="datePublished"]';
    public const ARTICLE_TEXT =  'div[itemprop="articleBody"]';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            '_3skYW' => false,
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

                if($articleCrawler->filter(self::ARTICLE_TEXT)->count()) {
                    self::$post->image = self::getNodeImage('content', $articleCrawler, self::ARTICLE_IMAGE);
                    self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);

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
            if(strpos($text, '«') === 0 && substr_count($text, '», - ')) {
                static::$post->itemQuote = $text;
                return;
            }
            elseif(strpos($text, 'Подписывайтесь на НСН:') === 0 || strpos($text, 'Ранее на') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
