<?php

namespace app\components;

use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use InvalidArgumentException;
use League\Uri\Uri;
use linslin\yii2\curl\Curl;

class Helper
{
    /**
     * Prepare string to valid format
     * @param $string
     * @return string|null
     */
    public static function prepareString($string)
    {
        if ($string == '')
            return null;
        return strip_tags(trim($string));
    }

    /**
     * Prepare URL to valid format
     * @param $url
     * @return string
     */
    public static function prepareUrl($url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $link = preg_replace("(^https?://)", "", $url);
        $url = '';
        $url .= $scheme ? $scheme . '://' : 'https://';
        $url .= $link;
        return $url;
    }

    /**
     * Encode international unicode URL
     * @param $url
     * @return string
     */
    public static function encodeUrl(string $url): string
    {
        $uriParts = parse_url($url);
        if ($uriParts === false) {
            throw new InvalidArgumentException('Невалидный или сильно искаженный URL: ' . $url);
        }

        if (!empty($uriParts['path'])) {
            $uriParts['path'] = implode('/', array_map('rawurlencode', explode('/', $uriParts['path'])));
        }

        return (string) Uri::createFromComponents($uriParts);
    }

    /**
     * Get curl object
     * @return Curl
     */
    public static function getCurl(): Curl
    {
        $curl = new Curl();
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        return $curl;
    }

    public static function printPosts(array $posts)
    {
        /** @var NewsPost $post */
        foreach ($posts as $post) {
            echo PHP_EOL . "---------------------" . PHP_EOL;
            echo 'title: ' . $post->title . PHP_EOL;
            echo 'description: ' . $post->description . PHP_EOL;
            echo $post->image . PHP_EOL;
            echo $post->original . PHP_EOL;
            echo $post->createDate->format('Y-m-d H:i:s') . PHP_EOL;
            /** @var NewsPostItem $item */
            foreach ($post->items as $item) {
                echo "---" . NewsPostItem::TYPES_NAMES [$item->type] . PHP_EOL;
                if (in_array($item->type, [
                    NewsPostItem::TYPE_HEADER,
                    NewsPostItem::TYPE_TEXT,
                    NewsPostItem::TYPE_QUOTE,
                    NewsPostItem::TYPE_LINK,

                ]))
                    echo "\t $item->text" . PHP_EOL;
                if ($item->type == NewsPostItem::TYPE_LINK)
                    echo "\t $item->link" . PHP_EOL;
                if ($item->type == NewsPostItem::TYPE_IMAGE)
                    echo "\t $item->image" . PHP_EOL;
                if ($item->type == NewsPostItem::TYPE_VIDEO)
                    echo "\t $item->youtubeId" . PHP_EOL;
            }
        }
    }

}
