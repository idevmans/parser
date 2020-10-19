<?php

namespace app\components\helper\metallizzer;

class Text
{
    public static function trim($value = '', $character_mask = " \t\n\r\0\x0B")
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $value), $character_mask);
    }

    public static function normalizeWhitespace($value = '')
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_replace('/[\pZ\pC]{1,}/u', ' ', self::trim($value));
    }
}
