/**
 * A loading state and a double-submit guard for every form in the application.
 *
 * The complaint this fixes: a purchase debits a wallet, calls an upstream and
 * sends receipts, so there is a real pause between clicking "Buy" and the page
 * changing. Nothing on screen said so, and an impatient second click sent a
 * second purchase.
 *
 * Why it is global rather than per-view: there are purchase forms across seven
 * products, plus admin screens, and the ones that remembered to guard themselves
 * each did it differently (`submitBtn.disabled = true` here, an Alpine
 * `submitting` flag there). One listener covers all of them, including forms
 * added later.
 *
 * Two details matter for correctness:
 *
 *   * the listener runs in the **bubble** phase, so Alpine's `@submit.prevent`
 *     has already run by the time it fires. If the event was prevented, a script
 *     is handling the request itself and owns its own loading state — disabling
 *     its button would leave it dead, because nothing navigates. Those forms can
 *     also opt out explicitly with `data-no-loading`.
 *   * the lock is released on `pageshow` and after a timeout, so a blocked or
 *     failed submission can never leave a form permanently unusable.
 */

const DEFAULT_LOADING_TEXT = 'Processing…';

/** Give up waiting for a navigation we will never see, and unlock. */
const SAFETY_TIMEOUT = 30000;

function submitButtons(form) {
    return Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
}

/** The button that was actually clicked, falling back to the form's first. */
function submitterFor(form, event) {
    if (event.submitter && event.submitter.form === form) {
        return event.submitter;
    }

    return form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]');
}

function lock(form, button) {
    const loadingText = (button && button.dataset.loadingText)
        || form.dataset.loadingText
        || DEFAULT_LOADING_TEXT;

    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');

    submitButtons(form).forEach((candidate) => {
        candidate.disabled = true;

        if (candidate !== button) {
            return;
        }

        candidate.setAttribute('aria-busy', 'true');

        if (candidate.tagName === 'BUTTON') {
            // Remembered so the original content (icons and all) can come back
            // if the page is restored from the back/forward cache.
            candidate.dataset.originalHtml = candidate.innerHTML;
            candidate.innerHTML = '';

            const spinner = document.createElement('span');
            spinner.className = 'btn-spinner';
            spinner.setAttribute('aria-hidden', 'true');

            const label = document.createElement('span');
            label.textContent = loadingText;

            candidate.append(spinner, label);
        } else {
            candidate.dataset.originalValue = candidate.value;
            candidate.value = loadingText;
        }
    });

    form.dataset.submitGuard = String(window.setTimeout(() => unlock(form), SAFETY_TIMEOUT));
}

export function unlock(form) {
    if (!form || !form.dataset.submitting) {
        return;
    }

    window.clearTimeout(Number(form.dataset.submitGuard || 0));

    delete form.dataset.submitting;
    delete form.dataset.submitGuard;
    form.removeAttribute('aria-busy');

    submitButtons(form).forEach((candidate) => {
        candidate.disabled = false;
        candidate.removeAttribute('aria-busy');

        if (candidate.dataset.originalHtml !== undefined) {
            candidate.innerHTML = candidate.dataset.originalHtml;
            delete candidate.dataset.originalHtml;
        }

        if (candidate.dataset.originalValue !== undefined) {
            candidate.value = candidate.dataset.originalValue;
            delete candidate.dataset.originalValue;
        }
    });
}

window.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    // A script already took responsibility for this submission.
    if (event.defaultPrevented || form.hasAttribute('data-no-loading')) {
        return;
    }

    if (form.dataset.submitting) {
        /*
         * The second attempt: a double click, a double tap, or Enter pressed
         * again while the first request is still in flight. The wallet already
         * has an idempotency key as a server-side backstop, but not sending the
         * duplicate at all is better.
         */
        event.preventDefault();

        return;
    }

    lock(form, submitterFor(form, event));
}, false);

// Back/forward cache: the browser can restore the page with the button still
// disabled, which reads as a dead form.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }

    document.querySelectorAll('form[data-submitting]').forEach(unlock);
});

export { lock };
