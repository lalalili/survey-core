<?php

namespace Lalalili\SurveyCore\Actions;

use Lalalili\SurveyCore\Support\SurveyHtmlSanitizer;

class SanitizeSurveyBuilderSchemaAction
{
    public function __construct(
        private readonly SurveyHtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function execute(array $schema): array
    {
        if (! config('survey-core.security.sanitize_html', true)) {
            return $schema;
        }

        $this->cleanPath($schema, ['settings', 'description']);
        $this->cleanPath($schema, ['settings', 'terms_text']);

        foreach ($schema['pages'] ?? [] as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }

            $this->cleanPath($schema, ['pages', $pageIndex, 'welcome_settings', 'content']);
            $this->cleanPath($schema, ['pages', $pageIndex, 'thank_you_settings', 'message']);

            foreach ($page['elements'] ?? [] as $elementIndex => $element) {
                if (! is_array($element)) {
                    continue;
                }

                $this->cleanPath($schema, ['pages', $pageIndex, 'elements', $elementIndex, 'description']);
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, int|string>  $path
     */
    private function cleanPath(array &$schema, array $path): void
    {
        $cursor = &$schema;
        $lastKey = array_pop($path);

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return;
            }

            $cursor = &$cursor[$segment];
        }

        if (! is_array($cursor) || ! array_key_exists($lastKey, $cursor)) {
            return;
        }

        $cursor[$lastKey] = $this->sanitizer->clean(is_string($cursor[$lastKey]) ? $cursor[$lastKey] : null);
    }
}
