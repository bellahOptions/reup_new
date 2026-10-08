# ReUp UI Runbook

Internal reference for the design system. Read before touching any Blade view.

## Where things live

| Thing | Path |
| --- | --- |
| Design tokens + component classes | `resources/css/app.css` |
| Icon component | `resources/views/components/icon.blade.php` |
| Shared head (Vite, fonts, favicons) | `resources/views/partials/head.blade.php` |
| Flash toasts | `resources/views/partials/flash.blade.php` |
| Support FAB | `resources/views/partials/support-widget.blade.php` |
| Customer navbar | `resources/views/layouts/navbar.blade.php` |
| Customer shell (`@extends('layouts.app')`) | `resources/views/layouts/app.blade.php` |
| Marketing shell (`@extends('layouts.main')`) | `resources/views/layouts/main.blade.php` |
| Auth shell (component, `{{ $slot }}`) | `resources/views/layouts/guest.blade.php` |
| Admin shell (`@extends('admin.layouts.app')`) | `resources/views/admin/layouts/app.blade.php` |

## Hard rules

1. **No emoji.** Ever, in any view, flash message or email. Use `<x-icon name="…" />`.
2. **No CDN Tailwind.** `@vite` is already in `partials/head.blade.php`. Never add
   `<script src="…tailwindcss/browser…">`, jQuery, Font Awesome, or Bootstrap.
3. **No inline `<style>` blocks.** Add the rule to `resources/css/app.css` instead.
4. **No hardcoded brand hex.** Use the semantic utilities below.
5. **Escape output.** Blade `{{ }}` for anything user-supplied. `{!! !!}` only for
   content already sanitised by the server.
6. **Accessible names.** Icon-only buttons need `aria-label`. Decorative icons are
   `aria-hidden` already (the component sets it).
7. **Browser JS must be ESM.** Never use `require()` or `module.exports` in
   anything under `resources/js/`. Vite does not polyfill `require`; it emits the
   call verbatim, so the bundle throws `ReferenceError: require is not defined`
   at its first statement and Alpine never starts — every `x-cloak` element stays
   hidden and every `x-text` binding renders empty. The page still returns HTTP
   200 with correct CSS, so this failure is invisible unless the JS executes.
   `php tools/check-assets.php` fails the build if CommonJS creeps back in.

## Brand

Anchor colour is `#2AB70D` (from `public/images/reup-03.svg`). The full ramp is
`brand-50 … brand-950`. Semantic aliases exist so views never name a shade:

| Utility | Meaning |
| --- | --- |
| `bg-background` / `bg-surface` | page canvas / recessed panel |
| `text-foreground` / `text-muted-foreground` | primary / secondary text |
| `border-border` | hairline separator |
| `bg-primary` `text-primary` `border-primary` | brand (`brand-500`) |
| `bg-accent` `text-accent-foreground` | brand tint (`brand-50` on `brand-700`) |
| `text-destructive` | errors |
| `bg-ink-50 … ink-950` | neutral scale (green-leaning) |

Prefer `border-border` + `bg-white` + `shadow-subtle` over heavy shadows. Elevation
(`shadow-overlay`) is reserved for popovers, dropdowns and modals.

## Component classes

`resources/css/app.css` defines these. Use them instead of long utility soup for
anything that repeats.

**Buttons** — base `btn`, then one variant, plus an optional size.
```
btn btn-primary            default
btn btn-outline btn-sm     small outline
btn btn-ghost btn-icon     square icon-only
btn btn-destructive
btn btn-secondary
```
Sizes: `btn-sm`, `btn-lg`, `btn-icon`.

**Cards**
```
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Title</h2>
    <p class="card-description">Supporting copy</p>
  </div>
  <div class="card-content">…</div>
  <div class="card-footer">…</div>
</div>
```

**Forms**
```
<label class="label" for="x">Label</label>
<input id="x" class="input" />
<select class="select">…</select>
<textarea class="textarea"></textarea>
<p class="field-error">Message</p>       {{-- or add .input-error to the control --}}
<input type="checkbox" class="checkbox" />
```

**Badges** — `badge` + one of `badge-neutral`, `badge-primary`, `badge-success`,
`badge-warning`, `badge-destructive`, `badge-info`.

**Tables**
```
<table class="table">
  <thead><tr><th>…</th></tr></thead>
  <tbody><tr><td>…</td></tr></tbody>
</table>
```
Wrap in `<div class="overflow-x-auto">`.

**Other** — `container-page`, `stat-value`, `stat-label`, `divider`, `link`,
`empty-state`, `sidebar-link`, `sidebar-link-active`,
`scrollbar-slim`, `pause-on-hover`, `legal-prose`.

Page headings are the `h1` alone. A small uppercase label above a heading
(`section-eyebrow`) was removed site-wide — do not reintroduce it.

`legal-prose` is the typographic scale for server-stored HTML (terms of service,
privacy policy). It styles bare `h1`–`h4`, `p`, `ul`, `ol`, `table`, `blockquote`,
`hr` and `code` descendants, so a rich-text body needs no per-element classes.
Wrap it in `overflow-hidden` when the source can contain wide tables.

## Icons

```blade
<x-icon name="wallet" />                       {{-- 20px, 1.6 stroke --}}
<x-icon name="wallet" class="h-4 w-4" />       {{-- override size via class --}}
<x-icon name="check-circle" variant="solid" class="h-5 w-5 text-brand-600" />
```

Available names (see the `$outline` / `$solid` arrays in the component for the
authoritative list — do not invent names):

`home gauge phone device-phone-mobile wifi signal tv bolt credit-card wallet
banknotes receipt-percent chart-bar chart-pie arrow-trending-up arrow-down-tray
arrow-up-tray arrow-up-right arrow-right arrow-left chevron-right chevron-down
chevron-up chevron-left plus minus x-mark check check-circle shield-check
lock-closed key identification user users user-plus user-minus envelope
chat-bubble-left-right chat-bubble-oval-left paper-airplane bell megaphone
document-text clipboard-document-list clock calendar cog-6-tooth
adjustments-horizontal magnifying-glass funnel arrows-up-down ellipsis-horizontal
ellipsis-vertical bars-3 bars-3-bottom-left arrow-path arrow-right-on-rectangle
arrow-left-on-rectangle exclamation-triangle exclamation-circle information-circle
question-mark-circle truck building-library building-office-2 academic-cap
document-check printer gift sparkles star heart globe-alt map-pin light-bulb
inbox inbox-stack at-symbol link eye eye-slash trash pencil-square pencil
document-duplicate arrow-down-circle arrow-up-circle no-symbol pause-circle
play-circle fire finger-print queue-list code-bracket server-stack circle-stack
swatch book-open lifebuoy rocket-launch wrench-screwdriver`

Solid variants (use for status glyphs): `check-circle`, `exclamation-circle`,
`information-circle`, `exclamation-triangle`, `bell`, `star`, `bolt`,
`shield-check`, `user`, `users`, `lock-closed`, `wallet`, `megaphone`, `envelope`,
`chat-bubble-left-right`, `clock`, `trash`, `check`, `x-mark`.

## Mapping emoji → icon

| Emoji | Icon |
| --- | --- |
| 📱 📲 | `device-phone-mobile` / `phone` |
| 📶 📡 🌐 | `signal` / `wifi` / `globe-alt` |
| 💳 💰 💵 | `credit-card` / `wallet` / `banknotes` |
| 🏦 | `building-library` |
| ⚡ | `bolt` |
| 📺 | `tv` |
| 💡 | `light-bulb` |
| 🎓 📝 ✏️ | `academic-cap` / `document-check` |
| 🔒 🔐 🛡️ | `lock-closed` / `shield-check` |
| 📊 📈 📉 | `chart-bar` / `arrow-trending-up` |
| 📢 📣 🔔 | `megaphone` / `bell` |
| 📧 ✉️ 📩 | `envelope` / `inbox` / `inbox-stack` |
| 💬 🗨️ | `chat-bubble-left-right` / `chat-bubble-oval-left` |
| 👤 👥 🙋 | `user` / `users` |
| ⏳ ⏰ 🕐 | `clock` |
| ✅ ✓ | `check` or `<x-icon name="check-circle" variant="solid" />` |
| ❌ ✗ ⛔ | `x-mark` / `no-symbol` |
| ⚠️ ❗ | `exclamation-triangle` |
| ℹ️ | `information-circle` |
| 🚀 ✨ | `rocket-launch` / `sparkles` |
| ⭐ | `star` |
| 🎁 | `gift` |
| 📄 📋 📃 | `document-text` / `clipboard-document-list` |
| 🔍 | `magnifying-glass` |
| ⚙️ 🔧 | `cog-6-tooth` / `wrench-screwdriver` |
| 🗑️ | `trash` |
| 👁️ | `eye` / `eye-slash` |
| ➡️ ← | `arrow-right` / `arrow-left` |
| 🔄 ♻️ | `arrow-path` |
| 🏠 | `home` |
| 📍 | `map-pin` |
| 🎯 | `funnel` |
| 🆘 🛟 | `lifebuoy` |
| 🏢 | `building-office-2` |
| 🔥 | `fire` |
| 💡 tip | `light-bulb` |
| 🖨️ | `printer` |
| 📥 📤 | `arrow-down-tray` / `arrow-up-tray` |
| 🧾 | `receipt-percent` |
| 🔗 | `link` |
| 🕵️ | `finger-print` |
| Any flag (🇳🇬 etc.) | remove — replace with text |
| Any unlisted emoji | choose the nearest semantic icon above |

Wrap the icon in the same container the emoji lived in and move size/colour
utilities across, e.g.
```blade
- <span class="text-3xl">📱</span>
+ <x-icon name="device-phone-mobile" class="h-7 w-7 text-brand-600" />
```

## Status colour conventions

| State | Classes |
| --- | --- |
| success / credit / active | `badge-success` or `text-green-700` |
| pending / verifying | `badge-warning` or `text-amber-700` |
| processing / info | `badge-info` or `text-sky-700` |
| failed / debit / destructive | `badge-destructive` or `text-red-700` |
| cancelled / neutral | `badge-neutral` |

## Admin specifics (shadcn/ui idiom)

The admin shell already provides the sidebar, header, breadcrumbs slot
(`@section('page-title')`, `@section('page-description')`, `@section('page-actions')`)
and flash toasts. Inside `@section('content')`:

- **Do not** add a second page heading; the header renders it.
- Page background is `bg-surface`; content sits on `bg-white` cards with
  `border-border` hairlines.
- Data density matters: `table` class, `text-sm`, `tabular-nums` for numbers.
- Toolbars: put filters in a `card` with `p-4` and a `flex flex-wrap gap-3`.
- Empty states use `empty-state` with a muted icon.
- Metrics use `stat-label` + `stat-value`.
- No gradients, no `shadow-2xl`, no `hover:translate-x`, no coloured sidebars.
- Destructive actions need a confirm step and `btn-destructive`.

## Component logic belongs in the bundle, not in `x-data`

`resources/js/wallet-funding.js` exists because an inline Alpine component
shipped a bug that **no server-side test could see**.

Alpine evaluates `x-data="..."` at runtime with `new Function`. If the
expression does not parse, the whole component fails — and because Alpine
resolves bindings against it, *every* binding on the page dies at once. The
console fills with `ReferenceError: submitting is not defined`,
`ReferenceError: outcomeTone is not defined`, one per attribute, none of which
points at the real cause. The single useful line is a bare
`SyntaxError: Invalid or unexpected token`.

The trigger was mundane: a double quote inside a JavaScript comment —

```js
/*
 * A full-page POST keeps the old page — and its "Processing…" label — on screen
 */
```

That `"` closes the **HTML attribute** early. The browser hands Alpine a
truncated, unterminated object literal, and the page is dead. Nothing about the
rendered status code, the markup or the CSS reveals it.

So:

* **Put component logic in `resources/js/*.js`** and pass only server values into
  the attribute: `x-data="walletFunding({ amount: @js(...), ... })"`.
* **Run the browser audit after touching a component.** `php tools/check-assets.php`
  proves the bundle loads; it does not prove an inline expression parses. The
  audit is what catches this class of bug:

  ```
  node tools/browser-audit.js "http://127.0.0.1:8000/wallet/fund" cookie.txt
  ```

  Note the cookie file must be one line, `reup_session=<value>` — **with** the
  name. A bare value silently makes every request unauthenticated, the tool
  follows the redirect to `/login`, and it then reports `ALL GREEN` for the login
  page you did not ask about. Read the reported URL, not just the verdict.

### Interaction with `resources/js/forms.js`

`forms.js` listens for `submit` **globally** and, when the event has not already
been prevented, replaces the clicked button's `innerHTML` with a spinner. An
Alpine `@submit` attribute does not prevent the event by itself, so a handler
that submits with `fetch` must call `preventDefault()` **synchronously, before
its first `await`** — otherwise the global listener wipes the button's child
nodes, including the `x-show`/`x-text` spans, and the button never recovers.
`wallet-funding.js` also keeps `data-no-loading` on the form as an explicit
second signal.

## Wallet funding submits in the background

The funding form posts with `fetch()` and renders the JSON reply, rather than
doing a page POST. A page POST leaves the old page — and its "Processing…"
label — on screen until the server answers, and that answer waits on a card
gateway (two, when the Bachs fallback is in play), each with a 30s timeout. That
is what made a slow provider look like an infinite spinner.

The endpoint returns either `{status: "redirect", redirect: <gateway url>}` or
`502 {status: "failed", message: ...}`, and the client aborts at 20s so an
unresponsive provider still produces a readable outcome. A plain, non-JSON POST
still gets a real redirect, so a browser without JavaScript is not broken by it.

### Markup injected with `innerHTML` never runs its scripts

The admin transaction modal fetches its body over AJAX and assigns it to
`innerHTML`. Two separate things go wrong when that body carries its own
JavaScript, and they happened together:

* **`innerHTML` does not execute `<script>`.** Injected markup is inert. Only
  the HTML parser runs scripts, so any handler defined in the fetched partial
  simply does not exist on the page.
* **`@push('scripts')` is not emitted by a partial.** The stack is rendered by
  the *layout*; fetching the partial directly means nothing renders it, and the
  script body is flushed into the markup as **literal text**. An operator
  opening the modal was shown the source code of their own buttons.

So the modal's action handlers live in `resources/js/admin-transactions.js`,
imported globally from `app.js` so they are on `window` before the modal can be
opened. If you add a control to an AJAX-injected partial, put its handler in the
bundle — never in a `@push` inside that partial.

Anything the partial needs to know about itself (its URLs, its reference) is
published as a `data-*` attribute and read from the host page, because the
partial is rendered separately from the page that displays it.

## Verifying a change

```
npm run build             # must succeed
php tools/check-assets.php # asserts rendered markup AND that the JS bundle is ESM-executable
php artisan route:list    # must not throw
php -l <changed file>
```

`tools/check-assets.php` is the guard that matters most here: it asserts on the
*rendered output* and on the shipped bundle, not on HTTP status codes. It exists
because two shipped bugs were invisible to status-code checks — `@vite` never
compiling, and a CommonJS bundle aborting before Alpine started.

### Checking the UI in a real browser

Static checks cannot see a JavaScript failure. To confirm pages actually
hydrate, render them in headless Chromium:

```
node tools/browser-audit.js "http://127.0.0.1:8001/wallet/fund,http://127.0.0.1:8001/airtime"
```

It reports, per page, whether Alpine booted, whether any `[x-cloak]` element is
still visible, whether any visible `x-text` binding is empty, and every console
error. Exit code is non-zero if any page fails, so it is CI-usable.

For pages behind auth, pass a cookie file holding one line,
`reup_session=<value>`, copied from a logged-in browser:

```
node tools/browser-audit.js "http://127.0.0.1:8001/wallet/fund" cookie.txt shots/
```

An optional third argument writes a screenshot per page (useful for eyeballing
layout and confirming logos/icons render).

It reuses the Chromium that Playwright has already cached on the machine, so
there is no Playwright dependency in `package.json`.

### Environment gotchas

`php artisan env:doctor` reports variables set in the *process* environment that
shadow `.env`. Dotenv never overwrites a real environment variable, so a stray
exported `MAIL_HOST=mailhog` or `APP_NAME=Laravel` silently wins over `.env` and
produces confusing failures (mail 502s, wrong "from" name). Restart the shell or
supervisor that exported them, then re-run `env:doctor`.
