/**
 * Wallet funding — background submit with immediate feedback.
 *
 * ## The problem this solves
 *
 * The funding form used a plain page POST. That keeps the *old* page on screen
 * until the server answers, and the server's answer waits on a card gateway —
 * two of them in the fallback case, each with a 30s timeout. Meanwhile all the
 * customer sees is the button label stuck on "Processing…", which is
 * indistinguishable from a hung page.
 *
 * So the form submits with `fetch()` instead, the JSON reply is rendered as
 * soon as it arrives, and a hard client-side deadline guarantees an *outcome*
 * even when the provider never answers.
 *
 * ## Why this lives in the bundle and not in the Blade `x-data`
 *
 * It was an inline Alpine expression first, and it shipped a syntax error that
 * no server-side test could see: the whole component failed to evaluate, so
 * every binding on the page died at once (Alpine reports
 * "ReferenceError: submitting is not defined" for each one). Real JavaScript in
 * a real file is checked by the build, by the editor, and by anything that can
 * parse JavaScript. Quotes inside an HTML attribute string are not.
 *
 * ## Two interactions with `forms.js` that matter
 *
 *   * `forms.js` listens for `submit` globally and, if the event is *not*
 *     already prevented, replaces the clicked button's innerHTML with a
 *     spinner. Alpine's `@submit` attribute does not prevent the event by
 *     itself, so this handler must call `preventDefault()` **synchronously,
 *     before its first await** — otherwise the global listener wipes the
 *     button's contents, taking the `x-show`/`x-text` spans with it, and the
 *     button never recovers.
 *   * because this handler owns the loading state, the form keeps
 *     `data-no-loading` as a second, explicit signal that `forms.js` must keep
 *     its hands off.
 */

/** How long to wait for the server before telling the customer something. */
const SUBMIT_DEADLINE_MS = 20000;

export function walletFunding(config) {
    return {
        amount: config.amount ?? null,
        method: config.method ?? 'paystack',
        submitting: false,
        outcome: null,
        outcomeTone: 'error',

        quick: config.quick ?? [],
        min: Number(config.min ?? 0),
        max: Number(config.max ?? 0),

        paystackPercentage: Number(config.paystackPercentage ?? 0),
        paystackAdditional: Number(config.paystackAdditional ?? 0),
        paystackCap: config.paystackCap === null || config.paystackCap === undefined
            ? null
            : Number(config.paystackCap),
        bankFixedFee: Number(config.bankFixedFee ?? 0),

        get numericAmount() {
            const n = parseFloat(this.amount);

            return Number.isFinite(n) && n > 0 ? n : 0;
        },

        get fee() {
            if (this.numericAmount <= 0) return 0;
            if (this.method === 'bank_transfer') return this.bankFixedFee;

            let fee = (this.numericAmount * this.paystackPercentage / 100) + this.paystackAdditional;

            if (this.paystackCap !== null && fee > this.paystackCap) fee = this.paystackCap;

            return Math.round(fee * 100) / 100;
        },

        get total() {
            return this.numericAmount + this.fee;
        },

        get outOfRange() {
            if (this.numericAmount <= 0) return false;

            return this.numericAmount < this.min || this.numericAmount > this.max;
        },

        format(value) {
            return '\u20A6' + Number(value).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        async submit(event) {
            // Synchronous, and first: see the note about `forms.js` above.
            event.preventDefault();

            if (this.outOfRange || this.submitting) {
                return;
            }

            this.submitting = true;
            this.outcome = null;

            const form = event.target;
            const deadline = new AbortController();
            const timer = setTimeout(() => deadline.abort(), SUBMIT_DEADLINE_MS);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: deadline.signal,
                });

                const payload = await response.json().catch(() => ({}));

                if (payload.redirect) {
                    this.outcomeTone = 'success';
                    this.outcome = 'Taking you to the secure payment page…';
                    window.location.assign(payload.redirect);
                    return;
                }

                this.outcomeTone = 'error';
                this.outcome = payload.message
                    || this.firstValidationError(payload)
                    || 'We could not start that payment. Nothing has been charged — please try again.';
            } catch (error) {
                this.outcomeTone = 'error';
                this.outcome = error.name === 'AbortError'
                    ? 'That is taking longer than expected. Nothing has been charged — check your history in a moment, then try again.'
                    : 'We could not reach the server. Check your connection and try again.';
            } finally {
                clearTimeout(timer);
                this.submitting = false;
            }
        },

        /**
         * Laravel's 422 body. Surfaced rather than swallowed, because "the amount
         * is below the minimum" is actionable and a generic failure is not.
         */
        firstValidationError(payload) {
            const errors = payload && payload.errors;

            if (!errors || typeof errors !== 'object') {
                return null;
            }

            const first = Object.values(errors)[0];

            return Array.isArray(first) ? first[0] : (typeof first === 'string' ? first : null);
        },
    };
}

window.walletFunding = walletFunding;

export default walletFunding;
