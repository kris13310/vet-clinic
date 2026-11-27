# Authentication security setup

This branch adapts the supplied VETCARE security kit to the existing Supabase PostgreSQL application. It does not replace the existing database connection or session implementation.

## Before password recovery can send email

Add these Vercel environment variables to the environments where the feature will be used:

- `RESEND_API_KEY`: API key from Resend.
- `MAIL_FROM`: verified sender, for example `Veterinary Clinic <no-reply@your-verified-domain>`.
- `APP_URL`: canonical clinic website URL, currently `https://vet-clinic-one-pied.vercel.app`.

Email delivery is intentionally not faked. If these settings are absent, the API reports that recovery email is not configured.

## Database migration

Run `supabase/migrations/20261128_auth_security.sql` in Supabase SQL Editor. The migration adds hashed reset-token fields and a private rate-limit table. The matching idempotent definitions are also included in `supabase/schema.sql` for new installations.

## Included changes

- Login and registration request throttling backed by PostgreSQL, so limits do not disappear when Vercel instances change.
- Password reset tokens are random, stored as SHA-256 hashes, expire after one hour, and are consumed atomically when used.
- Password reset email delivery uses the Resend API and requires an explicitly configured sender.
- Login UI supports requesting a reset email, manually entering a token, and following a reset link.
- Vercel security headers include a CSP that permits the existing Google Fonts stylesheet and the project's Supabase-hosted pet images.

## Not included

- Registration approval gating. The supplied kit assumes a different MySQL users table and has no matching approval workflow in this app. Applying that behavior would lock out newly registered pet owners until an admin approval feature is built.
- Audit logging for medical-record and appointment changes.
- Removing `unsafe-inline` from CSP. The current app contains inline scripts and style attributes; this remains a follow-up hardening task.
