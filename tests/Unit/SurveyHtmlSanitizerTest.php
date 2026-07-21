<?php

use Lalalili\SurveyCore\Support\SurveyHtmlSanitizer;

function sanitize(string $html): string
{
    return (new SurveyHtmlSanitizer)->clean($html) ?? '';
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

it('keeps supported text alignment on paragraphs and headings', function () {
    expect(sanitize('<p style="text-align: center">Centered</p><h2 style="TEXT-ALIGN:RIGHT">Right</h2><h4 style="text-align:left">Left</h4>'))
        ->toBe('<p style="text-align: center">Centered</p><h2 style="text-align: right">Right</h2><h4 style="text-align: left">Left</h4>');
});

it('keeps six digit hex colors on spans', function () {
    expect(sanitize('<span style="color: #EF4444">Red</span>'))
        ->toBe('<span style="color: #ef4444">Red</span>');
});

it('normalizes browser rgb colors to six digit hex colors', function () {
    expect(sanitize('<span style="color: rgb(249, 115, 22)">Orange</span>'))
        ->toBe('<span style="color: #f97316">Orange</span>');
});

it('removes unsupported css while retaining independently valid declarations', function () {
    $result = sanitize('<p class="x" onclick="bad()" style="background:url(javascript:bad); text-align:center; color:red">Text</p><span title="x" style="font-size:99px;color:#3B82F6;--x:expression(bad)">Blue</span>');

    expect($result)
        ->toBe('<p style="text-align: center">Text</p><span style="color: #3b82f6">Blue</span>')
        ->not->toContain('javascript:')
        ->not->toContain('expression')
        ->not->toContain('class=')
        ->not->toContain('onclick=');
});

it('rejects unsupported alignment and color values', function (string $html) {
    expect(sanitize($html))->not->toContain('style=');
})->with([
    'justify alignment' => '<p style="text-align: justify">Text</p>',
    'alignment expression' => '<h3 style="text-align: expression(alert(1))">Text</h3>',
    'named color' => '<span style="color: red">Text</span>',
    'out of range rgb color' => '<span style="color: rgb(256, 0, 0)">Text</span>',
    'percentage rgb color' => '<span style="color: rgb(100%, 0%, 0%)">Text</span>',
    'rgb color with alpha' => '<span style="color: rgba(255, 0, 0, 0.5)">Text</span>',
    'short hex color' => '<span style="color: #fff">Text</span>',
    'css variable color' => '<span style="color: var(--danger)">Text</span>',
]);

it('keeps variable token attributes and a valid color while removing other attributes', function () {
    $result = sanitize('<span class="survey-variable-token" data-variable-token="{{ calc.score }}" data-variable-label="Score" contenteditable="false" onclick="bad()" style="color:#8B5CF6;background:url(evil)">Score<code>calc.score</code></span>');

    expect($result)
        ->toContain('class="survey-variable-token"')
        ->toContain('data-variable-token="{{ calc.score }}"')
        ->toContain('data-variable-label="Score"')
        ->toContain('style="color: #8b5cf6"')
        ->not->toContain('contenteditable')
        ->not->toContain('onclick')
        ->not->toContain('background');
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

it('allows img with http src used by non-TLS builder environments', function () {
    $result = sanitize('<img src="http://54.249.44.62:8443/storage/survey-builder/welcome.jpg" alt="photo">');

    expect($result)
        ->toContain('<img')
        ->toContain('src="http://54.249.44.62:8443/storage/survey-builder/welcome.jpg"');
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
