<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

final class FontLibrary
{
    public function catalog(): array
    {
        return json_decode(file_get_contents(resource_path('fonts/catalog.json')), true, 512, JSON_THROW_ON_ERROR)['families'];
    }

    public function family(string $id): string
    {
        foreach ($this->catalog() as $font) {
            if ($font['id'] === $id) {
                return '"'.$font['family'].'",'.$font['category'];
            }
        }

        return 'Arial,Helvetica,sans-serif';
    }
}
