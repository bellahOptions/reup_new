import './bootstrap';

// Global form behaviour: loading state on the clicked button, and one submit per
// attempt. Must be imported before Alpine so it is listening before any
// component can submit.
import './forms';

// Global feedback channel: toasts for script-driven requests, and a fetch
// wrapper so nothing fails silently.
import './feedback';

// Wallet funding: background submit with immediate feedback. Must be imported
// before Alpine.start() so `window.walletFunding` exists when the funding
// page's x-data is evaluated.
import './wallet-funding';

// Admin transaction modal actions. Imported globally rather than only on the
// admin page because the modal's markup is injected with innerHTML, which never
// executes <script> — the handlers have to already be on `window`.
import './admin-transactions';

// Appearance switch. Registered before Alpine.start() so the `themeSwitch`
// component exists by the time any element with x-data="themeSwitch" is
// evaluated.
import './theme';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
