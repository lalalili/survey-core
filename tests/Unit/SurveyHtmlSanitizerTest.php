<?php

use Lalalili\SurveyCore\Support\SurveyHtmlSanitizer;

function sanitize(string $html): string
{
    return (new SurveyHtmlSanitizer())->clean($html) ?? '';
}

// ── existing behaviour ──────────────────────────────────────────────────────

it('keeps allowed inline tags', function () {
    expect(sanitize('<p><strong>bold</strong> and <em>italic</em></p>'))
        ->toBe('<p><strong>bold</strong> and <em>italic</em></p>');
});

it('strips script tags with contents', function () {
    expect(sanitize('<p>hi</p><script>alert(1)</script>'))
        ->toBe('<p>hi</p>');
});

it('keeps safe anchor with https href', function () {
    $result = sanitize('<a href="https://example.com" target="_blank">link</a>');
    expect($result)
        ->toContain('href="https://example.com"')
        ->toContain('rel="noopener noreferrer"');
});

it('removes javascript href', function () {
    expect(sanitize('<a href="javascript:alert(1)">x</a>'))
        ->not->toContain('href=');
});

// ── image support ───────────────────────────────────────────────────────────

it('allows img with https src', function () {
    $result = sanitize('<img src="https://cdn.example.com/photo.jpg" alt="photo">');
    expect($result)
        ->toContain('<img')
        ->toContain('src="https://cdn.example.com/photo.jpg"')
        ->toContain('alt="photo"')
        ->toContain('loading="lazy"');
});

it('allows img with root-relative src', function () {
    $result = sanitize('<img src="/uploads/img.png" alt="">');
    expect($result)->toContain('src="/uploads/img.png"');
});

it('strips img with javascript src', function () {
    expect(sanitize('<img src="javascript:alert(1)" alt="x">'))
        ->not->toContain('src=');
});

it('strips onerror attribute from img', function () {
    expect(sanitize('<img src="https://x.com/a.jpg" onerror="alert(1)">'))
        ->not->toContain('onerror');
});

it('strips img with data-uri src', function () {
    expect(sanitize('<img src="data:image/png;base64,abc123" alt="">'))
        ->not->toContain('src=');
});

// ── iframe / video support ──────────────────────────────────────────────────

it('allows youtube iframe', function () {
    $html = '<div class="survey-video"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0" allowfullscreen></iframe></div>';
    $result = sanitize($html);
    expect($result)
        ->toContain('<iframe')
        ->toContain('www.youtube.com/embed/')
        ->toContain('loading="lazy"')
        ->toContain('referrerpolicy="strict-origin-when-cross-origin"');
});

it('allows youtube-nocookie iframe', function () {
    $result = sanitize('<iframe src="https://youtube-nocookie.com/embed/abc123"></iframe>');
    expect($result)->toContain('<iframe');
});

it('allows vimeo iframe', function () {
    $result = sanitize('<iframe src="https://player.vimeo.com/video/123456"></iframe>');
    expect($result)
        ->toContain('<iframe')
        ->toContain('player.vimeo.com');
});

it('removes iframe from disallowed host', function () {
    expect(sanitize('<iframe src="https://evil.com/xss"></iframe>'))
        ->not->toContain('<iframe');
});

it('removes iframe with empty src', function () {
    expect(sanitize('<iframe src=""></iframe>'))
        ->not->toContain('<iframe');
});

it('removes srcdoc attribute from iframe', function () {
    $result = sanitize('<iframe src="https://www.youtube.com/embed/abc" srcdoc="<script>alert(1)</script>"></iframe>');
    expect($result)->not->toContain('srcdoc');
});

// ── div.survey-video wrapper ─────────────────────────────────────────────────

it('keeps div with class survey-video', function () {
    $result = sanitize('<div class="survey-video"><iframe src="https://www.youtube.com/embed/abc"></iframe></div>');
    expect($result)->toContain('class="survey-video"');
});

it('unwraps div with disallowed class', function () {
    $result = sanitize('<div class="something-else"><p>text</p></div>');
    expect($result)
        ->not->toContain('<div')
        ->toContain('<p>text</p>');
});

it('unwraps div with no class', function () {
    $result = sanitize('<div><p>text</p></div>');
    expect($result)
        ->not->toContain('<div')
        ->toContain('<p>text</p>');
});
