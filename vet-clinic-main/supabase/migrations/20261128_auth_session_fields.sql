-- Required by auth_version session revocation and password reset endpoints.
-- Safe to apply to existing Supabase databases; all additions are idempotent.
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS auth_version INTEGER NOT NULL DEFAULT 1;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_token_hash TEXT;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_expires TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS users_password_reset_token_hash_idx
  ON public.users (password_reset_token_hash)
  WHERE password_reset_token_hash IS NOT NULL;
