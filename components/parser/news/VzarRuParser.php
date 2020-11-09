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
class VzarRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://www.vzsar.ru/';
    public const NEWSLIST_URL = 'https://www.vzsar.ru/rss/index.php';

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
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_VIDEO = '.innerNews .newshead .video iframe';
    public const ARTICLE_GALLERY = '.innerNews .newshead .gallery .scrollImg';

    public const ARTICLE_TEXT = '.innerNews .full';
    public const ARTICLE_REDIRECT = 'HEAD META[HTTP-EQUIV="REFRESH"]';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'seealso_banner' => false,
            'textauthor' => true,
        ]
    ];

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
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            self::$post->image = self::getNodeImage('url', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                if($articleCrawler->filter(self::ARTICLE_REDIRECT)->count()) {
                    $redirect = self::getNodeData('content', $articleCrawler, self::ARTICLE_REDIRECT);
                    $redirect =explode('URL=', $redirect);

                    self::$post->original = trim(end($redirect));

                    if(!self::$post->original) {
                        return;
                    }

                    $articleContent = self::getPage(self::$post->original);

                    $articleCrawler = new Crawler($articleContent);
                }

                if(!$articleCrawler->filter(self::ARTICLE_TEXT)->count()) {
                    return;
                }


                if($articleCrawler->filter(self::ARTICLE_VIDEO)->count()) {
                    self::getNodeVideoId(self::getNodeImage('src', $articleCrawler, self::ARTICLE_VIDEO));
                }

                if($articleCrawler->filter(self::ARTICLE_GALLERY)->count()) {
                    self::parse($articleCrawler->filter(self::ARTICLE_GALLERY));
                    self::$post->stopParsing = false;
                }

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

        if($node->nodeName() == 'p') {
            $text = $node->text();
            if(strpos($text, 'Подпишитесь на наши каналы') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
