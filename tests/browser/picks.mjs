/*
 * The Strengths tick list in a browser (Karl, 1.215): every item starts
 * unticked, a Pick one group allows one tick, and the count says how many
 * are ticked against the goal of 6 to 8.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-picks.php']).toString().trim();
let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);
const p = await browser.newPage();
const errors = [];
p.on('pageerror', e => errors.push(e.message));
await p.goto('file://' + page_path);
await p.waitForTimeout(150);
const st = () => p.evaluate(() => ({
  ticked: [...document.querySelectorAll('.bftd-picks input[type=checkbox]:checked')].map(c => c.value),
  count: document.querySelector('.bftd-picks-count').textContent,
}));

check(errors.length === 0, 'the admin script loads without errors' + (errors.length ? ': ' + errors[0] : ''));
let s = await st();
check(s.ticked.length === 0 && s.count === '0 ticked (goal 6 to 8)', 'nothing is ticked to start, and the count says so against the goal, got ' + s.count);

const box = (t) => p.locator('.bftd-picks tr', { hasText: t }).locator('input');
await box('Reads word lists independently at enrolled grade level').check();
await box('Reads word lists independently above enrolled grade level').check();
s = await st();
check(s.ticked.length === 1 && /above enrolled/.test(s.ticked[0]), 'in a Pick one group, ticking one unticks the other, got ' + JSON.stringify(s.ticked));
await box('Polite and respectful').check();
await box('Positive, upbeat attitude').check();
s = await st();
check(s.ticked.length === 3 && s.count === '3 ticked (goal 6 to 8)', 'items outside a group tick freely, and the count follows, got ' + s.count);

const guide = await p.evaluate(() => document.querySelector('.bftd-picks tr.is-pick-one .bftd-picks-guide').textContent);
check(/^Pick one/.test(guide), 'a Pick one row says so in its guidance');

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
