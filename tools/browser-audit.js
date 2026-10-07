/**
 * Browser UI audit.
 *
 * Renders pages in a real Chromium and reports what the browser actually
 * executes, not just what the server returns. This exists because the worst
 * front-end bug in this project was invisible to status codes: the entry
 * chunk threw "ReferenceError: require is not defined" on its first statement,
 * so Alpine never started, every `x-cloak` element stayed hidden and every
 * `x-text` binding rendered empty — while every response was HTTP 200 with
 * correct CSS and correct markup.
 *
 * Checks per page:
 *   - Alpine actually booted (window.Alpine defined)
 *   - no `[x-cloak]` element is still visible after hydration
 *   - no visible element has an empty `x-text` binding
 *   - console errors / uncaught exceptions
 *
 * Requires an authenticated session cookie for pages behind auth.
 *
 * Usage:
 *   node tools/browser-audit.js <url[,url...]> [cookieFile] [screenshotDir]
 *
 * The cookie file holds one line, "reup_session=<value>", taken from a
 * logged-in browser. See docs/UI_RUNBOOK.md.
 */
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const [, , urlArg, cookieFile, outDir] = process.argv;

if (!urlArg) {
    console.error('usage: node tools/browser-audit.js <url[,url...]> [cookieFile] [screenshotDir]');
    process.exit(2);
}

const urls = urlArg.split(',').map((u) => u.trim()).filter(Boolean);

/** Locate the Chromium that Playwright cached; there is no Playwright dependency here. */
function findChrome() {
    const root = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    if (!fs.existsSync(root)) return null;

    for (const dir of fs.readdirSync(root)) {
        if (!dir.startsWith('chromium')) continue;
        for (const rel of [
            'chrome-win64/chrome.exe', 'chrome-win/chrome.exe',
            'chrome-linux/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
        ]) {
            const candidate = path.join(root, dir, rel);
            if (fs.existsSync(candidate)) return candidate;
        }
    }
    return null;
}

const CHROME = findChrome();
if (!CHROME) {
    console.error('No cached Chromium found under the Playwright browser cache.');
    console.error('Install one with: npx playwright install chromium');
    process.exit(2);
}

const PORT = 9333;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'reup-audit-'));
const child = spawn(CHROME, [
    '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars',
    '--window-size=1440,1200', 'about:blank',
], { stdio: ['ignore', 'ignore', 'ignore'] });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Runs inside the page; returns a JSON string. */
const PROBE = `(() => {
    const visible = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const text = (el) => el ? el.innerText.replace(/\\s+/g, ' ').trim() : null;
    const stuck = [...document.querySelectorAll('[x-cloak]')].filter(e => getComputedStyle(e).display !== 'none');
    const empties = [...document.querySelectorAll('[x-text]')].filter(e => visible(e) && !e.innerText.trim());
    return JSON.stringify({
        title: document.title,
        url: location.href,
        h1: text(document.querySelector('h1')),
        alpine: typeof window.Alpine !== 'undefined',
        stuckCloak: stuck.length,
        stuckSample: stuck.slice(0, 3).map(e => (e.id || e.className || e.tagName).toString().slice(0, 70)),
        emptyBindings: empties.length,
        emptySample: empties.slice(0, 4).map(e => (e.getAttribute('x-text') || '').slice(0, 50)),
        visibleInputs: [...document.querySelectorAll('input:not([type=hidden]), select')].filter(visible).length,
        bodyText: document.body.innerText.replace(/\\s+/g, ' ').trim().slice(0, 200),
    });
})()`;

(async () => {
    let wsUrl;
    for (let i = 0; i < 60 && !wsUrl; i++) {
        try {
            const r = await fetch(`http://127.0.0.1:${PORT}/json/version`);
            if (r.ok) wsUrl = (await r.json()).webSocketDebuggerUrl;
        } catch {}
        if (!wsUrl) await sleep(250);
    }
    if (!wsUrl) throw new Error('Chromium did not expose a CDP endpoint');

    const ws = new WebSocket(wsUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let id = 0;
    const pending = new Map();
    let events = [];
    ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else if (m.method) events.push(m);
    };
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const msg = { id: ++id, method, params };
        if (sessionId) msg.sessionId = sessionId;
        pending.set(msg.id, res);
        ws.send(JSON.stringify(msg));
    });

    const { result: targets } = await send('Target.getTargets');
    const page = targets.targetInfos.find((t) => t.type === 'page');
    const { result: att } = await send('Target.attachToTarget', { targetId: page.targetId, flatten: true });
    const sid = att.sessionId;

    for (const domain of ['Page', 'Runtime', 'Log', 'Network']) await send(`${domain}.enable`, {}, sid);

    if (cookieFile && cookieFile !== '-' && fs.existsSync(cookieFile)) {
        const raw = fs.readFileSync(cookieFile, 'utf8').trim();
        const [name, ...rest] = raw.split('=');
        await send('Network.setCookie', {
            name, value: rest.join('='), domain: '127.0.0.1', path: '/', httpOnly: true,
        }, sid);
    }

    let failures = 0;

    for (const url of urls) {
        events = [];
        await send('Page.navigate', { url }, sid);
        await sleep(3000);

        const { result } = await send('Runtime.evaluate', { expression: PROBE, returnByValue: true }, sid);
        const info = JSON.parse(result.result.value);

        const exceptions = events
            .filter((e) => e.method === 'Runtime.exceptionThrown')
            .map((e) => (e.params.exceptionDetails.exception?.description || e.params.exceptionDetails.text || '').split('\n')[0]);
        const logErrors = events
            .filter((e) => e.method === 'Log.entryAdded' && e.params.entry.level === 'error')
            .map((e) => e.params.entry.text);

        const problems = [];
        if (!info.alpine) problems.push('Alpine did not start');
        if (info.stuckCloak > 0) problems.push(`${info.stuckCloak} x-cloak element(s) still visible: ${info.stuckSample.join(', ')}`);
        if (info.emptyBindings > 0) problems.push(`${info.emptyBindings} empty x-text binding(s): ${info.emptySample.join(', ')}`);
        for (const err of exceptions) problems.push('exception: ' + err);
        for (const err of logErrors) problems.push('console: ' + err.slice(0, 140));

        if (problems.length) failures++;

        console.log(`${problems.length ? 'FAIL' : 'OK  '}  ${info.title}`);
        console.log(`       ${info.url}`);
        console.log(`       h1="${info.h1}"  alpine=${info.alpine}  inputs=${info.visibleInputs}`);
        for (const p of problems) console.log(`       -> ${p}`);

        if (outDir && outDir !== '-') {
            fs.mkdirSync(outDir, { recursive: true });
            const shot = await send('Page.captureScreenshot', { format: 'png' }, sid);
            const slug = url.replace(/^https?:\/\/[^/]+/, '').replace(/\W+/g, '_') || 'root';
            if (shot.result) fs.writeFileSync(path.join(outDir, `${slug}.png`), Buffer.from(shot.result.data, 'base64'));
        }
    }

    console.log(`\n${failures ? `FAILED (${failures}/${urls.length})` : `ALL GREEN (${urls.length} page(s))`}`);

    ws.close();
    child.kill();
    process.exit(failures ? 1 : 0);
})().catch((err) => {
    console.error('ERR', err.message);
    child.kill();
    process.exit(1);
});
