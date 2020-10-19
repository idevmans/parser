<?php

namespace app\components\helper\metallizzer;

class Url
{
    public static function encode(string $url = null, array $replace = [])
    {
        if (!is_string($url) or empty(trim($url))) {
            return '';
        }

        if (!preg_match('/[^\x00-\x7F]/S', $url)) {
            return $url;
        }

        $parts = parse_url($url);

        if (!empty($parts['host']) && preg_match('/[^\x00-\x7F]/S', $parts['host'])) {
            $parts['host'] = idn_to_ascii($parts['host']);
        }

        if (!empty($parts['path']) && preg_match('/[^\x00-\x7F]/S', $parts['path'])) {
            $parts['path'] = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

            if ($replace) {
                $parts['path'] = strtr($parts['path'], $replace);
            }
        }

        return static::build($parts);
    }

    public static function build(array $parts)
    {
        if (function_exists('http_build_url')) {
            return http_build_url($parts);
        }

        return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '')
            .((isset($parts['user']) || isset($parts['host'])) ? '//' : '')
            .(isset($parts['user']) ? "{$parts['user']}" : '')
            .(isset($parts['pass']) ? ":{$parts['pass']}" : '')
            .(isset($parts['user']) ? '@' : '')
            .(isset($parts['host']) ? "{$parts['host']}" : '')
            .(isset($parts['port']) ? ":{$parts['port']}" : '')
            .(isset($parts['path']) ? "{$parts['path']}" : '')
            .(isset($parts['query']) ? "?{$parts['query']}" : '')
            .(isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
    }
}
