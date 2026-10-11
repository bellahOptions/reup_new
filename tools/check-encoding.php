<?php

/**
 * Check that the files touched by a change are still clean.
 *
 * ## Why this exists next to tools/find-mojibake.php
 *
 * `find-mojibake.php` looks for UTF-8 bytes that were reinterpreted as
 * Windows-1252 — `₦` (E2 82 A6) read as `â`, `‚`, `¦`. That is the right check
 * for the damage it hunts, and it reports "0 file(s)" when there is none.
 *
 * What it also does is print `SKIP (would not decode cleanly)` for every file
 * that contains a *legitimate* multi-byte character, because decoding those
 * produces bytes that are not valid UTF-8. On this codebase that is dozens of
 * files — `₦` appears throughout the tests and the CLI tools — so the skip list
 * is noise, and the "0 file(s)" line is easy to read as a clean bill of health
 * when it means "nothing to report among the files that happened to decode".
 *
 * This is the narrower question a change actually needs answered: are the files I
 * just wrote valid UTF-8, do they carry a BOM, and do they contain a mojibake
 * sequence specifically? No BOM matters because a BOM before `<?php` is sent to
 * the browser as output and breaks `header()` calls; PowerShell's
 * `Set-Content -Encoding UTF8` adds one, which is exactly how it got into this
 * codebase once before.
 *
 * Usage: php tools/check-encoding.php [path ...]
 *        With no arguments it checks the files named in tools/encoding-targets.txt.
 */

$targets = array_slice($argv, 1);

if ($targets === []) {
    $list = __DIR__ . '/encoding-targets.txt';

    if (is_file($list)) {
        $targets = array_values(array_filter(array_map('trim', file($list)), fn ($line) => $line !== '' && $line[0] !== '#'));
    }
}

if ($targets === []) {
    fwrite(STDERR, "No files to check. Pass paths, or write them to tools/encoding-targets.txt.\n");
    exit(1);
}

// The same mapping find-mojibake.php uses: each cp1252 character and the byte it
// stands for, so a sequence can be named rather than guessed at.
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

for ($codepoint = 0xA0; $codepoint <= 0xFF; $codepoint++) {
    $cp1252[mb_chr($codepoint, 'UTF-8')] = chr($codepoint);
}

$problems = 0;
$checked = 0;

foreach ($targets as $target) {
    if (! is_file($target)) {
        printf("%-64s  <fg=red>MISSING</>\n", $target);
        $problems++;

        continue;
    }

    $checked++;
    $bytes = file_get_contents($target);
    $notes = [];

    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $notes[] = 'UTF-8 BOM (will be emitted before <?php)';
    }

    if (! mb_check_encoding($bytes, 'UTF-8')) {
        $notes[] = 'not valid UTF-8';
    } else {
        $found = [];

        foreach ($cp1252 as $char => $byte) {
            if (str_contains($bytes, $char)) {
                $found[] = sprintf('%s (U+%04X)', $char, mb_ord($char, 'UTF-8'));
            }
        }

        // A single cp1252 character is normal in prose — an em dash, a curly
        // quote — so only a *sequence* that decodes to valid UTF-8 is treated as
        // mojibake, which is what the fixer in find-mojibake.php relies on.
        if ($found !== [] && mb_check_encoding(strtr($bytes, $cp1252), 'UTF-8')) {
            $notes[] = 'mojibake: ' . implode(' ', array_slice($found, 0, 4));
        }
    }

    if ($notes !== []) {
        $problems++;
        printf("%-64s  %s\n", $target, implode('; ', $notes));

        continue;
    }

    // Non-ASCII is not a problem, but it is worth seeing: it is the thing that
    // breaks when a file is rewritten by a tool that does not preserve encoding.
    $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $bytes);

    printf(
        "%-64s  ok%s\n",
        $target,
        $nonAscii ? " ({$nonAscii} non-ASCII char(s))" : ''
    );
}

echo "\n{$checked} file(s) checked, {$problems} with a problem.\n";

exit($problems > 0 ? 1 : 0);
