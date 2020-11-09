<?php

namespace app\components\parser\news;

class GudvillComVoronezhParser extends GudvillComTulaParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://gudvill.com/voronezh/';
    }
}
