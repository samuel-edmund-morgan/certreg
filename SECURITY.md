# SECURITY

This document consolidates the security model for the registry, covering
core properties, threat scenarios, hardening measures and testing
approach.

## Contents
- [Core Properties](#core-properties)
- [Threat Scenarios](#threat-scenarios)
- [Non-Goals](#non-goals)
- [NAME Normalisation](#name-normalisation)
- [Canonical v2 Format](#canonical-v2-format)
- [Expiry Sentinel](#expiry-sentinel)
- [Password Hashing](#password-hashing)
- [Hardening Checklist](#hardening-checklist)
- [Bulk Endpoint Security Notes](#bulk-endpoint-security-notes)
- [INT Code](#int-code)
- [Log Hygiene](#log-hygiene)
- [Next Steps](#next-steps)
- [Testing Checklist](#testing-checklist)
- [Incident Considerations](#incident-considerations)
- [Future (Optional v3 Thoughts)](#future-optional-v3-thoughts)

## Core Properties
* Zero PII storage: NAME never leaves client, only HMAC digest `h` and metadata stored.
* Per-certificate random 32B salt in QR only (not stored) defeats offline brute-force on leaked DB alone.
* HMAC-SHA256 over canonical v2 string binds NAME, ORG, CID, course, grade, issued_date, valid_until.
* Short Integrity Code (INT) = first 10 hex of HMAC (display convenience, not security boundary).

## Threat Scenarios
| Scenario | Outcome | Mitigation |
|----------|---------|------------|
| DB dump exfiltration | No names, only tokens & h | Salt absent → impractical brute-force; strong admin hash |
| QR image tampering (replace code) | INT mismatch / failed HMAC | INT visual compare + user NAME input step |
| Attempt to alter printed NAME only | Fails at HMAC recompute | NAME part of canonical string |
| Token swap (reuse other CID) | HMAC mismatch (CID inside canonical) | v2 includes CID |
| ORG code change after issuance (early v2 without `org`) | Breaks validation | Freeze org_code, embed org in new QR |
| Mass status scraping | Limited metadata only | Rate-limit, minimal fields, no NAME |
| Replay of status requests | Harmless | Idempotent lookup increment only |

## Non-Goals
* Offline verification (server contact required intentionally)
* NAME confidentiality when user voluntarily reveals it to verifier
* Preventing user from sharing their own valid certificate

## NAME Normalisation
Unchanged from v1: NFC, remove apostrophes, collapse spaces, trim, uppercase, homoglyph guard (mixed Cyrillic + Latin T/O/C). Future: expand homoglyph set (A,E,K,M,H,O,P,C,T,X,Y,B).

## Canonical v2 Format
```
v2|NAME|ORG|CID|COURSE|GRADE|ISSUED_DATE|VALID_UNTIL
```
ORG from config (or payload if present) – do not rotate casually.

## Expiry Sentinel
`4000-01-01` chosen to avoid dedicated boolean; simplifies single index and comparisons. Expired => valid_until < today && != sentinel.

## Password Hashing
Preferred: Argon2id (memory_cost ≈ 128MB, time_cost 3, threads 1). Fallback: bcrypt cost ≥ 12. Application auto-rehashes on successful login if stronger available. Ensure PHP build has Argon2id; else monitor bcrypt cost drift vs hardware.

## Hardening Checklist
Legend: ✓ done, ▶ planned.

### Web / HTTP
| Control | Status | Notes |
|---------|--------|-------|
| Whitelist PHP entrypoints | ✓ | Nginx location block denies arbitrary .php |
| Strict CSP (no inline) | ▶ | Transition: nonce-based → external JS only |
| X-Frame-Options / frame-ancestors deny | ✓ | Added header & CSP directive |
| Referrer-Policy no-referrer | ✓ | Minimal leakage |
| Permissions-Policy restrictive | ✓ | Disable unused features |
| HSTS (long + preload) | ▶ | After stable HTTPS deployment |
| Rate-limit status API | ▶ | `limit_req` 30–60 r/m/IP |
| Suppress logging QR param `p` | ▶ | Custom log_format / map to blank |

### Application
| Control | Status | Notes |
|---------|--------|-------|
| CSRF for admin POST | ✓ | Token + header support JSON bulk endpoint |
| Session cookie Secure/HttpOnly/SameSite | ✓ | Logic in auth.php |
| Output escaping | ✓ | `htmlspecialchars` in listings |
| Validation of inputs | ✓ | Length / charset constraints |
| Revocation reason validation | ✓ | Server + client side |
| Homoglyph detection baseline | ✓ | Mixed Cyrillic/Latin TOC block |
| Bulk operations transactional | ✓ | Per-CID DB transactions & events |
| Self-check script | ▶ | Extend to headers / file whitelist |

### Database
| Control | Status | Notes |
|---------|--------|-------|
| Separate limited public user | ✓ | For status API lookups only |
| Strict SQL modes | ▶ | Enable `STRICT_TRANS_TABLES` etc. |
| Regular encrypted backups | ▶ | Use age/gpg; exclude transient logs |
| Privilege minimization (no ALTER in runtime) | ▶ | Revoke after migrations |

### Server / OS
| Control | Status | Notes |
|---------|--------|-------|
| Auto security updates | ▶ | `unattended-upgrades` or distro equivalent |
| Isolated php-fpm user | ▶ | Dedicated pool user & perms 750 |
| SSH key-only auth | ▶ | Disable password logins |
| Log rotation & retention policy | ▶ | Ensure no sensitive QR params |
| Time sync | ✓ | chrony or systemd-timesyncd |

### TLS / Domain
| Control | Status | Notes |
|---------|--------|-------|
| ACME automated renewal | ▶ | certbot timer/systemd |
| OCSP stapling | ▶ | `ssl_stapling on;` |
| HSTS preload submission | ▶ | After stable HTTPS period |

### Operations
| Control | Status | Notes |
|---------|--------|-------|
| Threat classification doc | ▶ | Summarise data flows, non-PII status |
| Periodic password audit | ▶ | 6–12 months or staff change |
| Incident runbook | ▶ | Revoke creds, rotate keys, verify integrity |
| Backup restore test | ▶ | Periodic drill |

## Bulk Endpoint Security Notes
* Accepts up to 100 CIDs to prevent oversized payload abuse.
* CSRF protected (header or form token).
* Each CID handled in transaction; failures logged individually.

## INT Code
First 10 hex of HMAC (≈40 bits). Useful for manual verbal / visual matching. Not a cryptographic proof; full HMAC always verified.

## Log Hygiene
Avoid logging full querystrings containing `p` (QR payload). If logs must exist, strip or hash parameter name only.

## Next Steps
| Action | Purpose |
|--------|---------|
| Remove all inline scripts | Allow CSP `script-src 'self'` only |
| Enforce rate limiting for status & events endpoints | Limit abuse |
| Expand homoglyph detection set | Improve spoofing detection |
| Implement self-check CLI: headers diff, file whitelist, PHP version, writable dirs | Automate configuration checks |
| Automate security header regression test (baseline snapshot) | Detect misconfigurations |

## Testing Checklist
| Test | Goal |
|------|------|
| v1 cert verifies | Backward compatibility |
| Early v2 (no org) verifies | Fallback path |
| New v2 (org) verifies | Primary path |
| Expired cert flagged | Expiry logic |
| Revoked cert flagged | Revocation logic |
| Bulk revoke/unrevoke/delete | Batch integrity + audit |

## Incident Considerations
If DB + web logs leak simultaneously (rare worst case) attacker may obtain (salt,h) pairs for subset; targeted brute-force of specific known names still computationally heavy due to Unicode + combinatorics. Maintain strong admin password to avoid lateral compromise.

## Future (Optional v3 Thoughts)
Potential additional fields: template_version, issuer role, stronger homoglyph set, algorithm agility (e.g., HMAC-SHA512). Any change must preserve deterministic reconstruction across clients.
