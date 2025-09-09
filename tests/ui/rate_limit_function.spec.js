const { test, expect } = require('@playwright/test');
const cp = require('child_process');
const path = require('path');

// Directly exercise rate_limit() logic in an isolated PHP process (bypasses CERTREG_TEST_MODE from web server env).
// We call rate_limit with custom key & overrides to trigger 429 on third call.

function runPhp(code){
  return cp.spawnSync('php', ['-d','display_errors=0','-r', code], { encoding: 'utf8' });
}

test('rate_limit function enforces limit', async () => {
  const script = `require '${path.join(__dirname,'..','..','rate_limit.php').replace(/\\/g,'/')}';
  putenv('CERTREG_TEST_MODE=');
  $_SERVER['REMOTE_ADDR']='127.9.9.9';
  // Clean previous state file if any
  $dir = sys_get_temp_dir().'/certreg_rl';
  $file = $dir.'/custom_127.9.9.9';
  if(file_exists($file)) unlink($file);
  rate_limit('custom',[2,60]); echo "1\n"; // first allowed
  rate_limit('custom',[2,60]); echo "2\n"; // second allowed reaches limit
  rate_limit('custom',[2,60]); echo "3\n"; // should not reach here
  `;
  const res = runPhp(script);
  // On 3rd call process exits before echo 3; output should contain lines 1 & 2 then JSON error
  expect(res.stdout).toMatch(/1\n2\n/);
  expect(res.stdout).toMatch(/rate_limited/);
});
