<?php

/**
 * Report every mojibake sequence in the source tree, with its decoded meaning.
 *
 * Mojibake is systematic: UTF-8 bytes reinterpreted as Windows-1252. `₦` is
 * E2 82 A6, and reading those three bytes as cp1252 gives `â` `‚` `¦`. So the
 * damage is reversible exactly, and the fix is a byte-level replacement rather
 * than a judgement call per string.
 *
 * Usage: php tools/find-mojibake.php [--fix]
 */

$fix = in_array('--fix', $argv, true);

$roots = ['app', 'resources', 'config', 'routes', 'database', 'tests', 'tools', 'public', 'docs'];

// The cp1252 characters, and the byte each one stands for. Written as
// codepoints and converted with mb_chr, because a literal here would itself be
// at the mercy of the file's encoding — which is the whole problem being fixed.
$cp1252 = [];
foreach ([
    0x20AC => "\x80", 0x201A => "\x82", 0x0192 => "\x83", 0x201E => "\x84",
    0x2026 => "\x85", 0x2020 => "\x86", 0x2021 => "\x87", 0x02C6 => "\x88",
    0x2030 => "\x89", 0x0160 => "\x8A", 0x2039 => "\x8B", 0x0152 => "\x8C",
    0x017D => "\x8E", 0x2018 => "\x91", 0x2019 => "\x92", 0x201C => "\x93",
    0x201D => "\x94", 0x2022 => "\x95", 0x2013 => "\x96", 0x2014 => "\x97",
    0x02DC => "\x98", 0x2122 => "\x99", 0x0161 => "\x9A", 0x203A => "\x9B",
    0x0153 => "\x9C", 0x017E => "\x9E", 0x0178 => "\x9F",
] as $codepoint => $byte) {
    $cp1252[mb_chr($codepoint, 'UTF-8')] = $byte;
}

/*
 * U+00A0 … U+00FF map straight back to bytes 0xA0 … 0xFF, and this half is the
 * one that is easy to miss. Only the 0x80–0x9F range differs between cp1252 and
 * latin-1, so `â` is plain byte 0xE2 rather than a cp1252 speciality. Without
 * these entries a `₦` — bytes E2 82 A6, read as `â` `‚` `¦` — decodes only two
 * thirds of the way, and the result is still invalid UTF-8, which the guard
 * below then skips in silence.
 */
for ($codepoint = 0xA0; $codepoint <= 0xFF; $codepoint++) {
    $cp1252[mb_chr($codepoint, 'UTF-8')] = chr($codepoint);
}

$report = [];
$fixed = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(getcwd(), FilesystemIterator::SKIP_DOTS),
        function ($file) {
            return ! in_array($file->getFilename(), ['node_modules', 'vendor', 'storage', '.git', 'dist'], true);
        }
    )
);

foreach ($iterator as $file) {
    if (! $file->isFile()) {
        continue;
    }

    if (! in_array(strtolower($file->getExtension()), ['php', 'blade.php', 'js', 'css', 'json', 'md'], true)) {
        continue;
    }

    $bytes = file_get_contents($file->getPathname());

    // Only consider files that are valid UTF-8; anything else is a different
    // problem and must not be silently rewritten.
    if (! mb_check_encoding($bytes, 'UTF-8')) {
        continue;
    }

    $found = [];

    foreach ($cp1252 as $char => $byte) {
        if (str_contains($bytes, $char)) {
            $found[] = $char;
        }
    }

    if (! $found) {
        continue;
    }

    // Decode: each cp1252 character becomes its original byte, which together
    // are the UTF-8 the file was always meant to hold.
    $decoded = strtr($bytes, $cp1252);

    if (! mb_check_encoding($decoded, 'UTF-8')) {
        fwrite(STDERR, "SKIP (would not decode cleanly): {$file->getPathname()}\n");
        continue;
    }

    $report[$file->getPathname()] = $found;

    if ($fix && $decoded !== $bytes) {
        file_put_contents($file->getPathname(), $decoded);
        $fixed[] = $file->getPathname();
    }
}

$root = getcwd() . DIRECTORY_SEPARATOR;

foreach ($report as $path => $chars) {
    $shown = array_map(fn ($c) => sprintf('%s (U+%04X)', $c, mb_ord($c, 'UTF-8')), array_slice($chars, 0, 6));
    printf("%-72s %s\n", str_replace($root, '', $path), implode(' ', $shown));
}

echo "\n" . count($report) . " file(s) contain mojibake.\n";

if ($fix) {
    echo count($fixed) . " file(s) repaired.\n";
} else {
    echo "Re-run with --fix to repair them.\n";
}
