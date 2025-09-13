# MIGRATION (to v3, privacy-first)

This guide documents the migration to canonical format v3 and the strict privacy model where PІІ (ПІБ) never leaves the browser, is never stored on the server, and never appears in QR payloads.

## Contents
- [v3 Overview](#v3-overview)
- [Data Model Changes](#data-model-changes)
- [Database Migration](#database-migration)
- [Application Changes](#application-changes)
- [Canonical Reconstruction (reference)](#canonical-reconstruction-reference)
- [NAME Normalisation](#name-normalisation)
- [Validation & Smoke Checks](#validation--smoke-checks)
- [Rollback](#rollback)

## v3 Overview
v3 removes legacy "course/grade" fields and introduces two key concepts:
- Canonical verify URL included in the canonical string (binds signatures to the verification domain)
- Optional `EXTRA` field (free-form, non-PII) that can be stored in DB and embedded in QR

Canonical string (v3):
```
v3|PIB|ORG|CID|ISSUED_DATE|VALID_UNTIL|CANON_URL|EXTRA
```

- `PIB` is the normalized name (client-side only; not stored server-side)
- `ORG` is the issuing organisation code from `config.php`
- `CID` is a client-generated identifier
- `ISSUED_DATE` and `VALID_UNTIL` are ISO dates; unlimited validity uses sentinel `4000-01-01`
- `CANON_URL` is the canonical verify URL from config (e.g., `https://example.org/verify.php`)
- `EXTRA` is optional metadata (e.g., nomination), no PІІ

QR payload (v3) contains no PІІ and is a compact JSON packed into the verify URL:
```
{ v:3, cid, s, org, date, valid_until, canon, extra }
```
where `s` is the per-certificate HMAC salt (base64url).

## Data Model Changes
Table `tokens` (final shape relevant for v3):
- `version` (INT) defaults to 3 for new rows
- `h` (CHAR(64)) HMAC hex
- `extra_info` (VARCHAR(255) NULL) replaces any legacy `course`/`grade`
- `issued_date` (DATE)
- `valid_until` (DATE, sentinel `4000-01-01` means no expiry)

All legacy `course` and `grade` columns must be dropped.

## Database Migration
Use the provided migration script:
```
php scripts/migrate.php
```
It will:
1. Add `extra_info` after `h` if missing
2. Drop `course` and `grade` if present

If you maintain SQL manually, the essential steps are:
```sql
ALTER TABLE tokens ADD COLUMN extra_info VARCHAR(255) NULL AFTER h;
ALTER TABLE tokens DROP COLUMN course;
ALTER TABLE tokens DROP COLUMN grade;
```

## Application Changes
- `config.php` must define `canonical_verify_url` and stable `org_code`
- Issuance UI only asks for: PIB (not sent to server), optional EXTRA, dates
- Register API accepts only v3 payload: `{ cid, v:3, h, date, valid_until, extra_info? }`
- Verification recomputes canonical locally (no PІІ on server) and compares HMAC
- Admin UI shows `extra_info`, dates, status and events; no course/grade anywhere

## Canonical Reconstruction (reference)
```php
$pibNorm = '...'; // client-only normalized name
$org = $CONFIG['org_code'];
$canonUrl = $CONFIG['canonical_verify_url'];
$canonical = "v3|$pibNorm|$org|$cid|$issued|$validUntil|$canonUrl|$extra";
$h = hmac_sha256_hex($salt, $canonical);
```

## NAME Normalisation
1. NFC
2. Remove apostrophes ("'", "’", "`", U+02BC)
3. Collapse whitespace → single space
4. Trim
5. Uppercase
6. Heuristic to warn about mixed Latin/Cyrillic homoglyphs (client-side only)

## Validation & Smoke Checks
- Issue one award via UI; ensure auto-download works and QR opens verify page
- Verify API `/api/register.php` rejects non-v3 `v` values
- Verify `/api/status.php` returns `exists`, `h`, `valid_until`
- Revoke/unrevoke/delete flows update `token_events` as expected
- `self_check.php` (if present) passes

## Rollback
1. Keep a DB backup prior to schema changes
2. Revert to a commit before v3 if needed
3. Note: v3-issued records remain cryptographically valid; revoke them via bulk action if policy requires
