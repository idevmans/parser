<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\TyRunBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты yakutiamedia.ru
 *
 */
class YakutiaMedia extends TyRunBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.page-fullnews';

    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_CSS_CLASS = 'fn-quote';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_REGEXP = '/soc_invites_block|news_links_related/';

    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://yakutiamedia.ru/rss/feed';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const MAX_PARSE_DEPTH = 3;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        $crawler = new Crawler($rss);
        $crawler->filter('rss channel item')->slice(0, self::MAX_NEWS_COUNT)->each(function ($node) use (&$curl, &$posts) {
            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                $node->filter('description')->text(),
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                $node->filter('enclosure')->attr('url')
            );

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);
            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);
                /**
                 * Попали на другой тип страницы, не новость
                 */
                if (!$newsContent->count()) {
                    return;
                }

                /**
                 * Основное фото ( всегда одно в начале статьи)
                 */
                $mainImage = $newsContent->filter('.main_foto img');
                if ($mainImage->count()) {
                    if (self::checkImg($newPost->image, $mainImage->attr('src'))) {
                        $newPost->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_IMAGE,
                                null,
                                $mainImage->attr('src'),
                                null,
                                null,
                                null
                            ));
                    }

                    /**
                     * Подпись под основным фото
                     */
                    $annotation = $newsContent->filter('.main_foto')->siblings()->filter('small');
                    if ($annotation->count() && !empty($annotation->text())) {
                        $newPost->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $annotation->text(),
                                null,
                                null,
                                null,
                                null
                            ));
                    }
                }

                /**
                 * Текст статьи, может содержать цитаты ( все полезное содержимое в тегах <p> )
                 * Не знаю нужно или нет, но сделал более универсально, с рекурсией
                 */
                $articleContent = $newsContent->filter('.page-content')->children();
                $stopParsing = false;
                if ($articleContent->count()) {
                    $articleContent->each(function ($node) use ($newPost, &$stopParsing) {
                        if ($stopParsing) {
                            return;
                        }
                        self::parseNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing);
                    });
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing): void
    {
        /**
         * Пропускаем элемент, если элемент имеет определенный класс
         * @see EXCLUDE_CSS_CLASSES_PATTERN
         */
        if (self::EXCLUDE_CSS_CLASSES_PATTERN
            && preg_match(self::EXCLUDE_CSS_CLASSES_PATTERN, $node->attr('class'))) {
            return;
        }

        /**
         * Прекращаем парсить страницу, если дошли до конца статьи
         * (до определенного элемента с классом указанным в @see CUT_CSS_CLASS )
         *
         */
        $stringForCheck = $node->attr('id').' '.$node->attr('class');
        if (self::CUT_CSS_REGEXP && preg_match(self::CUT_CSS_REGEXP, $stringForCheck)) {
            $maxDepth = 0;
            $stopParsing = true;
        }

        /**
         * Ограничение максимальной глубины парсинга
         * @see MAX_PARSE_DEPTH
         */
        if (!$maxDepth) {
            return;
        }
        $maxDepth--;

        switch ($node->nodeName()) {
            case 'div': //запускаем рекурсивно на дочерние ноды, если есть, если нет то там обычно ненужный шлак
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'p':
                self::parseParagraph($node, $newPost);
                break;
            case 'img':
                self::parseImage($node, $newPost);
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                self::parseLink($node, $newPost);
                break;
            case 'iframe':
                $videoId = self::extractYouTubeId($node->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'ul':
            case 'ol':
                self::parseUl($node, $newPost);
                break;
        }

    }

    /**
     * Парсер для тегов <p>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    private static function parseParagraph(Crawler $node, NewsPost $newPost): void
    {
        if (!empty($node->text())) {
            $type = NewsPostItem::TYPE_TEXT;
            if (stristr($node->attr('class'), self::QUOTE_CSS_CLASS)) {
                $type = NewsPostItem::TYPE_QUOTE;
            }

            $newPost->addItem(
                new NewsPostItem(
                    $type,
                    $node->text(),
                    null,
                    null,
                    null,
                    null
                ));
        }
    }

    private static function checkImg(string $rssImage, string $parsedImg): bool
    {
        $rssImagePath = str_replace('https://yakutiamedia.ru', '', $rssImage);
        return !stristr($parsedImg, $rssImagePath);
    }

}