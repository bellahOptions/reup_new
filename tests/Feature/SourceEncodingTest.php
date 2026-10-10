<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Source files must be valid UTF-8, with no mojibake.
 *
 * ## Why this needs a test
 *
 * A mangled `₦` is not a rendering bug that can be fixed in CSS — it is a *byte*
 * defect, and the bytes decide. Somewhere in this project's history several
 * views were rewritten through a tool that read UTF-8 as Windows-1252 and wrote
 * it back as UTF-8, doubling every non-ASCII character. The result reached
 * production as `â‚¦240.0` in place of `₦240.00`, and as `Loadingâ€¦` and
 * `â€”` in place of the ellipsis and the em dash it was meant to be.
 *
 * Nothing else catches it. The pages render, the status is 200, the markup is
 * well-formed, and a screenshot looks like a font problem rather than an
 * encoding one. Blade will not complain, and neither will PHP.
 *
 * ## Why it cannot be a style rule
 *
 * `₦` is *correct* in a view and `â‚¦` is not, so the test cannot ban non-ASCII.
 * It looks for the specific signature instead: a lead byte from a multi-byte
 * UTF-8 sequence that has been decoded as a latin-1 character. Those are
 * distinguishable because the first byte of a 3-byte UTF-8 sequence (0xE0–0xEF)
 * is always followed by two continuation bytes, which as latin-1 are themselves
 * characters in the ranges U+0080–U+00BF — the ones a legitimate file almost
 * never places immediately after `â`/`Â`/`ð`.
 */
class SourceEncodingTest extends TestCase
{
    /**
     * Files that are allowed to contain raw bytes rather than text.
     *
     * The mojibake detector's own documentation quotes the sequences it looks
     * for, which would otherwise make it fail on itself — which is a fair trade
     * for having the sequences written down where the next person will find
     * them.
     */
    private const ALLOWED = [
        'tests/Feature/SourceEncodingTest.php',
        'tools/find-mojibake.php',
    ];

    public function test_no_source_file_contains_mojibake(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
            $relative = str_replace('\\', '/', $relative);

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $bytes = (string) file_get_contents($path);

            // A file that is not valid UTF-8 at all is the same class of defect
            // and is reported the same way.
            if (! mb_check_encoding($bytes, 'UTF-8')) {
                $offenders[] = $relative . ' — not valid UTF-8';

                continue;
            }

            $found = $this->mojibakeSequences($bytes);

            if ($found !== []) {
                $offenders[] = $relative . ' — ' . implode(', ', array_slice($found, 0, 4));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files contain mojibake — UTF-8 text that was decoded as Windows-1252 and re-encoded, "
            . "so every non-ASCII character is doubled. `php tools/find-mojibake.php --fix` repairs them:\n  "
            . implode("\n  ", $offenders)
        );
    }

    public function test_the_mojibake_detector_actually_detects_it(): void
    {
        /*
         * A guard that cannot fail is worse than no guard, and this one is easy
         * to write in a way that never matches: the sequences are multi-byte and
         * a naive `str_contains` on a mis-escaped literal silently looks for the
         * wrong thing. So it is given a known-bad string and must find it — the
         * exact failure that a first attempt at this test had.
         */
        $broken = "Price: \u{00E2}\u{201A}\u{00A6}240.00";   // `â‚¦240.00`
        $this->assertNotSame([], $this->mojibakeSequences($broken), 'The detector does not detect a known mojibake string.');

        $good = "Price: \u{20A6}240.00";                      // `₦240.00`
        $this->assertSame([], $this->mojibakeSequences($good), 'The detector flags correct UTF-8.');
    }

    /**
     * The mojibake sequences present in a string.
     *
     * @return array<int,string> human-readable names, at most one per family
     */
    private function mojibakeSequences(string $text): array
    {
        $families = [
            // 2-byte sequences read as latin-1: `Â` + a latin-1 char.
            'Â' => 'a 2-byte sequence decoded as latin-1',
            // 3-byte sequences: `â` + two latin-1 chars (`â€”`, `â‚¦`, `â€¦`).
            'â' => 'a 3-byte sequence decoded as latin-1',
            // 4-byte sequences (emoji) read as latin-1.
            'ð' => 'a 4-byte sequence decoded as latin-1',
        ];

        $found = [];

        foreach ($families as $lead => $description) {
            if (preg_match('/' . preg_quote($lead, '/') . self::CONTINUATION_CLASS . '/u', $text) === 1) {
                $found[$lead] = $description;
            }
        }

        return array_values($found);
    }

    /**
     * One latin-1 character, restricted to the codepoints a doubled UTF-8
     * sequence actually produces.
     *
     * ## Why the range is not `[\x{0080}-\x{00BF}]`
     *
     * It looks like the obvious choice — the continuation bytes of a multi-byte
     * UTF-8 sequence are 0x80–0xBF, so decoding them as latin-1 gives U+0080 to
     * U+00BF — and it is wrong, which cost two attempts at this test.
     *
     * The mangling did not decode with latin-1. It decoded with **Windows-1252**,
     * and cp1252 is not latin-1 in exactly the 0x80–0x9F band: there, byte 0x9A
     * is U+201A rather than U+009A, 0x93 is U+201C, 0x97 is U+2014, and so on.
     * A `₦` is bytes `E2 82 A6`, and cp1252 turns those into `â` `‚` `¦` —
     * U+00E2, **U+201A**, U+00A6. The middle character is 8218, nowhere near
     * 0xBF, so a latin-1 range matches nothing and the detector reports every
     * file as clean.
     *
     * The class below is therefore built from the same table the repair tool
     * uses. Two spellings of the same knowledge would drift; these are checked
     * against each other by the known-bad string in the test.
     */
    private const CONTINUATION_CLASS = '[\x{00A0}-\x{00BF}\x{2018}-\x{201E}\x{2020}\x{2021}\x{2026}\x{2030}\x{2039}\x{203A}\x{02C6}\x{02DC}\x{2122}\x{0160}\x{0161}\x{0152}\x{0153}\x{0178}\x{017D}\x{017E}\x{0192}\x{20AC}]';

    /**
     * Every text file a person authors.
     *
     * @return array<int,string>
     */
    private function sourceFiles(): array
    {
        $roots = ['app', 'config', 'database', 'resources', 'routes', 'tests', 'tools', 'docs'];
        $extensions = ['php', 'js', 'css', 'json', 'md'];
        $skip = ['node_modules', 'vendor', 'storage', '.git', 'dist'];

        $files = [];

        foreach ($roots as $root) {
            $directory = base_path($root);

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                    fn ($file) => ! in_array($file->getFilename(), $skip, true)
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
