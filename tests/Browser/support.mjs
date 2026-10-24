// Supporto per i test del browser: server PHP in ambiente test, Chrome headless pilotato con il
// DevTools Protocol (WebSocket e fetch nativi di Node, nessuna dipendenza npm), fixture PHP.
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const projectDir = new URL('../../', import.meta.url).pathname;
const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

export const chromeBinary = ['google-chrome', 'chromium', 'chromium-browser']
    .find((name) => { try { execFileSync('which', [name], { stdio: 'ignore' }); return true; } catch { return false; } });

function freePort() {
    return new Promise((resolve) => {
        const server = createServer().listen(0, '127.0.0.1', () => { const { port } = server.address(); server.close(() => resolve(port)); });
    });
}

async function waitFor(check, timeout = 10000) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        try { const value = await check(); if (value) return value; } catch { /* non ancora pronto */ }
        await sleep(100);
    }
    throw new Error('Timed out waiting for a condition');
}

export function fixtures(...args) {
    return execFileSync('php', ['tests/Browser/fixtures.php', ...args], { cwd: projectDir, encoding: 'utf8' });
}

export async function startApplication() {
    const port = await freePort();
    // variables_order=EGPCS: senza "E" il server integrato non passa APP_ENV al runtime di Symfony,
    // che userebbe l'ambiente dev di .env (e il database di sviluppo).
    const server = spawn('php', ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: projectDir, env: { ...process.env, APP_ENV: 'test' }, stdio: 'ignore' });
    const baseUrl = `http://127.0.0.1:${port}`;
    await waitFor(async () => (await fetch(`${baseUrl}/help`)).ok);
    return { baseUrl, stop: () => server.kill() };
}

export async function startBrowser() {
    const port = await freePort();
    const profile = mkdtempSync(join(tmpdir(), 'bestiabase-chrome-'));
    const chrome = spawn(chromeBinary, ['--headless=new', '--no-sandbox', '--disable-gpu', `--user-data-dir=${profile}`, `--remote-debugging-port=${port}`, 'about:blank'], { stdio: 'ignore' });
    const target = await waitFor(async () => (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((t) => t.type === 'page'));
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve) => socket.addEventListener('open', resolve));

    let lastId = 0;
    const pending = new Map();
    const problems = [];
    let loads = 0;

    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id && pending.has(message.id)) { pending.get(message.id)(message); pending.delete(message.id); }
        if (message.method === 'Page.loadEventFired') loads += 1;
        if (message.method === 'Runtime.exceptionThrown') problems.push(`exception: ${message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text}`);
        if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error') problems.push(`console: ${message.params.entry.text} ${message.params.entry.url ?? ''}`.trim());
    });

    const send = (method, params = {}) => new Promise((resolve) => { lastId += 1; pending.set(lastId, resolve); socket.send(JSON.stringify({ id: lastId, method, params })); });
    const evaluate = async (expression) => {
        const response = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (response.result.exceptionDetails) throw new Error(response.result.exceptionDetails.exception?.description ?? 'evaluation failed');
        return response.result.result.value;
    };
    await send('Runtime.enable');
    await send('Log.enable');
    await send('Page.enable');

    return {
        problems,
        evaluate,
        loads: () => loads,
        async open(url) {
            const before = loads;
            await send('Page.navigate', { url });
            await waitFor(() => loads > before);
            // Stimulus si collega dopo il caricamento dei moduli.
            await waitFor(() => evaluate('window.Stimulus !== undefined || document.querySelector("[data-controller]") === null || document.readyState === "complete"'));
            await sleep(300);
        },
        waitFor: (expression, timeout) => waitFor(() => evaluate(expression), timeout),
        async close() { socket.close(); chrome.kill(); await sleep(200); rmSync(profile, { recursive: true, force: true }); },
    };
}

export function change(name, value) {
    return `(() => { const field = document.querySelector('[name="search_form[${name}]"]'); field.value = ${JSON.stringify(value)}; field.dispatchEvent(new Event('change', { bubbles: true })); })()`;
}
