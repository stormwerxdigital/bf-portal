/*
 * The student's School box searches GoHighLevel's Companies and the portal's
 * school list (Karl, 1.212). Driven in a browser with the real script: what
 * it offers, what picking sends with the form, and the keyboard.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-school-search.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);
const p = await browser.newPage({ viewport: { width: 800, height: 700 } });
const errors = [];
p.on('pageerror', e => errors.push(e.message));
await p.goto('file://' + page_path);
await p.waitForTimeout(150);

const typeIn = async (q) => { await p.fill('#bftd_student_school', ''); await p.type('#bftd_student_school', q); await p.waitForTimeout(350); };
const offered = () => p.evaluate(() => ({
  open: !document.querySelector('#bftd-school-list').hidden,
  groups: [...document.querySelectorAll('.bftd-school-g')].map(g => g.textContent.trim()).filter(Boolean),
  options: [...document.querySelectorAll('.bftd-school-o')].map(o => o.textContent.trim()),
  expanded: document.querySelector('#bftd_student_school').getAttribute('aria-expanded'),
}));
const sent = () => p.evaluate(() => ({
  name: document.querySelector('#bftd_student_school').value,
  pick: document.querySelector('input[name="bftd_school_pick"]').value,
  said: document.querySelector('.bftd-school-said').textContent.trim(),
}));

check(errors.length === 0, 'the admin script loads without errors' + (errors.length ? ': ' + errors[0] : ''));
const box = await p.evaluate(() => { const i = document.querySelector('#bftd_student_school');
  return { role: i.getAttribute('role'), name: i.name, label: !!document.querySelector('label[for="bftd_student_school"]') }; });
check(box.role === 'combobox' && box.name === 'bftd[student][school]' && box.label, 'School is a labelled search box that still saves as School');

await typeIn('bow');
let o = await offered();
check(o.open && o.expanded === 'true' && o.groups[0] === 'In GoHighLevel' && /Bow River Elementary/.test(o.options[0]) && /Link and add to our list/.test(o.options[0]),
  'a GoHighLevel Company is offered to link and add, got ' + JSON.stringify(o));
check(o.options.some(x => /Add "bow" as a new school/.test(x)), 'and so is adding what was typed as a new school');
await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter'); await p.waitForTimeout(50);
let s = await sent();
check(s.name === 'Bow River Elementary' && s.pick === 'ghl:cbr' && /Saving adds it/.test(s.said), 'arrow and Enter pick it, and the form will link it, got ' + JSON.stringify(s));
check(!(await offered()).open, 'and the list closes');

await typeIn('foot');
o = await offered();
check(o.options.filter(x => /Foothills Elementary School/.test(x)).length === 1 && /On our list/.test(o.options[0]), 'a Company already on our list is offered once, saying so, got ' + JSON.stringify(o.options));
check(o.groups.includes('On our list') && o.options.some(x => /^Foothills Middle/.test(x)), 'our own list is offered too');
check((await sent()).pick === '', 'typing again forgets the earlier pick');
await p.locator('.bftd-school-o', { hasText: 'Foothills Middle' }).dispatchEvent('mousedown');
s = await sent();
check(s.name === 'Foothills Middle' && s.pick === 'list:4', 'clicking one from our list links it');

await typeIn('fothills middle');
o = await offered();
check(o.groups.includes('Did you mean') && /Foothills Middle/.test(o.options[0]), 'a misspelling is offered the close name');

await typeIn('prairie sky');
o = await offered();
check(o.options.length === 1 && /Add "prairie sky" as a new school/.test(o.options[0]), 'a school on neither list is offered as new, and nothing else');
await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter'); await p.waitForTimeout(50);
s = await sent();
check(s.name === 'prairie sky' && s.pick === 'new' && /Saving adds it/.test(s.said), 'picking it keeps the name typed, to be added on save');

await typeIn('bow');
await p.keyboard.press('Escape'); await p.waitForTimeout(50);
check(!(await offered()).open, 'Escape closes the list');

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
