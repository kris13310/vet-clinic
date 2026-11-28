-- Session revocation and password recovery fields for existing Supabase databases.
-- Safe to run repeatedly.
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS auth_version INTEGER NOT NULL DEFAULT 1;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_token_hash TEXT;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_expires TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS users_password_reset_token_hash_idx
  ON public.users (password_reset_token_hash)
  WHERE password_reset_token_hash IS NOT NULL;


-- Rate-limit storage used by login, registration, and password recovery.
-- Include this here so existing deployments receive it without rerunning the full schema.
CREATE TABLE IF NOT EXISTS public.auth_rate_limits (
  bucket_key TEXT PRIMARY KEY,
  window_started_at TIMESTAMPTZ NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS auth_rate_limits_window_started_idx ON public.auth_rate_limits (window_started_at);
ALTER TABLE public.auth_rate_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.auth_rate_limits FROM anon, authenticated;
