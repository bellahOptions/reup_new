<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Services\ClubKonnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

/**
 * Upload/download hardening, secret redaction and the browser-header baseline.
 *
 * These assert behaviour, not status codes: that nothing was written to a
 * disk, that a stored file carries a framework-generated name, that the
 * private copy has no public URL, and that a configured credential appears in
 * neither a response body nor a log record.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const AVATAR_PAYLOAD = 'photo.jpg';

    /** Temp files created for "real bytes, hostile name" uploads. */
    private array $tempUploads = [];

    protected function tearDown(): void
    {
        foreach ($this->tempUploads as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempUploads = [];

        parent::tearDown();
    }

    /* =====================================================================
     | A. Uploads — what may be stored, and where
     |=================================================================== */

    public function test_an_upload_never_keeps_the_client_supplied_extension(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        // Real JPEG bytes, hostile *name*. `.html` is not on Laravel's
        // php-blocklist and `mimes:` only inspects the content-derived
        // extension, so this upload passed validation before — and was then
        // stored verbatim as `profiles/profile-<id>-<time>.html` on the public
        // disk, where the web server serves it as text/html (stored XSS).
        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture' => $this->jpegNamed('avatar.html'),
        ]);

        $response->assertSessionHasNoErrors();

        $stored = (string) $user->fresh()->profile_picture;
        $name = basename((string) parse_url($stored, PHP_URL_PATH));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.(jpg|jpeg|png|gif)$/', $name);
        $this->assertStringNotContainsString('html', $name);

        // The only file on either disk is the one the framework named.
        $this->assertSame(['avatars/' . $name], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_script_payload_renamed_to_an_image_is_rejected_and_nothing_is_written(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            // Claims image/jpeg on the wire; the content is a PHP script.
            'profile_picture' => UploadedFile::fake()->create('shell.jpg', 4, 'text/x-php'),
        ]);

        $response->assertSessionHasErrors('profile_picture');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($user->fresh()->profile_picture);
    }

    public function test_an_svg_upload_is_rejected_even_though_laravel_treats_svg_as_an_image(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        // `image` accepts SVG; the explicit mimetypes allowlist is what refuses
        // it, which matters because an SVG can carry script and is served from
        // our own origin.
        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ]);

        $response->assertSessionHasErrors('profile_picture');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_executable_extension_is_refused_even_with_image_content(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        foreach (['shell.phar', 'shell.phtml', 'shell.php'] as $name) {
            $this->actingAs($user)->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'profile_picture' => $this->jpegClaiming($name),
            ])->assertSessionHasErrors('profile_picture');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($user->fresh()->profile_picture);
    }

    public function test_an_oversized_upload_is_rejected_and_nothing_is_written(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        // 5 MB against a 2 MB cap.
        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture' => UploadedFile::fake()->create('huge.jpg', 5120, 'image/jpeg'),
        ]);

        $response->assertSessionHasErrors('profile_picture');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_accepted_avatar_is_private_and_has_no_public_url(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture' => UploadedFile::fake()->image(self::AVATAR_PAYLOAD),
        ])->assertSessionHasNoErrors();

        $url = (string) $user->fresh()->profile_picture;
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        $this->assertTrue(Storage::disk('local')->exists('avatars/' . $name));
        $this->assertFileDoesNotExist(public_path('storage/avatars/' . $name));

        // The public disk is untouched, so `storage:link` exposes nothing.
        $this->assertSame([], Storage::disk('public')->allFiles());

        // …and the guessed public URL is not a route at all.
        $this->get('/storage/avatars/' . $name)->assertNotFound();
    }

    public function test_an_avatar_is_served_only_to_its_owner_or_an_admin(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->put(route('profile.update'), [
            'name' => $owner->name,
            'email' => $owner->email,
            'profile_picture' => UploadedFile::fake()->image(self::AVATAR_PAYLOAD),
        ])->assertSessionHasNoErrors();

        $url = (string) $owner->fresh()->profile_picture;
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // A different signed-in customer gets a 403, not the image.
        $this->actingAs($other)->get($url)->assertForbidden();

        // An administrator may read it: the console lists avatars.
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();

        // A file name that is not the one stored for this user does not resolve.
        $this->actingAs($owner)
            ->get(route('profile.avatar', ['user' => $owner->id, 'file' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg']))
            ->assertNotFound();

        $this->assertTrue(Storage::disk('local')->exists('avatars/' . $name));
    }

    public function test_a_guest_cannot_read_an_avatar(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create(['profile_picture' => null]);

        $this->get(route('profile.avatar', ['user' => $user->id, 'file' => 'anything.jpg']))
            ->assertRedirect(route('login'));
    }

    public function test_a_bank_transfer_proof_is_private_and_the_admin_route_is_gated(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();
        $transfer = $this->pendingBankTransfer($user);

        $this->actingAs($user)->post(route('wallet.bank-transfer.submit-proof'), [
            'transaction_reference' => $transfer->reference,
            'proof' => UploadedFile::fake()->create('receipt.jpg', 64, 'image/jpeg'),
        ])->assertSessionHasNoErrors();

        $path = (string) $transfer->fresh()->meta['proof_path'];

        $this->assertStringStartsWith('payment-proofs/', $path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFileDoesNotExist(public_path('storage/' . $path));

        // The stream is admin-only: a signed-in customer is refused.
        $this->actingAs($user)
            ->get(route('admin.bank-transfers.proof.view', $transfer->id))
            ->assertForbidden();

        // An admin that holds the wallet permission may read it, and the
        // response never carries a client-supplied file name.
        $admin = User::factory()->admin(['manage_wallets'])->create();
        $this->actingAs($admin)
            ->get(route('admin.bank-transfers.proof.view', $transfer->id))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // The disposition is built from the stored, server-generated name —
        // never from anything the customer sent.
        $disposition = (string) $this->actingAs($admin)
            ->get(route('admin.bank-transfers.proof.view', $transfer->id))
            ->headers->get('Content-Disposition');

        $this->assertStringContainsString('proof-' . $transfer->reference, $disposition);
        $this->assertStringNotContainsString('receipt.jpg', $disposition);
    }

    public function test_a_guest_cannot_reach_a_proof_download(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $transfer = $this->pendingBankTransfer(User::factory()->create());

        foreach ([
            route('admin.bank-transfers.proof.view', $transfer->id),
            route('admin.bank-transfers.proof.download', $transfer->id),
        ] as $url) {
            $response = $this->get($url);

            // Whatever the guard order, a guest never receives the document;
            // they land on a sign-in screen.
            $response->assertRedirect();
            $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
        }
    }

    /* =====================================================================
     | C. Security headers
     |=================================================================== */

    public function test_the_baseline_headers_are_set_on_a_page_response(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('microphone=()', (string) $response->headers->get('Permissions-Policy'));

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        // Cloudflare's Web Analytics beacon is injected at the edge, not by
        // this repository, so the policy has to name its origin explicitly or
        // every production page logs a script-src violation. It belongs in
        // script-src only: the beacon POSTs to the same origin's /cdn-cgi/rum,
        // which `connect-src 'self'` already allows.
        $this->assertStringContainsString('https://static.cloudflareinsights.com', $csp);
        $this->assertStringNotContainsString('cloudflareinsights.com', $this->directive($csp, 'connect-src'));
        // Alpine evaluates expressions with `new Function`, and layouts use
        // inline bootstraps — both must stay permitted or the UI dies.
        $this->assertStringContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("'unsafe-inline'", $csp);
    }

    public function test_the_headers_are_also_present_on_a_json_response(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_absent_on_plain_http(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_only_over_https_in_production(): void
    {
        // The test client builds its request from an absolute URL, and Symfony
        // unsets the HTTPS server variable when that URL's scheme is http — so
        // a secure request is made by asking for the https:// URL explicitly.
        // Both URLs are captured up front: a later `route()` call would return
        // the *previous* request's scheme, not the one under test.
        $http = route('login');
        $https = preg_replace('#^http://#', 'https://', $http);

        // HTTPS but not production: still no HSTS, so a local install cannot
        // pin the developer's browser to a scheme it does not serve.
        $this->get($https)->assertHeaderMissing('Strict-Transport-Security');

        $this->app->detectEnvironment(fn () => 'production');

        $this->get($https)
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Production, but plain HTTP (a misconfigured proxy that is not on the
        // trusted list): still nothing, because HSTS is only meaningful once
        // the connection actually arrived over TLS.
        $this->get($http)->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_production_form_action_allows_the_owned_apex_and_www_origins(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $https = preg_replace('#^http://#', 'https://', route('login'));

        $response = $this->get($https)->assertOk();
        $formAction = $this->directive((string) $response->headers->get('Content-Security-Policy'), 'form-action');

        $this->assertStringContainsString("'self'", $formAction);
        $this->assertStringContainsString('https://reup.com.ng', $formAction);
        $this->assertStringContainsString('https://www.reup.com.ng', $formAction);
    }

    public function test_html_responses_get_the_current_application_csp_even_if_one_was_set_earlier(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        Route::get('/_test/stale-csp', function () {
            return response('<form method="POST" action="https://reup.com.ng/wallet/fund"></form>')
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Content-Security-Policy', "default-src 'self'; form-action 'self';");
        });

        $response = $this->get('/_test/stale-csp')->assertOk();
        $formAction = $this->directive((string) $response->headers->get('Content-Security-Policy'), 'form-action');

        $this->assertStringContainsString("'self'", $formAction);
        $this->assertStringContainsString('https://reup.com.ng', $formAction);
        $this->assertStringContainsString('https://www.reup.com.ng', $formAction);
    }

    /**
     * `form-action 'self'` is measured against the *page* origin, and a browser
     * counts the port as part of that origin. So an action built by
     * `route('wallet.process-funding')` — which Laravel 8 renders as an
     * absolute URL derived from APP_URL — is refused the moment APP_URL names
     * a different host or port than the one in the address bar, e.g.
     *
     *   Sending form data to 'http://127.0.0.1:8000/wallet/fund' violates the
     *   following Content Security Policy directive: "form-action 'self'".
     *
     * That is what happened on the fund-wallet page. The fix is for every form
     * action to be origin-relative (`route($name, $parameters, false)`), which
     * is also the only shape that survives being browsed at 127.0.0.1:8000,
     * localhost:8000 or a forwarded port.
     */
    public function test_the_wallet_funding_form_acts_on_the_current_origin(): void
    {
        $user = User::factory()->create();

        // The exact string the browser needs: no scheme, no host, no port.
        $this->assertSame(
            '/wallet/fund',
            route('wallet.process-funding', [], false),
            'The funding form action must be a path, not an absolute URL.'
        );

        $response = $this->actingAs($user)->get(route('wallet.fund'))->assertOk();

        $action = $this->formAction((string) $response->getContent(), 'fundWalletForm');

        $this->assertSame('/wallet/fund', $action);

        // Belt and braces: nothing on the page may post to an absolute URL at
        // all, whichever host APP_URL happens to name.
        foreach ($this->formActions((string) $response->getContent()) as $attribute) {
            $this->assertStringStartsWith(
                '/',
                $attribute,
                "Form action [{$attribute}] is absolute; form-action 'self' will block it off-origin."
            );

            foreach ([config('app.url'), 'localhost', '127.0.0.1'] as $host) {
                if (is_string($host) && $host !== '') {
                    $authority = rtrim((string) preg_replace('#^https?://#', '', $host), '/');

                    $this->assertStringNotContainsString(
                        $authority,
                        $attribute,
                        "Form action [{$attribute}] embeds the configured host, so it breaks whenever the page is reached on another origin."
                    );
                }
            }
        }
    }

    /**
     * The runtime assertions above only cover the page they render. This walks
     * every Blade template so a newly added `<form>` cannot quietly
     * reintroduce an absolute action that CSP will refuse.
     */
    public function test_no_blade_form_action_is_absolute(): void
    {
        $files = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'No Blade templates were found to scan.');

        $offenders = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            // Multi-line <form ...> tags included: the attributes between
            // `<form` and `>` are what matters.
            preg_match_all('/<form\b[^>]*>/i', $contents, $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('/\baction="([^"]*)"/i', $tag, $action)) {
                    continue;
                }

                $value = $action[1];

                // No action attribute at all, or an Alpine binding: the form
                // posts to the current URL, which is the origin by definition.
                if ($value === '' || str_contains($value, '{{') === false) {
                    continue;
                }

                if (! str_contains($value, 'route(')) {
                    $offenders[] = basename($file) . ': ' . $value;
                    continue;
                }

                // route('name', [], false) and route('name', $param, false) are
                // relative; route('name') and route('name', $param) are not.
                if (! preg_match('/route\([^)]*,\s*false\s*\)/', $value)
                    && ! preg_match('/route\([^)]*,\s*\[\],\s*false\s*\)/', $value)) {
                    $offenders[] = basename($file) . ': ' . $value;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These form actions are absolute; add a trailing `, false` to the route() call " .
            "so form-action 'self' cannot block them:\n  - " . implode("\n  - ", $offenders)
        );
    }

    /* =====================================================================
     | B. Secrets in responses and logs
     |=================================================================== */

    public function test_no_configured_secret_reaches_an_auth_or_payment_response(): void
    {
        $secret = 'sk_test_' . Str::random(24);

        config([
            'services.paystack.secret_key' => $secret,
            'services.clubkonnect.api_key' => $secret,
            'services.pairgate.api_key' => $secret,
            'services.pairgate.webhook_secret' => $secret,
        ]);

        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $this->get($url)->assertOk()->assertDontSee($secret, false);
        }

        // A correctly signed webhook must not echo the key it was signed with.
        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'NOSUCH-' . Str::random(8)],
        ]);

        $this->call('POST', route('paystack.webhook'), [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertDontSee($secret, false);

        // And neither must an unsigned one.
        $this->post(route('paystack.webhook'), ['event' => 'charge.success'])
            ->assertDontSee($secret, false);

        $this->post(route('pairgate.webhook'), ['reference' => 'NOSUCH'])
            ->assertDontSee($secret, false);
    }

    public function test_a_provider_credential_never_reaches_the_log_or_the_caller(): void
    {
        $apiKey = 'ck_live_' . Str::random(24);
        $clientId = 'ck-user-4242';

        config([
            'services.clubkonnect.api_key' => $apiKey,
            'services.clubkonnect.client_id' => $clientId,
        ]);

        $handler = $this->captureLogs();

        // The exact shape Laravel produces for a dead connection: the message
        // embeds the request URL, and the URL carries both credentials.
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 6: Could not resolve host: www.nellobytesystems.com (see '
                . 'https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '
                . 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp'
                . '?UserID=' . $clientId . '&APIKey=' . $apiKey
            ),
        ]);

        $this->app->forgetInstance(ClubKonnectService::class);

        $result = $this->app->make(ClubKonnectService::class)->get(
            'https://www.nellobytesystems.com/APIWalletBalanceV1.asp'
        );

        $this->assertSame('EXCEPTION', $result['status']);

        $logged = json_encode($handler->getRecords());

        $this->assertStringNotContainsString($apiKey, json_encode($result));
        $this->assertStringNotContainsString($apiKey, (string) $logged);
        $this->assertStringNotContainsString($clientId, (string) $logged);
    }

    public function test_a_customer_phone_number_is_masked_before_it_is_logged(): void
    {
        config([
            'services.clubkonnect.api_key' => 'ck_test_key',
            'services.clubkonnect.client_id' => 'ck-user-1',
        ]);

        $handler = $this->captureLogs();

        Http::fake(['*' => Http::response(['status' => 'ORDER_RECEIVED'], 200)]);

        $this->app->forgetInstance(ClubKonnectService::class);

        $this->app->make(ClubKonnectService::class)
            ->purchaseAirtime('01', '08031234567', 100.0, 'REQ-1');

        $logged = (string) json_encode($handler->getRecords());

        $this->assertStringNotContainsString('08031234567', $logged);
        // A tail is kept so support can correlate the request.
        $this->assertStringContainsString('4567', $logged);
    }

    public function test_the_transaction_pin_and_one_time_code_are_not_flashed_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.pin'), [
            'current_pin' => '1111',
            'pin' => '2468',
            'pin_code' => 'not-a-code',
        ])->assertSessionHasErrors('pin_code');

        $old = (array) session('_old_input');

        $this->assertArrayNotHasKey('pin', $old);
        $this->assertArrayNotHasKey('pin_code', $old);
        $this->assertArrayNotHasKey('current_pin', $old);
    }

    /* =====================================================================
     | Helpers
     |=================================================================== */

    /** A real JPEG whose client-supplied name is whatever we like. */
    private function jpegNamed(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'reup-upload-');

        imagejpeg(imagecreatetruecolor(10, 10), $path);

        $this->tempUploads[] = $path;

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * A file that reports image/jpeg on the wire with an executable extension.
     *
     * The `image`/`mimes` rules reject the name before the content is looked at
     * (Laravel maintains a php-extension blocklist), which is the behaviour
     * these cases pin down.
     */
    private function jpegClaiming(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 4, 'image/jpeg');
    }

    /**
     * A single directive's value from a serialised CSP header, e.g.
     * `directive($csp, 'connect-src')` -> `" 'self' https://..."`. Empty when
     * the directive is absent, so a caller cannot accidentally match another
     * directive's origins.
     */
    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);

            if (str_starts_with($part, $name . ' ')) {
                return substr($part, strlen($name));
            }
        }

        return '';
    }

    /**
     * Every `action="..."` value rendered by a page's forms.
     *
     * @return array<int, string>
     */
    private function formActions(string $html): array
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]*)"/i', $html, $matches);

        return $matches[1];
    }

    /**
     * The action of one specific form, located by its id attribute.
     */
    private function formAction(string $html, string $id): string
    {
        preg_match('/<form\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/i', $html, $matches);

        $this->assertNotEmpty($matches, "No <form id=\"{$id}\"> was found in the response.");

        $this->assertSame(
            1,
            preg_match('/\baction="([^"]*)"/i', $matches[0], $action),
            "The <form id=\"{$id}\"> has no action attribute."
        );

        return $action[1];
    }

    private function pendingBankTransfer(User $user): Transactions
    {
        return Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding by bank transfer',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
    }

    /**
     * Route every log record into an in-memory handler so a test can assert on
     * what was written (Monolog's TestHandler keeps message *and* context).
     */
    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();

        Log::swap(new Logger(new MonologLogger('security-hardening-test', [$handler])));

        return $handler;
    }
}
