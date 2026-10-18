# SplashEstate CRM - Code Review (October 2026)

This document records the full code review done on 2026-10-18, what was fixed,
how it was verified, and what is still open. It replaces an earlier version of
this file whose claims (for example "CSRF on all forms") did not match the code.

## Summary

Before the review, most of the application could not run on PHP 8:

- Every main CRUD controller (Leads, Clients, Properties, Deals, Tasks, Webhooks)
  failed to load because each defined a `view($id)` action that collided with
  the base `Controller::view()` template renderer. Even on older PHP the action
  shadowed the renderer, so detail pages never rendered.
- Seven models overrode base `Model` methods with incompatible signatures,
  which is a fatal error on PHP 8.
- 24 views referenced by controllers did not exist (all Properties, Deals,
  Tasks and Webhooks pages, several edit pages and four reports).
- Forms did not send CSRF tokens although controllers checked them, so most
  forms always failed. Seven controllers had no CSRF checks at all.
- No user had any RBAC role, so every permission check denied access.
- Lead scoring, workflows and webhooks were implemented but never triggered.
- The migration CLI, workflow UI and saved searches called classes or methods
  that did not exist.

All of the above is fixed. The app now runs cleanly on PHP 8.3 across 38 pages
for every demo role, with no PHP warnings.

## Verification

| Check | Result |
|-------|--------|
| `php -l` on every PHP file | clean |
| Load every class (catches signature fatals `php -l` misses) | clean |
| Static check that every `$this->model->method()` call exists | clean |
| `php tests/run_tests.php` | 57 tests, 404 assertions, passing |
| `php tests/api_tests.php` | passing |
| `tests/smoke.sh`: 38 pages x 4 roles (tenant admin, agent, other tenant, platform admin) | all OK, no PHP warnings |
| Cross-tenant access (tenant 2 user requesting tenant 1 records by id, GET and POST) | refused everywhere |
| End to end: lead created in the UI -> score -> workflow (notification + task) -> signed webhook | working |
| Password reset end to end (email -> link -> new password -> token reuse rejected) | working |
| Migrations from the Phase 1 and pre-October schemas | produce a schema identical to `database.sql`; rollback works |

## Fixes by area

### Core framework
- Router: 404 for unknown controllers/methods (previously fell back to the
  dashboard); only public controller methods are routable (private ones caused
  fatals); case-insensitive controller lookup (e.g. `/savedsearch` failed on
  Linux); `/x/view/{id}` dispatches to `show($id)`.
- Model: column names validated before being placed in SQL; `search()` no longer
  reuses a placeholder (rejected by native prepares); helpers for child tables.
- Database: one shared connection per request (was one per model);
  `getInstance()`/`getConnection()` added (used but never defined).
- `APP_DEBUG=false` in `.env` was read as the truthy string "false".
- Permissions: users without explicit roles fall back to the system role matching
  `users.role`; demo users seeded with roles.
- One `dispatchEvent()` fans each event out to lead scoring, workflows and webhooks.
- Hardened session cookies, idle timeout, security headers, global error handler.

### Security
- CSRF on every form and AJAX request; destructive actions and logout are POST-only.
- SQL injection: sort columns, filter fields, bulk-operation table names and the
  quota resource were interpolated into SQL; all are whitelisted now.
- Workflow `update_field` could write any column of any table; now whitelisted
  per entity and tenant-scoped.
- XSS in filter chips, gallery captions and email templates.
- Stripe integration sent raw card numbers to Stripe; replaced by Stripe Checkout
  and the Billing Portal. Stripe webhooks verify signatures and are idempotent.
- Webhooks: SSRF protection, HMAC signatures, short timeouts.
- SMTP header injection; STARTTLS could silently fall back to plaintext.
- Login brute-force protection; password reset with hashed single-use tokens;
  login timing no longer reveals which emails exist.
- File uploads: real MIME detection, files served only through access-checked routes.
- Role management cannot be used to escalate privileges.

### Features completed
- All missing pages; Kanban deal pipeline; property gallery, map and lead matching.
- Lead scoring admin, score panel on leads, grade column and filters.
- Workflow builder for all action types, delayed and scheduled workflows, dry-run tests.
- In-app notifications; `cron.php` scheduler (delayed actions, scheduled workflows,
  webhook retries, task reminders, cleanup).
- Roles and permissions UI, team management with invitations, audit log viewer.
- Reports (funnel, pipeline, revenue, agents, sources, grades) with CSV export.
- Saved searches, bulk operations with per-record permission and tenant checks.
- Migrations for upgrading existing installs; deploy script with backup and tests.

## Known limitations / recommendations

1. **Webhook delivery is synchronous.** Each event waits for subscribed endpoints
   (5 s timeout per call, 8 s budget per event). For busy tenants, move delivery
   to a queue processed by `cron.php`, or call `fastcgi_finish_request()` first.
2. **Charts and the calendar load Chart.js / FullCalendar from a CDN.** They fall
   back to tables and a task list when the CDN is unreachable. The rendered
   charts were not visually verified during this review (no internet access in
   the review environment).
3. **PHP 7.0 compatibility** was kept in mind (no PHP 8-only syntax), but the
   code was only executed on PHP 8.3.
4. **No API rate limiting.** Consider per-key request limits.
5. **Demo data** (users with password `password`, demo API keys) is seeded by
   `database.sql`. Remove it for production installs.
6. Real email delivery needs SMTP settings; the default `log` driver only writes
   to `storage/logs/mail.log`.
