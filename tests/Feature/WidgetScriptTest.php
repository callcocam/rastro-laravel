<?php

use Callcocam\Rastro\Widget;
use Illuminate\Support\Facades\Blade;

it('serves the compiled chat, cached for a year at its current version', function () {
    $response = $this->get('support/widget/rastro-widget.js?v='.Widget::version());

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertCookieMissing(config('session.cookie'));

    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('window.Rastro');
});

it('caches an old or missing version only for a few minutes', function () {
    expect($this->get('support/widget/rastro-widget.js?v=old')->headers->get('Cache-Control'))->toContain('max-age=300');
});

it('renders the tag with the current version and this site\'s relay', function () {
    $html = Blade::render('<x-rastro::widget />');

    expect($html)->toContain('support/widget/rastro-widget.js?v='.Widget::version())
        ->toContain('data-endpoint="http://localhost/support/widget"')
        ->toContain('data-navigate-once')
        ->not->toContain('data-storage-key');
});

it('passes another endpoint and storage key along', function () {
    $html = Blade::render('<x-rastro::widget endpoint="/ajuda/chat" storage-key="painel" />');

    expect($html)->toContain('data-endpoint="/ajuda/chat"')
        ->toContain('data-storage-key="painel"');
});
