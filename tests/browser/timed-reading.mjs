/*
 * Timed reading, section 4 of the diagnostic, is two assessments and a
 * student does one. Each draws its own report from the tutor training
 * document, and none of the other's. Driven in a browser because what a
 * parent sees is the page, not the source.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

for (const w of ['kinder', 'passage']) execFileSync('php', [join(here, 'build-timed.php'), w]);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

for (const [which, mine, theirs] of [
  ['kinder',  ['Kindergarten word fluency assessment', 'we skip the paragraph reading', 'Minimum speed benchmarks',
               '1-minute timed reading, unpracticed Kindergarten word list', '130 WPM, Grade 8', 'EBLI raises reading grade level',
               'How does Linguistic Literacy help?'],
              ['Passage reading norms', 'EBLI builds fluency from the inside out', 'ORF* norm']],
  ['passage', ['Oral reading fluency isn', 'Passage reading norms for oral reading fluency', '1-minute timed reading, unpracticed grade-level passage',
               '133 WPM'.replace('WPM', 'WCPM') + ', Grade 4', 'End of year ORF* norm for enrolled grade', 'EBLI builds fluency from the inside out',
               'How does Linguistic Literacy help?'],
              ['we skip the paragraph reading', 'Minimum speed benchmarks', 'EBLI raises reading grade level']],
]) {
  const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
  await page.goto('file://' + join(here, `timed-${which}.html`));
  // Open every section so the text is on screen, as a reader would.
  const sec = page.locator('#dxsec-4');
  check(await sec.count() === 1, `${which}: the timed reading section is on the report`);
  await sec.locator('.docsec-h').click();
  await page.waitForTimeout(600);
  const text = await sec.innerText();
  for (const t of mine)   check(text.includes(t),  `${which}: shows "${t}"`);
  for (const t of theirs) check(!text.includes(t), `${which}: does not show "${t}"`);
  const card = await page.locator('.skill[data-section="dxsec-4"] .meta').innerText();
  check(which === 'kinder' ? card === 'Grade 8 minimum, 130 words per minute' : card === 'Grade 4 norm, 133 correct words per minute',
    `${which}: the card names its goal (${card})`);
  if (process.env.SHOT) await sec.screenshot({ path: join(process.env.SHOT, `timed-${which}.png`) });

  // At phone width nothing in the section is wider than the screen.
  await page.setViewportSize({ width: 390, height: 844 });
  const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check(over <= 0, `${which}: no sideways scroll at phone width (${over}px over)`);
  if (process.env.SHOT) await sec.screenshot({ path: join(process.env.SHOT, `timed-${which}-phone.png`) });
  await page.close();
}

await browser.close();
console.log(fail ? '\nFAIL' : '\nPASS');
process.exit(fail ? 1 : 0);
