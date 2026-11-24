# Deploy the Veterinary Clinic System on Vercel + Supabase

This folder is the **Vercel version** of the project. The XAMPP version is separate and unchanged.
Database: Supabase (PostgreSQL). Pictures: Supabase Storage. Hosting: Vercel (PHP in a FrankenPHP Docker container).

```
Dockerfile, Caddyfile        how Vercel builds and serves the PHP app
public/                      the website (HTML/JS/CSS) and the PHP API
supabase/schema.sql          run this once in Supabase
.env.example                 the settings to enter in Vercel
```

## 1. Supabase (database + picture storage)
1. Create a project at supabase.com. Choose the region closest to your users and save the **database password**.
2. Open **SQL Editor > New query**, paste all of `supabase/schema.sql`, click **Run**.
   It creates the tables, locks them from Supabase's public API (RLS), and creates the public `pet-photos` bucket.
3. Click **Connect** (top bar) and copy the **Transaction pooler** details:
   host, port (6543), database (`postgres`), user (`postgres.<project-ref>`).
4. **Project Settings > API Keys**: copy the **Project URL** and the **secret key** (the `service_role` key on the "Legacy" tab also works; either is fine).
   This key is a master secret: only paste it into Vercel, never into code, GitHub or the browser.

## 2. GitHub
Create a new repository and upload this whole folder (keep `Dockerfile` and `Caddyfile` at the top level).
Do not commit a real `.env` file (`.gitignore` already blocks it).

## 3. Vercel
1. vercel.com > **Add New > Project** > import the repository. Vercel detects the `Dockerfile` and uses the **Container** preset.
2. Add these **Environment Variables** (see `.env.example`), then **Deploy**:

| Name | Value |
|---|---|
| `DB_HOST` | pooler host from step 1.3 |
| `DB_PORT` | `6543` |
| `DB_NAME` | `postgres` |
| `DB_USER` | `postgres.<project-ref>` |
| `DB_PASSWORD` | your database password |
| `SUPABASE_URL` | Project URL (`https://<ref>.supabase.co`) |
| `SUPABASE_SERVICE_KEY` | the service_role key |
| `SUPABASE_BUCKET` | `pet-photos` |

## 4. Check it works
Open `https://YOUR-SITE.vercel.app/api/health.php`. You should see `"ok":true` with every item `true`.
If something is `false`:

| Item | Meaning |
|---|---|
| (error "Cannot connect") | wrong `DB_*` values, or use the pooler host, not `db.<ref>.supabase.co` |
| `tables`: false | `schema.sql` was not run (or run on another project) |
| `sessions_table`: false | same: re-run `schema.sql` |
| `storage_configured`: false | `SUPABASE_URL` / `SUPABASE_SERVICE_KEY` missing in Vercel |
| `storage_bucket`: false | bucket `pet-photos` missing, or wrong service key |

After changing environment variables, **redeploy**.

## 5. First accounts
1. Open the site and **sign up**. Sign-up always creates a Pet Owner account.
2. Make yourself the administrator: Supabase > **SQL Editor**:
   ```sql
   UPDATE users SET role = 'Administrator' WHERE username = 'YOUR_USERNAME';
   ```
3. Sign in, open **Users**, add the clinic's doctor with the role **Veterinarian** (or run
   `UPDATE users SET role = 'Veterinarian' WHERE username = '...';`). That person is assigned to appointments automatically.
4. Set the clinic hours/closed days in `public/api/helpers.php` if needed, then redeploy.

## Notes
- Local test (optional): install Docker + the Vercel CLI, then `vercel dev --local`.
- Sessions are stored in the `php_sessions` table, so logins survive container restarts.
- Pet pictures are stored in Supabase Storage; the picture's public URL is saved in `pets.photo_path`.
- Supabase's free projects pause after a period of inactivity; the first visit afterwards can be slow or fail until it wakes.
- Back up regularly (Supabase > Database > Backups, or export from the SQL Editor).

## If sign-up says "Invalid server response" or "Server error"
1. Open `https://YOUR-SITE/api/auth.php?action=me`. A working server answers `{"success":false,"message":"You are not signed in."}`.
2. Open `/api/health.php` and fix whichever item is `false`.
3. In Vercel add the environment variable `APP_DEBUG` = `1`, redeploy, and try again: the message now includes the technical reason.
   Remove `APP_DEBUG` again when everything works.
4. Vercel > your project > **Logs** shows the server's own error lines.
