/**
 * Theme contrast probe (temporary verification tool).
 *
 * Renders a page, forces `data-theme`, and reports the *computed* colour of the
 * elements that matter plus their contrast ratio against the page behind them.
 *
 * This exists because the failure it checks for is invisible to every other
 * check: the markup is correct, the CSS is correct, the status is 200 Ã¢â‚¬â€ and the
 * text is the same colour as the background. Reading `getComputedStyle` is the
 * only way to see it without an eye.
 *
 * Usage: node tools/probe-theme-contrast.js <url> [light|dark]
 */
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const [, , urlArg, theme = 'dark'] = process.argv;

if (!urlArg) {
    console.error('usage: node tools/probe-theme-contrast.js <url> [light|dark]');
    process.exit(2);
}

function findChrome() {
    const root = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    if (!fs.existsSync(root)) return null;
    for (const dir of fs.readdirSync(root)) {
        if (!dir.startsWith('chromium')) continue;
        for (const rel of ['chrome-win64/chrome.exe', 'chrome-win/chrome.exe', 'chrome-linux/chrome']) {
            const candidate = path.join(root, dir, rel);
            if (fs.existsSync(candidate)) return candidate;
        }
    }
    return null;
}

const CHROME = findChrome();
if (!CHROME) { console.error('No cached Chromium.'); process.exit(2); }

const PORT = 9344;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'reup-probe-'));
const child = spawn(CHROME, [
    '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars',
    '--window-size=1440,1200', 'about:blank',
], { stdio: ['ignore', 'ignore', 'ignore'] });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const PROBE = `(() => {
    const bodyStyle = getComputedStyle(document.body);
    const probe = document.createElement('div');
    document.body.appendChild(probe);
    const probeBg = getComputedStyle(probe).backgroundColor;
    probe.remove();

    const lum = (rgb) => {
        const m = rgb.match(/\\d+(\\.\\d+)?/g);
        if (!m) return null;
        const [r, g, b] = m.slice(0, 3).map(Number).map((v) => {
            const s = v / 255;
            return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (a, b) => {
        const la = lum(a), lb = lum(b);
        if (la === null || lb === null) return null;
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };
    // Walk up for the first non-transparent background.
    const bgOf = (el) => {
        let node = el;
        while (node && node !== document.documentElement) {
            const bg = getComputedStyle(node).backgroundColor;
            if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg;
            node = node.parentElement;
        }
        return getComputedStyle(document.body).backgroundColor;
    };

    const pageBg = getComputedStyle(document.body).backgroundColor;

    // The support widget, inspected directly: the probe reports the span's
    // *inherited* colour, so this is what tells us whether the anchor above it
    // carries the class at all.
    const wa = document.querySelector('a[href*="wa.me"], a[aria-label*="WhatsApp"]');

    // Which declaration actually wins the support widget's colour?
    const waRules = [];
    if (wa) {
        for (const sheet of document.styleSheets) {
            let rules;
            try { rules = sheet.cssRules; } catch (e) { continue; }
            const walk = (list, path) => {
                for (const rule of list) {
                    if (rule.cssRules && !rule.selectorText) { walk(rule.cssRules, path + '@' + (rule.conditionText || 'group') + '/'); continue; }
                    if (!rule.selectorText || !rule.style) continue;
                    if (!rule.style.color && !rule.style.getPropertyValue('color')) continue;
                    try {
                        if (wa.matches(rule.selectorText)) {
                            waRules.push(path + rule.selectorText + ' => ' + rule.style.color + ' [priority ' + (rule.style.getPropertyPriority('color') || 'none') + ']');
                        }
                    } catch (e) {}
                }
            };
            walk(rules, '');
        }
    }

    const waInfo = wa ? {
        classes: wa.className,
        colour: getComputedStyle(wa).color,
        ink950Var: getComputedStyle(wa).getPropertyValue('--color-ink-950').trim(),
        backgroundColor: getComputedStyle(wa).backgroundColor,
        matchingColorRules: waRules,
        // A scratch element carrying the utility, measured in isolation.
        scratch: (() => {
            const el = document.createElement('span');
            el.className = 'text-ink-950';
            el.textContent = 'x';
            document.body.appendChild(el);
            const c = getComputedStyle(el).color;
            const v = getComputedStyle(el).getPropertyValue('--color-ink-950').trim();
            el.remove();
            return { color: c, variable: v, matches: wa ? wa.matches('.text-ink-950') : null };
        })(),
    } : null;

    // Every rule in the CSSOM that declares --color-ink-950, with its selector
    // and the order the browser walks them in.
    const ink950Rules = [];
    for (const sheet of document.styleSheets) {
        let rules;
        try { rules = sheet.cssRules; } catch (e) { continue; }
        const walk = (list, layer) => {
            for (const rule of list) {
                if (rule.cssRules && !rule.selectorText) {
                    walk(rule.cssRules, (layer ? layer + '>' : '') + (rule.constructor.name === 'CSSLayerBlockRule' ? rule.name : (rule.conditionText || 'group')));
                    continue;
                }
                if (!rule.style) continue;
                const decl = rule.style.getPropertyValue('--color-ink-950');
                if (decl) ink950Rules.push({ layer, selector: rule.selectorText, value: decl });
            }
        };
        walk(rules, '');
    }

    // What the rules actually say, read from the CSSOM. If this disagrees with
    // the computed value, something later in the cascade is winning.
    const matched = [];
    const sheetAudit = [];
    for (const sheet of document.styleSheets) {
        let rules;
        try { rules = sheet.cssRules; } catch (e) { sheetAudit.push((sheet.href || 'inline') + ' -> BLOCKED: ' + e.name); continue; }
        sheetAudit.push((sheet.href || 'inline').split('/').pop() + ' -> ' + rules.length + ' rules');
        const walk = (list) => {
            for (const rule of list) {
                if (rule.cssRules && !rule.selectorText) { walk(rule.cssRules); continue; }
                if (!rule.selectorText) continue;
                if (rule.selectorText === 'body') {
                    matched.push({ sheet: (sheet.href || 'inline').split('/').pop(), selector: 'body', text: rule.style.cssText.slice(0, 200) });
                }
            }
        };
        walk(rules);
    }
    const targets = [];

    const add = (label, el) => {
        if (!el) return;
        const cs = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return;
        const bg = bgOf(el);
        targets.push({
            label,
            colour: cs.color,
            background: bg,
            contrast: ratio(cs.color, bg),
            sample: (el.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 48),
        });
    };

    add('h1', document.querySelector('h1'));
    add('h2', document.querySelector('h2'));
    add('hero paragraph', document.querySelector('h1 + p'));
    add('hero stat label', document.querySelector('dl dt'));
    add('nav link', document.querySelector('header nav a'));

    // Every visible text node on the page, paired with the background it is
    // actually painted on. This is the assertion that matters: an element can
    // be perfectly styled and still be the same colour as what is behind it.
    const seen = new Set();
    document.querySelectorAll('body *').forEach((el) => {
        if (el.children.length > 0) return;
        const text = (el.innerText || '').trim();
        if (!text) return;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0 || r.top > 1400) return;
        const cs = getComputedStyle(el);
        const bg = bgOf(el);
        const c = ratio(cs.color, bg);
        if (c === null || c >= 4.5) return;
        const key = cs.color + '|' + bg + '|' + text.slice(0, 24);
        if (seen.has(key)) return;
        seen.add(key);
        targets.push({
            label: 'LOW',
            selector: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(/\s+/).slice(0, 4).join('.') : ''),
            colour: cs.color,
            background: bg,
            contrast: c,
            sample: text.slice(0, 60),
        });
    });

    return JSON.stringify({
        theme: document.documentElement.getAttribute('data-theme'),
        rootAttr: document.documentElement.outerHTML.slice(0, 220),
        pageBackground: pageBg,
        computedBodyBackground: bodyStyle.backgroundColor,
        whatsapp: waInfo,
        ink950Rules,
        computedBodyVarBackground: bodyStyle.getPropertyValue('--color-background').trim(),
        htmlBackground: getComputedStyle(document.documentElement).backgroundColor,
        probeBackground: probeBg,
        bodyText: getComputedStyle(document.body).color,
        h1Colour: document.querySelector('h1') ? getComputedStyle(document.querySelector('h1')).color : null,
        sheets: [...document.styleSheets].map((s) => s.href || 'inline'),
        cssVarBackground: getComputedStyle(document.documentElement).getPropertyValue('--color-background').trim(),
        cssVarInk950: getComputedStyle(document.documentElement).getPropertyValue('--color-ink-950').trim(),
        cssVarSurfacePage: getComputedStyle(document.documentElement).getPropertyValue('--color-surface-page').trim(),
        cssVarPrimary: getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim(),
        cssVarPrimaryFg: getComputedStyle(document.documentElement).getPropertyValue('--color-primary-foreground').trim(),
        cssVarBrand400: getComputedStyle(document.documentElement).getPropertyValue('--color-brand-400').trim(),
        sheetHref: [...document.styleSheets].map((s) => s.href || 'inline').join(' | '),
        matched,
        sheetAudit,
        ruleMatches: [...document.styleSheets].some((sheet) => {
            try {
                return [...sheet.cssRules].some((r) => r.selectorText === 'html[data-theme=dark]');
            } catch (e) {
                return false;
            }
        }),
        targets,
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
    ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
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

    for (const domain of ['Page', 'Runtime']) await send(`${domain}.enable`, {}, sid);

    await send('Page.navigate', { url: urlArg }, sid);
    await sleep(3500);

    // Force the theme first, then let the browser recalculate before measuring.
    // Doing both in one evaluation reads the *old* computed styles: the
    // attribute is set, but nothing has been repainted yet, so every colour
    // comes back as the theme the page booted in. That produced a probe which
    // reported "dark" and then measured white.
    await send('Runtime.evaluate', {
        expression: `(() => {
            document.documentElement.setAttribute('data-theme', '${theme}');
            document.documentElement.style.colorScheme = '${theme}';
        })()`,
    }, sid);

    await sleep(600);

    const { result } = await send('Runtime.evaluate', {
        expression: PROBE, returnByValue: true,
    }, sid);

    const info = JSON.parse(result.result.value);

    console.log(`theme forced:   ${info.theme}`);
    console.log(`html tag:       ${info.rootAttr}`);
    console.log(`stylesheets:    ${info.sheets.join(' | ')}`);
    console.log(`rule matched:   ${info.ruleMatches}`);
    console.log(`--color-background:   ${info.cssVarBackground}`);
    console.log(`--color-ink-950:      ${info.cssVarInk950}`);
    console.log(`--color-surface-page: ${info.cssVarSurfacePage}`);
    console.log(`--color-primary:      ${info.cssVarPrimary}   foreground: ${info.cssVarPrimaryFg}`);
    console.log(`--color-brand-400:    ${info.cssVarBrand400}`);
    console.log(`page background:${info.pageBackground}`);
    console.log(`computed body bg:${info.computedBodyBackground}  (body var: ${info.computedBodyVarBackground})`);
    console.log(`html background: ${info.htmlBackground}`);
    console.log(`new element bg:  ${info.probeBackground}`);
    console.log('whatsapp widget: ' + JSON.stringify(info.whatsapp));
    console.log('rules declaring --color-ink-950, in cascade order:');
    for (const r of (info.ink950Rules || [])) {
        console.log('  ' + (r.layer || 'unlayered').padEnd(18) + ' ' + r.selector.padEnd(30) + ' ' + r.value);
    }
    console.log('');
    console.log('stylesheet audit:');
    for (const s of info.sheetAudit) console.log('  ' + s);
    console.log('');
    console.log('body rules seen:');
    for (const m of info.matched) console.log(`  [${m.sheet}] ${m.selector} { ${m.text} }`);
    console.log('');
    console.log(`body text:      ${info.bodyText}`);
    console.log(`h1 colour:      ${info.h1Colour}`);
    console.log('');
    console.log('label'.padEnd(20), 'contrast'.padEnd(10), 'colour'.padEnd(26), 'background');
    for (const t of info.targets) {
        const c = t.contrast === null ? '?' : t.contrast.toFixed(2);
        const flag = t.contrast !== null && t.contrast < 4.5 ? '  <-- FAILS' : '';
        console.log(
            t.label.padEnd(20),
            (c + ':1').padEnd(10),
            t.colour.padEnd(26),
            t.background + flag
        );
        if (t.selector) {
            console.log(' '.repeat(20) + t.selector.slice(0, 90));
            console.log(' '.repeat(20) + '"' + (t.sample || '') + '"');
        }
    }

    ws.close();
    child.kill();
})().catch((err) => { console.error('ERR', err.message); child.kill(); process.exit(1); });
