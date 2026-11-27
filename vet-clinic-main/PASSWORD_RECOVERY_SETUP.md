# VETCARE password recovery and security setup

This change keeps the existing PHP API, PostgreSQL database, PHP session handling, and custom users table. It does not switch authentication to Supabase Auth or change registration approval behavior.

## Required Vercel environment variables

Set these in Vercel → Project → Settings → Environment Variables for Preview and Production:

- RESEND_API_KEY: API key from Resend with permission to send email.
- MAIL_FROM: a sender address verified in Resend, e.g. VETCARE <no-reply@your-verified-domain>.
- APP_URL: canonical public app URL, e.g. https://vet-clinic-one-pied.vercel.app.

Redeploy after adding or changing environment variables. Password recovery returns a configuration error until RESEND_API_KEY and MAIL_FROM are present. Never put these values in frontend JavaScript or commit them to Git.

## Database

The connected Supabase database already has users.password_reset_token_hash, users.password_reset_expires, and the private auth_rate_limits table. The matching idempotent migration is included at supabase/migrations/20261128_password_recovery_security.sql for fresh or separately provisioned environments.

Reset tokens contain 256 bits of randomness. Only SHA-256 hashes are stored. Tokens expire after 60 minutes and are consumed by a single conditional database update. Rate limits are stored in PostgreSQL because Vercel instances do not share local files.

## Behavior

- Login: 5 attempts per 15-minute IP/username bucket.
- Password-reset requests: limited per IP and normalized email.
- Reset attempts: limited per IP.
- Forgot-password responses do not disclose whether an email is registered.
- Public registration still creates Active Pet Owner accounts.
- Existing PHP sessions and role behavior are preserved.

## Security headers

vercel.json is placed in the configured Vercel Root Directory. Its CSP permits the same-origin app, Supabase API/storage, and Google Fonts. The inline boot script in public/index.html requires 'unsafe-inline' in script-src; moving that script to a separate file is a future hardening step.
