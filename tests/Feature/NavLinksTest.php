<?php

use Sgrjr\Dispatch\Support\NavLinks;

/*
 * dispatch.nav.links — host-configured links in the layout's top navigation,
 * so a host ties Dispatch back into its own app without publishing the layout.
 */

beforeEach(fn () => dispatchFakeUsers());

test('configured nav links render in the layout navigation', function () {
    config(['dispatch.nav.links' => [
        ['label' => 'Back to Chat', 'url' => '/chat?tasks=with_me', 'title' => 'Open your queue in chat'],
        ['label' => 'Docs', 'url' => 'https://docs.example.test', 'new_tab' => true],
    ]]);

    $this->actingAs(dispatchMakeUser(1))->get(route('dispatch.board'))
        ->assertOk()
        ->assertSee('Back to Chat')
        ->assertSee('href="/chat?tasks=with_me"', false)
        ->assertSee('title="Open your queue in chat"', false)
        ->assertSee('href="https://docs.example.test" class="dispatch-nav-host"', false)
        ->assertSee('target="_blank" rel="noopener"', false);
});

test('no configured links renders none', function () {
    config(['dispatch.nav.links' => []]);

    $this->actingAs(dispatchMakeUser(1))->get(route('dispatch.board'))
        ->assertOk()
        ->assertDontSee('dispatch-nav-host', false);
});

test('route links resolve by name; unregistered routes and malformed entries are skipped', function () {
    config(['dispatch.nav.links' => [
        ['label' => 'Board again', 'route' => 'dispatch.board'],
        ['label' => 'Ghost', 'route' => 'no.such.route'],
        ['label' => '', 'url' => '/nowhere'],
        ['label' => 'No target'],
        'not-an-array',
    ]]);

    $links = NavLinks::resolve(true);

    expect($links)->toHaveCount(1)
        ->and($links[0]['label'])->toBe('Board again')
        ->and($links[0]['href'])->toBe(route('dispatch.board'));
});

test('staff_only links are hidden from non-staff', function () {
    config(['dispatch.nav.links' => [
        ['label' => 'Staff tools', 'url' => '/staff', 'staff_only' => true],
        ['label' => 'Help', 'url' => '/help'],
    ]]);

    expect(array_column(NavLinks::resolve(false), 'label'))->toBe(['Help'])
        ->and(array_column(NavLinks::resolve(true), 'label'))->toBe(['Staff tools', 'Help']);
});
