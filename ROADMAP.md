# CertReg Roadmap

## Vision
Побудувати мультиорганізаційну платформу видачі та верифікації нагород із гнучким брендуванням, шаблонами сертифікатів, аудитом дій та можливістю масштабування для інтеграцій.

---
## Phase 1: Template System (High Priority)
### 1.1 DB & Schema
- [x] Create `templates` table (org scoped, status enum, file metadata, coords JSON, versioning fields)
- [x] Alter `tokens` add `template_id` FK (ON DELETE SET NULL)

### 1.2 Backend CRUD APIs
- [x] `api/template_create.php` – upload file, validate, store metadata, generate preview
- [x] `api/template_update.php` – update name/coords, optional file replace
- [x] `api/template_delete.php` – delete template, nullify references
- [x] `api/template_toggle.php` – switch active/inactive
- [x] Extend `templates_list.php` – full metadata + minimal mode

### 1.3 UI & Editor
- [x] Templates tab in `settings.php`
- [x] `template.php` detail page (basic manage: rename/toggle/replace/delete)
- [ ] JS coords editor (canvas + drag/drop, property panel, save)
- [x] Preview generation pipeline

### 1.4 Issuance Integration
- [x] Template select in single & bulk issuance
- [x] Persist `template_id` with token
- [x] Org ownership validation
- [ ] Coordinate-based render (image/PDF step)
- [x] README section: Templates

---
## Phase 2: Issuance Enhancements (High Priority)
### 2.1 Batch PDF Generation
- [ ] PDF/ZIP pipeline
- [ ] Real-time progress indicator
- [ ] Memory/stream optimizations
- [ ] Page size options (A4/Letter/Custom)

### 2.2 Statistics & Reporting
- [ ] Dashboard (counts per org/time)
- [ ] Stats API endpoints
- [ ] CSV/Excel export
- [ ] Charts UI (daily/monthly)

---
## Phase 3: Audit & Security (Medium Priority)
### 3.1 Audit Logging
- [ ] `audit_log` table
- [ ] Hooks for org/template/operator CRUD
- [ ] Filtered viewer UI
- [ ] Retention cleanup (>90d)

### 3.2 Security Hardening
- [ ] Rate limiting middleware
- [ ] 2FA (TOTP) for admins
- [ ] Session token refactor
- [ ] First login password reset enforcement

---
## Phase 4: UX Improvements (Low Priority)
### 4.1 Notifications
- [ ] Toast system
- [ ] Server flash messages
- [ ] Realtime channel (SSE/WebSocket)
- [ ] Email notifications

### 4.2 Interface Enhancements
- [ ] Dark mode
- [ ] Keyboard shortcuts (e.g., Ctrl+S)
- [ ] Onboarding tour
- [ ] Mobile layout refinements

---
## Phase 5: Extended Features (Optional)
### 5.1 External API & Integrations
- [ ] OAuth2 REST API
- [ ] Webhooks
- [ ] SDK scaffolding
- [ ] OpenAPI spec

### 5.2 White Label & Multi-Tenancy
- [ ] Custom domains
- [ ] Branded emails per org
- [ ] Stronger data isolation patterns

---
## Technical Debt & Foundations
- [ ] Unified API error format `{ ok:false, error, message }`
- [ ] Central validation helpers (lengths, patterns, file checks)
- [ ] Query/index optimization pass
- [ ] Architecture / security / API docs expansion
- [x] Basic smoke & unit tests (template CRUD)

---
## Data Structures (Draft)
### templates.coords JSON element example:
```json
{
  "field": "recipient_name",
  "x": 420,
  "y": 690,
  "size": 48,
  "font": "Montserrat",
  "align": "center",
  "color": "#102d4e"
}
```
Potential future keys: `max_width`, `uppercase`, `letter_spacing`.

---
## File Layout (Proposed)
```
files/
  templates/
    {org_id}/
      {template_id}/
        original.{ext}
        preview.jpg
```

---
## API Response Standard
Success:
```json
{ "ok": true, "data": { /* ... */ } }
```
Error:
```json
{ "ok": false, "error": "CODE", "message": "Human readable" }
```

---
## Changelog Anchor
Maintain updates here when milestones are completed.

---
## Notes
This roadmap is a living document; adjust as priorities shift or constraints emerge.
