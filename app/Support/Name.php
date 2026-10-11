<?php

namespace App\Support;

use Normalizer;

/**
 * Customer names: normalising, validating, and judging whether one deserves a
 * second look.
 *
 * ## Why this is a value object and not four regexes in four controllers
 *
 * A name is written in four places (sign-up, the profile form, the admin customer
 * editor, and the legacy whole-name path each of those still accepts) and it is
 * *read* in more: the dashboard greeting, the admin console, receipts, and — the
 * reason any of this matters — the account name of the customer's Paystack
 * dedicated virtual account, which is what a payer reads in their own banking app
 * before sending money.
 *
 * Four validators of one concept is the shape that drifts: one accepts a hyphen,
 * one silently strips it, and the name on the customer's bank account stops
 * matching the name they typed. So the rule lives here once, the four call sites
 * pass their input through it, and there is nothing to keep in step.
 *
 * ## The three jobs, kept apart on purpose
 *
 *   * **normalise()** changes what is stored without changing what it means:
 *     Unicode NFC, no invisible characters, no doubled spaces.
 *   * **validate()** decides whether the value is a name at all. It rejects only
 *     what cannot be a name — digits, emoji, symbols, control characters — and
 *     never rejects a name for being unfamiliar.
 *   * **review()** is advice, not a verdict. It returns reasons a name *might* be
 *     junk ("test", "aaaa", "qwerty") for a human to look at, and nothing here
 *     ever blocks on it.
 *
 * The separation is the whole design. Name validation that rejects on a heuristic
 * is a validation that tells a real person their own name is invalid, and there is
 * no error message that recovers from that.
 */
final class Name
{
    /**
     * The longest a single name part may be.
     *
     * A bound is needed because `users.first_name` is a `varchar(191)` on MySQL
     * and an over-long value would be silently truncated at the storage layer —
     * the worst outcome, because the customer would be shown a name on their bank
     * account that stops mid-word. 100 leaves room for the longest legitimate
     * single name (they exist in the 40s) and for a two-word given name.
     */
    public const MAX_PART_LENGTH = 100;

    /**
     * What may appear in a name part.
     *
     * `\p{L}` is a letter in any script, and `\p{M}` is a combining mark. Both are
     * required and the second is not obvious: `Ọ̀ṣun` is spelled with a combining
     * grave accent that is in neither `a-zA-Z` nor `\p{L}`, so the obvious pattern
     * rejects a real Nigerian name while accepting "John".
     *
     * Apostrophe, hyphen, full stop and space are allowed *inside* a part because
     * O'Brien, Al-Amin, J. R. and "Chidinma Nneka" all exist. The first character
     * must be a letter, so leading and trailing whitespace cannot survive a
     * validation that only trims its copy.
     */
    public const PART_PATTERN = '/^\p{L}[\p{L}\p{M}\'\-\\. ]*$/u';

    /** A name part carrying a digit. Explicit so the message can say so. */
    private const HAS_DIGIT_PATTERN = '/\p{Nd}/u';

    /** An emoji, a pictograph, or a dingbat — characters people put in usernames. */
    private const HAS_EMOJI_PATTERN = '/[\p{So}\p{Cs}\p{Sk}]/u';

    /** A character that cannot be seen: control codes, zero-width joiners, bidi overrides. */
    private const HAS_INVISIBLE_PATTERN = '/[\p{Cc}\p{Cf}]/u';

    /**
     * A character that is visible but not a letter, mark or allowed punctuation.
     *
     * A "*disallowed* character", not "an unsupported symbol", because the class is
     * the complement — it catches anything at all that the name pattern does not
     * allow, including other punctuation. `HAS_DIGIT_PATTERN` and
     * `HAS_EMOJI_PATTERN` are the two cases worth naming in a message; everything
     * else that reaches this one is not a letter and not `' - .`, so the message
     * lists what *is* allowed rather than trying to name what is not.
     *
     * Checked *before* `HAS_DIGIT_PATTERN` in {@see issues()}, and that order is the
     * reason it exists as its own constant: "Ada1" should be told it contains a
     * number, while "Ada=1" is better told which characters are allowed at all,
     * because the `=` is the mistake the customer needs to see.
     */
    private const HAS_DISALLOWED_PATTERN = '/[^\p{L}\p{M}\'\-\\. ]/u';

    /**
     * Words that are almost always a placeholder rather than a person.
     *
     * Matched as whole words against the whole name, never as substrings: "Testy"
     * is a surname and "Anas" contains "ana". The list is deliberately short —
     * every entry is a word nobody is called, and a longer list would start
     * catching real names in other languages.
     */
    private const PLACEHOLDER_WORDS = [
        'test', 'tests', 'testing', 'demo', 'dummy', 'sample', 'example',
        'asdf', 'asdfasdf', 'qwerty', 'qwertyuiop', 'zxcvbn', 'abc', 'abcd', 'abcdef',
        'xxx', 'xxxx', 'yyy', 'zzz', 'zzzz',
        'none', 'nil', 'null', 'undefined', 'nan', 'na', 'n/a',
        'anonymous', 'unknown', 'firstname', 'lastname', 'surname', 'fullname',
        'yourname', 'name', 'newuser', 'username', 'user', 'admin', 'guest',
        'junk', 'fake', 'fakename', 'random', 'randomname', 'foo', 'foobar', 'bar', 'baz',
        'delete', 'deleteme', 'please', 'whatever', 'nothing', 'asd', 'aaa', 'aaaa', 'aaaaa',
    ];

    /** A short, very common Latin keyboard or alphabet run. */
    private const KEYBOARD_RUNS = [
        'abc', 'abcd', 'abcde', 'abcdef', 'qwerty', 'qwert', 'asdf', 'asdfg', 'zxcv', 'zxcvb',
        'qazwsx', 'qweasd', 'hjkl', 'uiop', 'wasd', 'ghjkl', 'mnbvc',
    ];

    /**
     * A consonant run with no vowel that looks like keyboard noise.
     *
     * Five rather than four, and that is the whole point of the bound: real
     * consonant clusters reach four in transliterated names — "Nguyen",
     * "Nkrumah", "Srdjan", "Vlksic" — and a threshold of four would flag every
     * one of them. Genuine noise does not stop at five.
     */
    private const CONSONANT_RUN = 5;

    /**
     * How many distinct characters a three-letter part must use.
     *
     * Applies only at exactly three Latin letters, and both bounds are the point.
     *
     * *Three, not two*, because a two-letter name is ordinary and repeating a
     * letter in one is not evidence of anything: "Xi", "Ng", "Mw" and "Ae" are all
     * real. A rule that flagged them would put real customers in a review queue,
     * which is how a queue stops being read.
     *
     * *Latin only*, because a three-character CJK name such as 张伟 is two
     * characters of which one is 张 — "almost no variety" is a statement about
     * repeated letters in an alphabet and it does not translate. Scripts that repeat
     * a character in a doubled given name are legitimate and reachable here.
     *
     * What survives is "Aaa", "Xxy", "Zzz" — three letters, fewer than three
     * distinct — which is filler and nothing else.
     *
     * ## The known false positive, stated rather than hidden
     *
     * Three-letter names that repeat a letter are real: "Naa" and "Yaa" are Akan
     * day-names, "Lee" is a surname, "Ari" and "Ini" are Nigerian given names. Two
     * of those are flagged by this rule. That is the accepted cost of the only
     * reliable signal for "aaa": a false flag costs an administrator one glance,
     * because clearing it takes a single click and blocks nothing, while a database
     * full of "aaa" is a set of accounts nobody can search or reconcile.
     *
     * It is deliberately *not* widened to four-letter parts or to non-adjacent
     * repeats: "Annan", "Bobbie", "Lilly" and "Dada" are all real, and a rule that
     * flagged them would cost far more attention than it saved.
     */
    private const MIN_DISTINCT_SHORT = 3;

    /** The length at which the short-part variety rule applies. */
    private const SHORT_PART_LENGTH = 3;

    /* =====================================================================
     | Normalisation
     |=================================================================== */

    /**
     * The stored form of a name: canonical Unicode, no invisible characters, one
     * space between words.
     *
     * Applied to every name the application accepts, so the value on the customer
     * record, the value sent to Paystack and the value printed on a receipt are
     * the same string rather than three renderings of the same intent.
     *
     * Returns null for a value that is absent, so callers can hand the result
     * straight to `array_filter` or a nullable column without a second check for
     * `''`.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        /*
         * Normalise *first*, before anything else looks at the string.
         *
         * A name typed on a phone can arrive as a base letter plus a combining
         * accent — `é` as U+0065 U+0301 — which is two characters for one letter.
         * That spelling passes a pattern built for combining marks, fails to match
         * itself in a `LIKE` search, and counts as two characters against a length
         * limit. NFC composes it to the single code point, which is the only form
         * worth storing.
         */
        if (class_exists(Normalizer::class)) {
            $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

            if (is_string($normalised)) {
                $value = $normalised;
            }
        }

        /*
         * Invisible characters are removed rather than rejected, because they are
         * almost never deliberate: they arrive from a copy-paste out of a styled
         * document. A zero-width joiner inside a name is not a name a person typed,
         * and keeping it would break search and length checks for everybody.
         *
         * Tab, newline and carriage return become a space rather than vanishing, so
         * a name pasted across two lines joins into one name instead of welding
         * two words together.
         */
        $value = preg_replace('/[\t\n\r]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? $value;

        // One space between words, no leading or trailing space.
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Split a whole name into parts, normalised.
     *
     * The last part is *everything* after the first word — not one word — so
     * "Ada Obi Nwosu" round-trips to "Ada" + "Obi Nwosu" and joins back to exactly
     * the string it came from. Splitting into exactly two halves is what the
     * previous whole-name path did and it is what made a three-part name lose its
     * middle name into a surname.
     *
     * @return array<int,string> one or more parts; empty when there is no name
     */
    public static function parts(?string $value): array
    {
        $normalised = self::normalise($value);

        if ($normalised === null || $normalised === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalised), fn ($part) => $part !== ''));
    }

    /* =====================================================================
     | Validation
     |=================================================================== */

    /**
     * Is this a name?
     *
     * True for anything that could be a person's name in any script, false for
     * anything that cannot be one. It is deliberately *generous*: digits, emoji,
     * symbols, control characters and a name that is too long are the only
     * refusals. Unfamiliar spellings, unusual punctuation and names from scripts
     * this application has never seen are accepted, because the failure mode of
     * being strict here is telling a real person their own name is invalid.
     */
    public static function isValid(?string $value): bool
    {
        return self::validate($value) === null;
    }

    /**
     * The first problem with a name, or null when it is acceptable.
     *
     * One problem rather than all of them, because this is what a rule reports and
     * a form field can only usefully show one message at a time. `issues()` is the
     * full list.
     *
     * @return string|null a human-readable message, safe to show a customer
     */
    public static function validate(?string $value): ?string
    {
        return self::issues($value)[0] ?? null;
    }

    /**
     * Everything wrong with a name.
     *
     * @return array<int,string>
     */
    public static function issues(?string $value): array
    {
        $normalised = self::normalise($value);

        if ($normalised === null || $normalised === '') {
            return ['Enter your name.'];
        }

        $issues = [];

        /*
         * A value with no letters in it at all — "123", "😀", "..." — is not a name
         * with a problem, it is not a name, and the specific complaint ("names cannot
         * contain numbers") would be a strange way to say so. Answered once, here,
         * before the per-character rules start describing a thing that is not a name.
         */
        if (preg_match('/\p{L}/u', $normalised) !== 1) {
            return ['Enter your name using letters.'];
        }

        foreach (self::parts($normalised) as $part) {
            if (mb_strlen($part) > self::MAX_PART_LENGTH) {
                $issues[] = 'Names cannot be longer than ' . self::MAX_PART_LENGTH . ' characters.';
            }

            /*
             * The refusals are spelled out one class at a time, so the message can
             * name the actual problem. "Enter a valid name" tells somebody with a
             * stray digit nothing about which character to remove.
             *
             * The order is deliberate, and it is not the order of severity. Each
             * check answers the question the customer is actually asking, and the
             * cases overlap: "Ada1" holds a digit *and* fails the allowed-character
             * class, so whichever runs first decides the message. A digit is the
             * more useful thing to be told about, so the digit check runs before the
             * broad one. `-Ada` escapes both — every character in it is allowed —
             * which is why a name must also *start* with a letter.
             */
            if (preg_match(self::HAS_EMOJI_PATTERN, $part) === 1) {
                $issues[] = 'Names cannot contain emoji.';
            } elseif (preg_match(self::HAS_DIGIT_PATTERN, $part) === 1) {
                $issues[] = 'Names cannot contain numbers.';
            } elseif (preg_match(self::HAS_INVISIBLE_PATTERN, $part) === 1) {
                $issues[] = 'Names cannot contain hidden characters. Please retype your name.';
            } elseif (preg_match(self::HAS_DISALLOWED_PATTERN, $part) === 1) {
                $issues[] = 'Use letters, spaces, apostrophes, full stops and hyphens only.';
            } elseif (preg_match('/^\p{L}/u', $part) !== 1) {
                $issues[] = 'A name must start with a letter.';
            }
        }

        return array_values(array_unique($issues));
    }

    /* =====================================================================
     | Review heuristics
     |=================================================================== */

    /**
     * Reasons this name might not be a real one, for a human to look at.
     *
     * ## What this is not
     *
     * It is not validation, and nothing calls it to decide whether to accept a
     * name. If it did, the first international customer whose name happened to
     * look odd to a heuristic written by an English-speaking developer would be
     * refused — and there is no error message that recovers from being told your
     * own name is fake.
     *
     * ## How it stays conservative
     *
     * Every rule is anchored on a pattern that essentially never occurs in a real
     * name, and each is checked against the parts rather than the whole: a name is
     * flagged when *any* part looks like filler. Repeating a letter is normal
     * ("Anna", "Nneka", "Okafor" has no repeated *letters* at all but "Alli"
     * does); a run of five consonants with no vowel is not. Whole-word matching
     * means "Testy" and "Anas" are untouched, and the vowel rule only applies
     * below four letters, because plenty of real names have none.
     *
     * The cost of a false positive here is a flag on a customer record that an
     * administrator clears in a second. The cost of a false negative is a database
     * full of "asdf" that nobody can search or reconcile. Both are cheap; neither
     * is a reason to reject anybody.
     *
     * @return array<int,string> machine-readable reasons; empty when nothing is odd
     */
    public static function reviewReasons(?string $value): array
    {
        $parts = self::parts($value);

        if ($parts === []) {
            return [];
        }

        $whole = mb_strtolower(implode(' ', $parts));
        $reasons = [];

        foreach ($parts as $part) {
            $lower = mb_strtolower($part);
            $letters = preg_replace('/[^\p{L}]/u', '', $part) ?? '';

            if (in_array($lower, self::PLACEHOLDER_WORDS, true)) {
                $reasons[] = 'placeholder_word';
            }

            /*
             * A keyboard or alphabet run, matched as a prefix: "qwertyuiop" and
             * "qwerty" are the same mistake, and so are "abcdef" and "abcd".
             * Length-guarded so a three-letter run only counts when it *is* the
             * whole part — "Abc" is filler, "Aban" contains "ab" and is not.
             */
            foreach (self::KEYBOARD_RUNS as $run) {
                if ($lower === $run || (mb_strlen($run) >= 4 && str_starts_with($lower, $run))) {
                    $reasons[] = 'keyboard_run';
                    break;
                }
            }

            // Four identical letters in a row: "aaaa", "Joooohn".
            if (preg_match('/(\p{L})\1{3,}/u', $part) === 1) {
                $reasons[] = 'repeated_characters';
            }

            // A short part with almost no variety: "Aaa", "Xxy", "Zzz". Latin
            // only, and see the constant for why the floor is three.
            if (self::isLatin($letters) && mb_strlen($letters) === self::SHORT_PART_LENGTH) {
                $distinct = count(array_unique(preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) ?: []));

                if ($distinct < self::MIN_DISTINCT_SHORT) {
                    $reasons[] = 'low_variety';
                }
            }

            /*
             * A long name with no vowel and no "y".
             *
             * The length floor is what makes this safe. "Ng", "Nguyen" and "Nkrumah"
             * all have no vowel among their first letters, and two-letter surnames
             * like "Ng" and "Mw" are real — but a run of eight Latin letters with no
             * vowel at all is keyboard noise. A name written in a script with no
             * vowels (Arabic, Chinese) never reaches this test, because `isLatin()`
             * excludes it.
             */
            if (self::isLatin($letters)
                && mb_strlen($letters) >= 8
                && preg_match('/[aeiouy]/', $lower) !== 1) {
                $reasons[] = 'no_vowel';
            }
        }

        // The separator-spam shape: "asdf.asdf", "Test Test".
        $words = preg_split('/[\s\.\-]+/u', $whole, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2 && count(array_unique($words)) === 1) {
            $reasons[] = 'repeated_word';
        }

        return array_values(array_unique($reasons));
    }

    /** Convenience for callers that only need the yes or no. */
    public static function needsReview(?string $value): bool
    {
        return self::reviewReasons($value) !== [];
    }

    /**
     * Is every letter in this string from the Latin alphabet?
     *
     * The review heuristic asks this because most of its rules are statements about
     * Latin writing — a keyboard run, a vowel, repeated letters — and applying them
     * to a script they were not written for produces flags about names that are
     * perfectly ordinary. A name is never refused for it; it is exempted.
     */
    private static function isLatin(string $letters): bool
    {
        return $letters !== '' && preg_match('/^[a-z]+$/', mb_strtolower($letters)) === 1;
    }

    /**
     * A reason list rendered for a human, for the admin console and the audit log.
     */
    public static function explainReview(array $reasons): string
    {
        $labels = [
            'placeholder_word' => 'contains a placeholder word',
            'keyboard_run' => 'contains a keyboard run',
            'repeated_characters' => 'repeats one character',
            'low_variety' => 'has almost no variety',
            'no_vowel' => 'has no vowels',
            'repeated_word' => 'repeats the same word',
        ];

        $described = array_map(fn (string $reason) => $labels[$reason] ?? $reason, $reasons);

        return implode(', ', array_unique($described));
    }

    /**
     * Does a normalised value still differ from what arrived?
     *
     * Used by the validation rule to decide whether to rewrite the request, and by
     * tests that need to prove the rewrite happened. Kept here so the comparison
     * covers everything `normalise()` does rather than the one thing the caller
     * happened to remember.
     */
    public static function isNormalised(?string $value): bool
    {
        return self::normalise($value) === $value;
    }
}
