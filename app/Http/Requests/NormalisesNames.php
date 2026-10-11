<?php

namespace App\Http\Requests;

use App\Rules\ValidName;
use App\Support\Name;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Name inputs on a form request, normalised before they are validated.
 *
 * ## Why a trait rather than rules repeated per request
 *
 * The normalisation and the validation have to agree, and they agree in exactly one
 * way that matters: both must judge the *stored* form of the value. If validation
 * runs against the raw input and normalisation afterwards, " Ada " is rejected for
 * leading whitespace and "Ada  Obi" is rejected for a double space — both of which
 * the application is about to fix, so the rejection is pure friction. Normalising
 * first makes the two see the same string.
 *
 * ## The rewrite happens on the request, not in the controller
 *
 * `$request->validated()` returns the normalised values, and `$request->input()`
 * returns them too — so a controller that stores `$validated['first_name']` and a
 * view that re-renders `old('first_name')` after a validation failure both see the
 * same cleaned-up name. A controller that normalised its own copy would leave the
 * form redisplaying the raw input on the next round trip.
 *
 * ## Length
 *
 * `max:` is checked against the *normalised* value, which is the one that will be
 * stored. The limit is {@see Name::MAX_PART_LENGTH}, tied to the length of the
 * `users.first_name` column: a name longer than the column would be silently
 * truncated by MySQL, and a customer whose bank account name stops mid-word is
 * worse than one who is asked to shorten it.
 */
trait NormalisesNames
{
    /**
     * Fields carrying a single name part.
     *
     * @return array<int,string>
     */
    protected function nameParts(): array
    {
        return ['first_name', 'last_name'];
    }

    /**
     * Fields carrying a whole name, which the model splits into parts.
     *
     * @return array<int,string>
     */
    protected function wholeNames(): array
    {
        return ['name'];
    }

    /**
     * Clean every name input. Called by the request class, not by Laravel.
     *
     * ## Why this is not named `prepareForValidation()`
     *
     * It was, and that was a bug worth recording. `FormRequest::prepareForValidation()`
     * is an empty hook, so a request class that overrides it and calls
     * `parent::prepareForValidation()` calls `Request::prepareForValidation()` —
     * *not* this trait's, because the trait is composed into the child class, not
     * into its parent. The result was silent: validation passed, the value was
     * stored, and only the normalisation never ran. The tests caught it as
     * `"Chidinma   Nneka"` landing in a column that should have held
     * `"Chidinma Nneka"`.
     *
     * A distinct name makes the call explicit and impossible to get wrong. The
     * request class' own `prepareForValidation()` does its field-specific work and
     * then calls this.
     *
     * Both parts and whole names are handled: the whole-name path exists because a
     * sign-up or profile form that predates the split still posts a single `name`,
     * and leaving it unnormalised would put an uncomposed, double-spaced value into
     * the column the parts are derived from.
     */
    protected function prepareNamesForValidation(): void
    {
        $cleaned = [];

        foreach (array_merge($this->nameParts(), $this->wholeNames()) as $field) {
            if ($this->has($field)) {
                $cleaned[$field] = Name::normalise((string) $this->input($field));
            }
        }

        if ($cleaned !== []) {
            $this->merge($cleaned);
        }
    }

    /**
     * The rules that apply to a name part.
     *
     * @return array<int,mixed>
     */
    protected function namePartRules(): array
    {
        return ['required', 'string', 'max:' . Name::MAX_PART_LENGTH, new ValidName()];
    }

    /**
     * The rules that apply to a whole name.
     *
     * The bound is higher than one part's: a whole name may hold a first, a middle
     * and a last, plus the spaces between them.
     *
     * @return array<int,mixed>
     */
    protected function wholeNameRules(): array
    {
        return ['required', 'string', 'max:' . (Name::MAX_PART_LENGTH * 2), new ValidName()];
    }

    /**
     * Messages for the name rules, with the field named the way a person reads it.
     *
     * Only the `required` cases are overridden. The character refusals come from
     * {@see ValidName}, which names the actual problem — a digit, an emoji, a
     * hidden character — rather than saying "invalid" at somebody who pasted a
     * name out of a document with a zero-width space in it.
     *
     * @return array<string,string>
     */
    protected function nameMessages(): array
    {
        return [
            'first_name.required' => 'We need your first name.',
            'last_name.required' => 'We need your last name.',
            'name.required' => 'We need your name.',
            'first_name.max' => 'That first name is too long.',
            'last_name.max' => 'That last name is too long.',
            'name.max' => 'That name is too long.',
        ];
    }

    /**
     * Every name field this request carries, for a caller that needs to iterate
     * them after validation.
     *
     * @return array<int,string>
     */
    protected function nameFields(): array
    {
        return array_merge($this->nameParts(), $this->wholeNames());
    }

    /**
     * The validated name values, normalised, keyed by field.
     *
     * @return array<string,string>
     */
    protected function validatedNames(): array
    {
        return array_filter(
            Arr::only($this->validated(), $this->nameFields()),
            fn ($value) => is_string($value) && $value !== ''
        );
    }
}
