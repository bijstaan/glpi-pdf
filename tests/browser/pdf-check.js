// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// glpi-pdf: the tab is present where it should be, absent where it should not,
// and the bulk massive action produces one merged file.
//
// The per-item section picker and its download are covered by
// pdf-tab-check.js; this is the surrounding wiring.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'http://localhost:8081';
const fail = [];
const check = (n, c, d) => {
  console.log(`${c ? 'PASS' : 'FAIL'}  ${n}${d ? ' :: ' + d : ''}`);
  if (!c) fail.push(n);
};

/**
 * Is there a PDF entry in the tab strip?
 *
 * Read from the DOM rather than clicked: the strip is a <ul> on some forms and
 * a <select> on others depending on the page's tab orientation, so a click is
 * a test of the layout rather than of this plugin.
 */
async function pdfTab(page, url) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tab = page.locator('#tabspanel a', { hasText: /^PDF$/ });
  return (await tab.count()) ? tab.first() : null;
}

/**
 * The document names offered inside the tab.
 *
 * Fetched from the tab's own ajax endpoint rather than by clicking or by
 * forcetab. Clicking depends on the page's tab orientation, and `forcetab` is
 * silently dropped on plugin forms whose file name does not match their
 * namespaced class — a known GLPI 11 quirk that glpi-sop works around in its
 * own front controller. Neither is what this test is about.
 */
async function documents(page, tab) {
  const url = await tab.getAttribute('data-glpi-ajax-content');
  const html = await page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'same-origin' });
    return r.text();
  }, url);

  return [...html.matchAll(/<h3 class='card-title[^']*'>(.*?)<\/h3>/g)].map(m => m[1].trim());
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1100 }, acceptDownloads: true });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push(e.message));

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  // --- the tab is the only entry point -------------------------------------
  const ticketTab = await pdfTab(page, `${BASE}/front/ticket.form.php?id=1095`);
  check('a ticket has a PDF tab', ticketTab !== null);

  check('and no export button was left on the form itself',
    (await page.locator('.glpipdf-actions, .glpipdf-button').count()) === 0);

  // --- an itemtype nobody contributed a document for has no tab ------------
  const noTab = await pdfTab(page, `${BASE}/front/computer.php`);
  check('an unexported itemtype gets no tab', noTab === null);

  // --- availability: two documents where there is a review, one where not ---
  const incident = `${BASE}/plugins/glpimajor/front/incident.form.php`;

  const tab76 = await pdfTab(page, `${incident}?id=76`);
  check('an incident has a PDF tab', tab76 !== null);

  const docs76 = tab76 ? await documents(page, tab76) : [];
  check('an incident with a written review offers both documents',
    docs76.length === 2, docs76.join(' | '));

  const tab50 = await pdfTab(page, `${incident}?id=50`);
  const docs50 = tab50 ? await documents(page, tab50) : [];
  check('an incident with no review does not offer the PIR',
    docs50.length === 1, docs50.join(' | '));

  // --- the bulk massive action ---------------------------------------------
  //
  // GLPI 11 puts the actions behind an <a class="btn"> that opens a modal, so
  // the dropdown does not exist until it is pressed.
  await page.goto(`${BASE}/front/ticket.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  const rows = page.locator('.massive_action_checkbox');
  const count = await rows.count();
  check('search rows are selectable', count > 1, `checkboxes=${count}`);

  for (let i = 1; i <= Math.min(3, count - 1); i++) {
    await rows.nth(i).check({ force: true }).catch(() => {});
  }
  await page.waitForTimeout(400);

  await page.locator('a.btn:has-text("Actions")').first().click();
  await page.waitForTimeout(1500);

  // The modal's own select is named `massiveaction`; `action` is the hidden
  // field it writes into.
  const action = page.locator('.modal.show select[name="massiveaction"]');
  check('the actions modal opens', (await action.count()) > 0);

  if (await action.count()) {
    const opts = await action.first().locator('option').allInnerTexts();
    const pdf = opts.filter(o => /PDF/i.test(o));
    check('the bulk PDF action is offered', pdf.length > 0, pdf.join(' | '));

    if (pdf.length) {
      await action.first().selectOption({ label: pdf[0] });
      await page.waitForTimeout(2000);

      const submit = page.locator('.modal.show button[name="massiveaction"], .modal.show input[name="massiveaction"]');
      check('the export sub-form offers a submit', (await submit.count()) > 0);

      const [dl] = await Promise.all([
        page.waitForEvent('download', { timeout: 30000 }),
        submit.last().click(),
      ]).catch(e => { console.log('  (bulk) ' + e.message.split('\n')[0]); return [null]; });

      if (dl) {
        const path = await dl.path();
        check('bulk export downloads one merged PDF',
          fs.readFileSync(path).subarray(0, 5).toString() === '%PDF-',
          `${dl.suggestedFilename()} bytes=${fs.statSync(path).size}`);
      } else {
        check('bulk export downloads one merged PDF', false, 'no download');
      }
    }
  }

  check('no javascript errors', errs.length === 0, errs.join(' | '));
  console.log(fail.length ? 'FAILURES: ' + fail.join(', ') : 'all pdf checks passed');
  await browser.close();
  process.exit(fail.length ? 1 : 0);
})();
