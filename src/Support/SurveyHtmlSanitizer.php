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
        'img',
        'iframe',
        'figure',
        'div',
    ];

    /**
     * @var array<int, string>
     */
    private array $removedWithContents = [
        'script',
        'style',
        'object',
        'embed',
        'svg',
        'math',
    ];

    /** @var array<int, string> */
    private array $allowedIframeHosts = [
        'www.youtube.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
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

            // sanitizeIframeAttributes may remove the element when src is disallowed
            if ($child->parentNode === null) {
                continue;
            }

            // divs without class="survey-video" are unwrapped after their children are sanitized
            if ($tag === 'div' && $child->getAttribute('class') !== 'survey-video') {
                $this->sanitizeNode($child);
                $this->unwrapElement($child);

                continue;
            }

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
        $tag = strtolower($element->tagName);

        match ($tag) {
            'a'      => $this->sanitizeAnchorAttributes($element),
            'img'    => $this->sanitizeImgAttributes($element),
            'iframe' => $this->sanitizeIframeAttributes($element),
            'div'    => $this->sanitizeDivAttributes($element),
            default  => $this->stripAllAttributes($element),
        };
    }

    private function sanitizeAnchorAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (! in_array($name, ['href', 'target', 'rel'], true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'href' && ! $this->isSafeHref($value)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function sanitizeImgAttributes(DOMElement $element): void
    {
        $allowed = ['src', 'alt', 'width', 'height', 'loading'];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'src' && ! $this->isSafeImageSrc($value)) {
                $element->removeAttribute($attribute->name);
            }
        }

        $element->setAttribute('loading', 'lazy');
    }

    private function sanitizeIframeAttributes(DOMElement $element): void
    {
        $src = trim($element->getAttribute('src'));

        if (! $this->isAllowedIframeSrc($src)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        $allowed = ['src', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'title', 'frameborder'];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        $element->setAttribute('loading', 'lazy');
        $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    }

    private function sanitizeDivAttributes(DOMElement $element): void
    {
        $class = trim($element->getAttribute('class'));

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }

        if ($class === 'survey-video') {
            $element->setAttribute('class', 'survey-video');
        }
    }

    private function stripAllAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }
    }

    private function isSafeImageSrc(string $src): bool
    {
        if ($src === '') {
            return false;
        }

        if (str_starts_with($src, '/') && ! str_starts_with($src, '//')) {
            return true;
        }

        return preg_match('/^https:\/\//i', $src) === 1;
    }

    private function isAllowedIframeSrc(string $src): bool
    {
        if ($src === '') {
            return false;
        }

        $host = parse_url($src, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        foreach ($this->allowedIframeHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }

        return preg_match('/^(https?:|mailto:|tel:)/i', $href) === 1;
    }
}
