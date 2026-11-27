-- Password recovery and API rate limiting for the custom PHP/PostgreSQL auth flow.
-- Safe to run repeatedly. These objects are already present in the connected database.
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_token_hash CHAR(64),
  ADD COLUMN IF NOT EXISTS password_reset_expires TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_users_password_reset_token_hash
  ON public.users (password_reset_token_hash)
  WHERE password_reset_token_hash IS NOT NULL;

CREATE TABLE IF NOT EXISTS public.auth_rate_limits (
  bucket_key TEXT PRIMARY KEY,
  window_started_at TIMESTAMPTZ NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0)
);
ALTER TABLE public.auth_rate_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.auth_rate_limits FROM anon, authenticated;
