<?php

namespace Lalalili\SurveyCore\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class SurveyHtmlSanitizer
{
    /**
     * @var array<int, string>
     */
    private array $allowedTags = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        'a',
        'ul',
        'ol',
        'li',
        'h2',
        'h3',
        'h4',
        'blockquote',
        'code',
        'pre',
    ];

    /**
     * @var array<int, string>
     */
    private array $removedWithContents = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'svg',
        'math',
    ];

    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if ($html === '') {
            return '';
        }

        if (! class_exists(DOMDocument::class)) {
            return Str::of($html)->stripTags('<'.implode('><', $this->allowedTags).'>')->toString();
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="survey-html-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('survey-html-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeNode($root);

        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }

        return trim($clean);
    }

    private function sanitizeNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, $this->removedWithContents, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! in_array($tag, $this->allowedTags, true)) {
                $this->sanitizeNode($child);
                $this->unwrapElement($child);

                continue;
            }

            $this->sanitizeAttributes($child);
            $this->sanitizeNode($child);
        }
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if ($element->tagName !== 'a' || ! in_array($name, ['href', 'target', 'rel'], true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'href' && ! $this->isSafeHref($value)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if (strtolower($element->tagName) === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isSafeHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }

        return preg_match('/^(https?:|mailto:|tel:)/i', $href) === 1;
    }
}
