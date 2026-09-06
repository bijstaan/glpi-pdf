// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The PDF tab: does it appear, does it offer the whole record, and does
// ticking things actually change the file?
//
// The half that HTTP cannot reach — the tab arrives over ajax, the select
// all/none/reset buttons are delegated JavaScript, and the export is a real
// browser download.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'http://localhost:8081';
const fail = [];
const check = (n, c, d) => {
  console.log(`${c ? 'PASS' : 'FAIL'}  ${n}${d ? ' :: ' + d : ''}`);
  if (!c) fail.push(n);
};

async function openTab(page, url) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const tab = page.locator('#tabspanel a', { hasText: /^PDF$/ });
  if (!(await tab.count())) return false;
  await tab.first().click();
  await page.waitForSelector('.glpipdf-tab', { timeout: 15000 });
  await page.waitForTimeout(500);
  return true;
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

  // --- the tab exists on a ticket -----------------------------------------
  const ok = await openTab(page, `${BASE}/front/ticket.form.php?id=1095`);
  check('a PDF tab appears on a ticket', ok);
  if (!ok) { await browser.close(); process.exit(1); }

  const boxes = page.locator('.glpipdf-parts input[type=checkbox]');
  const labels = await page.locator('.glpipdf-parts .form-check-label').allInnerTexts();
  console.log(`  ${labels.length} sections offered:`);
  console.log('   ', labels.join(' | '));

  check('a ticket offers the whole record', labels.length >= 15, `${labels.length} sections`);
  check('the audit trail is offered', labels.some(l => /history/i.test(l)));
  check('linked assets are offered', labels.some(l => /asset/i.test(l)));
  check('costs are offered', labels.some(l => /cost/i.test(l)));
  check('the SOP appendix is offered as a section', labels.some(l => /SOP/i.test(l)));

  const groups = await page.locator('.glpipdf-parts-group').allInnerTexts();
  check('sections are grouped by where they come from', groups.length >= 2, groups.join(' | '));

  const hist = page.locator('.glpipdf-parts .form-check', { hasText: /history/i }).locator('input');
  check('field history is off by default', !(await hist.first().isChecked()));

  // --- select none / all / reset ------------------------------------------
  await page.locator('[data-glpipdf-none]').first().click();
  await page.waitForTimeout(150);
  let checked = await page.locator('.glpipdf-parts input[type=checkbox]:checked').count();
  check('"select none" clears every box', checked === 0, `${checked} still ticked`);

  await page.locator('[data-glpipdf-all]').first().click();
  await page.waitForTimeout(150);
  checked = await page.locator('.glpipdf-parts input[type=checkbox]:checked').count();
  check('"select all" ticks every box', checked === (await boxes.count()), `${checked}/${await boxes.count()}`);

  await page.locator('[data-glpipdf-reset]').first().click();
  await page.waitForTimeout(150);
  check('"back to the usual set" restores the defaults', !(await hist.first().isChecked()));

  // --- export everything, audit trail included ----------------------------
  await page.locator('[data-glpipdf-all]').first().click();
  await page.waitForTimeout(150);
  const [dl] = await Promise.all([
    page.waitForEvent('download', { timeout: 30000 }),
    page.locator('.glpipdf-tab button[type=submit]').first().click(),
  ]);
  fs.copyFileSync(await dl.path(), 'pdf-full.pdf');
  check('exporting everything downloads a PDF',
    fs.readFileSync('pdf-full.pdf').subarray(0, 5).toString() === '%PDF-', dl.suggestedFilename());

  // --- and the minimum, which must be a genuinely different document ------
  await openTab(page, `${BASE}/front/ticket.form.php?id=1095`);
  await page.locator('[data-glpipdf-none]').first().click();
  await page.waitForTimeout(150);
  await page.locator('.glpipdf-parts .form-check', { hasText: /Summary/i }).locator('input').first().check();
  const [dl2] = await Promise.all([
    page.waitForEvent('download', { timeout: 30000 }),
    page.locator('.glpipdf-tab button[type=submit]').first().click(),
  ]);
  fs.copyFileSync(await dl2.path(), 'pdf-min.pdf');
  check('a one-section export is a real PDF',
    fs.readFileSync('pdf-min.pdf').subarray(0, 5).toString() === '%PDF-');

  check('the two exports differ',
    fs.statSync('pdf-full.pdf').size !== fs.statSync('pdf-min.pdf').size,
    `full=${fs.statSync('pdf-full.pdf').size} min=${fs.statSync('pdf-min.pdf').size}`);

  check('no javascript errors', errs.length === 0, errs.join(' | '));
  console.log(fail.length ? 'FAILURES: ' + fail.join(', ') : 'all tab checks passed');
  await browser.close();
  process.exit(fail.length ? 1 : 0);
})();
