const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

async function generateSingle(page){
  await page.goto('/issue_token.php');
  await page.fill('#issueForm input[name="pib"]', 'Структурний Тест');
  await page.fill('#issueForm input[name="course"]', 'PDFTEST');
  await page.fill('#issueForm input[name="grade"]', 'A');
  const today = new Date().toISOString().slice(0,10);
  await page.fill('#issueForm input[name="date"]', today);
  await page.check('#issueForm input[name="infinite"]');
  const [ download ] = await Promise.all([
    page.waitForEvent('download'),
    page.click('#issueForm button[type="submit"]')
  ]);
  const path = await download.path();
  const buf = require('fs').readFileSync(path);
  return buf;
}

function parsePdf(buf){
  const txt = buf.toString('binary');
  if(!txt.startsWith('%PDF-1.4')) throw new Error('Missing header');
  const xrefPos = txt.lastIndexOf('startxref');
  if(xrefPos === -1) throw new Error('No startxref');
  const trailerIdx = txt.indexOf('trailer', xrefPos - 1000);
  if(trailerIdx === -1) throw new Error('No trailer');
  // Basic object presence checks
  const needed = ['1 0 obj','2 0 obj','3 0 obj','4 0 obj','5 0 obj','/Catalog','/Pages','/Image','/XObject'];
  for(const n of needed){ if(!txt.includes(n)) throw new Error('Missing segment '+n); }
  // verify xref table lines correspond to object markers
  const xrefIdx = txt.lastIndexOf('xref');
  if(xrefIdx === -1) throw new Error('No xref');
  const xrefSection = txt.slice(xrefIdx, xrefIdx+400).split(/\n/).slice(1,15);
  const offsets = [];
  for(const line of xrefSection){
    const m = line.match(/^(\d{10}) 00000 n/); if(m) offsets.push(parseInt(m[1],10));
  }
  // Ensure each offset points to an object keyword
  for(const off of offsets){
    const segment = txt.slice(off, off+20);
    if(!/\d+ 0 obj/.test(segment)) throw new Error('Offset does not point to object');
  }
  return true;
}

test('single PDF structural integrity', async ({ page }) => {
  await login(page);
  const buf = await generateSingle(page);
  expect(() => parsePdf(buf)).not.toThrow();
});
