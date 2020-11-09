<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;
use yii\web\NotFoundHttpException;

class BaltnewsEeParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://baltnews.ee/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $uri = '/mir_novosti/';

        while (count($previewList) < $maxNewsCount && !empty($uri)) {
            $uriPreviewPage = UriResolver::resolve($uri, $this->getSiteUrl());
            unset($uri);

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $uri = UriResolver::resolve($previewNewsCrawler->filter('.rubric-list__get-more a')->attr('href'), $this->getSiteUrl());

            $previewNewsCrawler = $previewNewsCrawler->filter('.rubric__list .rubric-list__article');

            $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
                $title = Text::trim($this->normalizeSpaces($newsPreview->filter('h3 a')->text()));
                $uri = UriResolver::resolve($newsPreview->filter('h3 a')->attr('href'), $this->getSiteUrl());

                $previewList[] = new PreviewNewsDTO($uri, null, $title);
            });
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filter('.main__content article');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"article-inject")]');

        try {
            $title = Text::trim($this->normalizeSpaces($contentCrawler->filter('.article-header__title')->text()));
            $previewNewsDTO->setTitle($title);
        } catch (\Exception $exception) {
            throw new NotFoundHttpException(null, null, $exception);
        }

        $publishedAtString = $contentCrawler->filter('.article-header__date')->attr('datetime');
        $publishedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $publishedAtString);
        $previewNewsDTO->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));

        $image = null;

        $mainImageCrawler = $contentCrawler->filterXPath('//*[contains(@class,"article-media")]/img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//*[contains(@class,"article-media")]');
        }

        if ($image !== null && $image !== '') {
            $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
            $previewNewsDTO->setImage($image);
        }

        $descriptionCrawler = $contentCrawler->filterXPath('//p[contains(@class,"article-header__lead")]');
        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            if ($descriptionText) {
                $description = $descriptionText;
                $this->removeDomNodes($contentCrawler, '//p[contains(@class,"article-header__lead")]');
            }
        }

        $contentCrawler = $contentCrawler->filter('.article-content__body');

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
