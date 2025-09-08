# MIGRATION

This guide describes how to migrate the certificate registry from
canonical format v1 to v2, covering compatibility, schema changes and
operational steps.

## Contents
- [v1 → v2 Overview](#v1--v2-overview)
- [Backward Compatibility](#backward-compatibility)
- [Expiry Model](#expiry-model)
- [Data Model Changes](#data-model-changes)
- [Migration Steps](#migration-steps)
- [Canonical Reconstruction Pseudocode](#canonical-reconstruction-pseudocode)
- [NAME Normalisation (unchanged)](#name-normalisation-unchanged)
- [Testing Checklist](#testing-checklist)
- [Rollback Plan](#rollback-plan)
- [Future Extensions (v3 placeholder considerations)](#future-extensions-v3-placeholder-considerations)

## v1 → v2 Overview
v2 canonical string adds ORG, CID, VALID_UNTIL while keeping NAME normalisation identical.
```
v1: v1|NAME|COURSE|GRADE|DATE
v2: v2|NAME|ORG|CID|COURSE|GRADE|ISSUED_DATE|VALID_UNTIL
```
Reasons:
* ORG binds certificates to issuing organisation (prevents silent domain relocation ambiguity)
* CID inside canonical allows HMAC to cover identifier (prevents token swapping edge cases)
* VALID_UNTIL introduces expiry (optional) using sentinel `4000-01-01` instead of boolean

### TL;DR (Operational Snapshot)
| Step | Action | Command / Note |
|------|--------|----------------|
| 1 | Backup DB | `mysqldump certreg > pre_v2.sql` |
| 2 | Freeze `org_code` | Ensure `config.php` stable |
| 3 | Deploy code (fallback logic) | Pull commit with dual-path verify |
| 4 | Schema alter | `ALTER TABLE tokens ADD valid_until DATE NOT NULL DEFAULT '4000-01-01' AFTER issued_date;` |
| 5 | (Rename date→issued_date) | `ALTER TABLE tokens CHANGE date issued_date DATE NOT NULL;` |
| 6 | Smoke test v1 + early v2 | Use sample payloads | 
| 7 | Start issuing with expiry | UI optional field |
| 8 | Tag release | `git tag v2-migration` |
| 9 | Announce & document | Link README + this file |

Rollback = restore dump + revert code. v2 certs лишаються криптографічно валідними (можна відкликати політично).

## Backward Compatibility
Early v2 QR codes may lack the `org` field in payload. Verification logic:
1. If `org` present in QR → use it in canonical reconstruction.
2. Else → fall back to current `org_code` from `config.php`.
3. Compare computed HMAC with stored `h`.

Implication: changing `org_code` after issuing any payloads that did not embed `org` will invalidate those certificates. Therefore freeze `org_code` once production issuance starts.

## Expiry Model
`valid_until` stores either real ISO date or sentinel `4000-01-01` meaning "no expiry".
Front-end considers certificate expired if `valid_until < today` and not sentinel. This keeps query logic simple (single DATE column, index friendly).

## Data Model Changes
Table `tokens` fields impacted:
* add `valid_until` DATE (default '4000-01-01')
* (existing) `issued_date` used instead of generic `date`
* no storage of ORG (comes from config)
* CID already present

## Migration Steps

### Preparation
1. Freeze `org_code` value (write once) in `config.php`.
2. Deploy code supporting fallback verification.

### Database Schema Updates
Run:

```sql
ALTER TABLE tokens
    ADD COLUMN valid_until DATE NOT NULL DEFAULT '4000-01-01' AFTER issued_date;
```

If your previous column name was `date` rename it for clarity:

```sql
ALTER TABLE tokens CHANGE COLUMN date issued_date DATE NOT NULL;
```

### Application Updates
1. Update issuance UI to request optional expiry and include ORG + CID in canonical.
2. Regenerate README / docs references.
 3. Add monitoring query:
```sql
SELECT COUNT(*) AS expired FROM tokens WHERE valid_until <> '4000-01-01' AND valid_until < CURDATE();
```

## Canonical Reconstruction Pseudocode
```php
$org = $payload['org'] ?? $CONFIG['org_code'];
$canonical = "v2|$NAME_NORM|$org|$cid|$course|$grade|$issued|$validUntil";
$h = hmac_sha256_hex($salt, $canonical);
```

## NAME Normalisation (unchanged)
1. NFC
2. Remove apostrophes ("'", "’", "`", U+02BC)
3. Collapse whitespace → single space
4. trim
5. Uppercase
6. Block mixed Cyrillic + Latin suspicious (T,O,C baseline)

## Testing Checklist
- [ ] Existing v1 certificates still verify (code path for version 1 unchanged)
- [ ] Early v2 (without org in QR) verify after deployment
- [ ] New v2 (with org) verify with frozen org_code
- [ ] Expired sample (set yesterday) shows expired status and not valid
- [ ] Revocation still works (revoke/unrevoke/delete) and logs events
- [ ] Bulk operations unaffected by added column
 - [ ] self_check asserts presence of `valid_until`

## Rollback Plan
If critical issue appears post-migration:
1. Keep DB backup pre-alter.
2. Revert application code to commit before v2 merge.
3. Drop `valid_until` column if added (optional cleanup).
4. Certificates issued as v2 remain cryptographically valid; if you must invalidate them, mass revoke via bulk endpoint.

## Future Extensions (v3 placeholder considerations)
Potential future fields (not active): template_version, issuer role, additional anti-homoglyph set, signature algorithm agility. Keep v2 stable to avoid churn.
