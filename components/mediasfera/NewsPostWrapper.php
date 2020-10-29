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
namespace app\components\mediasfera;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;

/**
 * @version 1.1.4
 *
 * @property string $title NewsPost->title
 * @property string $description NewsPost->description
 * @property string $createDate NewsPost->createDate
 * @property string $original NewsPost->original
 * @property string $image NewsPost->image
 *
 * @property NewsPostItem[] $items Property provides adding NewsPostItems array to NewsPost
 *
 * @property-write NewsPostItem $item Property provides adding NewsPostItem to NewsPost
 *
 * @property-write array  $itemHeader [string text, int level]<br>Property provides adding NewsPostItem::TYPE_HEADER to NewsPost
 * @property-write string $itemText Property provides adding NewsPostItem::TYPE_TEXT to NewsPost
 * @property-write array  $itemImage Property provides adding NewsPostItem::TYPE_IMAGE to NewsPost
 * @property-write string $itemQuote Property provides adding NewsPostItem::TYPE_QUOTE to NewsPost
 * @property-write array  $itemLink Property provides adding NewsPostItem::TYPE_LINK to NewsPost
 * @property-write string $itemVideo Property provides adding NewsPostItem::TYPE_VIDEO to NewsPost
  */

class NewsPostWrapper
{
    public bool $stopParsing = false;

    private string $parser;

    private string $title = '';
    private string $description = '';
    private string $createDate = '';
    private string $original = '';
    private string $image = '';

    private array $items = [];

    public string $check_empty_chars = " !,.:;?\t\n\r\0\x0B\xC2\xA0";

    public bool $isPrepareItems = true;

    public function __construct()
    {
        [$self, $caller] = debug_backtrace(0, 2);

        $class = $caller['class'];

        $this->parser = $class;
    }

    public function __set($name, $value)
    {
        switch ($name)
        {
            case 'title' :
            case 'createDate' :
            case 'original' :
            case 'image' :
                $this->$name = $this->prepareString($value) ?? '';
                break;

            case 'description' :
                $this->description = $this->isEmptyText($value) ? '' : $this->prepareString($value);
                break;

            case 'item' :
                $this->addItem($value);
                break;

            case 'itemHeader' :
            case 'itemText' :
            case 'itemImage' :
            case 'itemQuote' :
            case 'itemLink' :
            case 'itemVideo' :
                $method = 'add' . $name;
                $this->$method($value);
                break;

            case 'items' :
                $this->addItems($value);
                break;
        }
    }


    public function __get($name)
    {
        switch ($name)
        {
            case 'items' :
                return $this->items;

            case 'title' :
            case 'description' :
            case 'createDate' :
            case 'original' :
            case 'image' :
                return $this->$name;
        }

        return null;
    }


    public function stopParsing()
    {
        $this->stopParsing = true;
    }


    private function addItems(array $items) : void
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }
    }


    private function addItem(?NewsPostItem $item) : void
    {
        if($item === null) {
            return;
        }

        $this->items[] = $item;
    }


    private function addItemHeader(?array $value) : void
    {
        if(!$value || count($value) != 2) {
            return;
        }

        $text = $this->prepareString($value[0]);
        $level = (int)$value[1];

        if($this->isEmptyText($text)) {
            return;
        }

        if($level < 1 || $level > 6) {
            return;
        }

        if($text == $this->title) {
            return;
        }

        if($text == $this->description) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_HEADER,
            $text,
            null,
            null,
            $level
        );
    }


    private function addItemText(?string $value) : void
    {
        $value = $this->prepareString($value);

        if($this->isEmptyText($value)) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_TEXT,
            $value
        );
    }


    private function addItemImage(?array $value) : void
    {
        if(!$value || count($value) != 2 || !$value[1]) {
            return;
        }

        $image = $this->prepareString($value[1]);

        if(!$image || $image == $this->image) {
            return;
        }

        $text = $this->prepareString($value[0]) ?? null;
        $text = (pathinfo($text, PATHINFO_EXTENSION) == pathinfo($image, PATHINFO_EXTENSION)) ? null : $text;

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_IMAGE,
            $text,
            $image
        );
    }


    private function addItemQuote(?string $value) : void
    {
        $value = $this->prepareString($value);

        if($this->isEmptyText($value)) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_QUOTE,
            $value
        );
    }


    private function addItemLink(?array $value) : void
    {
        if(!$value || count($value) != 2) {
            return;
        }

        $link = $this->prepareString($value[1]);
        $text = $this->prepareString($value[0]);

        if(!$link) {
            if($text) {
                $this->items[] = new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    $text
                );
            }

            return;
        }

        $text = $text ?? $link;

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_LINK,
            $text,
            null,
            $link
        );
    }


    private function addItemVideo(?string $value) : void
    {
        $value = $this->prepareString($value);

        if(!$value) {
            return;
        }

        $this->items[] = new NewsPostItem(
            NewsPostItem::TYPE_VIDEO,
            null,
            null,
            null,
            null,
            $value
        );
    }


    private function prepareString(?string $string) : string
    {
        if(!$string) {
            return '';
        }

        return trim(strip_tags($string));
    }


    private function isEmptyText(?string $value) : bool
    {
        $value = trim(strip_tags(html_entity_decode($value)), $this->check_empty_chars);

        if($value) {
            return false;
        }

        return true;
    }


    private function setDescription() : void
    {
        if($this->description) {
            return;
        }

        $description = [];
        $letters = 0;
        $addDot = true;

        foreach ($this->items as $key => $item) {

            if($item->type == NewsPostItem::TYPE_IMAGE || $item->type == NewsPostItem::TYPE_VIDEO) {
                continue;
            }

            $tmp = explode('.', $item->text);
            $addDot = $this->isEmptyText(end($tmp));

            foreach ($tmp as $k => $chunk) {
                $letters += strlen($chunk) + 1;
                $description[] = trim($chunk);
                unset($tmp[$k]);

                if($letters > 200) {
                    break;
                }
            }

            if(!sizeof($tmp)) {
                $addDot = false;
            }

            if($item->type == NewsPostItem::TYPE_TEXT) {
                $itemText = implode('.', $tmp);

                if($this->isEmptyText($itemText)) {
                    unset($this->items[$key]);
                }
                else {
                    $this->items[$key]->text = $itemText;
                }
            }

            if($letters > 200) {
                break;
            }
        }

        if(sizeof($description)) {
            $this->description = trim(implode('. ', $description), '.') . ($addDot ? '.' : '');
        }
        else {
            $this->description = $this->title;
        }
    }


    private function prepareItems() : void
    {
        if(!($this->description && $this->isPrepareItems)) {
            return;
        }

        $description = $this->description;

        foreach ($this->items as $key => $item) {
            if(!$item->text) {
                continue;
            }

            if(strpos($description, $item->text) !== 0) {
                continue;
            }

            $description = trim(
                substr_replace($description, '', 0, strlen($item->text))
            );

            if($item->type == NewsPostItem::TYPE_TEXT || $item->type == NewsPostItem::TYPE_HEADER ) {
                unset($this->items[$key]);
            }

            if(!$description) {
                return;
            }
        }
    }


    public function getNewsPost() : NewsPost
    {
        if($this->description) {
            $this->prepareItems();
        }
        else {
            $this->setDescription();
        }

        $post = new NewsPost(
            $this->parser,
            $this->title,
            $this->description,
            $this->createDate,
            $this->original,
            $this->image ?? null
        );

        foreach ($this->items as $item) {
            $post->addItem($item);
        }

        return $post;
    }
}
