// Global setup: ensure test admin user exists before UI tests run.
const { execSync } = require('child_process');
module.exports = async () => {
  execSync('php tests/create_test_admin.php', { stdio: 'inherit' });
};
