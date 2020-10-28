<?php

namespace app\components\helper\metallizzer;

use app\components\parser\NewsPostItem;
use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    const YOUTUBE_REGEX = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/';

    protected $selectors = [
        'header' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
        'link'   => ['a'],
        'image'  => ['img'],
        'quote'  => ['blockquote'],
        'video'  => ['iframe'],
    ];

    protected $glued     = [];
    protected $callbacks = [];
    protected $subNode   = './*/node()';
    protected $ignore    = [
        'script',
        'noscript',
        'style',
        'video',
        'embed',
        'form',
        'table',
    ];
    protected $joinText = true;

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

    public function addIgnore($selector, $merge = true)
    {
        if (!is_array($selector)) {
            $selector = [$selector];
        }

        if ($merge) {
            $this->ignore = array_merge($this->ignore, $selector);
        } else {
            $this->ignore = $selector;
        }

        return $this;
    }

    public function setJoinText($joinText)
    {
        $this->joinText = (bool) $joinText;

        return $this;
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
        if (count($this->ignore)) {
            foreach ($this->ignore as $selector) {
                if ($node->matches($selector)) {
                    return [];
                }
            }

            $node->filterXPath('//'.implode('|//', $this->ignore))->each(function (Crawler $crawler) {
                $domNode = $crawler->getNode(0);

                if ($domNode && $domNode->parentNode) {
                    $domNode->parentNode->removeChild($domNode);
                }
            });
        }

        $this->glued = array_map(function ($v) {
            return implode(',', $v);
        }, $this->selectors);

        foreach ($this->selectors as $type => $value) {
            if (!$this->isNodeMatches($node, $type)) {
                continue;
            }

            $method = 'parse'.ucfirst($type);

            if (false !== $item = $this->{$method}($node, $i)) {
                return $this->filterItems([$item]);
            }
        }

        if ($items = $this->parseSubnodes($node, $i)) {
            return $this->filterItems($items);
        }

        $text = $node->nodeName() == 'br'
            ? PHP_EOL
            : Text::normalizeWhitespace($node->text(null, false));

        return $this->filterItems([$this->textNode($text)]);
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

    protected function filterItems(array $items)
    {
        return array_filter($items, function ($item) {
            if (empty($item['type'])) {
                return false;
            }

            switch ($item['type']) {
                case NewsPostItem::TYPE_HEADER:
                case NewsPostItem::TYPE_TEXT:
                case NewsPostItem::TYPE_QUOTE:
                    return strlen(Text::trim($item['text'])) > 0;

                case NewsPostItem::TYPE_IMAGE:
                    return !empty($item['image']);

                case NewsPostItem::TYPE_VIDEO:
                    return !empty($item['youtubeId']);
            }

            return false;
        });
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

        if (!$this->joinText) {
            return $items;
        }

        $lastItem = false;
        $lastKey  = null;

        foreach ($items as $key => $item) {
            if ($lastItem
                && NewsPostItem::TYPE_TEXT == $item['type']
                && $item['type'] == $lastItem['type']
            ) {
                $space = '';

                if (!preg_match('/^[[:punct:]]/', $item['text'])) {
                    $space = ' ';
                }

                $items[$lastKey]['text'] .= $space.$item['text'];

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

        $type  = NewsPostItem::TYPE_LINK;
        $link  = $node->filter($this->glued['link'])->first();
        $image = null;
        $text  = $link->text();
        $url   = $link->link()->getUri();

        // Если ссылка на изображение, возвращаем изображение
        if (preg_match('/\.(jpe?g|gif|png)$/i', $url)) {
            list($image, $url) = [$url, $image];

            $type = NewsPostItem::TYPE_IMAGE;
        } elseif ($this->isNodeContains($node, 'image')) {
            // Если внутри ссылки содержится изображение, возвращаем его

            return $this->parseImage($node, $i);
        }

        if (!preg_match('/^(?:(?:(?<proto>https?|ftp):)?\/)?\//i', $link->attr('href'))) {
            return $this->textNode($text ?: $link->attr('href'));

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
