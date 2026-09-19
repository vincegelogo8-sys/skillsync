// Run after SKILLSYNC_E2E_CAPTURE=1 generates test-only HTML snapshots.
// Uses installed Edge and Node built-ins; no browser package or production login.
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';

const output = resolve('storage/app/testing/e2e');
const profile = mkdtempSync(resolve('storage/app/testing/edge-verify-'));
const executable = process.env.SKILLSYNC_EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const browser = spawn(executable, ['--headless=new', '--disable-gpu', '--no-first-run', '--disable-background-networking', '--allow-file-access-from-files', '--remote-debugging-port=0', `--user-data-dir=${profile}`], { windowsHide: true, stdio: 'ignore' });
let socket;
let sequence = 0;
const pending = new Map();
const command = (method, params = {}, sessionId) => new Promise((resolveCommand, reject) => {
    const id = ++sequence;
    const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`Timeout: ${method}`)); }, 15000);
    pending.set(id, { resolve: resolveCommand, reject, timeout });
    socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
});

try {
    const portFile = join(profile, 'DevToolsActivePort');
    for (let i = 0; !existsSync(portFile) && i < 200; i++) await pause(100);
    if (!existsSync(portFile)) throw new Error('Edge debugging endpoint did not start.');
    const [port, endpoint] = readFileSync(portFile, 'utf8').trim().split(/\r?\n/);
    socket = new WebSocket(`ws://127.0.0.1:${port}${endpoint}`);
    await new Promise((resolveOpen, reject) => { socket.onopen = resolveOpen; socket.onerror = reject; });
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        const entry = pending.get(message.id);
        if (!entry) return;
        clearTimeout(entry.timeout);
        pending.delete(message.id);
        if (message.error) entry.reject(new Error(JSON.stringify(message.error)));
        else entry.resolve(message.result);
    };
    const { targetId } = await command('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await command('Target.attachToTarget', { targetId, flatten: true });
    await command('Page.enable', {}, sessionId);
    await command('Network.enable', {}, sessionId);
    // Local assets must render without any external network requests.
    await command('Network.setBlockedURLs', { urls: ['http://*', 'https://*'] }, sessionId);
    await command('Page.bringToFront', {}, sessionId);
    await command('Emulation.setFocusEmulationEnabled', { enabled: true }, sessionId);
    const evaluate = async (expression) => (await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId)).result.value;
    const report = [];
    for (const [name, source, width, mobile] of [
        ['admin-desktop', 'admin-pdf', 1440, false],
        ['faculty-desktop', 'faculty-pdf', 1440, false],
        ['student-desktop', 'student-pdf', 1440, false],
        ['student-mobile', 'student-pdf', 390, true],
        ['admin-mobile', 'admin-pdf', 390, true],
        ['faculty-tablet', 'faculty-pdf', 768, true],
        ['student-small-mobile', 'student-pdf', 320, true],
        ['ranking-desktop', 'ranking-pdf', 1440, false],
        ['ranking-mobile', 'ranking-pdf', 390, true],
    ]) {
        await command('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile }, sessionId);
        await command('Page.navigate', { url: pathToFileURL(join(output, `${source}.html`)).href }, sessionId);
        await pause(500);
        await evaluate('document.fonts.ready.then(() => true)');
        const metrics = await evaluate('({width:innerWidth,client:document.documentElement.clientWidth,scroll:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,styles:document.styleSheets.length})');
        const selector = name.startsWith('ranking') ? 'article details' : '[data-dropdown]';
        await evaluate(`window.checkDetails = [...document.querySelectorAll(${JSON.stringify(selector)})].at(-1); window.checkDetails.querySelector('summary').focus(); true`);
        await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' }, sessionId);
        await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 }, sessionId);
        const keyboardOpened = await evaluate('window.checkDetails.open');
        let navigationPassed = true;
        if (mobile) {
            navigationPassed = await evaluate(`(() => {
                const menu = document.querySelector('[data-sidebar-toggle]');
                const sidebar = document.querySelector('.sidebar');
                const main = document.querySelector('.app-main');
                const overlay = document.querySelector('[data-sidebar-overlay]');
                const initiallyHidden = getComputedStyle(sidebar).visibility === 'hidden';
                menu.click();
                const opened = sidebar.classList.contains('is-open') && main.inert && !overlay.hidden && menu.getAttribute('aria-expanded') === 'true';
                overlay.click();
                const closed = !sidebar.classList.contains('is-open') && !main.inert && overlay.hidden && document.activeElement === menu;
                return initiallyHidden && opened && closed;
            })()`);
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); true`);
            await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }, sessionId);
            await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }, sessionId);
            navigationPassed &&= await evaluate(`!document.querySelector('.sidebar').classList.contains('is-open') && !document.querySelector('.app-main').inert`);
        }
        const design = await evaluate(`({
            noIcons: document.querySelectorAll('svg, i[class], [class*="icon"]').length === 0,
            activeLinks: document.querySelectorAll('.sidebar .nav-item.active[aria-current="page"]').length,
            background: getComputedStyle(document.body).backgroundColor,
            accent: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
            sidebarWidth: document.querySelector('.sidebar').getBoundingClientRect().width,
            localAssets: [...document.querySelectorAll('script[src], link[rel="stylesheet"], img[src]')].every(el => (el.src || el.href).startsWith('file:')),
        })`);
        const expanded = await evaluate('({width:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight})');
        const screenshot = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width, height: Math.min(expanded.height, 5000), scale: 1 } }, sessionId);
        writeFileSync(join(output, `${name}.png`), Buffer.from(screenshot.data, 'base64'));
        const passed = metrics.scroll <= metrics.client && expanded.width <= metrics.client && metrics.styles > 0 && keyboardOpened && navigationPassed && design.noIcons && design.activeLinks === 1 && design.accent === '#4338ca' && design.localAssets;
        report.push({ name, ...metrics, expandedWidth: expanded.width, keyboardOpened, navigationPassed, ...design, passed });
    }
    writeFileSync(join(output, 'browser-report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    if (report.some(item => !item.passed)) process.exitCode = 1;
} finally {
    if (socket?.readyState === WebSocket.OPEN) {
        try { await command('Browser.close'); } catch { /* Process exit also closes the socket. */ }
        socket.close();
    }
    if (browser.exitCode === null) browser.kill();
}
