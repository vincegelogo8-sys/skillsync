// Run after SKILLSYNC_E2E_CAPTURE=1 generates test-only HTML snapshots.
// Uses installed Edge and Node built-ins; no browser package or production login.
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdtempSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';

const output = resolve('storage/app/testing/ui');
const profile = mkdtempSync(resolve('storage/app/testing/edge-verify-'));
const executable = process.env.SKILLSYNC_EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const browser = spawn(executable, ['--headless=new', '--disable-gpu', '--no-first-run', '--disable-background-networking', '--allow-file-access-from-files', '--remote-debugging-port=0', `--user-data-dir=${profile}`], { windowsHide: true, stdio: 'ignore' });
let socket;
let sequence = 0;
const pending = new Map();
const command = (method, params = {}, sessionId) => new Promise((resolveCommand, reject) => {
    const id = ++sequence;
    const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`Timeout: ${method}`)); }, 30000);
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

    await command('Network.enable', {}, sessionId);
    // Local assets must render without any external network requests.
    await command('Network.setBlockedURLs', { urls: ['http://*', 'https://*'] }, sessionId);
    await command('Page.bringToFront', {}, sessionId);
    await command('Emulation.setFocusEmulationEnabled', { enabled: true }, sessionId);
    const evaluate = async (expression) => (await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId)).result.value;
    const report = [];

    const selectedPages = process.env.SKILLSYNC_UI_PAGES?.split(',');
    const sources = readdirSync(output).filter(file => file.endsWith('.html')).map(file => file.slice(0,-5)).filter(name => !selectedPages || selectedPages.includes(name));
    const widths = process.env.SKILLSYNC_UI_WIDTHS?.split(',').map(Number) || [1440, 390];
    for (const source of sources) for (const width of widths) {
        const name = source + '-' + width;
        const mobile = width < 901;
        await command('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile}, sessionId);
        await command('Page.navigate', {url: pathToFileURL(join(output, source+'.html')).href}, sessionId);
        await pause(180);
        await evaluate('document.fonts.ready.then(() => true)');
        const metrics = await evaluate(`({client:document.documentElement.clientWidth,scroll:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,styles:document.styleSheets.length,h1:document.querySelectorAll('h1').length,active:document.querySelectorAll('.sidebar [aria-current="page"]').length,workspace:!!document.querySelector('.sidebar')})`);
        let navigation = true;
        if (mobile && metrics.workspace) {
            navigation = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                menu.click();
                const opened=menu.getAttribute('aria-expanded')==='true' && document.querySelector('.app-main').inert;
                document.querySelector('[data-sidebar-close]').click();
                return opened && menu.getAttribute('aria-expanded')==='false' && !document.querySelector('.app-main').inert;
            })()`);
        }
        let assessment = true;
        if (source === 'faculty-assessment-active') {
            assessment = await evaluate(`(() => {
                const input=document.querySelector('[data-assessment-form] input[type="radio"]');
                input.click();
                return document.querySelector('[data-assessment-progress]').value===1 && document.querySelector('[data-answered-count]').textContent==='1';
            })()`);
        }
        const screenshot = await command('Page.captureScreenshot', {format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width,height:Math.min(metrics.height,5000),scale:1}}, sessionId);
        writeFileSync(join(output,name+'.png'),Buffer.from(screenshot.data,'base64'));
        const submission = await evaluate(`(async () => {
            const form = document.querySelector('main form[method="POST"], main form[method="post"]');
            if (!form) return true;
            const button = [...form.elements].find(el => el instanceof HTMLButtonElement && el.type==='submit' && !el.disabled);
            if (!button) return true;
            const originalConfirm = window.confirm;
            window.confirm = () => true;
            // Synthetic events exercise the handler without posting the form.
            form.addEventListener('submit', event => event.preventDefault(), {once:true});
            form.dispatchEvent(new SubmitEvent('submit', {bubbles:true,cancelable:true,submitter:button}));
            const cancelledIsIdle = !form.hasAttribute('aria-busy');
            form.dispatchEvent(new SubmitEvent('submit', {bubbles:true,cancelable:true,submitter:button}));
            await new Promise(resolve => setTimeout(resolve, 20));
            const busy = form.getAttribute('aria-busy')==='true' && button.disabled;
            const duplicateBlocked = !form.dispatchEvent(new SubmitEvent('submit', {bubbles:true,cancelable:true,submitter:button}));
            window.dispatchEvent(new PageTransitionEvent('pageshow'));
            window.confirm = originalConfirm;
            return cancelledIsIdle && busy && duplicateBlocked && !button.disabled && !form.hasAttribute('aria-busy');
        })()`);
        const passed=metrics.scroll<=metrics.client && metrics.styles>0 && metrics.h1===1 && (!metrics.workspace || metrics.active===1) && navigation && assessment && submission;
        report.push({name,...metrics,navigation,assessment,submission,passed});
    }
    writeFileSync(join(output, 'browser-report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify({checked:report.length,passed:report.filter(item=>item.passed).length,failed:report.filter(item=>!item.passed)},null,2));
    if (report.some(item => !item.passed)) process.exitCode = 1;
} finally {
    if (socket?.readyState === WebSocket.OPEN) {
        try { await command('Browser.close'); } catch { /* Process exit also closes the socket. */ }
        socket.close();
    }
    if (browser.exitCode === null) browser.kill();
}
