<?php

namespace App\Rules;

use App\Support\Name;
use Illuminate\Contracts\Validation\Rule;

/**
 * A customer's name, in one part or as a whole.
 *
 * Used by every endpoint that accepts a name, so the four of them cannot disagree
 * about what a name is. It reads as a rule rather than a regex at the call site
 * because the interesting part is not the pattern — it is that the message names
 * the actual problem (a digit, an emoji, a hidden character) instead of saying
 * "invalid" at somebody with a stray character in their name.
 *
 * ## It validates the *normalised* value
 *
 * The value the customer typed is not the value that gets stored: `Name::normalise`
 * composes Unicode, strips invisible characters and collapses whitespace. Judging
 * the raw input would reject " Ada " for starting with a space and then store "Ada"
 * — a rejection for something the application was about to fix anyway.
 *
 * The rewrite itself is done once, up front, by
 * {@see \App\Http\Requests\NormalisesNames::prepareForValidation()}; this rule
 * repeats the normalisation only because a rule has to be correct when used on its
 * own, in a console command or a test.
 */
class ValidName implements Rule
{
    private ?string $message = null;

    public function passes($attribute, $value): bool
    {
        if ($value === null || $value === '') {
            // `required` owns the empty case; this rule only judges what is there.
            return true;
        }

        if (! is_string($value)) {
            $this->message = 'A name must be text.';

            return false;
        }

        $problem = Name::validate($value);

        if ($problem !== null) {
            $this->message = $problem;

            return false;
        }

        return true;
    }

    public function message(): string
    {
        return $this->message ?? 'Enter a valid name.';
    }
}
