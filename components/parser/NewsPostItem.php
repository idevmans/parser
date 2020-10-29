<?php


namespace app\components\parser;

use app\components\Helper;
use yii\base\Exception;

/**
 * Class NewsPostItem
 * @package app\components\parser
 * @property int type
 * @property string text
 * @property ?string image
 * @property ?string youtubeId
 * @property ?string link
 * @property ?integer headerLevel
 */
class NewsPostItem
{
    public int $type;
    public ?string $text;
    public ?string $image;
    public ?string $link;
    public ?int $headerLevel;
    public ?string $youtubeId;


    const TYPE_HEADER = 0;
    const TYPE_TEXT = 1;
    const TYPE_IMAGE = 2;
    const TYPE_QUOTE = 3;
    const TYPE_LINK = 4;
    //const TYPE_AUDIO = 5;
    const TYPE_VIDEO = 6;

    const AVAILABLE_TYPES = [
        self::TYPE_HEADER,
        self::TYPE_TEXT,
        self::TYPE_IMAGE,
        self::TYPE_QUOTE,
        self::TYPE_LINK,
        self::TYPE_VIDEO,
    ];
    const TYPES_NAMES = [
        self::TYPE_HEADER => "Header",
        self::TYPE_TEXT => "Text",
        self::TYPE_IMAGE => "Image",
        self::TYPE_QUOTE => "Quote",
        self::TYPE_LINK => "Link",
        self::TYPE_VIDEO => "Video"
    ];

    /**
     * NewsPostItem constructor
     * @param int $type PostItemType
     * @param string|null $text text item
     * @param string|null $image url to image
     * @param string|null $link url external link
     * @param int|null $headerLevel header level for type HEADER
     * @param string|null $youtubeId video youtube id
     */
    public function __construct(int $type,
                                ?string $text = null,
                                ?string $image = null,
                                ?string $link = null,
                                ?int $headerLevel = null,
                                ?string $youtubeId = null
    )
    {
        $this->type = $type;
        $this->text = Helper::prepareString($text);
        $this->image = Helper::prepareString($image);
        $this->link = Helper::prepareString($link);
        $this->headerLevel = $headerLevel;
        $this->youtubeId = Helper::prepareString($youtubeId);
    }

    /**
     * Validate NewsPostItem
     * @param string $parser
     * @throws Exception
     */
    public function validate(string $parser): void
    {
        if (!isset($this->type) || !in_array($this->type, self::AVAILABLE_TYPES))
            throw new Exception("Invalid NewsPostItem type: {$this->type}");
        if (in_array($this->type, [self::TYPE_HEADER, self::TYPE_TEXT, self::TYPE_QUOTE])
            && (!$this->text || $this->text == ''))
            throw new Exception("Invalid text for type {$this->type}: $parser");
        switch ($this->type) {
            case self::TYPE_HEADER:
                if (!$this->headerLevel || $this->headerLevel > 6)
                    throw new Exception("Invalid postItem header level: $parser");
                break;
            case self::TYPE_LINK:
                if (!$this->link || $this->link == '')
                    throw new Exception("Invalid postItem link: $parser");
                break;
            case self::TYPE_IMAGE:
                if (!$this->image || $this->image == '')
                    throw new Exception("Invalid postItem image: $parser");
                break;
            case self::TYPE_VIDEO:
                if (!$this->youtubeId || (strlen($this->youtubeId) != 11))
                    throw new Exception("Invalid postItem video: $parser");
        }
    }
}
