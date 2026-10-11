<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ValidName;
use App\Support\Name;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The customer's own profile form.
 *
 * Moved out of `ProfileController::update()`, which had grown to hold the upload
 * rules, the avatar allowlists, the name rules and the phone rules inline — around
 * forty lines of validation in front of the four lines that do the work. The split
 * is along the line that was already there: this decides what may be *submitted*,
 * the controller decides what happens to what was submitted.
 *
 * The rules themselves are unchanged except for the names, which now go through the
 * shared {@see ValidName} rule so that sign-up, this form and the admin customer
 * editor cannot disagree about what a name is.
 */
class UpdateProfileRequest extends FormRequest
{
    use NormalisesNames;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Clean the phone numbers and the name before the rules run.
     *
     * The phone cleanup was already here and is the same shape as the name one:
     * turn what was typed into what will be stored, so the rule judges the stored
     * form rather than the punctuation somebody pasted.
     */
    public function prepareForValidation(): void
    {
        $this->merge([
            'phone' => preg_replace('/\D/', '', (string) $this->input('phone')),
            'whatsapp' => preg_replace('/\D/', '', (string) $this->input('whatsapp')),
        ]);

        /*
         * The profile form asks for the two halves of the name, for the same reason
         * sign-up does: they are what Paystack builds the account name of a personal
         * account number from. A save that sends only the old whole `name` is still
         * accepted — the halves are filled in from it — so an open tab or a script
         * that has not been updated cannot blank a customer's name.
         */
        if (! $this->filled('first_name') && ! $this->filled('last_name') && $this->filled('name')) {
            [$first, $last] = User::splitName((string) $this->input('name'));

            $this->merge(['first_name' => $first, 'last_name' => $last]);
        }

        // Whitespace, Unicode and invisible characters. See `NormalisesNames` for
        // why this is its own method rather than an override of
        // `parent::prepareForValidation()`.
        $this->prepareNamesForValidation();
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'first_name' => $this->namePartRules(),
            'last_name' => $this->namePartRules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/', Rule::unique('users')->ignore($userId)],
            'whatsapp' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'birthday' => ['nullable', 'date', 'before:-18 years'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'profile_picture' => [
                'nullable',
                'file',
                'image',
                // `mimes` compares the extension *guessed from the content*,
                // `mimetypes` compares the detected MIME itself. Both are listed
                // deliberately: the pair rejects a PHP/HTML/SVG payload renamed to
                // .jpg, and the explicit allowlist keeps `image`'s wider set
                // (bmp/webp/svg) out.
                'mimes:jpeg,png,jpg,gif',
                'mimetypes:image/jpeg,image/png,image/gif',
                'max:2048',
            ],
            // Avatar choices are validated against the configured keys rather than
            // accepted as free text, so a hand-crafted POST cannot store a colour or
            // glyph the renderer does not know about.
            'avatar_color' => ['nullable', 'string', Rule::in(array_keys(config('avatars.colors', [])))],
            'avatar_icon' => ['nullable', 'string', Rule::in(array_keys(config('avatars.icons', [])))],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return $this->nameMessages() + [
            'phone.regex' => 'Please enter a valid Nigerian phone number (e.g., 08012345678)',
            'whatsapp.regex' => 'Please enter a valid Nigerian phone number for WhatsApp',
            'birthday.before' => 'You must be at least 18 years old',
        ];
    }

    /**
     * Flag a name that now looks like a placeholder.
     *
     * Takes the user rather than reading `$this->user()` because the caller runs
     * it *after* saving — the model instance it holds has the new name, while the
     * request's own user is a different instance loaded at the start of the
     * request and still carrying the old one. Reading the stale copy would judge
     * the name the customer just replaced.
     *
     * Like the sign-up flag it blocks nothing and accuses nobody: it puts a
     * question in front of a human. `User::flagNameIfSuspicious()` will not re-open
     * a review an administrator has already approved.
     *
     * @return array<int,string> the reasons, empty when the name is unremarkable
     */
    public function flagNameForReview(User $user): array
    {
        if (! $user->flagNameIfSuspicious()) {
            return [];
        }

        Log::info('Customer name flagged for review after a profile change', [
            'user_id' => $user->id,
            'reason' => $user->name_review_reason,
        ]);

        return Name::reviewReasons((string) $user->name);
    }
}
