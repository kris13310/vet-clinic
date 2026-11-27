# VETCARE security fix-kit integration audit

This branch adapts the useful parts of the archived `vet-care.zip` fix kit to the current Supabase/PostgreSQL application. It intentionally does not copy the old MySQL implementation or replace current frontend files wholesale.

## Applied in this branch

- Public Pet Owner registration now creates an `Inactive` account and does not create an authenticated session.
- The existing sign-in endpoint already rejects `Inactive` accounts. Its message now explains that the account may be awaiting administrator approval.
- The existing Administrator Users page/API already lets an administrator edit a Pet Owner account and change its status to `Active`. No new approval column or schema migration is required.
- Registration attempts are rate-limited per client IP using the existing PostgreSQL `auth_rate_limits` table.
- Login attempts are limited both per username hash and per IP+username hash. This supplements, rather than replaces, the existing PostgreSQL rate limiter.
- The existing frontend now displays the pending-approval response after signup and returns to the login form rather than treating the pending account as signed in.

## Already present on main; retained unchanged

- Password recovery uses random 256-bit tokens, stores only SHA-256 token hashes, expires tokens after 60 minutes, and consumes them in one database update.
- Passwords are stored with PHP `password_hash()` and checked with `password_verify()`.
- Login and password-reset rate limiting already use PostgreSQL.
- The existing Vercel Content Security Policy already permits the required Supabase HTTPS/WSS endpoints and Supabase-hosted images, as well as the app's Google Fonts resources. The archived kit's narrower policy would break these resources, so it was not copied.
- Appointment completion is already handled transactionally with medical report creation and owner notification in `api/medical_reports.php`. The archived JavaScript workaround was not copied because it would duplicate completion behavior.
- The current calendar selection behavior was retained; the archived JavaScript does not preserve the same selected-date initialization.
- Existing `style.css` and `owner.css` were retained. The two promised patched CSS files were not present in the archive/branch, so no speculative CSS replacement was made.

## Deployment / operational requirements

- No Supabase schema change is needed for these changes; the existing `auth_rate_limits` table is used.
- The administrator must activate newly registered accounts from the existing Users page by editing the account status to Active.
- Password recovery email delivery still requires Vercel environment variables `RESEND_API_KEY`, `MAIL_FROM` (a verified Resend sender), and `APP_URL`.
- This PR does not merge or deploy to production. GitHub Actions syntax checks should run on the pull request; functional sign-up/approval and password-reset email tests still need to be performed against a preview deployment with configured email variables.
