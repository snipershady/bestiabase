// Proof of work ALTCHA nel browser reale: verifica di sicurezza prima della home, login,
// registrazione e pagina "nopainnogain", con la Content-Security-Policy in vigore.
// Lanciare con: composer test:browser
import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { chromeBinary, startApplication, startBrowser } from './support.mjs';

describe('altcha', { skip: chromeBinary === undefined ? 'Chrome/Chromium not installed' : false }, () => {
    let application;
    let browser;

    before(async () => {
        // Le difficoltà predefinite di produzione, non quelle istantanee dei test: un'incongruenza tra
        // la sfida emessa e quella attesa dal server farebbe ripartire la verifica all'infinito.
        process.env.ALTCHA_SECURITY_CHECK_DIFFICULTY = 'high';
        process.env.ALTCHA_LOGIN_DIFFICULTY = 'medium_high';
        application = await startApplication();
        browser = await startBrowser();
    });

    after(async () => {
        await browser?.close();
        application?.stop();
    });

    const submitEnabledWithSolution = `(() => {
        const button = document.querySelector('[data-altcha-target="submit"]');
        const field = document.querySelector('[data-altcha-target="field"]');
        return button.disabled === false && field.value.length > 0;
    })()`;

    it('shows the security check to anonymous visitors, then the index', async () => {
        await browser.open(`${application.baseUrl}/`);

        await browser.waitFor(`location.pathname === '/' && document.querySelector('#about-title') !== null`, 30000);
        assert.equal(await browser.evaluate(`document.querySelector('form[data-controller="search-form"]')`), null, 'anonymous visitors cannot search');
    });

    it('does not ask for the check again while the cookie is valid', async () => {
        await browser.open(`${application.baseUrl}/`);

        assert.equal(await browser.evaluate('location.pathname'), '/');
    });

    it('solves the proof of work on the login page before the form can be sent', async () => {
        await browser.open(`${application.baseUrl}/login`);

        assert.equal(await browser.evaluate(`document.querySelector('[data-altcha-target="submit"]').disabled`), true);
        await browser.waitFor(submitEnabledWithSolution, 30000);
    });

    it('solves the proof of work on the registration page', async () => {
        await browser.open(`${application.baseUrl}/register`);

        await browser.waitFor(submitEnabledWithSolution, 30000);
    });

    it('renders the other account pages', async () => {
        for (const [path, heading] of [['/login', 'Sign in'], ['/register', 'Create an account'], ['/reset-password', 'Forgot your password?']]) {
            await browser.open(`${application.baseUrl}${path}`);

            assert.equal(await browser.evaluate(`document.querySelector('h1')?.textContent`), heading);
        }
    });

    it('sends unknown routes to the trap page, which leads back to the index once solved', async () => {
        await browser.open(`${application.baseUrl}/this-page-does-not-exist`);

        assert.equal(await browser.evaluate('location.pathname').then((path) => ['/nopainnogain', '/'].includes(path)), true);
        await browser.waitFor(`location.pathname === '/'`, 30000);
    });

    it('runs every page without JavaScript errors or Content-Security-Policy violations', () => {
        assert.deepEqual(browser.problems, []);
    });
});
