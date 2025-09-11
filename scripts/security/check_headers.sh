#!/usr/bin/env bash
set -Eeuo pipefail

# Verifies required security headers are present on selected endpoints.
# Exits non‑zero on mismatch. Designed for CI but can be run locally.

BASE_URL=${BASE_URL:-http://127.0.0.1:8080}
ENDPOINTS=${ENDPOINTS:-"/verify.php"}
# Extra flags for curl (e.g., -k for self-signed HTTPS)
CURL_FLAGS=${CURL_FLAGS:-}

# Expected headers (name-insensitive, value exact match)
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
  # Parse status code (e.g., HTTP/2 200)
  status_code=$(echo "$hdrs" | sed -n '1s/^[^ ]\+ \([0-9][0-9][0-9]\).*/\1/p')
  if [[ -z "${status_code:-}" ]]; then status_code=000; fi
  # On 4xx/5xx responses, we don't require CSP because it may be served by the web server directly
  skip_csp_on_error=0
  if [[ "$status_code" =~ ^[45] ]]; then
    skip_csp_on_error=1
  fi
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    # Expected header name and value
    exp_name=$(echo "$line" | sed 's/:.*$//')
    exp_value=$(echo "$line" | sed 's/^[^:]*:[[:space:]]*//' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
    # Optionally skip CSP on error codes
    if [[ $skip_csp_on_error -eq 1 && ${exp_name,,} == "content-security-policy" ]]; then
      echo "[headers] Note: skipping CSP check on $ep due to HTTP $status_code"
      continue
    fi
    # Collect all actual lines for this header (case-insensitive name match)
    mapfile -t actual_lines < <(echo "$hdrs" | awk -v IGNORECASE=1 -v n="$exp_name" '$0 ~ "^" n ":" {print}')
    if [[ ${#actual_lines[@]} -eq 0 ]]; then
      echo "[headers][ERR] Missing header on $ep: $exp_name"
      fail=1
      continue
    fi
    # Check if any actual header value matches exactly (ignoring surrounding whitespace)
    found_match=0
    first_actual="${actual_lines[0]}"
    first_actual_val=$(echo "$first_actual" | sed 's/^[^:]*:[[:space:]]*//' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
    for actual in "${actual_lines[@]}"; do
      actual_val=$(echo "$actual" | sed 's/^[^:]*:[[:space:]]*//' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
      if [[ "$actual_val" == "$exp_value" ]]; then
        found_match=1
        break
      fi
    done
    if [[ $found_match -eq 0 ]]; then
      echo "[headers][ERR] Mismatch on $ep:"
      echo "  expected: $exp_name: $exp_value"
      echo "  actual:   ${first_actual,,}"
      fail=1
    fi
  done <<< "$EXPECTED"
done

exit $fail
