/**
 * Appearance switch.
 *
 * The *resolving* and the *painting* already happened in a synchronous script in
 * <head> (resources/views/partials/theme.blade.php) — that is what stops a dark
 * device from showing a white page first. This file adds the two things that
 * cannot be done there:
 *
 *   1. a control the user can press, and
 *   2. persistence, without a page reload.
 *
 * ---------------------------------------------------------------------------
 * The three states
 * ---------------------------------------------------------------------------
 * Light, Dark and System. System is not a theme — it is an instruction to follow
 * the device — so pressing the switch moves through all three rather than
 * toggling a boolean. A two-state toggle cannot express "stop overriding this",
 * which is the state most people actually want and the one the device default
 * exists to serve.
 *
 * ---------------------------------------------------------------------------
 * Where the choice is stored
 * ---------------------------------------------------------------------------
 * Signed in:  on the account (`users.theme_preference`), through the endpoint the
 *             switch posts to. It therefore follows the person to their phone.
 * Signed out: on the device, in localStorage. A guest has no account to hang it
 *             on, and dropping their choice on every navigation would make the
 *             switch feel broken.
 *
 * The two are deliberately not merged: a signed-in request is authoritative, and
 * hydrating it from localStorage as well would let a stale device value override
 * an explicit account choice on the next page load.
 */

// Only the top-level "is this a signed-in page" decision is read once; the
// current values are always read from the DOM so that two switches (desktop and
// mobile) can never drift apart.
const STORAGE_KEY = 'reup.theme';

function readState() {
    const root = document.documentElement;

    return {
        /** The element the attributes live on, exposed so a caller can read a
         *  fresh value without another DOM query. */
        root,

        /** The stored choice: 'system', 'light' or 'dark'. */
        mode: root.getAttribute('data-requested-theme') || 'system',

        /** What that choice currently renders as: 'light' or 'dark'. */
        resolved: root.getAttribute('data-theme') || 'light',

        /** True when the choice is saved on the account rather than the device. */
        persists: root.getAttribute('data-theme-persist') === 'server',

        /** The available states, in cycle order, as published by the server. */
        modes: root.getAttribute('data-theme-modes'),
    };
}

/**
 * Guarded because localStorage throws in a few real situations — Safari private
 * mode, and any browser with site data blocked. Failing to remember a preference
 * is acceptable; failing to apply it is not, so every caller has a fallback.
 */
function remember(mode) {
    try {
        window.localStorage.setItem(STORAGE_KEY, mode);
    } catch (e) {
        /* Not fatal: the choice still applies for this page. */
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('themeSwitch', () => ({
        ...readState(),

        /**
         * Order the switch cycles through, as published by the server from
         * config('theme.modes'). Read from the DOM rather than hard-coded so the
         * server remains the only place that knows the available states.
         */
        all() {
            const list = readState().modes;

            return list ? list.split(',') : ['light', 'dark', 'system'];
        },

        init() {
            this.sync();

            // Another tab or the operating system changing under us.
            window.addEventListener('reup:theme-changed', () => this.sync());
        },

        sync() {
            const state = readState();

            this.mode = state.mode;
            this.resolved = state.resolved;
            this.persists = state.persists;
            this.modes = state.modes;
        },

        /** The state the current press moves to. */
        next() {
            const list = this.all();
            const index = list.indexOf(this.mode);

            return list[(index + 1) % list.length];
        },

        label() {
            return this.mode.charAt(0).toUpperCase() + this.mode.slice(1);
        },

        /**
         * Which theme is actually being drawn: the choice, or — when the choice
         * is "follow the device" — whichever way the device currently points.
         * The switch uses this to tell "you chose dark" apart from "dark is what
         * your device asked for", which are different statements.
         */
        renderMode() {
            return this.mode === 'system' ? this.resolved : this.mode;
        },

        /**
         * Apply first, persist second.
         *
         * The local application is synchronous and cannot fail, so the switch
         * always responds to the press even if the network request is slow or
         * refused. A failed save is surfaced rather than swallowed, because a
         * preference that silently does not stick is worse than one that never
         * claimed to.
         */
        async choose(mode) {
            if (!window.ReUpTheme) {
                return;
            }

            window.ReUpTheme.set(mode);
            this.sync();

            if (!this.persists) {
                remember(mode);

                return;
            }

            const url = document.documentElement.getAttribute('data-theme-endpoint');

            if (!url) {
                return;
            }

            try {
                await window.axios.put(
                    url,
                    { theme: mode },
                    { headers: { 'Content-Type': 'application/json' } }
                );
            } catch (e) {
                if (window.ReUpFeedback) {
                    window.ReUpFeedback.error(
                        'We could not save your appearance choice. It will apply on this page only.'
                    );
                }
            }
        },

        /** Press handler: advance to the next state in the cycle. */
        cycle() {
            return this.choose(this.next());
        },
    }));
});
