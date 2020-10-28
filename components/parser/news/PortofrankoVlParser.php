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

class PortofrankoVlParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://portofranko-vl.ru';
    public const NEWSLIST_URL = 'https://portofranko-vl.ru';

    public const DATEFORMAT = 'd.m.Y H:i O';

    public const NEWSLIST_POST = '.block-newslist a.newslist-element';
    public const NEWSLIST_TITLE = '.post-title';

    public const NEWSLIST_TIME = '.post-time';
    public const ARTICLE_DATE =   '.block-postcontent .post-time';

    public const ARTICLE_IMAGE =  '.block-postcontent img.attachment-post-thumbnail';
    public const ARTICLE_TEXT = '.block-postcontent div:nth-of-type(3)';
    public const ARTICLE_DESC = '.block-postcontent div:nth-of-type(3) p:first-of-type';

    public const ARTICLE_BREAKPOINTS = [];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                $time = explode(',', self::getNodeData('text', $node, self::NEWSLIST_TIME));
                $date = self::getNodeData('text', $articleCrawler, self::ARTICLE_DATE);

                $date = $date . ' ' . trim(array_pop($time)) . ' +1000';

                self::$post->createDate = self::fixDate($date);
                self::$post->image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $newsPost = self::$post->getNewsPost();

                foreach ($newsPost->items as $key => $item) {
                    if($item->type == NewsPostItem::TYPE_TEXT) {
                        if(strpos($item->text, '—') === 0 && substr_count($item->text, '—') > 1) {
                            $newsPost->items[$key]->type = NewsPostItem::TYPE_QUOTE;
                        }
                    }
                }

                $posts[] = $newsPost;
            }
        });

        return $posts;
    }
}
