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
class YtroRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://ytro.ru/';
    public const NEWSLIST_URL = 'https://ytro.ru/news/';

    public const DATEFORMAT = 'H:i, d.m.Y';

    public const NEWSLIST_POST =  '.news-listing > li';
    public const NEWSLIST_LINK =  'a';

    public const ARTICLE_TITLE = '.news .news__title';
    public const ARTICLE_DATE =  '.news__meta .news__date';
    public const ARTICLE_DESC =  '.col-content .news__lead';
    public const ARTICLE_IMAGE = '.news__media img';
    public const ARTICLE_TEXT =  '.news .io-article-bod';

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();


            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->title = self::getNodeData('text', $articleCrawler, self::ARTICLE_TITLE);
                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                if($articleCrawler->filter(self::ARTICLE_TEXT)->count()) {
                    self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                }

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {

            $nodeText = $node->text();

            if (strpos($nodeText, '"') === 0 && substr_count($nodeText, '", – ')) {
                static::$post->itemQuote = $nodeText;
                return;
            }

            $qouteClasses = [
                'article__quote',
            ];

            $NodeClasses = array_filter(explode(' ', $node->attr('class')));

            $diff = array_diff($qouteClasses, $NodeClasses);

            if ($diff != $qouteClasses) {
                static::$post->itemQuote = $nodeText;
                return;
            }
        }

        parent::parseNode($node);
    }
}
