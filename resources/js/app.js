import './bootstrap';

// Global form behaviour: loading state on the clicked button, and one submit per
// attempt. Must be imported before Alpine so it is listening before any
// component can submit.
import './forms';

// Global feedback channel: toasts for script-driven requests, and a fetch
// wrapper so nothing fails silently.
import './feedback';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
