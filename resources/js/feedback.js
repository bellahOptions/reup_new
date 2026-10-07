/**
 * Every request ends in something the customer can see.
 *
 * Two things live here:
 *
 *   1. `ReUpFeedback.toast()` — the script-side twin of `partials/flash`. Server
 *      redirects already carry their outcome as a flash message; anything
 *      fetched from JavaScript previously had to remember to render its own
 *      error, and most call sites did not, so a failed verify or a dropped
 *      connection looked exactly like nothing happening.
 *   2. a `fetch()` wrapper that turns the two failures no caller can be
 *      expected to handle — the network is gone, and our own 5xx — into a
 *      message. 4xx responses are left alone: those carry validation detail that
 *      the calling code renders next to the field it belongs to.
 *
 * Messages are inserted with `textContent`, never `innerHTML`: a provider error
 * can quote what the customer typed.
 */

const VARIANTS = ['success', 'error', 'warning', 'info'];

const DEFAULT_DURATION = 6000;

/** How many toasts may stack before the oldest is dropped. */
const MAX_VISIBLE = 3;

/** Identical messages inside this window are folded into one. */
const REPEAT_WINDOW = 10000;

let lastMessage = '';
let lastShownAt = 0;

/** The toast container, created on demand so layouts need no extra markup. */
function region() {
    let host = document.querySelector('.flash-region--js');

    if (!host) {
        host = document.createElement('div');
        host.className = 'flash-region flash-region--js';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);
    }

    return host;
}

export function toast(message, { variant = 'info', duration = DEFAULT_DURATION } = {}) {
    if (!message) {
        return;
    }

    const now = Date.now();

    /*
     * An outage plus a page that polls (live chat, notification badge) would
     * otherwise stack an identical toast every few seconds. Repeating the same
     * message inside the window is treated as noise.
     */
    if (message === lastMessage && now - lastShownAt < REPEAT_WINDOW) {
        return;
    }

    lastMessage = message;
    lastShownAt = now;

    const host = region();
    const node = document.createElement('div');
    node.className = 'flash-toast flash-toast--' + (VARIANTS.includes(variant) ? variant : 'info');

    const text = document.createElement('p');
    text.className = 'flex-1';
    text.textContent = message;

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'flash-toast__dismiss';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.textContent = '×';
    dismiss.addEventListener('click', () => node.remove());

    node.append(text, dismiss);
    host.appendChild(node);

    while (host.children.length > MAX_VISIBLE) {
        host.firstElementChild.remove();
    }

    if (duration > 0) {
        window.setTimeout(() => node.remove(), duration);
    }
}

window.ReUpFeedback = {
    toast,
    success: (message, options) => toast(message, { ...options, variant: 'success' }),
    error: (message, options) => toast(message, { ...options, variant: 'error' }),
    warning: (message, options) => toast(message, { ...options, variant: 'warning' }),
    info: (message, options) => toast(message, { ...options, variant: 'info' }),
    afterReload,
};

/*
 * ---------------------------------------------------------------------------
 * Results that have to survive a page reload
 * ---------------------------------------------------------------------------
 * Some actions answer with JSON and then reload to refresh what is on screen
 * (verifying a phone number, for instance). A toast shown before the reload is
 * wiped by it — which is how "phone number verified" ended up nowhere. The
 * message is parked in sessionStorage and replayed on the next load.
 */
const PENDING_KEY = 'reup.flash.pending';

function afterReload(message, { variant = 'success' } = {}) {
    if (!message) {
        return;
    }

    try {
        window.sessionStorage.setItem(PENDING_KEY, JSON.stringify({ message, variant }));
    } catch (error) {
        // Storage unavailable (private mode, blocked cookies): better to show it
        // now than not at all.
        toast(message, { variant });
    }
}

function replayPending() {
    try {
        const raw = window.sessionStorage.getItem(PENDING_KEY);

        if (!raw) {
            return;
        }

        window.sessionStorage.removeItem(PENDING_KEY);

        const pending = JSON.parse(raw);

        if (pending && pending.message) {
            toast(pending.message, { variant: pending.variant || 'success' });
        }
    } catch (error) {
        // A malformed or unreadable entry is not worth a message of its own.
    }
}

replayPending();

/*
 * ---------------------------------------------------------------------------
 * Nothing may fail silently
 * ---------------------------------------------------------------------------
 * `reupSilent: true` opts a request out of the automatic message, for a caller
 * that renders its own (a background poll, or a verify box with an inline error
 * area).
 */
const nativeFetch = window.fetch ? window.fetch.bind(window) : null;

if (nativeFetch) {
    window.fetch = function (input, init) {
        const options = init || {};
        const silent = options.reupSilent === true;

        return nativeFetch(input, options)
            .then((response) => {
                if (!silent && response.status >= 500) {
                    toast('Something went wrong on our side. Please try again in a moment.', {
                        variant: 'error',
                    });
                }

                return response;
            })
            .catch((error) => {
                if (!silent) {
                    toast('We could not reach ReUp. Check your connection and try again.', {
                        variant: 'error',
                    });
                }

                throw error;
            });
    };
}

/*
 * A rejected promise nobody handled — a JSON parse failure, a typo in a page
 * script — is the definition of a silent failure, so it gets a message too.
 * An aborted request is not a failure: that is a navigation.
 */
window.addEventListener('unhandledrejection', (event) => {
    if (event.reason && event.reason.name === 'AbortError') {
        return;
    }

    toast('Something on this page did not finish loading. Refresh if anything looks incomplete.', {
        variant: 'warning',
    });
});
