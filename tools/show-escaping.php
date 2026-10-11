<?php

/**
 * Print how Blade encodes a customer name.
 *
 * `{{ }}` is the escaping guarantee every view in this application relies on, and
 * the *form* of the encoding is not obvious: an apostrophe may come out as
 * `&#039;`, as `&apos;`, or unchanged, depending on the PHP build's
 * `htmlspecialchars` flags. A test that pins the wrong one fails for a reason
 * unrelated to escaping, so this prints what actually happens here.
 *
 * Read-only. Usage: php tools/show-escaping.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = "Ada O'Brien <b>x</b> & \"quoted\"";

echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
echo 'input:       ' . $name . PHP_EOL . PHP_EOL;
echo 'e() output:  ' . e($name) . PHP_EOL . PHP_EOL;

// The flags `e()` uses, so the difference between `&#039;` and `&apos;` is
// visible rather than guessed at.
$flags = ENT_QUOTES | ENT_SUBSTITUTE;

echo 'htmlspecialchars with ENT_QUOTES only:     ' . htmlspecialchars($name, $flags, 'UTF-8') . PHP_EOL;
echo 'htmlspecialchars with HTML5:              ' . htmlspecialchars($name, $flags | ENT_HTML5, 'UTF-8') . PHP_EOL;
echo 'translation table for an apostrophe:      ' . (get_html_translation_table(HTML_ENTITIES, $flags, 'UTF-8')["'"] ?? '(none)') . PHP_EOL;

echo PHP_EOL . 'A blade file with the name in an attribute and as text:' . PHP_EOL;

$path = resource_path('views/__escaping-probe.blade.php');
file_put_contents($path, '<span title="{{ $name }}">{{ $name }}</span>');

try {
    echo (string) view('__escaping-probe', ['name' => $name]) . PHP_EOL;
} finally {
    @unlink($path);
}
