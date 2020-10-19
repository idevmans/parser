<?php

namespace app\components\helper\metallizzer;

use app\components\parser\NewsPostItem;
use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    const YOUTUBE_REGEX = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/';

    protected $selectors = [
        'header' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
        'image'  => ['img'],
        'quote'  => ['blockquote'],
        'link'   => ['a'],
        'video'  => ['iframe'],
    ];

    protected $glued     = [];
    protected $callbacks = [];
    protected $subNode   = './*/node()';

    public static function flatten(array $blocks)
    {
        $result = [];

        foreach ($blocks as $block) {
            foreach ($block as $item) {
                $result[] = $item;
            }
        }

        return array_filter($result);
    }

    public function setDeep(int $deep)
    {
        if ($deep > 0) {
            $this->subNode = '.'.str_repeat('/*', $deep).'/node()';
        }

        return $this;
    }

    public function setSubNode(string $subNode)
    {
        $this->subNode = $subNode;

        return $this;
    }

    public function parseMany(Crawler $node)
    {
        return self::flatten($node->each(function ($node, $i) {
            return $this->parse($node, $i);
        }));
    }

    public function parse(Crawler $node, int $i)
    {
        $this->glued = array_map(function ($v) {
            return implode(',', $v);
        }, $this->selectors);

        foreach ($this->selectors as $type => $value) {
            if (!$this->isNodeMatches($node, $type)) {
                continue;
            }

            $method = 'parse'.ucfirst($type);

            if (false !== $item = $this->{$method}($node, $i)) {
                return [$item];
            }
        }

        if ($items = $this->parseSubnodes($node, $i)) {
            return $items;
        }

        $text = $node->nodeName() == 'br'
            ? PHP_EOL
            : Text::normalizeWhitespace($node->text(null, false));

        return array_filter([$this->textNode($text)]);
    }

    public function addCallback(string $type, callable $callback)
    {
        $this->callbacks[$type] = $callback;

        return $this;
    }

    public function setSelector(string $type, string $selector, $merge = true)
    {
        if ($merge) {
            $this->selectors['type'][] = $selector;
        } else {
            $this->selectors['type'] = $selector;
        }

        $this->selectors = array_unique(
            array_filter(
                array_map('trim', $this->selectors)
            )
        );

        return $this;
    }

    protected function parseSubnodes(Crawler $node, int $i)
    {
        $items = array_filter($node->filterXpath($this->subNode)->each(function ($node, $i) {
            foreach ($this->selectors as $type => $value) {
                $method = 'parse'.ucfirst($type);

                if (false !== $item = $this->{$method}($node, $i)) {
                    return $item;
                }
            }

            $text = $node->nodeName() == 'br'
                ? PHP_EOL
                : Text::normalizeWhitespace($node->text(null, false));

            return $this->textNode($text);
        }));

        $lastItem = false;
        $lastKey  = null;

        foreach ($items as $key => $item) {
            if ($lastItem
                && NewsPostItem::TYPE_TEXT == $item['type']
                && $item['type'] == $lastItem['type']
            ) {
                $items[$lastKey]['text'] .= $item['text'];

                unset($items[$key]);

                continue;
            }

            $lastItem = $item;
            $lastKey  = $key;
        }

        return $items;
    }

    protected function textNode(string $text)
    {
        if ($text == '') {
            return;
        }

        return [
            'type'        => NewsPostItem::TYPE_TEXT,
            'text'        => $text,
            'image'       => null,
            'link'        => null,
            'headerLevel' => null,
            'youtubeId'   => null,
        ];
    }

    protected function getCallback(string $type)
    {
        if (isset($this->callbacks[$type]) && is_callable($this->callbacks[$type])) {
            return $this->callbacks[$type];
        }
    }

    protected function parseHeader(Crawler $node, int $i)
    {
        if ($callback = $this->getCallback(__FUNCTION__)) {
            return call_user_func($callback, $node, $i);
        }

        if (!$this->isNodeContains($node, 'header')) {
            return false;
        }

        $header   = $node->filter($this->glued['header'])->first();
        $selector = implode(' ', array_filter([$header->nodeName(), $header->attr('class')]));

        $headerLevel = 1;
        if (preg_match('/\bh([1-6])\b/i', $selector, $m)) {
            $headerLevel = (int) $m[1];
        }

        return [
            'type'        => NewsPostItem::TYPE_HEADER,
            'text'        => Text::normalizeWhitespace($header->text(null, false)),
            'image'       => null,
            'link'        => null,
            'headerLevel' => $headerLevel,
            'youtubeId'   => null,
        ];
    }

    protected function parseImage(Crawler $node, int $i)
    {
        if ($callback = $this->getCallback(__FUNCTION__)) {
            return call_user_func($callback, $node, $i);
        }

        if (!$this->isNodeContains($node, 'image')) {
            return false;
        }

        $image = $node->filter($this->glued['image'])->first();

        return [
            'type'        => NewsPostItem::TYPE_IMAGE,
            'text'        => $image->attr('alt'),
            'image'       => Url::encode($image->image()->getUri()),
            'link'        => null,
            'headerLevel' => null,
            'youtubeId'   => null,
        ];
    }

    protected function parseQuote(Crawler $node, int $i)
    {
        if ($callback = $this->getCallback(__FUNCTION__)) {
            return call_user_func($callback, $node, $i);
        }

        if (!$this->isNodeContains($node, 'quote')) {
            return false;
        }

        $quote = $node->filter($this->glued['quote'])->first();

        return [
            'type'        => NewsPostItem::TYPE_QUOTE,
            'text'        => Text::normalizeWhitespace($quote->text(null, false)),
            'image'       => null,
            'link'        => null,
            'headerLevel' => null,
            'youtubeId'   => null,
        ];
    }

    protected function parseLink(Crawler $node, int $i)
    {
        if ($callback = $this->getCallback(__FUNCTION__)) {
            return call_user_func($callback, $node, $i);
        }

        if (!$this->isNodeContains($node, 'link')) {
            return false;
        }

        // Если внутри ссылки содержится изображение, возвращаем его
        if ($this->isNodeContains($node, 'image')) {
            return $this->parseImage($node, $i);
        }

        $type  = NewsPostItem::TYPE_LINK;
        $link  = $node->filter($this->glued['link'])->first();
        $image = null;
        $text  = $link->text();
        $url   = $link->link()->getUri();

        // Если ссылка на изображение, возвращаем изображение
        if (preg_match('/\.(jpe?g|gif|png)$/i', $url)) {
            list($image, $url) = [$url, $image];

            $type = NewsPostItem::TYPE_IMAGE;
        }

        return [
            'type'        => $type,
            'text'        => $text,
            'image'       => $image ? Url::encode($image) : null,
            'link'        => $url ? Url::encode($url) : null,
            'headerLevel' => null,
            'youtubeId'   => null,
        ];
    }

    protected function parseVideo(Crawler $node, int $i)
    {
        if ($callback = $this->getCallback(__FUNCTION__)) {
            return call_user_func($callback, $node, $i);
        }

        if (!$this->isNodeContains($node, 'video')) {
            return false;
        }

        $video = $node->filter($this->glued['video'])->first();

        if (!preg_match(self::YOUTUBE_REGEX, $video->attr('src'), $m)) {
            return false;
        }

        return [
            'type'        => NewsPostItem::TYPE_VIDEO,
            'text'        => null,
            'image'       => null,
            'link'        => null,
            'headerLevel' => null,
            'youtubeId'   => $m[5],
        ];
    }

    protected function isNodeContains(Crawler $node, string $type)
    {
        return $node->filter($this->glued[$type])->count();
    }

    protected function isNodeMatches(Crawler $node, string $type)
    {
        return $node->matches($this->glued[$type]);
    }
}
