#!/usr/bin/env bash
set -Eeuo pipefail

# Verifies required security headers are present on selected endpoints.
# Exits non‑zero on mismatch. Designed for CI but can be run locally.

BASE_URL=${BASE_URL:-http://127.0.0.1:8080}
ENDPOINTS=${ENDPOINTS:-"/verify.php"}
# Extra flags for curl (e.g., -k for self-signed HTTPS)
CURL_FLAGS=${CURL_FLAGS:-}

# Expected headers (exact match)
EXPECTED=$(cat <<'EOF'
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; upgrade-insecure-requests
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()
X-XSS-Protection: 0
EOF
)

# Quick reachability probe
if ! curl -sS $CURL_FLAGS -I "$BASE_URL/" >/dev/null; then
  echo "[headers][ERR] Base URL not reachable: $BASE_URL"
  exit 2
fi

fail=0
for ep in $ENDPOINTS; do
  url="$BASE_URL$ep"
  echo "[headers] Checking $url"
  # Fetch headers; normalize whitespace
  hdrs=$(curl -sSI $CURL_FLAGS "$url" | tr -d '\r') || true
  # Quick show
  echo "$hdrs" | sed -n '1,40p'
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    # shellcheck disable=2001
    name=$(echo "$line" | sed 's/:.*$//')
    # Find actual header line (case-insensitive name match)
    actual=$(echo "$hdrs" | awk -v IGNORECASE=1 -v n="$name" '$0 ~ "^" n ":" {print; exit}')
    if [[ -z "$actual" ]]; then
      echo "[headers][ERR] Missing header on $ep: $name"
      fail=1
      continue
    fi
    if [[ "$actual" != "$line" ]]; then
      echo "[headers][ERR] Mismatch on $ep:"
      echo "  expected: $line"
      echo "  actual:   $actual"
      fail=1
    fi
  done <<< "$EXPECTED"
done

exit $fail
