<?php

namespace app\components\helper\metallizzer;

use Exception;

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

    public static function decode(string $string)
    {
        if (0 !== mb_strpos($string, "\x1f\x8b\x08")) {
            return $string;
        }

        return gzdecode($string);
    }

    public static function split(string $text, int $length = 200)
    {
        if ($length < 1) {
            throw new Exception('Min length must be at least 1');
        }

        $result = [
            'description' => $text,
            'content'     => '',
        ];

        if (mb_strlen($text) <= ($length + 30)) {
            return $result;
        }

        if (preg_match('/^(.{'.$length.',}\.(?=\s|$))(.*+)/usU', $text, $m)) {
            $result = [
                'description' => self::trim($m[1]),
                'content'     => self::trim($m[2]),
            ];
        }

        return $result;
    }
}
