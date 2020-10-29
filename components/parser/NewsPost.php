<?php


namespace app\components\parser;


use app\components\Helper;
use DateTime;
use yii\base\Exception;


/**
 * Class NewsPost
 * @package app\components\parser
 * @property string parser
 * @property string title
 * @property string description
 * @property DateTime createDate
 * @property string original
 * @property ?string image
 * @property array items
 */
class NewsPost
{
    public string $parser;
    public string $title;
    public string $description;
    public DateTime $createDate;
    public string $original;
    public ?string $image;
    public array $items = [];


    /**
     * NewsPost constructor
     * @param string $parser parser Classname
     * @param string $title news title
     * @param string $description news description
     * @param string $createDate news create date in 'Y-m-d H:i:s' format UTC+0
     * @param string $original url to original news
     * @param string|null $image url to news image
     * @throws \Exception
     */
    public function __construct(string $parser,
                                string $title,
                                string $description,
                                string $createDate,
                                string $original,
                                ?string $image)
    {
        $this->parser = $parser;
        $this->title = Helper::prepareString($title);
        $this->description = Helper::prepareString($description);
        $this->createDate = new DateTime(Helper::prepareString($createDate));
        $this->original = Helper::prepareString($original);
        $this->image = Helper::prepareString($image);
    }

    /**
     * Add item to post
     * @param NewsPostItem $item
     */
    public function addItem(NewsPostItem $item): void
    {
        $this->items[] = $item;
    }


    /**
     * Validate NewsPost
     * @throws Exception
     */
    public function validate(): void
    {

        if (!$this->parser)
            throw new Exception("Invalid parser");

        if (!$this->parser::USER_ID)
            throw new Exception("Invalid parser user id: {$this->parser}");

        if (!$this->parser::FEED_ID)
            throw new Exception("Invalid parser feed id: {$this->parser}");

        if (!$this->title)
            throw new Exception("Invalid post title: {$this->parser}");

        if (!$this->description)
            throw new Exception("Invalid post description: {$this->parser}");

        if (!$this->original || !filter_var($this->original, FILTER_VALIDATE_URL))
            throw new Exception("Invalid post original({$this->original}): {$this->parser}");

        if ($this->image && $this->image == '')
            throw new Exception("Invalid post image({$this->image}): {$this->parser}");

        foreach ($this->items as $item)
            /** @var NewsPostItem $item */
            $item->validate($this->parser);


    }


}