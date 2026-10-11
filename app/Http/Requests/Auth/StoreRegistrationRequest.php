<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\NormalisesNames;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;

/**
 * The sign-up form.
 *
 * Extracted from `RegisteredUserController::store()` so the name handling has one
 * home. The controller keeps its route, its referrer resolution and its login — the
 * parts that are about *registering* — and this owns the parts that are about the
 * *input*.
 *
 * ## The two things it does beyond validating
 *
 *   1. **Whitespace and Unicode are normalised before the rules run**, so
 *      `"  Ada  "` is stored as `Ada` and a name typed on a phone as `e` + a
 *      combining accent is stored as the single composed character. See
 *      {@see NormalisesNames}.
 *   2. **A suspicious name is flagged for review, never rejected.** The heuristic
 *      runs on the way out of validation and records its opinion on the account. It
 *      has no say in whether the registration succeeds: telling a real person their
 *      name looks fake is a worse outcome than a placeholder in the database, and
 *      only one of the two can be undone by an administrator.
 *
 * ## Redirects, not 422s
 *
 * This is a browser form. Laravel's default for a failed validation on a
 * `FormRequest` is a redirect back with the errors and the input flashed, which is
 * what the register view needs to redisplay what was typed. A request that asks for
 * JSON gets a 422, which is what an API client needs. That behaviour is inherited,
 * not reimplemented.
 */
class StoreRegistrationRequest extends FormRequest
{
    use NormalisesNames;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the phone number, then every name, before validation.
     *
     * The phone normalisation used to live in the controller and has moved here
     * because it is the same kind of work: turning what was typed into what will be
     * stored, so that the rule judges the stored form. It has to run *before* a
     * whole `name` is split into parts, which `rules()` does.
     */
    public function prepareForValidation(): void
    {
        /*
         * `+234 803 123 4567` and `0803-123-4567` are the same number, and the
         * regex that checks it is a Nigerian number is not a regex for punctuation.
         * Stripping non-digits first means the rule judges the number rather than
         * the copy-paste.
         *
         * The `234` → `0` step matters more than it looks: stripping the punctuation
         * from `+234 803 123 4567` leaves `2348031234567`, which is a perfectly good
         * number that the regex rejects for not starting with `0`. Accepting the
         * international form and storing it the way every other part of the
         * application expects is the difference between "we support +234" and "we
         * reject it with a confusing message".
         */
        $digits = preg_replace('/\D/', '', (string) $this->input('phone'));

        if (preg_match('/^234[7-9][0-9]{9}$/', $digits)) {
            $digits = '0' . substr($digits, 3);
        }

        $this->merge(['phone' => $digits]);

        /*
         * A request that carries only a whole `name` still registers. That is not
         * politeness: a visitor with the previous form already open, or a cached page
         * served from a CDN, posts the old field set, and the alternative to handling
         * it is rejecting a sign-up with "The first name field is required" for a form
         * this very site showed them.
         *
         * The split happens here rather than in `rules()` because the *last* name is
         * everything after the first word — "Ada Obi Nwosu" is first `Ada`, last
         * `Obi Nwosu` — and that is a decision about values, not about rules.
         */
        if (! $this->filled('first_name') && ! $this->filled('last_name') && $this->filled('name')) {
            [$first, $last] = User::splitName((string) $this->input('name'));

            $this->merge(['first_name' => $first, 'last_name' => $last]);
        }

        // Whitespace, Unicode and invisible characters, for both parts and a whole
        // name if one was posted. See `NormalisesNames` for why this is its own
        // method rather than an override of `parent::prepareForValidation()`.
        $this->prepareNamesForValidation();
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => $this->namePartRules(),
            'last_name' => $this->namePartRules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            /*
             * Required, deliberately.
             *
             * Two things need it and neither works without it: Paystack will not
             * issue a dedicated virtual account to a customer record with no phone
             * number, so "fund by transfer" silently becomes unavailable; and there
             * is no way to reach an account that has only an email address when
             * something goes wrong.
             *
             * Collecting it here rather than at first use means every new account has
             * one from the moment it exists, which is what retires the "add your phone
             * number" prompt on the virtual-account page for new customers. The prompt
             * remains for accounts created before this.
             */
            'phone' => [
                'required',
                'string',
                'regex:/^0[7-9][0-9]{9}$/',
                'unique:users,phone',
            ],
            // 8 is the platform floor; the reset flow enforces the same via
            // Rules\Password in NewPasswordController.
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            // The form has always rendered a consent checkbox; without a rule here it
            // carried no name and was never checked server-side.
            'terms' => ['accepted'],
            'ref' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return $this->nameMessages() + [
            'terms.accepted' => 'You must accept the terms of service and privacy policy to continue.',
            'first_name.required' => 'We need your first name — it is the name on your personal account number.',
            'last_name.required' => 'We need your last name — it is the name on your personal account number.',
            'phone.required' => 'We need your phone number to open your wallet.',
            'phone.regex' => 'Enter a valid Nigerian phone number, for example 08012345678.',
            'phone.unique' => 'That phone number is already registered to another account.',
        ];
    }

    /**
     * Flag a suspicious name for review, and record that it was flagged.
     *
     * Called by the controller *after* the account exists, so the flag is written
     * once with a user id to attach it to and one audit line explains it. Nothing
     * here can fail the registration: a logging problem must not cost somebody their
     * account.
     *
     * @return array<int,string> the reasons, for the caller to report
     */
    public function flagNameForReview(User $user): array
    {
        $reasons = \App\Support\Name::reviewReasons((string) $user->name);

        if ($reasons === []) {
            return [];
        }

        try {
            $user->forceFill([
                'name_review' => User::NAME_REVIEW_PENDING,
                'name_review_reason' => \App\Support\Name::explainReview($reasons),
            ])->save();

            /*
             * Logged rather than surfaced to the customer. The customer is not being
             * accused of anything — the heuristic is wrong often enough that a
             * visible accusation would be unacceptable — and an operator needs to be
             * able to see the flag being raised in the same place the review will
             * happen.
             */
            Log::info('Customer name flagged for review', [
                'user_id' => $user->id,
                'reasons' => $reasons,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not flag a name for review', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $reasons;
    }
}
