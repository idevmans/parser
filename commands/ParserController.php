<?php


namespace app\commands;

use app\components\Helper;
use yii\console\Controller;

class ParserController extends Controller
{
    /**
     * Run parser by class name
     * @param $class
     */

    public function actionNews(string $class): void
    {
        $class = "app\components\parser\\news\\$class";
        $class = new $class();
        $posts = ($class->run());
        foreach ($posts as $post)
            $post->validate();
        echo "Ok" . PHP_EOL;
        echo "Post count: " . count($posts) . PHP_EOL;
        Helper::printPosts($posts);
    }
}
