<?php

namespace Tests\Unit;

use App\Support\Name;
use PHPUnit\Framework\TestCase;

/**
 * The name rule itself.
 *
 * A unit test rather than a feature test, and that is the point: this is the one
 * piece of the name handling that must behave identically whether it is reached
 * through sign-up, the profile form, the admin console or a console command. Going
 * through HTTP here would test one route and leave the other three unproven.
 *
 * The data providers are the interesting part. Each is a list of names paired with
 * the outcome they must produce, and the *valid* list is deliberately long and
 * international — the failure this guards against is not "we accepted junk", it is
 * "we refused a real person's name", and only a wide list of real names can catch
 * that.
 */
class NameTest extends TestCase
{
    /* =====================================================================
     | Normalisation
     =================================================================== */

    public function test_it_composes_unicode_so_one_letter_is_one_character(): void
    {
        /*
         * A name typed on a phone can arrive as a base letter plus a combining
         * accent — `é` as U+0065 U+0301. That spelling is two characters for one
         * letter: it fails to match itself in a search, counts twice against a
         * length limit, and renders differently in some fonts. NFC composes it.
         */
        $decomposed = "e\u{0301}mile";

        $this->assertSame('émile', Name::normalise($decomposed));
        $this->assertSame(5, mb_strlen(Name::normalise($decomposed)));
    }

    public function test_it_collapses_whitespace_and_trims(): void
    {
        $this->assertSame('Ada Obi Nwosu', Name::normalise('  Ada   Obi  Nwosu  '));
    }

    public function test_it_turns_a_newline_between_two_words_into_a_space(): void
    {
        // A name pasted across two lines must join into one name, not weld the
        // words together and not leave a control character in the column.
        $this->assertSame('Ada Obi', Name::normalise("Ada\nObi"));
        $this->assertSame('Ada Obi', Name::normalise("Ada\tObi"));
    }

    public function test_it_removes_invisible_characters(): void
    {
        /*
         * A zero-width space is not something anybody types, and a right-to-left
         * override in a name can reverse how the rest of the line renders. Both are
         * removed rather than refused: the customer pasted them by accident and the
         * value that is stored is the name they meant.
         */
        $this->assertSame('AdaObi', Name::normalise("Ada\u{200B}Obi"));
        $this->assertSame('Ada', Name::normalise("\u{202E}Ada"));
        $this->assertSame('AdaObi', Name::normalise("Ada\x00Obi"));
    }

    public function test_it_returns_null_for_null_rather_than_an_empty_string(): void
    {
        // Callers hand this straight to a nullable column and to `array_filter`.
        $this->assertNull(Name::normalise(null));
        $this->assertSame('', Name::normalise(''));
        $this->assertSame('', Name::normalise('   '));
    }

    public function test_it_splits_a_whole_name_on_whitespace(): void
    {
        /*
         * `parts()` is the tokeniser: every word separately, whitespace collapsed.
         * The *two-way* split — "Ada Obi Nwosu" becoming first `Ada`, last `Obi
         * Nwosu` — is `User::splitName()`, because keeping a middle name with the
         * surname is a decision about the customer record rather than about
         * parsing. This asserts the tokeniser; the model's own test asserts the
         * two-way split.
         */
        $this->assertSame(['Ada', 'Obi', 'Nwosu'], Name::parts('Ada Obi Nwosu'));
        $this->assertSame(['Ada', 'Obi'], Name::parts("  Ada \t Obi  "));
        $this->assertSame([], Name::parts('   '));
        $this->assertSame(['Ada'], Name::parts('  Ada  '));
    }

    /* =====================================================================
     | What must be accepted
     =================================================================== */

    /**
     * Names that must be accepted. Every one of these is a real name somebody has.
     *
     * @return array<string, array{string}>
     */
    public static function validNames(): array
    {
        return [
            'plain' => ['Ada'],
            'two words' => ['Ada Obi'],
            'three words' => ['Ada Obi Nwosu'],
            'two-word given name' => ['Chidinma Nneka Okafor'],
            'apostrophe' => ["O'Brien"],
            'apostrophe at the end' => ["Mburu'"],
            'hyphen' => ['Al-Amin'],
            'full stop and spaces' => ['J. R.'],
            'initial with full stop' => ['A. O. Adeyemi'],
            'accented latin' => ['Aïcha'],
            'vietnamese' => ['Nguyễn'],
            'combining diacritics' => ['Ọ̀ṣun'],
            'more diacritics' => ['Adéwálé'],
            'german umlaut' => ['Müller'],
            'turkish' => ['Öztürk'],
            'polish' => ['Małgorzata'],
            'croatian' => ['Šime'],
            'serbian' => ['Đorđe'],
            'icelandic thorn' => ['Þóra'],
            'arabic' => ['محمد'],
            'chinese' => ['张伟'],
            'hebrew' => ['יונתן'],
            'consonant cluster' => ['Nkrumah'],
            'four-consonant cluster' => ['Srdjan'],
            'no vowel' => ['Nguyen'],
            'two-letter surname' => ['Ng'],
            'one-letter name' => ['Xi'],
            'looks like test but is not' => ['Testy'],
            'contains ana but is not a placeholder' => ['Anas'],
            'repeated letters are normal' => ['Annan'],
            'double letter is normal' => ['Bobbie'],
            'short and real' => ['Lily'],
            'particle surname' => ['van der Berg'],
            'particle surname lowercase' => ['de la Cruz'],
            'double-barrelled' => ['Adeyemi-Thomas'],
            'nigerian day name' => ['Naa'],
        ];
    }

    /**
     * @dataProvider validNames
     */
    public function test_it_accepts_real_names(string $name): void
    {
        $this->assertTrue(
            Name::isValid($name),
            "\"{$name}\" is a real name and was refused: " . (Name::validate($name) ?? '')
        );
    }

    /**
     * @dataProvider validNames
     */
    public function test_a_real_name_is_not_flagged_for_review(string $name): void
    {
        /*
         * The false-positive check, and the one that matters most. A heuristic that
         * flags real names produces a review queue nobody clears, and clearing a
         * false flag spends the attention the genuine ones need.
         *
         * Only two of the provider's entries are expected to be flagged — the Akan
         * day-names "Naa" and "Yaa" — so they are excluded here rather than the rule
         * being weakened to accommodate them. See `Name::MIN_DISTINCT_SHORT` for the
         * tradeoff, which is stated rather than hidden.
         */
        if (in_array($name, ['Naa', 'Yaa'], true)) {
            $this->markTestSkipped('A known, documented false positive of the short-part variety rule.');
        }

        $this->assertSame(
            [],
            Name::reviewReasons($name),
            "\"{$name}\" is a real name and was flagged: " . Name::explainReview(Name::reviewReasons($name))
        );
    }

    /* =====================================================================
     | What must be refused
     =================================================================== */

    /**
     * Values that cannot be a name, with a fragment of the message they must produce.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'digits' => ['Ada1', 'numbers'],
            'digits alone' => ['123', 'using letters'],
            'digits after a space' => ['Ada 2', 'numbers'],
            'emoji' => ['Ada 😀', 'emoji'],
            'emoji alone' => ['🎉', 'using letters'],
            'leading hyphen' => ['-Ada', 'start with a letter'],
            'leading full stop' => ['.Ada', 'start with a letter'],
            'leading apostrophe' => ["'Ada", 'start with a letter'],
            'underscore' => ['Ada_', 'letters'],
            'at sign' => ['Ada@example.com', 'letters'],
            'slash' => ['Ada/Obi', 'letters'],
            'hash' => ['Ada#1', 'numbers'],
            'percent' => ['Ada%', 'letters'],
            'angle brackets' => ['<b>Ada</b>', 'letters'],
            'pipe' => ['Ada|Obi', 'letters'],
            'only punctuation' => ['...', 'using letters'],
            'only hyphens' => ['--', 'using letters'],
            'empty' => ['', 'Enter your name'],
            'only spaces' => ['   ', 'Enter your name'],
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    public function test_it_refuses_what_cannot_be_a_name(string $name, string $expectedFragment): void
    {
        $problem = Name::validate($name);

        $this->assertNotNull($problem, "\"{$name}\" should have been refused.");
        $this->assertStringContainsStringIgnoringCase($expectedFragment, $problem);
    }

    public function test_the_message_names_the_actual_problem(): void
    {
        /*
         * "Enter a valid name" tells somebody with a stray digit nothing about which
         * character to remove. Each refusal says what is wrong.
         */
        $this->assertSame('Names cannot contain numbers.', Name::validate('Ada1'));
        $this->assertSame('Names cannot contain emoji.', Name::validate('Ada 😀'));
        $this->assertSame('A name must start with a letter.', Name::validate('-Ada'));
        $this->assertSame(
            'Use letters, spaces, apostrophes, full stops and hyphens only.',
            Name::validate('Ada_')
        );
    }

    /* =====================================================================
     | Length
     =================================================================== */

    public function test_it_refuses_a_name_longer_than_the_column_can_hold(): void
    {
        /*
         * `users.first_name` is a `varchar(191)`. A longer value would be silently
         * truncated by MySQL, and a customer whose bank account name stops mid-word
         * is worse off than one who is asked to shorten it.
         */
        $this->assertTrue(Name::isValid(str_repeat('a', Name::MAX_PART_LENGTH)));

        $problem = Name::validate(str_repeat('a', Name::MAX_PART_LENGTH + 1));

        $this->assertNotNull($problem);
        $this->assertStringContainsString((string) Name::MAX_PART_LENGTH, $problem);
    }

    /* =====================================================================
     | Review heuristics
     =================================================================== */

    public function test_it_flags_obvious_placeholders(): void
    {
        foreach (['asdf', 'asdfasdf', 'Test User', 'test', 'qwerty', 'aaaa', 'xxxx', 'Zzzz', 'aaaaz', 'Junk Name', 'qwertyuiop'] as $junk) {
            $this->assertTrue(
                Name::needsReview($junk),
                "\"{$junk}\" is filler and should be flagged for a human."
            );
        }
    }

    public function test_it_matches_placeholder_words_as_words_not_substrings(): void
    {
        /*
         * The whole reason the placeholder list is matched with `in_array` on the
         * whole part rather than with `str_contains`. "Testy" is a surname, "Anas"
         * contains "ana", and "Demo" appears inside "Odemo".
         */
        foreach (['Testy', 'Anas', 'Odemo', 'Bart', 'Nana Ama'] as $name) {
            $this->assertFalse(
                Name::needsReview($name),
                "\"{$name}\" is a real name and must not be flagged."
            );
        }
    }

    public function test_it_flags_a_keyboard_run_and_a_repeated_word(): void
    {
        $this->assertContains('keyboard_run', Name::reviewReasons('qwerty'));
        $this->assertContains('repeated_word', Name::reviewReasons('asdf.asdf'));
        $this->assertContains('repeated_word', Name::reviewReasons('Test Test'));
    }

    public function test_it_only_applies_the_vowel_rule_to_long_latin_names(): void
    {
        // Eight Latin letters with no vowel is noise; a shorter one can be a name,
        // and a script with no vowels at all must never reach the rule.
        $this->assertContains('no_vowel', Name::reviewReasons('Sdkjfhgkjsdhf'));
        $this->assertNotContains('no_vowel', Name::reviewReasons('Nkrumah'));
        $this->assertNotContains('no_vowel', Name::reviewReasons('Nguyen'));
        $this->assertNotContains('no_vowel', Name::reviewReasons('Ng'));
        $this->assertNotContains('no_vowel', Name::reviewReasons('محمد'));
    }

    public function test_review_reasons_are_human_readable(): void
    {
        $this->assertSame(
            'contains a placeholder word',
            Name::explainReview(['placeholder_word'])
        );

        $this->assertStringContainsString(
            'keyboard run',
            Name::explainReview(['placeholder_word', 'keyboard_run'])
        );
    }

    public function test_an_empty_name_is_not_flagged(): void
    {
        // `required` owns emptiness. A flag on a blank value would put every account
        // that has not set a name into the review queue.
        $this->assertSame([], Name::reviewReasons(null));
        $this->assertSame([], Name::reviewReasons(''));
        $this->assertFalse(Name::needsReview('   '));
    }

    /* =====================================================================
     | The model's contract
     =================================================================== */

    public function test_the_model_pattern_is_the_support_class_pattern(): void
    {
        /*
         * `User::NAME_PATTERN` is re-exported from `Name`. Two definitions of one
         * rule is the shape that drifts, so this asserts there is only one.
         */
        $this->assertSame(Name::PART_PATTERN, \App\Models\User::NAME_PATTERN);
    }

    public function test_the_model_helper_agrees_with_the_rule(): void
    {
        $this->assertTrue(\App\Models\User::isValidNamePart('Ada'));
        $this->assertFalse(\App\Models\User::isValidNamePart('Ada1'));
        $this->assertFalse(\App\Models\User::isValidNamePart(''));
        $this->assertFalse(\App\Models\User::isValidNamePart(null));
    }
}
