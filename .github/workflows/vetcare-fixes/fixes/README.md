# VETCARE — Security Fix Kit

Ready-to-deploy fixes for the four gaps identified in the analysis of
https://vet-clinic-one-pied.vercel.app/

## Contents

| File | Fix |
|---|---|
| `vercel.json` | Security headers: CSP, X-Content-Type-Options, X-Frame-Options, HSTS, Referrer-Policy, Permissions-Policy |
| `api/auth_security.php` | Backend: login rate limiting, registration approval gating, real password reset (`forgot` / `reset`), safe hashing enforcement |
| `app.patched.js` | Frontend: working "Forgot password?" flow (replaces dead link), deep-link `#reset-token=` support, pending-approval message on signup |

## Deployment steps

### 1. Security headers
- Copy `vercel.json` to your project root (next to `index.html`) and redeploy.
- Note: CSP includes `script-src 'self' 'unsafe-inline'` because the current
  `index.html` has an inline boot script. To go fully strict, move that inline
  script into a file (e.g. `boot.js`) and remove `'unsafe-inline'` from `script-src`.
- `style-src 'unsafe-inline'` is needed because the app uses inline `style` attributes.

### 2. Backend (`api/auth_security.php`)
- Apply the schema migration (MySQL):
  ```sql
  ALTER TABLE users
    ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN reset_token VARCHAR(64) NULL,
    ADD COLUMN reset_expires DATETIME NULL;
  ```
- Copy `api/auth_security.php` into your `api/` directory and dispatch the
  `login`, `register`, `forgot`, and `reset` actions to it from your existing
  `auth.php` router (or include it after your existing action handling).
  The file reuses `$pdo` if `auth.php` already defines one; otherwise it creates
  its own connection from `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` env vars.
- Configure:
  - `APP_REGISTER_OPEN` → keep `false` so registrations require admin approval.
  - `APP_URL` → your site URL, used to build reset links.
  - `ADMIN_EMAIL` → where pending-registration notifications go.
  - `RATE_LIMIT_DIR` → writable folder (default `storage/ratelimit`).
- Enable a real mail transport (or queue) so reset emails actually reach users.
- Add an admin-facing approval step (list `approved = 0`, set to 1) — a small
  admin endpoint or a row in your existing Users page is enough.

### 3. Frontend (`app.patched.js`)
- Replace the deployed `app.js` with `app.patched.js`. Syntax-verified.
- What changed:
  - "Forgot password?" now opens a real email-token request screen instead of
    showing "contact the administrator".
  - On success it offers "Enter it here" to paste the token, and the reset
    screen sets the new password (min 8 chars, confirm check).
  - Emailed links of the form `https://…/#reset-token=<token>` deep-link
    straight to the reset screen on load.
  - Signup now detects `approved: 0` responses and tells the user their account
    is pending admin approval instead of silently failing.
- Backend endpoints called: `auth.php` actions `forgot` and `reset` (JSON POST,
  same pattern as your existing `login`/`register`).

## Still worth doing afterwards
- Confirm passwords are stored with bcrypt/argon2 (`password_hash`) server-side.
- Ensure session cookies are `HttpOnly`, `Secure`, `SameSite`.
- Add audit logging for medical-record and appointment changes.
- Keep the Vercel bot checkpoint on `/api/*` — it is doing its job.
