<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * The 'dispatch-capture' named rate limiter (registered in
 * DispatchServiceProvider::registerRateLimiters(), attached via
 * `throttle:dispatch-capture` middleware to POST /capture and POST /attachments
 * in routes/web.php) reads `dispatch.capture.throttle` at REQUEST time rather
 * than at route-registration time, so flipping the config value inside a test
 * body (well after the app/routes have booted) takes effect on the very next
 * request — no re-registration or app reboot needed.
 */

function throttleCaptureUser(): \Illuminate\Contracts\Auth\Authenticatable
{
    return new class extends \Illuminate\Foundation\Auth\User
    {
        protected $attributes = ['id' => 501];
    };
}

test('the capture endpoint 429s once the configured limiter is exceeded', function () {
    config(['dispatch.capture.throttle' => '2,1']); // 2 requests per minute

    $this->actingAs(throttleCaptureUser())->withoutMiddleware(ValidateCsrfToken::class);

    $payload = ['title' => 'Throttle probe', 'type' => 'bug'];

    $this->post('/dispatch/capture', $payload)->assertCreated();
    $this->post('/dispatch/capture', $payload)->assertCreated();
    $this->post('/dispatch/capture', $payload)->assertStatus(429);
});

test('a null throttle config leaves the capture endpoint unlimited', function () {
    config(['dispatch.capture.throttle' => null]);

    $this->actingAs(throttleCaptureUser())->withoutMiddleware(ValidateCsrfToken::class);

    $payload = ['title' => 'Unlimited probe', 'type' => 'bug'];

    foreach (range(1, 5) as $i) {
        $this->post('/dispatch/capture', $payload)->assertCreated();
    }
});
