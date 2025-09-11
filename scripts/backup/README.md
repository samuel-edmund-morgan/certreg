# Backups for certreg

This folder contains simple backup scripts for the database and critical files.

Critical paths:
- /var/www/certreg (project files; keep `config.php` secure)
- /etc/nginx (site configs, TLS integration)
- /etc/php/*/fpm (PHP-FPM configs)
- /var/log/nginx (logs)
- MySQL dumps of the `certreg` database

Usage examples:

```bash
# Dry run DB backup (reads DB creds from /var/www/certreg/config.php)
DRY_RUN=1 BACKUP_DIR=/var/backups/certreg \
  /var/www/certreg/scripts/backup/backup_db.sh

# Real DB backup with 30-day retention
RETENTION_DAYS=30 BACKUP_DIR=/srv/backups/certreg \
  /var/www/certreg/scripts/backup/backup_db.sh

# Dry run files backup including nginx/php configs and logs
DRY_RUN=1 BACKUP_DIR=/var/backups/certreg \
  /var/www/certreg/scripts/backup/backup_files.sh

# Real files backup
BACKUP_DIR=/srv/backups/certreg \
  /var/www/certreg/scripts/backup/backup_files.sh
```

Notes:
- Excludes: node_modules/, test reports, .git/, and generated cert images.
- Validate DB dumps by listing tables or restoring into a test DB.
- Consider encryption (e.g., restic/borg or LUKS) for offsite backups.
