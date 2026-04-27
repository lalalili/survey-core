<?php

namespace Lalalili\SurveyCore\Database\Seeders;

use Illuminate\Database\Seeder;
use Lalalili\SurveyCore\Models\SurveyTheme;

class SurveyThemeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->themes() as $theme) {
            SurveyTheme::updateOrCreate(
                ['name' => $theme['name'], 'is_system' => true],
                ['tokens_json' => $theme['tokens_json'], 'owner_user_id' => null],
            );
        }
    }

    /**
     * @return list<array{name: string, tokens_json: array<string, string>}>
     */
    private function themes(): array
    {
        $base = [
            'surface' => '#f9fafb',
            'text' => '#111827',
            'text_muted' => '#6b7280',
            'border' => '#e5e7eb',
            'font_family' => 'Inter, sans-serif',
            'radius' => '0.5rem',
            'button_style' => 'filled',
        ];

        return [
            ['name' => 'Default', 'tokens_json' => array_merge($base, ['primary' => '#6366f1', 'accent' => '#f59e0b', 'background' => '#ffffff'])],
            ['name' => 'Warm', 'tokens_json' => array_merge($base, ['primary' => '#d97706', 'accent' => '#92400e', 'background' => '#fffbeb'])],
            ['name' => 'Clean', 'tokens_json' => array_merge($base, ['primary' => '#374151', 'accent' => '#0f766e', 'background' => '#ffffff'])],
            ['name' => 'Dark', 'tokens_json' => array_merge($base, ['primary' => '#8b5cf6', 'accent' => '#f59e0b', 'background' => '#111827', 'surface' => '#1f2937', 'text' => '#f9fafb', 'text_muted' => '#d1d5db', 'border' => '#374151'])],
            ['name' => 'Brand', 'tokens_json' => array_merge($base, ['primary' => '#000000', 'accent' => '#6366f1', 'background' => '#ffffff'])],
        ];
    }
}
