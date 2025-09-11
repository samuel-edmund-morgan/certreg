#!/usr/bin/env bash
set -Eeuo pipefail

# certreg files backup script
# Archives critical paths into a timestamped tar.gz. Supports DRY_RUN and excludes.

BACKUP_DIR=${BACKUP_DIR:-/var/backups/certreg}
RETENTION_DAYS=${RETENTION_DAYS:-14}
PROJECT_ROOT=${PROJECT_ROOT:-/var/www/certreg}
EXTRA_PATHS=${EXTRA_PATHS:-"/etc/nginx /etc/php /var/log/nginx"}
DRY_RUN=${DRY_RUN:-0}

log() { echo "[backup_files] $*"; }
die() { echo "[backup_files][ERR] $*" >&2; exit 1; }

need() { command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"; }
need tar; need date; need mkdir

DATE_STAMP=$(date +%Y%m%d)
TIME_STAMP=$(date +%H%M%S)
OUT_DIR="$BACKUP_DIR/$DATE_STAMP"
OUT_FILE="$OUT_DIR/files_${DATE_STAMP}_${TIME_STAMP}.tar.gz"

# Default include paths
INCLUDE_PATHS=("$PROJECT_ROOT" $EXTRA_PATHS)

# Excludes
EXCLUDES=(
  "--exclude=$PROJECT_ROOT/node_modules"
  "--exclude=$PROJECT_ROOT/test-results"
  "--exclude=$PROJECT_ROOT/playwright-report"
  "--exclude=$PROJECT_ROOT/blob-report"
  "--exclude=$PROJECT_ROOT/files/certs" # generated artifacts
  "--exclude=$PROJECT_ROOT/.git"
)

cmd_mkdir=(mkdir -p "$OUT_DIR")
cmd_tar=(tar -czf "$OUT_FILE" "${EXCLUDES[@]}" "${INCLUDE_PATHS[@]}")
cmd_prune=(find "$BACKUP_DIR" -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -print -exec rm -rf {} +)

log "Backup dir: $OUT_DIR"
if (( DRY_RUN == 1 )); then
  printf '[backup_files][DRY] %q ' "${cmd_mkdir[@]}"; echo
  printf '[backup_files][DRY] %q ' "${cmd_tar[@]}"; echo
  printf '[backup_files][DRY] %q ' "${cmd_prune[@]}"; echo
  exit 0
fi

"${cmd_mkdir[@]}"
"${cmd_tar[@]}"
log "Files archive written: $OUT_FILE"

"${cmd_prune[@]}" || true
log "Done."
