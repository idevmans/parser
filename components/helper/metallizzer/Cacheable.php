<?php

namespace app\components\helper\metallizzer;

use app\components\Helper;
use Yii;

/**
 * Для использования кэширования запросов во время разработки, можно подключить кэш.
 *
 * в файл config/console.php в секцию components добавить
 *
 *  'parserCache' => [
 *      'class' => 'yii\caching\FileCache', // Для файлового кэша
 *      'cachePath' => __DIR__.'/../../parser_cache',
 *  ],
 */
trait Cacheable
{
    public static function request(string $url)
    {
        $cacheKey = md5(__METHOD__.$url);
        $cache    = Yii::$app->parserCache ?? null;

        if (!$cache || false === ($data = $cache->get($cacheKey))) {
            $curl = Helper::getCurl();
            $data = $curl->get($url);

            if ($cache) {
                $cache->set($cacheKey, $data, 3600);
            }
        }

        return $data;
    }
}
