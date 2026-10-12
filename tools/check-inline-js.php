<?php

/**
 * Prove the inline scripts in a rendered page are syntactically valid JavaScript.
 *
 * ## Why this is worth a tool
 *
 * The airtime page is eight hundred lines of Blade with a `<script>` block inside it,
 * and Blade's directives are compiled before the browser ever sees the file. A
 * mismatched brace, a stray `@if` that compiles into a PHP error, or an edit that
 * lands inside the wrong function is **invisible from PHP** — the page renders, the
 * status is 200, and the only symptom is that part of the page stops responding.
 *
 * That is not hypothetical. A guard added to `checkAirtimeBalance()` in this session
 * left the surrounding function unbalanced; the page still rendered and every
 * server-side test still passed, because nothing server-side was wrong. This catches
 * it by extracting each inline script and handing it to Node's own parser.
 *
 * The rendered HTML is taken from a real request, so what is checked is what the
 * browser is actually given — after Blade, after the components, after everything.
 *
 * Usage: php tools/check-inline-js.php <url-path> [<url-path> ...]
 *        php tools/check-inline-js.php --all
 *
 * The database is whatever `.env` points at, but the session driver matters: this
 * renders pages through the HTTP kernel, and a `database` session driver needs a
 * `sessions` table the development database may not have. If it fails with
 * "Table ... sessions doesn't exist", run it as:
 *
 *     SESSION_DRIVER=file php tools/check-inline-js.php --all
 *
 * (On Windows, `$env:SESSION_DRIVER='file'; php tools/check-inline-js.php --all`.)
 * That is the same class of ambient-environment problem `phpunit.xml` now pins with
 * `force="true"` — a sibling project exporting `SESSION_DRIVER=database`
 * process-wide.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;

$paths = array_slice($argv, 1);

if ($paths === []) {
    fwrite(STDERR, "Usage: php tools/check-inline-js.php <url-path> [...]\n");
    fwrite(STDERR, "       php tools/check-inline-js.php --all\n");
    exit(1);
}

if ($paths === ['--all']) {
    $paths = [
        '/airtime',
        '/dashboard',
        '/wallet',
        '/wallet/fund',
        '/profile',
        '/admin/dashboard',
        '/admin/profit',
        '/admin/users',
        '/admin/pricing',
        '/admin/pricing/costs',
        '/admin/providers',
    ];
}

/*
 * A signed-in customer and a signed-in super admin, because most of these paths are
 * behind `auth` and one of them behind `admin`.
 */
$customer = User::where('is_admin', false)->first();
$admin = User::where('is_super_admin', true)->first();

if (! $customer || ! $admin) {
    fwrite(STDERR, "Needs at least one customer and one super admin in the database.\n");
    exit(1);
}

$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$failures = 0;
$checked = 0;

foreach ($paths as $path) {
    $actor = str_starts_with($path, '/admin') ? $admin : $customer;

    auth()->login($actor);

    $response = $kernel->handle(Request::create($path, 'GET'));

    $html = (string) $response->getContent();

    if ($response->getStatusCode() !== 200) {
        printf("%-28s HTTP %d — skipped\n", $path, $response->getStatusCode());
        auth()->logout();

        continue;
    }

    // Every inline <script> that is not loading an external file.
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $matches);

    if ($matches[1] === []) {
        printf("%-28s no inline script\n", $path);
        auth()->logout();

        continue;
    }

    foreach ($matches[1] as $index => $script) {
        $checked++;

        $file = tempnam(sys_get_temp_dir(), 'reup-js-') . '.js';
        file_put_contents($file, $script);

        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        @unlink($file);

        if ($exit !== 0) {
            $failures++;

            printf(
                "%-28s script #%d FAILED\n%s\n",
                $path,
                $index + 1,
                '    ' . implode("\n    ", array_slice($output, 0, 6))
            );
        }

        $output = [];
    }

    printf("%-28s %d inline script(s) ok\n", $path, count($matches[1]));

    auth()->logout();
}

echo "\n{$checked} inline script(s) checked, {$failures} with a syntax error.\n";

exit($failures > 0 ? 1 : 0);
