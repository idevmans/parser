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
class SlovodelComParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://slovodel.com/';
    public const NEWSLIST_URL = 'https://slovodel.com/vse-novosti';

    public const DATEFORMAT = 'Y-m-d\TH:i:sP';

    public const MAINPOST_TITLE = '.news-card_big .news-card__text h3';
    public const MAINPOST_LINK =  '.news-card_big';

    public const NEWSLIST_POST =  '.container .news-card';
    public const NEWSLIST_TITLE = '.news-card__text h3';
    public const NEWSLIST_LINK =  null;

    public const ARTICLE_DATE =  'article > meta[itemprop="datePublished"]';
    public const ARTICLE_DESC =  'article .article__lead';
    public const ARTICLE_IMAGE =  '.article div[itemprop="image"] img';
    public const ARTICLE_TEXT =  '.article div[itemprop="articleBody"]';


    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'b-article__intro' => false,
            'document_authors' => true,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);
        $listContent = '<!DOCTYPE html><html><head>' . substr($listContent, strpos($listContent, '</head>'));

        $listCrawler = new Crawler($listContent);

        // Main post
        self::$post = new NewsPostWrapper();
        self::$post->isPrepareItems = false;

        self::$post->title = self::getNodeData('text', $listCrawler, self::MAINPOST_TITLE);
        self::$post->original = self::getNodeLink('href', $listCrawler, self::MAINPOST_LINK);

        $articleContent = self::getPage(self::$post->original);
        $articleContent = '<!DOCTYPE html><html><head>' . substr($articleContent, strpos($articleContent, '</head>'));

        if (!empty($articleContent)) {

            $articleCrawler = new Crawler($articleContent);

            self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
            self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
            self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);

            self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
        }

        $posts[] = self::$post->getNewsPost();

        // Posts list
        $limit = self::NEWS_LIMIT - 1;

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, $limit)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);

            $articleContent = self::getPage(self::$post->original);
            $articleContent = '<!DOCTYPE html><html><head>' . substr($articleContent, strpos($articleContent, '</head>'));

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->createDate = self::getNodeDate('content', $articleCrawler, self::ARTICLE_DATE);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null): void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $text = trim($node->text());
            if(strpos($text, 'Источник фото:') === 0 || strpos($text, 'Это интересно:') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
