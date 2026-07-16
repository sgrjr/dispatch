<?php

namespace Sgrjr\Dispatch\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Minimal notifiable User for feature tests that exercise watchers and
 * notifications (the package's own user model is the host app's, absent under
 * Testbench). Point `dispatch.models.user` here via the dispatchFakeUsers()
 * helper in tests/Pest.php.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}
