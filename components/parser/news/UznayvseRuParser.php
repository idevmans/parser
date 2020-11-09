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

use app\components\Helper;
use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;


/**
 * @ajax_html
 */
class UznayvseRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://uznayvse.ru/';
    public const NEWSLIST_URL = 'https://uznayvse.ru/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'Y-m-d H:i:s';

    public const ARTICLE_TEXT = 'article[itemprop="articleBody"]';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'iku_ad336content' => false,
            'cls_placeholder_ad336' => false,
            'adcontenttop' => false,
            'ad_content_img_checker' => false,
            'ad_content_img' => false,
            'cls_placeholder_adimg' => false,
            'next_article_cont' => false,
            'post_but_dzen' => true,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::ajax();

        $tz = date_default_timezone_get();

        date_default_timezone_set('UTC');

        foreach ($listContent as $key => $post) {
            if($key == self::NEWS_LIMIT) {
                break;
            }

            self::$post = new NewsPostWrapper();
            self::$post->isPrepareItems = false;

            self::$post->title =       $post->title;
            self::$post->original =    self::resolveUrl($post->link);
            self::$post->createDate =  date('Y-m-d H:i:s', $post->created);
            self::$post->description = $post->title_site;
            self::$post->image =       self::resolveUrl($post->image);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $k => $item) {
                if($removeNext) {
                    unset($newsPost->items[$k]);
                    continue;
                }

                $text = ltrim($item->text, static::CHECK_CHARS);
                if($item->type == NewsPostItem::TYPE_TEXT) {
                    if(strpos($text, 'Подпишитесь на наш канал') === 0) {
                        unset($newsPost->items[$k]);
                        $removeNext = true;
                    }
                }
            }

            $posts[] = $newsPost;
        }

        date_default_timezone_set($tz);

        return $posts;
    }


    protected static function ajax() : array
    {
        $result = [];

        $options = [
            CURLOPT_HTTPHEADER => [
                'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'X-Requested-With: XMLHttpRequest'
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:81.0) Gecko/20100101 Firefox/81.0',
            CURLOPT_REFERER        => self::SITE_URL,
            CURLOPT_VERBOSE        => 1,
            CURLOPT_NOBODY         => 0,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => 0,
        ];

        $newsPerPage = 25;
        $pages = ceil(self::NEWS_LIMIT / $newsPerPage);

        for ($i = 0; $i < $pages; $i++) {
            $offset = $i * $newsPerPage;
            $url = self::SITE_URL . "ajax/newssection/1/{$newsPerPage}/{$offset}/";

            $curl = Helper::getCurl();
            $curl->setOptions($options);

            $array = json_decode($curl->get($url));

            $result = array_merge($result, $array);
        }

        return $result;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        if($node->nodeName() == 'div' && $node->attr('class') == 'video') {
            $code = $node->filter('div[data-embed]')->attr('data-embed');
            if($code) {
                static::$post->itemVideo = $code;
                return;
            }
        }

        $qouteClasses = [
            'postcite',
        ];

        $NodeClasses = array_filter(explode(' ', $node->attr('class')));

        $diff = array_diff($qouteClasses , $NodeClasses);

        if($diff != $qouteClasses) {
            static::$post->itemQuote = $node->text();
            return;
        }

        parent::parseNode($node);
    }
}
