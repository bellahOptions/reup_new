/**
 * Admin transaction actions (modal).
 *
 * ## Why this is a module and not an inline `@push('scripts')`
 *
 * The transaction detail modal is fetched over AJAX and injected with
 * `innerHTML`. That broke the handlers two different ways at once:
 *
 *   1. `@push('scripts')` queues content into a stack that only the *layout*
 *      renders. The modal is rendered standalone, so no `<script>` tag was
 *      emitted at all — the JavaScript body was flushed into the markup as
 *      literal text, and an operator opening the modal was shown the source
 *      code of their own buttons.
 *   2. Even with a `<script>` tag, `innerHTML` never executes scripts. Injected
 *      markup is inert; only the HTML parser runs scripts.
 *
 * So every button in that modal — Force Success, Force Failed, Cancel, Close —
 * was dead on arrival, and "Mark as Success" also pointed at a route that does
 * not exist. Living in the bundle fixes both: the handlers are on `window`
 * before the modal is ever opened, and the build parse-checks them.
 *
 * The route template is read from a `data-refresh-url-template` attribute on
 * the page shell, because the partial cannot know its own URLs at the time the
 * outer page is rendered.
 */

/** Toast when available, `alert` only if the bundle has not loaded. */
function notify(message, variant) {
    const feedback = window.ReUpFeedback;

    if (feedback && typeof feedback[variant] === 'function') {
        feedback[variant](message);

        return;
    }

    window.alert(message);
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.content : '';
}

/**
 * PUT a JSON action and normalise the reply.
 *
 * Never rejects on an HTTP error status: the caller needs the body to explain
 * what went wrong, and a 500 from this console always carries a message.
 */
function send(url, options = {}) {
    return fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        ...options,
    }).then(async (response) => {
        let data = {};

        try {
            data = await response.json();
        } catch (error) {
            data = {};
        }

        return { ok: response.ok, data: data || {} };
    });
}

function runAction(transactionId, action, confirmation, successMessage) {
    if (confirmation && !window.confirm(confirmation)) {
        return;
    }

    send(`/admin/transactions/${transactionId}/${action}`)
        .then(({ data }) => {
            if (data.success) {
                notify(
                    typeof successMessage === 'function' ? successMessage(data) : successMessage,
                    'success'
                );

                // Re-render so the operator sees the state their action produced.
                if (typeof window.viewTransactionDetails === 'function') {
                    window.viewTransactionDetails(transactionId);
                }

                return;
            }

            notify(`Failed: ${data.message || 'Unknown error'}`, 'error');
        })
        .catch((error) => {
            console.error('Admin transaction action failed:', error);
            notify('Could not reach the server. Please try again.', 'error');
        });
}

function refreshButton() {
    return document.getElementById('refreshStatusButton');
}

/**
 * Re-check the transaction with the payment gateway.
 *
 * The button is disabled while in flight because the check makes a
 * server-to-server call to the gateway. The tone follows `changed`, not `ok`:
 * "still pending" and "the gateway could not be reached" are successful checks
 * that changed nothing, and painting them as errors would teach an operator to
 * ignore the one that matters.
 */
export function refreshTransactionStatus(transactionId) {
    const button = refreshButton();
    const label = button ? button.querySelector('[data-refresh-label]') : null;
    const original = label ? label.textContent : null;
    const shell = document.querySelector('[data-refresh-url-template]');

    if (!shell) {
        notify('Refresh is unavailable on this page.', 'error');

        return;
    }

    if (button) {
        button.disabled = true;
    }

    if (label) {
        label.textContent = 'Checking\u2026';
    }

    const restore = () => {
        if (button) {
            button.disabled = false;
        }

        if (label && original !== null) {
            label.textContent = original;
        }
    };

    const url = shell.dataset.refreshUrlTemplate.replace('__ID__', transactionId);

    send(url)
        .then(({ data }) => {
            notify(data.message || 'Status checked.', data.changed ? 'success' : 'info');

            if (data.changed && typeof window.viewTransactionDetails === 'function') {
                // The modal is re-rendered, so this button is replaced; no need
                // to restore its label.
                window.viewTransactionDetails(transactionId);

                return;
            }

            restore();
        })
        .catch((error) => {
            console.error('Transaction status refresh failed:', error);
            notify('Could not reach the server to check this transaction.', 'error');
            restore();
        });
}

/**
 * "Mark as Success" / "Mark as Failed".
 *
 * These used to PUT to `/admin/transactions/{id}/status`, a route that does not
 * exist, so they always failed with a 404 and a generic alert. They now call
 * the endpoints that do exist — both idempotent, so a double press cannot mint
 * money.
 */
export function updateTransactionStatus(transactionId, status) {
    if (status === 'success') {
        forceSuccess(transactionId);

        return;
    }

    forceFailed(transactionId);
}

export function forceSuccess(transactionId) {
    runAction(
        transactionId,
        'force-success',
        "Are you sure you want to force this transaction as successful? This will credit the user's wallet if applicable.",
        'Transaction marked as successful'
    );
}

export function forceFailed(transactionId) {
    runAction(
        transactionId,
        'force-failed',
        'Are you sure you want to force this transaction as failed?',
        'Transaction marked as failed'
    );
}

export function cancelTransaction(transactionId) {
    runAction(
        transactionId,
        'cancel',
        'Are you sure you want to cancel this transaction? If payment was successful, the user will be refunded.',
        'Transaction cancelled'
    );
}

// The modal's markup calls these by name from inline `onclick` attributes, so
// they have to be reachable from the global scope.
window.refreshTransactionStatus = refreshTransactionStatus;
window.updateTransactionStatus = updateTransactionStatus;
window.forceSuccess = forceSuccess;
window.forceFailed = forceFailed;
window.cancelTransaction = cancelTransaction;
