<?php

namespace app\components\helper\nai4rus;

use app\components\parser\NewsPostItem;
use InvalidArgumentException;

class NewsPostItemDTO
{
    private int $type;
    private ?string $text;
    private ?string $image;
    private ?string $link;
    private ?int $headerLevel;
    private ?string $youtubeId;

    public function __construct(
        int $type,
        ?string $text = null,
        ?string $image = null,
        ?string $link = null,
        ?int $headerLevel = null,
        ?string $youtubeId = null
    ) {
        if ($link && ($link === '')) {
            throw new InvalidArgumentException('Невалидный аргумент $link: ' . $link);
        }

        if ($image && ($image === '')) {
            throw new InvalidArgumentException('Невалидный аргумент $image: ' . $image);
        }

        if (!in_array($type, NewsPostItem::AVAILABLE_TYPES)) {
            throw new InvalidArgumentException('Невалидный аргумент $type');
        }

        $this->type = $type;
        $this->text = $text;
        $this->image = $image;
        $this->link = $link;
        $this->headerLevel = $headerLevel;
        $this->youtubeId = $youtubeId;
    }

    public static function createHeaderItem(string $text, int $headerLevel): self
    {
        return new self(NewsPostItem::TYPE_HEADER, $text, null, null, $headerLevel);
    }

    public static function createTextItem(string $text): self
    {
        return new self(NewsPostItem::TYPE_TEXT, $text);
    }

    public static function createImageItem(string $image, ?string $text = null): self
    {
        return new self(NewsPostItem::TYPE_IMAGE, $text, $image);
    }

    public static function createQuoteItem(string $text): self
    {
        return new self(NewsPostItem::TYPE_QUOTE, $text);
    }

    public static function createLinkItem(string $link, ?string $text = null): self
    {
        return new self(NewsPostItem::TYPE_LINK, $text, null, $link);
    }

    public static function createVideoItem(string $youtubeId): self
    {
        return new self(NewsPostItem::TYPE_VIDEO, null, null, null, null, $youtubeId);
    }

    public function factoryNewsPostItem(): NewsPostItem
    {
        return new NewsPostItem(
            $this->getType(),
            $this->getText(),
            $this->getImage(),
            $this->getLink(),
            $this->getHeaderLevel(),
            $this->getYoutubeId()
        );
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function addText(string $text): void
    {
        $this->text .= $text;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getHeaderLevel(): ?int
    {
        return $this->headerLevel;
    }

    public function getYoutubeId(): ?string
    {
        return $this->youtubeId;
    }

    public function isHeader(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_HEADER;
    }

    public function isText(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_TEXT;
    }

    public function isImage(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_IMAGE;
    }

    public function isQuote(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_QUOTE;
    }

    public function isLink(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_LINK;
    }

    public function isVideo(): bool
    {
        return $this->getType() === NewsPostItem::TYPE_VIDEO;
    }

    public function getHash(): string
    {
        $data = [
            $this->type,
            trim($this->text, " /\t\n\r\0\x0B"),
            trim($this->image, " /\t\n\r\0\x0B"),
            trim($this->link, " /\t\n\r\0\x0B"),
            $this->headerLevel,
            $this->youtubeId,
        ];

        return md5(implode('', $data));
    }
}
