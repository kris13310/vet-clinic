-- Preserve veterinary medical history when a pet record is removed.
-- Existing installations may still have the original CASCADE foreign key.
-- Safe to rerun: the named constraint is replaced with the intended RESTRICT rule.
ALTER TABLE public.medical_reports
  DROP CONSTRAINT IF EXISTS medical_reports_pet_id_fkey;

ALTER TABLE public.medical_reports
  ADD CONSTRAINT medical_reports_pet_id_fkey
  FOREIGN KEY (pet_id)
  REFERENCES public.pets(id)
  ON DELETE RESTRICT;
