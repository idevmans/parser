<?php

namespace app\components\helper\nai4rus;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTimeImmutable;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use linslin\yii2\curl\Curl;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

abstract class AbstractBaseParser implements ParserInterface
{
    private int $microsecondsDelay;
    private int $pageCountBetweenDelay;
    private SplObjectStorage $nodeStorage;
    private SplObjectStorage $rootContentNodeStorage;
    private Curl $curl;

    public function __construct(int $microsecondsDelay = 200000, int $pageCountBetweenDelay = 10)
    {
        $this->microsecondsDelay = $microsecondsDelay;
        $this->pageCountBetweenDelay = $pageCountBetweenDelay;
        $this->nodeStorage = new SplObjectStorage();
        $this->rootContentNodeStorage = new SplObjectStorage();
        $this->curl = $this->factoryCurl();
    }

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(10, 50);
    }

    public function parse(int $minNewsCount = 10, int $maxNewsCount = 50): array
    {
        $previewList = $this->getPreviewNewsDTOList($minNewsCount, $maxNewsCount);

        $newsList = [];

        /** @var PreviewNewsDTO $newsPostDTO */
        foreach ($previewList as $key => $newsPostDTO) {
            $newsList[] = $this->parseNewsPage($newsPostDTO);
            $this->nodeStorage->removeAll($this->nodeStorage);

            if ($key % $this->pageCountBetweenDelay === 0) {
                usleep($this->microsecondsDelay);
            }
        }

        $this->curl->reset();
        return $newsList;
    }


    /**
     * @return string
     */
    abstract protected function getSiteUrl(): string;

    /**
     * @param int $minNewsCount
     * @param int $maxNewsCount
     * @return NewsPostItemDTO[]
     */
    abstract protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array;

    /**
     * @param PreviewNewsDTO $previewNewsItem
     * @return NewsPost
     */
    abstract protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost;


    protected function purifyNewsPostContent(Crawler $contentCrawler): void
    {
        $this->removeDomNodes($contentCrawler, '//a[starts-with(@href, "javascript")]');
        $this->removeDomNodes($contentCrawler, '//script | //video | //style | //form');
        $this->removeDomNodes($contentCrawler, '//table');
    }

    protected function parseNewsPostContent(Crawler $contentCrawler, PreviewNewsDTO $newsPostDTO): array
    {
        $newsPostItemDTOList = [];
        $this->setRootNodes($contentCrawler);

        foreach ($contentCrawler as $item) {
            $nodeIterator = new DOMNodeRecursiveIterator($item->childNodes);

            foreach ($nodeIterator->getRecursiveIterator() as $k => $node) {
                $newsPostItemDTO = $this->parseDOMNode($node, $newsPostDTO);
                if (!$newsPostItemDTO) {
                    continue;
                }

                $newsPostItemDTOList[] = $newsPostItemDTO;
            }
        }

        return $newsPostItemDTOList;
    }

    /**
     * @param PreviewNewsDTO $newsPostDTO
     * @param NewsPostItemDTO[] $newsPostItems
     * @param int $descLength
     * @return NewsPost
     */
    protected function factoryNewsPost(
        PreviewNewsDTO $newsPostDTO,
        array $newsPostItems,
        int $descLength = 200
    ): NewsPost {
        $uri = $newsPostDTO->getUri();
        $image = $newsPostDTO->getImage();

        $title = $newsPostDTO->getTitle();
        if ($title === null) {
            throw new InvalidArgumentException('Объект NewsPostDTO не содержит заголовка новости');
        }

        $publishedAt = $newsPostDTO->getPublishedAt() ?: new DateTimeImmutable();
        $publishedAtFormatted = $publishedAt->format('Y-m-d H:i:s');

        $emptyDescriptionKey = 'EmptyDescription';
        $autoDescriptionDone = false;
        $autoGeneratedDescription = '';
        $description = $emptyDescriptionKey;
        $descEmpty = true;
        if ($newsPostDTO->getDescription() !== null) {
            $description = $newsPostDTO->getDescription();
            $descEmpty = false;
        }

        $newsPost = new NewsPost(static::class, $title, $description, $publishedAtFormatted, $uri, $image);

        $duplicatedLinksHashMap = [];
        foreach ($newsPostItems as $newsPostItemDTO) {
            if ($newsPost->image === null && $newsPostItemDTO->isImage()) {
                $newsPost->image = $newsPostItemDTO->getImage();
                continue;
            }

            if ($newsPostItemDTO->isImage() && $newsPost->image === $newsPostItemDTO->getImage()) {
                continue;
            }

            if ($newsPost->description !== $emptyDescriptionKey) {
                $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
                continue;
            }

            $isDescriptionPart = !$newsPostItemDTO->isImage() && $newsPostItemDTO->getText() !== null;
            $autoDescLength = mb_strlen($autoGeneratedDescription);
            $needGenerateDescription = ($autoDescLength < $descLength || !$autoDescriptionDone) && $descEmpty;

            if ($needGenerateDescription && $isDescriptionPart) {
                if ($newsPostItemDTO->isLink() && !isset($duplicatedLinksHashMap[$newsPostItemDTO->getHash()])) {
                    $duplicatedLinksHashMap[$newsPostItemDTO->getHash()] = true;
                    $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
                }

                $space = $autoGeneratedDescription === '' ? '' : ' ';
                $newsPostText = $space . $newsPostItemDTO->getText();
                $reqNumberCharacters = $descLength - $autoDescLength;
                $reqNumberCharacters = $reqNumberCharacters < 0 ? 0 : $reqNumberCharacters;

                $matchResult = preg_match("/^(.{{$reqNumberCharacters}})([^.]*\.+)?(.*)/u", $newsPostText, $matches);
                if ($matchResult === 0 || $matchResult === false) {
                    $autoGeneratedDescription .= $newsPostText;
                    continue;
                }

                if ($matches[2] !== '') {
                    $autoDescriptionDone = true;

                    $autoGeneratedDescription .= $matches[1] . $matches[2];
                    $residualText = $matches[3];

                    if ($this->isAcceptableText($residualText) && !$newsPostItemDTO->isLink()) {
                        $newsPostItemDTO->setText($residualText);
                        $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
                    }
                } else {
                    $autoGeneratedDescription .= $matches[0];
                }
                continue;
            }

            $newsPost->addItem($newsPostItemDTO->factoryNewsPostItem());
        }

        if ($descEmpty) {
            if ($autoGeneratedDescription !== '') {
                $newsPost->description = trim($this->normalizeSpaces($autoGeneratedDescription));
                return $newsPost;
            }

            $newsPost->description = $newsPost->title;
        }

        return $newsPost;
    }


    protected function parseDOMNode(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        try {
            $newsPostItem = $this->searchQuoteNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchHeadingNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchLinkNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchYoutubeVideoNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchImageNewsItem($node, $newsPostDTO);
            if ($newsPostItem) {
                return $newsPostItem;
            }

            $newsPostItem = $this->searchTextNewsItem($node);
            if ($newsPostItem) {
                return $newsPostItem;
            }


            if ($node->nodeName === 'br') {
                $this->removeParentsFromStorage($node->parentNode);
                return null;
            }
        } catch (RuntimeException $exception) {
            return null;
        }
        return null;
    }

    protected function searchQuoteNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || !$this->isQuoteType($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isQuote = $this->isQuoteType($parentNode);

                if ($this->rootContentNodeStorage->contains($parentNode) && !$isQuote) {
                    return null;
                }

                return $isQuote;
            });
            $node = $parentNode ?: $node;
        }

        if (!$this->isQuoteType($node) || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = NewsPostItemDTO::createQuoteItem($this->normalizeText($node->textContent));

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchHeadingNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || $this->getHeadingLevel($node) === null) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isHeading = $this->getHeadingLevel($parentNode) !== null;

                if ($this->rootContentNodeStorage->contains($parentNode) && !$isHeading) {
                    return null;
                }

                return $isHeading;
            });
            $node = $parentNode ?: $node;
        }

        $headingLevel = $this->getHeadingLevel($node);

        if ($headingLevel === null || !$this->hasText($node)) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $newsPostItem = NewsPostItemDTO::createHeaderItem($this->normalizeText($node->textContent), $headingLevel);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchLinkNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        if ($this->isImageType($node)) {
            return null;
        }

        if ($node->nodeName === '#text' || !$this->isLink($node)) {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isLink = $this->isLink($parentNode);

                if ($this->rootContentNodeStorage->contains($parentNode) && !$isLink) {
                    return null;
                }

                return $isLink;
            });
            $node = $parentNode ?: $node;
        }


        if (!$node instanceof DOMElement || !$this->isLink($node)) {
            return null;
        }

        $link = UriResolver::resolve($node->getAttribute('href'), $newsPostDTO->getUri());
        if ($link === null) {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $linkText = null;

        if ($this->hasText($node) && trim($node->textContent, " /\t\n\r\0\x0B") !== trim($link, " /\t\n\r\0\x0B")) {
            $linkText = $this->normalizeSpaces($node->textContent);
        }

        $newsPostItem = NewsPostItemDTO::createLinkItem($link, $linkText);

        $this->nodeStorage->attach($node, $newsPostItem);
        $this->removeParentsFromStorage($node->parentNode);

        return $newsPostItem;
    }

    protected function searchYoutubeVideoNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#text' || $node->nodeName !== 'iframe') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isIframe = $parentNode->nodeName === 'iframe';

                if ($this->rootContentNodeStorage->contains($parentNode) && !$isIframe) {
                    return null;
                }

                return $isIframe;
            }, 3);
            $node = $parentNode ?: $node;
        }

        if (!$node instanceof DOMElement || $node->nodeName !== 'iframe') {
            return null;
        }

        if ($this->nodeStorage->contains($node)) {
            throw new RuntimeException('Тег уже сохранен');
        }

        $youtubeVideoId = $this->getYoutubeVideoId($node->getAttribute('src'));
        if ($youtubeVideoId === null) {
            return null;
        }
        $newsPostItem = NewsPostItemDTO::createVideoItem($youtubeVideoId);
        $this->nodeStorage->attach($node, $newsPostItem);

        return $newsPostItem;
    }

    protected function searchImageNewsItem(DOMNode $node, PreviewNewsDTO $newsPostDTO): ?NewsPostItemDTO
    {
        $isPicture = $this->isPictureType($node);

        if (!$node instanceof DOMElement || (!$this->isImageType($node) && !$isPicture)) {
            return null;
        }

        $imageLink = $node->getAttribute('src');

        if ($isPicture) {
            if ($this->nodeStorage->contains($node->parentNode)) {
                throw new RuntimeException('Тег уже сохранен');
            }

            $pictureCrawler = new Crawler($node->parentNode);
            $imgCrawler = $pictureCrawler->filterXPath('//img');

            if ($imgCrawler->count()) {
                $imageLink = $imgCrawler->first()->attr('src');
            }
        }

        if ($imageLink === '' || mb_stripos($imageLink, 'data:') === 0) {
            return null;
        }

        $imageLink = UriResolver::resolve($imageLink, $newsPostDTO->getUri());
        if ($imageLink === null) {
            return null;
        }

        $alt = $node->getAttribute('alt');
        $alt = $alt !== '' ? $alt : null;

        $newsPostItem = NewsPostItemDTO::createImageItem($imageLink, $alt);

        if ($isPicture) {
            $this->nodeStorage->attach($node->parentNode, $newsPostItem);
        }

        return $newsPostItem;
    }


    protected function searchTextNewsItem(DOMNode $node): ?NewsPostItemDTO
    {
        if ($node->nodeName === '#comment') {
            return null;
        }

        $attachNode = $node;
        if ($node->nodeName === '#text') {
            $parentNode = $this->getRecursivelyParentNode($node, function (DOMNode $parentNode) {
                $isFormattingTag = $this->isFormattingTag($parentNode);

                if ($this->rootContentNodeStorage->contains($parentNode) && !$isFormattingTag) {
                    return null;
                }

                if ($parentNode->parentNode && $this->isFormattingTag($parentNode->parentNode)) {
                    return false;
                }

                return $isFormattingTag;
            }, 6);

            $attachNode = $parentNode ?: $node->parentNode;
        }

        if ($this->isFormattingTag($attachNode)) {
            $attachNode = $attachNode->parentNode;
        }

        if ($this->nodeStorage->contains($attachNode)) {
            /** @var NewsPostItemDTO $parentNewsPostItem */
            $parentNewsPostItem = $this->nodeStorage->offsetGet($attachNode);
            $parentNewsPostItem->addText($this->normalizeText($node->textContent));

            throw new RuntimeException('Контент добавлен к существующему объекту NewsPostItemDTO');
        }

        if (!$this->hasText($node)) {
            return null;
        }

        $newsPostItem = NewsPostItemDTO::createTextItem($this->normalizeText($node->textContent));

        $this->nodeStorage->attach($attachNode, $newsPostItem);

        return $newsPostItem;
    }


    protected function removeParentsFromStorage(
        DOMNode $node,
        int $maxLevel = 5,
        array $exceptNewsPostItemTypes = null
    ): void {
        if ($maxLevel <= 0 || !$node->parentNode) {
            return;
        }

        if ($exceptNewsPostItemTypes === null) {
            $exceptNewsPostItemTypes = [NewsPostItem::TYPE_HEADER, NewsPostItem::TYPE_QUOTE, NewsPostItem::TYPE_LINK];
        }

        if ($this->nodeStorage->contains($node)) {
            /** @var NewsPostItemDTO $newsPostItem */
            $newsPostItem = $this->nodeStorage->offsetGet($node);

            if (in_array($newsPostItem->getType(), $exceptNewsPostItemTypes, true)) {
                return;
            }

            $this->nodeStorage->detach($node);
            return;
        }

        $maxLevel--;

        $this->removeParentsFromStorage($node->parentNode, $maxLevel);
    }

    protected function getRecursivelyParentNode(DOMNode $node, callable $callback, int $maxLevel = 5): ?DOMNode
    {
        $result = $callback($node);

        if ($result === true) {
            return $node;
        }

        if ($maxLevel <= 0 || !$node->parentNode || $result === null) {
            return null;
        }

        $maxLevel--;

        return $this->getRecursivelyParentNode($node->parentNode, $callback, $maxLevel);
    }

    protected function getJsonContent(string $uri): array
    {
        $encodedUri = Helper::encodeUrl($uri);
        $result = $this->curl->get($encodedUri, false);
        $this->checkResponseCode($this->curl);

        return $result;
    }


    protected function getPageContent(string $uri): string
    {
        $encodedUri = Helper::encodeUrl($uri);
        $content = $this->curl->get($encodedUri);
        $this->checkResponseCode($this->curl);

        return $this->decodeGZip($content);
    }

    protected function decodeGZip(string $string)
    {
        if (0 !== mb_strpos($string, "\x1f\x8b\x08")) {
            return $string;
        }

        return gzdecode($string);
    }


    protected function checkResponseCode(Curl $curl): void
    {
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;
        $uri = $responseInfo['url'] ?? null;

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
        }
    }


    protected function isPictureType(DOMNode $node): bool
    {
        return $node->parentNode->nodeName === 'picture';
    }


    protected function isImageType(DOMNode $node): bool
    {
        return $node->nodeName === 'img' || $node->nodeName === 'amp-img';
    }


    protected function isLink(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement || $node->nodeName !== 'a') {
            return false;
        }

        $link = $node->getAttribute('href');
        $scheme = parse_url($link, PHP_URL_SCHEME);

        if ($scheme && !in_array($scheme, ['http', 'https'])) {
            return false;
        }

        return $link !== '';
    }

    protected function isFormattingTag(DOMNode $node): bool
    {
        $formattingTags = [
            'strong' => true,
            'b' => true,
            'span' => true,
            's' => true,
            'i' => true,
            'a' => true,
            'em' => true
        ];

        return isset($formattingTags[$node->nodeName]);
    }

    protected function hasText(DOMNode $node): bool
    {
        return $this->isAcceptableText($node->textContent);
    }

    protected function isAcceptableText(string $text): bool
    {
        $stringWithoutSpaces = preg_replace('/[\pZ\pC\t\r\n⠀]/u', '', $text);

        if (mb_strlen($stringWithoutSpaces) > 5) {
            return true;
        }

        $stringWithoutPunctuationSymbols = preg_replace('/(\p{P})/u', '', $stringWithoutSpaces);

        return $stringWithoutPunctuationSymbols !== '';
    }

    protected function isQuoteType(DOMNode $node): bool
    {
        $quoteTags = ['q' => true, 'blockquote' => true];

        return $quoteTags[$node->nodeName] ?? false;
    }


    protected function getHeadingLevel(DOMNode $node): ?int
    {
        $headingTags = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

        return $headingTags[$node->nodeName] ?? null;
    }

    protected function setRootNodes(Crawler $contentCrawler): void
    {
        $this->rootContentNodeStorage->removeAll($this->rootContentNodeStorage);
        foreach ($contentCrawler as $rootNode) {
            $this->rootContentNodeStorage->attach($rootNode);
        }
    }

    protected function removeDomNodes(Crawler $crawler, string $xpath): void
    {
        $crawler->filterXPath($xpath)->each(function (Crawler $crawler) {
            $domNode = $crawler->getNode(0);
            if ($domNode) {
                $domNode->parentNode->removeChild($domNode);
            }
        });
    }

    protected function crawlerHasNodes(Crawler $crawler): bool
    {
        return $crawler->count() >= 1;
    }

    protected function encodeUri(string $uri)
    {
        try {
            $encodedUri = Helper::encodeUrl($uri);
        } catch (Throwable $exception) {
            return null;
        }

        if ($encodedUri === '' || !filter_var($encodedUri, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $encodedUri;
    }

    protected function getYoutubeVideoId(string $link): ?string
    {
        $youtubeRegex = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/iu';
        preg_match($youtubeRegex, $link, $matches);

        return $matches[5] ?? null;
    }

    protected function normalizeText(string $string): string
    {
        $string = preg_replace('/[\r\n\pC]/u', '', $string);
        return $this->normalizeSpaces($string);
    }

    protected function normalizeSpaces(string $string): string
    {
        return preg_replace('/(\s+|⠀+)/u', ' ', $string);
    }

    protected function factoryCurl(): Curl
    {
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_ENCODING, "gzip");

        return $curl;
    }

    protected function getCurl(): Curl
    {
        return $this->curl;
    }

    protected function getNodeStorage(): SplObjectStorage
    {
        return $this->nodeStorage;
    }

    protected function getMicrosecondsDelay(): int
    {
        return $this->microsecondsDelay;
    }

    protected function getPageCountBetweenDelay(): int
    {
        return $this->pageCountBetweenDelay;
    }

    protected function getRootContentNodeStorage(): SplObjectStorage
    {
        return $this->rootContentNodeStorage;
    }

}
