const { test, expect } = require('@playwright/test');
const { login } = require('./_helpers');

// Performance smoke: generate N bulk certificates via real UI interactions.
// Measures time from click on Generate to progress bar completion.
const N = 12;
const THRESHOLD_MS = 9000; // generous to avoid flake on CI

test('bulk performance for '+N+' entries < '+THRESHOLD_MS+'ms', async ({ page }) => {
  test.slow();
  await login(page);
  await page.goto('/issue_token.php');
  // Switch to bulk tab
  await page.getByRole('tab', { name: 'Масова генерація' }).click();
  // Fill shared fields
  const today = new Date().toISOString().slice(0,10);
  await page.locator('#bulkForm input[name="course"]').fill('COURSEP');
  await page.locator('#bulkForm input[name="date"]').fill(today);
  // Ensure infinite checked (default is checked)
  // Add rows via + Рядок button (one row already exists)
  const addBtn = page.locator('#addRowBtn');
  for(let i=1;i<N;i++){ // we already have 1 initial row
    await addBtn.click();
  }
  // Fill names & grades
  const nameInputs = page.locator('#bulkTable tbody tr input[name="name"]');
  const gradeInputs = page.locator('#bulkTable tbody tr input[name="grade"]');
  await expect(nameInputs).toHaveCount(N);
  for(let i=0;i<N;i++){
    await nameInputs.nth(i).fill('Тест Перф'+i);
    await gradeInputs.nth(i).fill('A');
  }
  // Wait for generate button to enable and show correct count
  const genBtn = page.locator('#bulkGenerateBtn');
  await expect(genBtn).toBeEnabled();
  await expect(genBtn).toHaveText(new RegExp('\\('+N+'\\)'));
  const t0 = Date.now();
  await genBtn.click();
  // Wait until progress bar done (class done) and summary text includes all counts
  await page.waitForFunction(() => {
    const bar = document.getElementById('bulkProgressBar');
    if(!bar) return false;
    return bar.classList.contains('done');
  }, { timeout: THRESHOLD_MS + 4000 });
  const elapsed = Date.now() - t0;
  expect(elapsed).toBeLessThan(THRESHOLD_MS);
});
