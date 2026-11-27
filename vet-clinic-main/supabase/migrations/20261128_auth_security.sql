-- Authentication security additions for the existing Supabase PostgreSQL database.
-- Safe to run more than once in Supabase SQL Editor.

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS password_reset_token_hash CHAR(64),
  ADD COLUMN IF NOT EXISTS password_reset_expires TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS public.auth_rate_limits (
  bucket_key TEXT PRIMARY KEY,
  window_started_at TIMESTAMPTZ NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0
);

ALTER TABLE public.auth_rate_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.auth_rate_limits FROM anon, authenticated;
