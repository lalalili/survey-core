<?php

namespace Lalalili\SurveyCore\Support;

use Illuminate\Support\Str;

class FieldKeyGenerator
{
    /**
     * Generate a stable field key from a label.
     * Appends a short random suffix so concurrent fields with the same label stay unique.
     */
    public static function generate(string $label): string
    {
        $slug = Str::slug($label, '_');

        if ($slug === '') {
            $slug = 'field';
        }

        return $slug . '_' . strtolower(Str::random(4));
    }
}
