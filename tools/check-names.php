<?php

/**
 * Exercise the name rules against a list of real and junk names.
 *
 * The heuristic in `App\Support\Name::reviewReasons()` is judged by its false
 * positives, and the only honest way to judge it is to run real names through it
 * and read the output. This does that, and it does it *read-only*, so it is safe
 * against production.
 *
 * It also runs every name already in the database through
 * `Name::validate()`, which is the question a deploy actually needs answered:
 * "could any existing customer have been rejected by this rule?" The answer must be
 * no — a validation that invalidates an account that already exists is a bug, not a
 * tightening.
 *
 * Usage: php tools/check-names.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\Name;

/** Real names this platform's customers plausibly have. None may be refused. */
$shouldPass = [
    'Ada', 'Obi Nwosu', 'Chidinma Nneka', "O'Brien", 'Al-Amin', 'J. R.',
    'Aïcha', 'Nguyễn', 'Ọ̀ṣun', 'Adéwálé', 'Ngozi', 'Adebayo', 'Okonkwo',
    'Nguyen', 'Nkrumah', 'Srdjan', 'Vlksic', 'Xi', 'Ng', 'Mwangi', 'Anas',
    'Testy', 'Dada', 'Annan', 'Bobbie', 'Lily', 'Adeyemi-Thomas', "D'Angelo",
    'van der Berg', 'de la Cruz', 'محمد', '张伟', 'יונתן', 'Öztürk', 'Müller',
    'Ægir', 'Þóra', 'Małgorzata', 'Šime', 'Đorđe', 'Naa', 'Yaa', 'Kofi',
];

/** Values that are not names and must be refused. */
$shouldFail = [
    'Ada1', '123', 'User123', 'Ada 😀', '🎉', '<script>alert(1)</script>',
    '-Ada', 'Ada_', 'Ada@example.com', 'Ada/Obi', 'Ada#1', 'Ada%',
    '<b>Ada</b>', 'Ada|Obi', 'Ada=1', '   ', '...', '--', '😀😀',
];

/*
 * Values that *look* like they should be refused and are not: they are cleaned
 * instead, and the cleaned value is a name.
 *
 * This is a deliberate choice, not a gap. A control character or a zero-width space
 * in a name is almost never deliberate — it arrives from a copy-paste out of a
 * styled document or a phone keyboard — so accepting "Ada" and storing "Ada" is
 * better than refusing somebody for a character they cannot see. The test below
 * asserts the *stored* value, not just the acceptance.
 */
$cleanedNotRefused = [
    "Ada\x00Obi" => 'AdaObi',
    "Ada\u{200B}Obi" => 'AdaObi',
    "Ada\t" => 'Ada',
    "\u{202E}Ada" => 'Ada',
    "  Ada  " => 'Ada',
];

/** Junk that a human should be asked about, but which is a *name* to the validator. */
$shouldBeFlagged = [
    'asdf', 'asdfasdf', 'Test User', 'Test', 'aaaa', 'xxxx', 'qwerty',
    'aaaaaaa', 'Zzzz', 'asdf.asdf', 'Test Test', 'abc', 'Aaa',
    'Sdkjfhgkjsdhf', 'Junk Name', 'qwertyuiop', 'Foo Bar', 'aaaaz',
];

/** Names that must be accepted *and* left unflagged, or the queue fills with real people. */
$mustNotBeFlagged = [
    'Ada', "O'Brien", 'Al-Amin', 'Aïcha', 'Nguyễn', 'Ọ̀ṣun', 'Adebayo', 'Okonkwo',
    'Nguyen', 'Nkrumah', 'Anas', 'Testy', 'Dada', 'Annan', 'Bobbie', 'Lily',
    'Xi', 'Ng', 'Deepa', 'Onochie', "D'Angelo", 'van der Berg',
    'محمد', '张伟', 'Öztürk', 'Małgorzata', 'Chidinma Nneka', 'Obi Nwosu',
    'Adeyemi-Thomas', 'J. R.', 'de la Cruz', 'Þóra', 'Đorđe',
];

/** Render control and invisible characters so the output is readable. */
function visible(string $value): string
{
    return preg_replace_callback(
        '/[\p{Cc}\p{Cf}\p{So}]/u',
        fn ($m) => sprintf('\\u{%04X}', mb_ord($m[0], 'UTF-8')),
        $value
    ) ?? $value;
}

echo "=== Must be accepted ===\n";
$failures = 0;

foreach ($shouldPass as $name) {
    $problem = Name::validate($name);
    $flag = Name::reviewReasons($name);

    printf(
        "%-22s %s%s\n",
        $name,
        $problem === null ? 'ok' : 'REFUSED: ' . $problem,
        $flag === [] ? '' : '  [flagged: ' . Name::explainReview($flag) . ']'
    );

    if ($problem !== null) {
        $failures++;
    }
}

echo "\n=== Must be refused ===\n";

foreach ($shouldFail as $name) {
    $problem = Name::validate($name);

    printf(
        "%-32s %s\n",
        visible($name),
        $problem === null ? 'ACCEPTED (should not be)' : $problem
    );

    if ($problem === null) {
        $failures++;
    }
}

echo "\n=== Junk a human should be asked about ===\n";

foreach ($shouldBeFlagged as $name) {
    $flag = Name::reviewReasons($name);

    printf(
        "%-22s %s\n",
        $name,
        $flag === [] ? 'NOT flagged (missed)' : Name::explainReview($flag)
    );
}

/*
 * The false-positive check, and the one that matters most: a heuristic that flags
 * real names is a queue nobody clears, and clearing a false flag costs an
 * administrator's attention that the genuine ones need.
 */
echo "\n=== Real names that must NOT be flagged ===\n";

foreach ($mustNotBeFlagged as $name) {
    $flag = Name::reviewReasons($name);

    printf(
        "%-22s %s\n",
        $name,
        $flag === [] ? 'clean' : 'FLAGGED: ' . Name::explainReview($flag)
    );

    if ($flag !== []) {
        $failures++;
    }
}

echo "\n=== Cleaned rather than refused ===\n";

foreach ($cleanedNotRefused as $input => $expected) {
    $problem = Name::validate($input);
    $actual = Name::normalise($input);

    $ok = $problem === null && $actual === $expected;

    printf(
        "%-28s -> %-18s %s\n",
        '"' . visible($input) . '"',
        '"' . $actual . '"',
        $ok ? '' : 'PROBLEM: ' . ($problem ?? 'stored "' . $actual . '", expected "' . $expected . '"')
    );

    if (! $ok) {
        $failures++;
    }
}

echo "\n=== Normalisation ===\n";

foreach ([
    '  Ada  Obi  ' => 'Ada Obi',
    "Ada\tObi" => 'Ada Obi',
    "Ada\u{200B}Obi" => 'AdaObi',
    "Ada\nObi" => 'Ada Obi',
    "e\u{0301}mile" => 'émile',
    'Ada   Obi   Nwosu' => 'Ada Obi Nwosu',
    'Ada-' => 'Ada-',
] as $input => $expected) {
    $actual = Name::normalise($input);

    printf(
        "%-28s -> %-20s %s\n",
        '"' . visible($input) . '"',
        '"' . $actual . '"',
        $actual === $expected ? '' : 'EXPECTED "' . $expected . '"'
    );

    if ($actual !== $expected) {
        $failures++;
    }
}

echo "\n=== Every name already in the database ===\n";

$users = User::query()->whereNotNull('name')->get(['id', 'name', 'first_name', 'last_name']);
$invalid = [];

foreach ($users as $user) {
    foreach (array_filter([$user->first_name, $user->last_name]) as $part) {
        $problem = Name::validate($part);

        if ($problem !== null) {
            $invalid[] = "#{$user->id} \"{$user->name}\" part \"{$part}\": {$problem}";
        }
    }
}

printf("%d account(s) checked, %d whose stored name this rule would refuse.\n", $users->count(), count($invalid));

foreach ($invalid as $line) {
    echo '  ' . $line . "\n";
}

if ($invalid !== []) {
    $failures += count($invalid);
}

echo "\n" . ($failures === 0 ? "No problems found.\n" : "{$failures} PROBLEM(S).\n");

exit($failures === 0 ? 0 : 1);
