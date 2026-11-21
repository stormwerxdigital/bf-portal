/*
 * A new session starts with the attendance marked on the Calendar (Karl,
 * 1.211): "If no session yet, then when that session is created/entered, it
 * will pull its attendance from the schedule." Driven in a browser with the
 * real admin script.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-session-mark.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);
const p = await browser.newPage();
const errors = [];
p.on('pageerror', e => errors.push(e.message));
await p.goto('file://' + page_path);
await p.waitForTimeout(200);

const state = () => p.evaluate(() => ({
  status: document.querySelector('#bftd_session_status').value,
  by: document.querySelector('#bftd_session_missed_by').value,
  why: document.querySelector('#bftd_session_missed_why').value,
  note: (document.querySelector('.bftd-from-calendar') || {}).textContent || '',
}));

check(errors.length === 0, 'the admin script loads without errors' + (errors.length ? ': ' + errors[0] : ''));
check('held' === (await state()).status, 'a new session starts as Attended, as before');

await p.selectOption('#bftd_student', '501');
await p.fill('#bftd_session_session_date', '2026-11-18');
await p.dispatchEvent('#bftd_session_session_date', 'change');
await p.waitForTimeout(150);
let s = await state();
check('missed' === s.status && '31' === s.by && 'Family away' === s.why, 'with the student and date in, it takes the Calendar\'s mark, who and why included, got ' + JSON.stringify(s));
check(/From the Calendar: Missed/.test(s.note), 'and says where it came from');

/* A different date with no mark leaves the tutor's choice alone. */
await p.selectOption('#bftd_session_status', 'late');
await p.fill('#bftd_session_session_date', '2026-11-19');
await p.dispatchEvent('#bftd_session_session_date', 'change');
await p.waitForTimeout(150);
s = await state();
check('late' === s.status && '' === s.note, 'a date with no mark changes nothing the tutor chose, and drops the note');

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
