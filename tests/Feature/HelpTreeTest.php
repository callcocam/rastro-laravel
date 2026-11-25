<?php

use Callcocam\Rastro\Console\HelpGuide;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * The help tree written by the site's own coding agent: the guide it
 * follows, and the file it writes sent to the central.
 */
beforeEach(function () {
    $this->base = base_path('help-fixture');
    File::deleteDirectory($this->base);
    File::ensureDirectoryExists($this->base);
    $this->app->setBasePath($this->base);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('puts the guide for the coding agent in the project, without overwriting one already there', function () {
    $this->artisan('rastro:help-guide')->assertSuccessful();

    $guide = File::get(base_path(HelpGuide::FILE));
    expect($guide)->toContain('rastro-ajuda.json')->toContain('"topics"')->toContain('rastro:help-push');

    File::put(base_path(HelpGuide::FILE), 'meu guia');
    $this->artisan('rastro:help-guide')->assertSuccessful();
    expect(File::get(base_path(HelpGuide::FILE)))->toBe('meu guia');

    $this->artisan('rastro:help-guide --force')->assertSuccessful();
    expect(File::get(base_path(HelpGuide::FILE)))->toContain('rastro-ajuda.json');
});

it('sends the tree to the central with the site key and says what came in', function () {
    Http::fake(['central.test/*' => Http::response(['created' => 3, 'updated' => 1, 'kept' => 2])]);
    $tree = ['topics' => [['label' => 'Acesso', 'children' => [['label' => 'Esqueci minha senha', 'answer' => 'Clique em "Esqueci".']]]]];
    File::put(base_path('rastro-ajuda.json'), json_encode($tree));

    $this->artisan('rastro:help-push')
        ->expectsOutputToContain('3')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://central.test/support/api/help/import'
        && $request->hasHeader('Authorization', 'Bearer central-key')
        && $request['topics'][0]['children'][0]['label'] === 'Esqueci minha senha');
});

it('shows each problem the central found, and stops before sending a file that is not a tree', function () {
    Http::fake(['central.test/*' => Http::response(['message' => 'x', 'errors' => ['topics.0.answer' => ['O campo é obrigatório.']]], 422)]);
    File::put(base_path('rastro-ajuda.json'), json_encode(['topics' => [['label' => 'A']]]));

    $this->artisan('rastro:help-push')
        ->expectsOutputToContain('topics.0.answer: O campo é obrigatório.')
        ->assertFailed();

    File::put(base_path('rastro-ajuda.json'), 'não é json');
    $this->artisan('rastro:help-push')->assertFailed();

    File::delete(base_path('rastro-ajuda.json'));
    $this->artisan('rastro:help-push')->assertFailed();

    Http::assertSentCount(1);
});
