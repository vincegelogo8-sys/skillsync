// Run after SKILLSYNC_E2E_CAPTURE=1 generates test-only HTML snapshots.
// Uses installed Edge and Node built-ins; no browser package or production login.
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
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
    await command('Page.addScriptToEvaluateOnNewDocument', {
        source: `(() => {
            const prefix = '__skillsync_test_storage__';
            const read = () => {
                try { return window.name.startsWith(prefix) ? JSON.parse(window.name.slice(prefix.length)) : {}; }
                catch { return {}; }
            };
            const write = (values) => { window.name = prefix + JSON.stringify(values); };
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                value: {
                    getItem: (key) => { if (window.__skillsyncStorageBlocked) throw new Error('Storage blocked'); return read()[key] ?? null; },
                    setItem: (key, value) => { if (window.__skillsyncStorageBlocked) throw new Error('Storage blocked'); const values = read(); values[key] = String(value); write(values); },
                    removeItem: (key) => { if (window.__skillsyncStorageBlocked) throw new Error('Storage blocked'); const values = read(); delete values[key]; write(values); },
                    clear: () => { if (window.__skillsyncStorageBlocked) throw new Error('Storage blocked'); write({}); },
                },
            });
        })();`,
    }, sessionId);
    const evaluate = async (expression) => (await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId)).result.value;
    const report = [];

    const selectedPages = process.env.SKILLSYNC_UI_PAGES?.split(',');
    const manifest = join(output, 'current-pages.json');
    if (!existsSync(manifest)) throw new Error('Capture UiPresentationTest with SKILLSYNC_E2E_CAPTURE=1 first.');
    const sources = JSON.parse(readFileSync(manifest, 'utf8')).filter(name => !selectedPages || selectedPages.includes(name));
    const widths = process.env.SKILLSYNC_UI_WIDTHS?.split(',').map(Number) || [1280, 600];
    let desktopSidebarChecked = false;
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
                const sidebar=document.querySelector('#workspace-sidebar');
                const margin=getComputedStyle(sidebar).marginLeft;
                document.documentElement.classList.add('sidebar-collapsed');
                const collapsedClassIgnored=getComputedStyle(sidebar).marginLeft===margin;
                menu.click();
                const opened=menu.getAttribute('aria-expanded')==='true' && menu.textContent.includes('Menu') && sidebar.classList.contains('is-open') && document.querySelector('.app-main').inert;
                document.querySelector('[data-sidebar-close]').click();
                const closed=menu.getAttribute('aria-expanded')==='false' && menu.textContent.includes('Menu') && !document.querySelector('.app-main').inert && document.activeElement===menu;
                document.documentElement.classList.remove('sidebar-collapsed');
                return collapsedClassIgnored && opened && closed;
            })()`);
        }
        let desktopNavigation = true;
        if (!mobile && metrics.workspace && width === 1280 && source === 'admin-dashboard' && !desktopSidebarChecked) {
            desktopSidebarChecked = true;
            desktopNavigation = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                const sidebar=document.querySelector('#workspace-sidebar');
                const page=document.querySelector('.page-content');
                localStorage.setItem('skillsync.sidebar','expanded');
                document.documentElement.classList.remove('sidebar-collapsed');
                const link=sidebar.querySelector('a[href]');
                return {
                    valid:menu.getAttribute('aria-expanded')==='true' && menu.textContent.includes('Collapse menu') &&
                        getComputedStyle(sidebar).visibility==='visible' && !link.hasAttribute('title'),
                    width:page.getBoundingClientRect().width,
                };
            })()`);
            const expandedWidth = desktopNavigation.width;
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); true`);
            await pause(250);
            const collapsed = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                const sidebar=document.querySelector('#workspace-sidebar');
                const link=[...sidebar.querySelectorAll('a[href]')].find((item)=>item.textContent.includes('Accounts'));
                const label=link.querySelector('.nav-label');
                const logout=sidebar.querySelector('.nav-logout');
                const page=document.querySelector('.page-content');
                const expandedWidth=${expandedWidth};
                link.focus();
                const linkRemainsFocusable=document.activeElement===link && link.tabIndex===0 &&
                    label.textContent.trim()==='Accounts' && getComputedStyle(label).position==='absolute';
                menu.focus();
                return document.documentElement.classList.contains('sidebar-collapsed') &&
                    menu.getAttribute('aria-expanded')==='false' && menu.textContent.includes('Expand menu') &&
                    getComputedStyle(sidebar).visibility==='visible' && Math.abs(sidebar.getBoundingClientRect().width-72)<2 &&
                    linkRemainsFocusable && link.title==='Accounts' && logout.title==='Logout' &&
                    page.getBoundingClientRect().width>expandedWidth && document.activeElement===menu;
            })()`);
            await command('Page.reload', {}, sessionId);
            await pause(250);
            const restoredCollapsed = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                const link=[...document.querySelectorAll('#workspace-sidebar a[href]')].find((item)=>item.textContent.includes('Accounts'));
                return document.documentElement.classList.contains('sidebar-collapsed') &&
                    localStorage.getItem('skillsync.sidebar')==='collapsed' &&
                    menu.getAttribute('aria-expanded')==='false' && menu.textContent.includes('Expand menu') &&
                    getComputedStyle(document.querySelector('#workspace-sidebar')).visibility==='visible' &&
                    document.querySelector('#workspace-sidebar').getBoundingClientRect().width===72 &&
                    link.textContent.includes('Accounts') && link.title==='Accounts';
            })()`);
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); true`);
            await pause(250);
            const expanded = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                return !document.documentElement.classList.contains('sidebar-collapsed') &&
                    localStorage.getItem('skillsync.sidebar')==='expanded' &&
                    menu.getAttribute('aria-expanded')==='true' && menu.textContent.includes('Collapse menu') &&
                    getComputedStyle(document.querySelector('#workspace-sidebar')).visibility==='visible' &&
                    !document.querySelector('#workspace-sidebar .nav-item[title]') &&
                    document.activeElement===menu;
            })()`);
            await evaluate(`window.__skillsyncStorageBlocked=true; document.querySelector('[data-sidebar-toggle]').click(); true`);
            const storageBlocked = await evaluate(`document.documentElement.classList.contains('sidebar-collapsed') &&
                document.querySelector('[data-sidebar-toggle]').getAttribute('aria-expanded')==='false'`);
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); window.__skillsyncStorageBlocked=false; true`);
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); true`);
            await command('Emulation.setDeviceMetricsOverride', {width:600,height:800,deviceScaleFactor:1,mobile:true}, sessionId);
            await pause(80);
            const crossedToMobile = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                const sidebar=document.querySelector('#workspace-sidebar');
                return menu.textContent.includes('Menu') && menu.getAttribute('aria-expanded')==='false' &&
                    !sidebar.classList.contains('is-open') && !document.body.classList.contains('sidebar-open') &&
                    document.documentElement.classList.contains('sidebar-collapsed') &&
                    [...sidebar.querySelectorAll('.nav-item')].every((item)=>item.querySelector('svg') &&
                        item.querySelector('.nav-label')?.textContent.trim() && !item.hasAttribute('title'));
            })()`);
            const openedAtMobile = await evaluate(`(() => {
                const menu=document.querySelector('[data-sidebar-toggle]');
                menu.click();
                const opened=menu.getAttribute('aria-expanded')==='true' &&
                    document.querySelector('#workspace-sidebar').classList.contains('is-open') &&
                    document.documentElement.classList.contains('sidebar-collapsed');
                const labelsRemainVisible=[...document.querySelectorAll('#workspace-sidebar .nav-label')]
                    .every((label)=>getComputedStyle(label).position!=='absolute' && label.getBoundingClientRect().width>0);
                document.querySelector('[data-sidebar-close]').click();
                return opened && menu.getAttribute('aria-expanded')==='false' &&
                    !document.body.classList.contains('sidebar-open') && labelsRemainVisible;
            })()`);
            await command('Emulation.setDeviceMetricsOverride', {width:1280,height:1000,deviceScaleFactor:1,mobile:false}, sessionId);
            await pause(80);
            const crossedToDesktop = await evaluate(`document.documentElement.classList.contains('sidebar-collapsed') &&
                document.querySelector('[data-sidebar-toggle]').getAttribute('aria-expanded')==='false' &&
                document.querySelector('[data-sidebar-toggle]').textContent.includes('Expand menu') &&
                document.querySelector('#workspace-sidebar .nav-item[title="Accounts"]')`);
            await evaluate(`document.querySelector('[data-sidebar-toggle]').click(); true`);
            await pause(250);
            const restoredExpansion = await evaluate(`!document.documentElement.classList.contains('sidebar-collapsed') &&
                document.querySelector('[data-sidebar-toggle]').getAttribute('aria-expanded')==='true'`);
            desktopNavigation = Boolean(desktopNavigation.valid && collapsed && restoredCollapsed && expanded && storageBlocked &&
                crossedToMobile && openedAtMobile && crossedToDesktop && restoredExpansion);
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
        const passed=metrics.scroll<=metrics.client && metrics.styles>0 && metrics.h1===1 && (!metrics.workspace || metrics.active===1) && navigation && desktopNavigation && submission;
        report.push({name,...metrics,navigation,desktopNavigation,submission,passed});
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
