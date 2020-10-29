<?php declare(strict_types=1);

namespace app\components\parser\news;

use Exception;

use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;

use app\components\Helper;
use Symfony\Component\DomCrawler\Crawler;

class ContextapParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

	const DOMAIN = 'http://www.contextap.ru';
    const ROOT_SRC = "http://www.contextap.ru/cgi-bin/rss.cgi";

	protected static $curl;

    /**
     * @return array
     * @throws Exception
     */

    public static function run(): array
    {
		self::$curl = Helper::getCurl();
		self::$curl->setOption(CURLOPT_CONNECTTIMEOUT, 30);
        $newsList = self::getListEntity();

        $posts = [];
		foreach ($newsList->xpath('//item') as $item) {
			$data = [
				'title' => (string) $item->title,
				'image' => '',
				'pubDate' => (string) $item->pubDate,
				'link' => (string) $item->link,
				'description' => (string)$item->description,
				//'content' => ''
			];
			
			try {
				$data = array_merge($data, self::parsePage((string)$item->link));
			} catch(Exception $e) {
				continue;
			}

			if ((string)$item->title !== '') {
				$posts[] = self::createPost($data);
			}
		}

        return $posts;
    }

	protected static function parsePage($uri)
	{
		$data = [];
		$pageNews = self::$curl->get($uri);
		if (self::$curl->errorCode != 0) {
			throw new Exception('Page not loaded');
		}

		if ($pageNews) {
			$news = new Crawler($pageNews);
			$data['image'] = self::getImageFromPage($news);
			$data['content'] = self::getContentFromPage($news);
			//$data['description'] = self::createDescription($data['content']);
		}
		return $data;
	}

	protected static function createPost($data)
	{
		$post = new NewsPost(
			self::class,
			$data['title'],
			$data['description'],
			$data['pubDate'],
			$data['link'],
			$data['image']
		);

		$post->addItem(
			new NewsPostItem(
				NewsPostItem::TYPE_TEXT,
				$data['content'],
				null,
				null,
				null,
				null
		));

		return $post;
	}

	protected static function getListEntity()
	{
		$rssString = self::$curl->get(self::ROOT_SRC);

        if ($rssString === false) {
            error_log(self::class . "| payload does not contain the necessary data");
            throw new Exception(self::class . " - No data received");
        }

		$xml = new \SimpleXMLElement($rssString);
        return $xml;
	}

	private static function getImageFromPage($news)
	{
		$imageNode = $news->filter('.article2 .img .img2 img');

		$image = '';
		if ($imageNode->count() && !empty($imageNode->attr('src'))) {
			$image = self::prepareImage(self::DOMAIN . $imageNode->attr('src'));
		} 

		return $image;
	}

	private static function getContentFromPage($news)
	{
		$content = $news->filter('.article2 article');
		self::deleteNode($content, 'table');
		self::deleteNode($content, 'div');

		return $content->text();
	}

	//utils methods //TODO 

	private static function createDescription($content)
	{
		$tmp = explode('.', $content);
		if (count($tmp) > 1) {
			return $tmp[0] . '.' . $tmp[1] . '.';
		} else {
			return $tmp[0] . '.';
		}
	}

	private static function deleteNode($html, $selector) 
	{
		$html->filter($selector)->each(function ($html) {
			foreach ($html as $node) {
				$node->parentNode->removeChild($node);
			}
		});
	}

    private static function prepareImage(string $imageUrl): string
    {
        $imageUrlExploded = explode('//', $imageUrl);
        $imageUrlExploded[1] = implode('/', array_map('rawurlencode', explode('/', $imageUrlExploded[1])));
        return  implode('//', $imageUrlExploded);
    }
} 
