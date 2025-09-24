#!/usr/bin/env bash
set -euo pipefail

BASE_URL=${BASE_URL:-"http://127.0.0.1:8080"}
EXPECTED_CSP="Content-Security-Policy: default-src 'self' blob:; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; upgrade-insecure-requests"

check_endpoint() {
  local path="$1"
  echo "[headers] Checking ${BASE_URL}${path}"
  local hdrs
  hdrs=$(curl -sSI "${BASE_URL}${path}" || true)
  echo "$hdrs" | sed -n '1,40p'

  # Reconstruct CSP header possibly folded across multiple lines by PHP dev server/proxies
  local csp
  csp=$(echo "$hdrs" | awk '
    BEGIN{IGNORECASE=1; cap=0; csp=""}
    {
      if (cap==0 && $0 ~ /^Content-Security-Policy:/) { cap=1; csp=$0; next }
      if (cap==1) {
        if ($0 ~ /^[A-Za-z0-9-]+:[[:space:]]/ ) { cap=2 }
        else {
          sub(/^\s+/,""); sub(/\s+$/,""); if (length($0)>0) { csp=csp" "$0 }
        }
      }
    }
    END{ if (csp!="") print csp }'
  )

  if [[ -z "$csp" ]]; then
    echo "[headers][ERR] CSP header missing on ${path}" >&2
    return 1
  fi

  if [[ "$csp" != "$EXPECTED_CSP" ]]; then
    echo "[headers][ERR] Mismatch on ${path}:" >&2
    echo "  expected: $EXPECTED_CSP" >&2
    echo "  actual:   ${csp,,}" | sed 's/^/  /' >&2
    return 1
  fi

  # Check other required headers exist
  grep -iq '^X-Content-Type-Options: nosniff$' <<<"$hdrs" || { echo "[headers][ERR] Missing X-Content-Type-Options" >&2; return 1; }
  grep -iq '^X-Frame-Options: DENY$' <<<"$hdrs" || { echo "[headers][ERR] Missing X-Frame-Options" >&2; return 1; }
  grep -iq '^Referrer-Policy: no-referrer$' <<<"$hdrs" || { echo "[headers][ERR] Missing Referrer-Policy" >&2; return 1; }
  grep -iq '^Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()$' <<<"$hdrs" || { echo "[headers][ERR] Missing Permissions-Policy" >&2; return 1; }
  grep -iq '^X-XSS-Protection: 0$' <<<"$hdrs" || { echo "[headers][ERR] Missing X-XSS-Protection" >&2; return 1; }
}

rc=0
check_endpoint "/verify.php" || rc=1
check_endpoint "/index.php" || rc=1
exit $rc
