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
 * @fullrss
 */
class RiavrnParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://riavrn.ru/';
    public const NEWSLIST_URL = 'https://riavrn.ru/news/yandex-rss/';

    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        if(strpos($listContent, '<?xml version="1.0" encoding="utf-8"?>') !== 0) {
            $listContent = '<?xml version="1.0" encoding="utf-8"?>' . $listContent;
        }

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            self::$post->image = self::getNodeData('url', $node, self::NEWSLIST_IMG);

            $contentNode = ($node->attr('turbo') == 'true') ? '//turbo:content' : '//yandex:full-text';

            $html = static::filterNode($node, $contentNode)->html();
            $html = str_replace("\n", ' ', $html);

            $articleCrawler = new Crawler('<body><div>'.$html.'</div></body>');

            static::parse($articleCrawler);

            $newsPost = self::$post->getNewsPost();

            /**
             * Вырезается первая картинка по просьбе заказчика.
             * Цитата: "Первый элементом каждой новости добавляется картинка, которая является заглавной, только с другим URL. Давайте просто не добавлять её"
             * @see https://github.com/vihos-dev/parser/pull/480
             */
            if($newsPost->items[0]->type == NewsPostItem::TYPE_IMAGE){
                unset($newsPost->items[0]);
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
