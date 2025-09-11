#!/usr/bin/env bash
set -Eeuo pipefail

# certreg DB backup script
# - Reads DB creds from environment or parses /var/www/certreg/config.php via PHP-CLI
# - Produces gzipped mysqldump with timestamp under BACKUP_DIR/YYYYMMDD
# - Supports --dry-run and retention pruning

BACKUP_DIR=${BACKUP_DIR:-/var/backups/certreg}
RETENTION_DAYS=${RETENTION_DAYS:-14}
PROJECT_ROOT=${PROJECT_ROOT:-/var/www/certreg}
DRY_RUN=${DRY_RUN:-0}

log() { echo "[backup_db] $*"; }
die() { echo "[backup_db][ERR] $*" >&2; exit 1; }

usage() {
  cat <<EOF
Usage: BACKUP_DIR=/var/backups/certreg PROJECT_ROOT=/var/www/certreg \
       RETENTION_DAYS=14 DRY_RUN=0 ./backup_db.sh

Env overrides:
  BACKUP_DIR       Base directory to store backups (default: /var/backups/certreg)
  PROJECT_ROOT     Path to project (default: /var/www/certreg)
  RETENTION_DAYS   How many days to keep backups (default: 14)
  DRY_RUN          1 to print commands without executing

DB creds can be provided via env or read from config.php:
  DB_HOST, DB_NAME, DB_USER, DB_PASS
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then usage; exit 0; fi

need() { command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"; }
need date; need mkdir; need gzip

# Try to obtain DB creds
DB_HOST=${DB_HOST:-}
DB_NAME=${DB_NAME:-}
DB_USER=${DB_USER:-}
DB_PASS=${DB_PASS:-}

if [[ -z "$DB_HOST" || -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
  need php
  CONFIG_PHP="$PROJECT_ROOT/config.php"
  [[ -r "$CONFIG_PHP" ]] || die "config.php not readable at $CONFIG_PHP; set DB_* env vars"
  # Use php -r (no opening tag) to read DB creds from config.php (supports array-returning config)
  mapfile -t arr < <(CONFIG_PHP="$CONFIG_PHP" php -r '
    $cfg = include getenv("CONFIG_PHP");
    if (is_array($cfg)) {
      echo ($cfg["db_host"] ?? ""), "\n",
           ($cfg["db_name"] ?? ""), "\n",
           ($cfg["db_user"] ?? ""), "\n",
           ($cfg["db_pass"] ?? ""), "\n";
    } else {
      echo (isset($db_host)?$db_host:""), "\n",
           (isset($db_name)?$db_name:""), "\n",
           (isset($db_user)?$db_user:""), "\n",
           (isset($db_pass)?$db_pass:""), "\n";
    }
  ') || true
  if (( ${#arr[@]} >= 4 )); then
    DB_HOST=${DB_HOST:-${arr[0]}}
    DB_NAME=${DB_NAME:-${arr[1]}}
    DB_USER=${DB_USER:-${arr[2]}}
    DB_PASS=${DB_PASS:-${arr[3]}}
  fi
fi

[[ -n "$DB_HOST" && -n "$DB_NAME" && -n "$DB_USER" && -n "$DB_PASS" ]] || die "DB_* not set and could not parse from config.php"

need mysqldump

DATE_STAMP=$(date +%Y%m%d)
TIME_STAMP=$(date +%H%M%S)
OUT_DIR="$BACKUP_DIR/$DATE_STAMP"
OUT_FILE_DB="$OUT_DIR/db_${DB_NAME}_${DATE_STAMP}_${TIME_STAMP}.sql.gz"

cmd_mkdir=(mkdir -p "$OUT_DIR")
cmd_dump=(mysqldump \
  --single-transaction --quick --lock-tables=false \
  --default-character-set=utf8mb4 \
  --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" "$DB_NAME")

cmd_prune=(find "$BACKUP_DIR" -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -print -exec rm -rf {} +)

log "Backup dir: $OUT_DIR"
if (( DRY_RUN == 1 )); then
  printf '[backup_db][DRY] %q ' "${cmd_mkdir[@]}"; echo
  # Redact password before printing
  redacted=("${cmd_dump[@]}")
  for i in "${!redacted[@]}"; do
    [[ "${redacted[$i]}" == --password=* ]] && redacted[$i]='--password=****'
  done
  printf '[backup_db][DRY] %q ' "${redacted[@]}"; echo "| gzip > $OUT_FILE_DB"
  printf '[backup_db][DRY] %q ' "${cmd_prune[@]}"; echo
  exit 0
fi

"${cmd_mkdir[@]}"
"${cmd_dump[@]}" | gzip -9 > "$OUT_FILE_DB"
log "DB dump written: $OUT_FILE_DB"

# Basic integrity check
gzip -t "$OUT_FILE_DB" && log "gzip integrity OK"

# Prune old days
"${cmd_prune[@]}" || true
log "Done."
