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
 * @fullhtml
 */
class Tvchannel31tvParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://31tv.ru/';
    public const NEWSLIST_URL = 'https://31tv.ru/glavnye-novosti/';

    public const TIMEZONE = '+0500';
    public const DATEFORMAT = 'd m Y - H:i';

    public const NEWSLIST_POST = '#news-list section .card';
    public const NEWSLIST_TITLE = 'h2';
    public const NEWSLIST_LINK = 'h2 a';
    public const NEWSLIST_IMAGE = 'img.card-img-top';

    public const ARTICLE_DATE = '#date-news';
    public const ARTICLE_DESC = '#header-news #text-news';
    public const ARTICLE_IMAGE = '#text-news img:first-of-type';

    public const ARTICLE_TEXT = '#content-news > #text-news';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'hideme' => false,
            'clear' => false,
            'read-more' => false,
        ],
        'id' => [
            'read-more' => false,
            'signature-news' => true,
            'ok_shareWidget' => true,
        ],
        'href' => [
            'https://goo.gl/oIqt5t' => false,
            'https://goo.gl/CN7xkq' => false
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
            self::$post->image = self::getNodeImage('src', $node, self::NEWSLIST_IMAGE);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->createDate = self::getNodeDate('text', $articleCrawler, self::ARTICLE_DATE);
                self::$post->description = self::getNodeData('text', $articleCrawler, self::ARTICLE_DESC);

                $image = self::getNodeImage('src', $articleCrawler, self::ARTICLE_IMAGE);

                if(!self::$post->image && $image || ($image && str_ireplace(basename($image), '', basename(self::$post->image)) != self::$post->image)) {
                    self::$post->image = $image;
                }

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }


            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                if($item->type == NewsPostItem::TYPE_IMAGE && basename($newsPost->image) == basename(urldecode($item->image))) {
                    unset($newsPost->items[$key]);
                }

                $text = trim($item->text);

                if(strpos($text, 'Следите за главными новостями региона на нашей странице в') !== false) {
                    unset($newsPost->items[$key]);
                    $removeNext = true;
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }


    public static function fixDate(string $date) : ?string
    {
        $replace = [
            'января'   => '01',
            'февраля'  => '02',
            'марта'    => '03',
            'апреля'   => '04',
            'мая'      => '05',
            'июня'     => '06',
            'июля'     => '07',
            'августа'  => '08',
            'сентября' => '09',
            'октября'  => '10',
            'ноября'   => '11',
            'декабря'  => '12',
        ];

        $date = trim(str_ireplace(array_keys($replace), $replace, $date));

        return parent::fixDate($date);
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'p') {
            $text = trim($node->text());
            if (strpos($text, 'Сообщение') === 0 && strpos($text, 'появились сначала на')) {
                return;
            }
            elseif (strpos($text, 'Фото:') === 0) {
                return;
            }
        }

        parent::parseNode($node);
    }
}
