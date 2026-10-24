// Test del JavaScript nel browser reale: controller Stimulus del form di ricerca e della pagina
// dei job, Turbo e Content-Security-Policy. Lanciare con: composer test:browser
import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { change, chromeBinary, fixtures, startApplication, startBrowser } from './support.mjs';

describe('browser', { skip: chromeBinary === undefined ? 'Chrome/Chromium not installed' : false }, () => {
    let application;
    let browser;
    let jobs;

    before(async () => {
        jobs = JSON.parse(fixtures('seed'));
        application = await startApplication();
        browser = await startBrowser();
    });

    after(async () => {
        await browser?.close();
        application?.stop();
        fixtures('teardown');
    });

    const state = `(() => {
        const field = (name) => document.querySelector('[name="search_form[' + name + ']"]');
        const visible = (name) => Array.from(field(name).options).filter((option) => !option.hidden).map((option) => option.textContent.trim());
        const shown = (name) => !document.querySelector('[data-field="' + name + '"]').hidden;
        return {
            databases: Array.from(document.querySelector('[name="search_form[databases][]"]').options).filter((o) => !o.hidden).map((o) => o.textContent.trim()).filter((t) => t.startsWith('Browser')),
            wordSizes: visible('wordSize'), wordSize: field('wordSize').value, gapCosts: field('gapCosts').value,
            filter: field('lowComplexityFilter').checked, softMasking: field('softMasking').checked,
            fields: ['nucleotideScoring', 'matrix', 'gapCosts', 'ungapped', 'queryGeneticCode', 'databaseGeneticCode'].filter(shown),
            matrixDisabled: field('matrix').disabled,
        };
    })()`;

    it('adapts databases, defaults and options to the program, like viroblast.js', async () => {
        await browser.open(`${application.baseUrl}/`);

        assert.deepEqual(await browser.evaluate(state), {
            databases: ['Browser nucleotides'], wordSizes: ['7', '11', '15'], wordSize: '11', gapCosts: '5,2',
            filter: true, softMasking: true, fields: ['nucleotideScoring', 'gapCosts', 'ungapped'], matrixDisabled: true,
        });

        const expected = {
            blastp: { databases: ['Browser proteins'], filter: false, fields: ['matrix', 'gapCosts', 'ungapped'] },
            blastx: { databases: ['Browser proteins'], filter: true, fields: ['matrix', 'gapCosts', 'ungapped', 'queryGeneticCode'] },
            tblastn: { databases: ['Browser nucleotides'], filter: true, fields: ['matrix', 'gapCosts', 'ungapped', 'databaseGeneticCode'] },
            tblastx: { databases: ['Browser nucleotides'], filter: true, fields: ['matrix', 'queryGeneticCode', 'databaseGeneticCode'] },
        };

        for (const [program, values] of Object.entries(expected)) {
            await browser.evaluate(change('program', program));
            const current = await browser.evaluate(state);
            assert.deepEqual(current.databases, values.databases, program);
            assert.deepEqual(current.wordSizes, ['2', '3'], program);
            assert.equal(current.wordSize, '3', program);
            assert.equal(current.filter, values.filter, program);
            assert.equal(current.softMasking, false, program);
            assert.deepEqual(current.fields, values.fields, program);
        }
    });

    it('offers the gap costs of the chosen matrix, default first', async () => {
        await browser.open(`${application.baseUrl}/`);
        await browser.evaluate(change('program', 'blastp'));
        await browser.evaluate(change('matrix', 'BLOSUM45'));

        assert.deepEqual(await browser.evaluate(`(() => { const select = document.querySelector('[name="search_form[gapCosts]"]'); return [select.value, select.options.length, select.options[0].textContent]; })()`), ['15,2', 12, 'Existence: 15, Extension: 2']);
    });

    it('submits through Turbo and lands on the job page', async () => {
        await browser.open(`${application.baseUrl}/`);
        await browser.evaluate(`(() => {
            document.querySelector('[name="search_form[query]"]').value = '>q\\nACGTACGTACGT';
            Array.from(document.querySelector('[name="search_form[databases][]"]').options).find((o) => o.textContent.trim() === 'Browser nucleotides').selected = true;
            document.querySelector('[name="search_form[basic]"]').click();
        })()`);

        await browser.waitFor(`location.pathname.startsWith('/job/')`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-job-status-target="status"]').textContent`), 'pending');
    });

    it('selects all the hits of the page and shows a partial selection', async () => {
        await browser.open(`${application.baseUrl}/job/${jobs.finished}`);
        const selection = `(() => { const all = document.querySelector('[data-select-all-target="all"]'); const items = [...document.querySelectorAll('[data-select-all-target="item"]')]; return [items.filter((i) => i.checked).length, all.checked, all.indeterminate]; })()`;

        await browser.evaluate(`document.querySelector('[data-select-all-target="all"]').click()`);
        assert.deepEqual(await browser.evaluate(selection), [3, true, false]);

        await browser.evaluate(`document.querySelector('[data-select-all-target="item"]').click()`);
        assert.deepEqual(await browser.evaluate(selection), [2, false, true]);
    });

    it('polls the job status and reloads the page when the search is done', async () => {
        await browser.open(`${application.baseUrl}/job/${jobs.pending}`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-job-status-target="status"]').textContent`), 'pending');

        fixtures('finish', jobs.pending);

        await browser.waitFor(`document.querySelector('[data-job-status-target="status"]')?.textContent === 'finished'`, 10000);
        assert.equal(await browser.evaluate(`document.querySelector('h2#results-title')?.textContent`), 'Results');
    });

    it('runs every page without JavaScript errors or Content-Security-Policy violations', () => {
        assert.deepEqual(browser.problems, []);
    });
});
