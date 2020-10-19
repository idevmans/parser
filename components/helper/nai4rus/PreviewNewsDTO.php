<?php

namespace app\components\helper\nai4rus;

use DateTimeInterface;
use InvalidArgumentException;

class PreviewNewsDTO
{
    private string $uri;
    private ?DateTimeInterface $publishedAt;
    private ?string $title;
    private ?string $description;
    private ?string $image;

    public function __construct(
        string $uri,
        ?DateTimeInterface $publishedAt = null,
        ?string $title = null,
        ?string $description = null,
        ?string $image = null
    ) {
        if ($uri === '' || !filter_var($uri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Невалидный аргумент $uri: ' . $uri);
        }

        $this->uri = $uri;
        $this->publishedAt = $publishedAt;
        $this->title = $title;
        $this->description = $description;
        $this->image = $image;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPublishedAt(): ?DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function getDateTime(): ?DateTimeInterface
    {
        return $this->getPublishedAt();
    }

    public function setPublishedAt(?DateTimeInterface $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getPreview(): ?string
    {
        return $this->getDescription();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): void
    {
        $this->image = $image;
    }
}
