<?php

/**
 * Front-end regression check.
 *
 * The original failure was silent: the page returned HTTP 200 with no styling
 * because the `@vite` directive was never compiled. So this asserts on the
 * rendered markup and fetches the referenced files — not on the status code.
 *
 * Run: php tools/check-assets.php
 */
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

$failures = 0;

$report = function (string $label, bool $ok, string $detail = '') use (&$failures) {
    printf("  %-4s %-40s %s\n", $ok ? 'OK' : 'FAIL', $label, $detail);
    if (! $ok) {
        $failures++;
    }
};

echo "=== @vite directive ===\n";

$html = Blade::render("@vite(['resources/css/app.css', 'resources/js/app.js'])");

$report('no literal text left in output', ! str_contains($html, '@vite('), '');
$report('emits a stylesheet link', str_contains($html, '<link rel="stylesheet"'), '');
$report('emits a script', str_contains($html, '<script'), '');

echo "\n=== Referenced files exist ===\n";

preg_match_all('/(?:href|src)="([^"]+)"/', $html, $m);

foreach ($m[1] as $url) {
    $path = parse_url($url, PHP_URL_PATH);

    // Only check our own assets; remote CSS lives elsewhere.
    if (! str_contains($path, '/build/') && ! str_contains($path, '/@vite/') && ! str_contains($path, '/resources/')) {
        continue;
    }

    $file = public_path(ltrim($path, '/'));
    $onDisk = File::exists($file);
    $remote = Vite::devServerUrl() !== null;

    $report(
        basename($path),
        $onDisk || $remote,
        $onDisk ? number_format(File::size($file)) . ' bytes' : ($remote ? 'served by dev server' : 'MISSING ' . $path)
    );
}

echo "\n=== Manifest ===\n";

$manifest = json_decode(File::get(public_path('build/manifest.json')), true);

foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
    $ok = isset($manifest[$entry]['file']);
    $report($entry, $ok, $ok ? $manifest[$entry]['file'] : 'missing');
}

echo "\n=== Dev server ===\n";

printf("  public/hot:        %s\n", File::exists(public_path('hot')) ? 'present' : 'absent');
printf("  resolved mode:     %s\n", Vite::devServerUrl() ? 'dev server (' . Vite::devServerUrl() . ')' : 'built assets');

echo "\n=== Rendered page ===\n";

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/login', 'GET'));
$body = $response->getContent();

$report('GET /login', $response->getStatusCode() === 200, 'HTTP ' . $response->getStatusCode());
$report('no literal @vite in markup', ! str_contains($body, '@vite('), '');

$count = preg_match_all('/<(?:link[^>]+rel="stylesheet"|script[^>]+src=)[^>]*>/', $body, $tags);
$report('asset tags present', $count > 0, $count . ' tag(s)');

foreach ($tags[0] ?? [] as $tag) {
    echo '       ' . trim(preg_replace('/\s+/', ' ', $tag)) . "\n";
}

echo "\n=== Bundled JS is browser-executable ===\n";

/*
 * Regression guard for the bug that blanked the entire UI.
 *
 * resources/js/bootstrap.js used CommonJS `require()`. Vite does not polyfill
 * `require` — it emits the call verbatim into the browser bundle, so the entry
 * chunk threw "ReferenceError: require is not defined" as its first statement.
 * Alpine never started, every `x-cloak` element stayed hidden and every
 * `x-text` binding rendered empty. Nothing about the response looked wrong:
 * HTTP 200, correct CSS, correct markup. Only executing the bundle revealed it.
 *
 * So: assert the shipped bundle contains no CommonJS runtime calls, and that
 * Alpine and the app entry are actually present in it.
 */
$bundleRel = $manifest['resources/js/app.js']['file'] ?? null;

if ($bundleRel === null) {
    $report('js entry in manifest', false, 'missing');
} elseif (Vite::devServerUrl() !== null) {
    $report('js bundle scan', true, 'skipped (dev server serves unbundled modules)');
} else {
    $bundlePath = public_path('build/' . $bundleRel);
    $js = File::exists($bundlePath) ? File::get($bundlePath) : '';

    $report('js bundle on disk', $js !== '', $bundleRel);

    // `require(` / `module.exports` only appear in a CJS bundle; browser ESM
    // builds never contain them.
    $cjs = [];
    if (preg_match_all('/\brequire\s*\(/', $js, $m)) {
        $cjs[] = count($m[0]) . ' require(';
    }
    if (str_contains($js, 'module.exports')) {
        $cjs[] = 'module.exports';
    }

    $report('bundle is ESM (no CommonJS runtime)', $cjs === [], $cjs ? implode(', ', $cjs) : 'clean');
    $report('Alpine bundled', str_contains($js, 'Alpine') || str_contains($js, 'x-data'), '');
    $report('styles are styled (not raw)', File::exists(public_path('build/' . ($manifest['resources/css/app.css']['file'] ?? 'x'))), '');
}

echo "\n";
echo $failures ? "  FAILED ($failures)\n" : "  ALL GREEN\n";

exit($failures ? 1 : 0);
