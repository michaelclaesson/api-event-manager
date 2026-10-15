<?php

namespace EventManager\Places;

final class PlaceName
{
    public static function normalize(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }
        return mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $name) ?? $name), 'UTF-8');
    }
}
