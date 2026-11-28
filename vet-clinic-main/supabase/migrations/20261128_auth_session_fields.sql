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
