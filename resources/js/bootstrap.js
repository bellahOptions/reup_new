import axios from 'axios';

/**
 * axios is exposed globally so inline scripts and Alpine components can issue
 * XHR requests without importing it. It handles sending the CSRF token as a
 * header based on the value of the "XSRF" token cookie.
 *
 * NOTE: this file previously used CommonJS `require()`. Vite leaves unresolved
 * `require()` calls in the browser bundle verbatim, which threw
 * "ReferenceError: require is not defined" as the very first statement of the
 * entry chunk — aborting execution before Alpine.start() ran. Every `x-cloak`
 * element therefore stayed hidden and every `x-text` binding rendered empty.
 * Keep this file ESM-only.
 */
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
