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

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
