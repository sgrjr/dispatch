<?php

use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Support\AssignableUsers;
use Sgrjr\Dispatch\Tests\Fixtures\User as FixtureUser;

beforeEach(function () {
    dispatchFakeUsers();
});

test('with no config the pool is the whole user table, name-ordered', function () {
    dispatchMakeUser(1, ['name' => 'Zed', 'email' => 'zed@anywhere.test']);
    dispatchMakeUser(2, ['name' => 'Amy', 'email' => 'amy@elsewhere.test']);

    $options = AssignableUsers::options();

    expect($options->pluck('name')->all())->toBe(['Amy', 'Zed']);
});

test('email_domains filters the pool to staff domains', function () {
    dispatchMakeUser(1, ['name' => 'Staff A', 'email' => 'a@centerpointlargeprint.com']);
    dispatchMakeUser(2, ['name' => 'Import Noise', 'email' => 'jdoe@cp-missing-email.com']);
    dispatchMakeUser(3, ['name' => 'Staff B', 'email' => 'b@centerpointlargeprint.com']);

    config(['dispatch.assignees.email_domains' => ['centerpointlargeprint.com']]);

    $options = AssignableUsers::options();

    expect($options->pluck('name')->all())->toBe(['Staff A', 'Staff B']);
});

test('multiple domains union, and a leading @ or whitespace on the config value is tolerated', function () {
    dispatchMakeUser(1, ['email' => 'a@one.test']);
    dispatchMakeUser(2, ['email' => 'b@two.test']);
    dispatchMakeUser(3, ['email' => 'c@three.test']);

    config(['dispatch.assignees.email_domains' => ['@one.test', ' two.test ']]);

    expect(AssignableUsers::options()->pluck('id')->all())->toBe([1, 2]);
});

test('the domain match anchors on @ — a lookalike suffix in the local part does not leak through', function () {
    dispatchMakeUser(1, ['email' => 'real@staff.test']);
    // The domain string appears in the address but NOT as the domain.
    dispatchMakeUser(2, ['email' => 'staff.test@evil.example']);

    config(['dispatch.assignees.email_domains' => ['staff.test']]);

    expect(AssignableUsers::options()->pluck('id')->all())->toBe([1]);
});

test('LIKE wildcards in a configured domain are escaped, not interpreted', function () {
    dispatchMakeUser(1, ['email' => 'a@staff.test']);
    dispatchMakeUser(2, ['email' => 'b@stuff.test']);

    // `_` would match any char un-escaped, making this swallow both.
    config(['dispatch.assignees.email_domains' => ['st_ff.test']]);

    expect(AssignableUsers::options())->toHaveCount(0);
});

test('a resolver class overrides everything, including email_domains', function () {
    dispatchMakeUser(1, ['name' => 'Resolved', 'email' => 'r@x.test']);
    dispatchMakeUser(2, ['name' => 'Excluded', 'email' => 'e@x.test']);

    config([
        'dispatch.assignees.email_domains' => ['never-consulted.test'],
        'dispatch.assignees.resolver' => AssignableUsersTestResolver::class,
    ]);

    expect(AssignableUsers::options()->pluck('name')->all())->toBe(['Resolved']);
});

test('a resolver that returns a non-Builder fails loud', function () {
    config(['dispatch.assignees.resolver' => AssignableUsersTestBadResolver::class]);

    expect(fn () => AssignableUsers::query())->toThrow(RuntimeException::class, 'Eloquent Builder');
});

test('the limit caps the option list and 0 lifts the cap', function () {
    foreach (range(1, 5) as $i) {
        dispatchMakeUser($i);
    }

    config(['dispatch.assignees.limit' => 3]);
    expect(AssignableUsers::options())->toHaveCount(3);

    config(['dispatch.assignees.limit' => 0]);
    expect(AssignableUsers::options())->toHaveCount(5);
});

class AssignableUsersTestResolver
{
    public function __invoke(): Builder
    {
        return FixtureUser::query()->where('name', 'Resolved');
    }
}

class AssignableUsersTestBadResolver
{
    public function __invoke(): array
    {
        return [];
    }
}
