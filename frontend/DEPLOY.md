# Deploying NutriLens to Railway

NutriLens is a **monorepo**: a Laravel API in `backend/` and a Next.js app in
`frontend/`. Neither lives at the repo root, and the root has no
`composer.json` or `package.json` of its own.

That matters because Railway's builder (Railpack) inspects the **root of the
build context**. Point a service at this repo without further configuration and
it sees only two directories and a README, which is why it reports:

```
✖ Railpack could not determine how to build the app.
```

The fix is not a build script — it is to create **two services**, each with its
**Root Directory** set to the app it should build. The two apps share no code, so
they need no shared build step (Railway calls this an *isolated* monorepo).

---

## 1. Create the database first

The API stores users, meals and photos in MySQL, and also keeps its sessions,
cache and queue there (`SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` are all
`database`). It cannot boot without one.

In the Railway project: **New → Database → MySQL**.

> Leaving `DB_*` unset is not a safe default here. `config/database.php` falls
> back to SQLite, which on Railway means a file on a container filesystem that is
> discarded on every redeploy — the app would appear to work, then silently lose
> every account.

## 2. Backend service (`backend/`)

**New → GitHub Repo → NutriLens_ClaudeAi**, then in **Settings**:

| Setting | Value |
| --- | --- |
| Service name | `backend` |
| Root Directory | `backend` |
| Watch Paths | `/backend/**` |
| Healthcheck Path | `/up` |

`Watch Paths` stops a frontend-only commit from rebuilding the API. `/up` is
Laravel's built-in health route, already registered in `bootstrap/app.php`.

No build configuration is needed. Railpack detects PHP from `composer.json`,
recognises Laravel from the `artisan` file, serves `public/` through FrankenPHP,
and on each deploy runs `composer install`, the `npm run build` in
`backend/package.json`, `php artisan migrate`, the storage symlink, and the
config/route caches.

### Backend variables

Generate the app key locally first — it encrypts sessions and signs the meal-photo
URLs, so it must be a real random key and must stay stable across deploys:

```bash
cd backend && php artisan key:generate --show
```

```bash
APP_NAME=NutriLens
APP_ENV=production
APP_KEY=base64:...            # paste the output of the command above
APP_DEBUG=false
APP_TIMEZONE=UTC

# Must be the service's own public domain. Meal photos are handed to the
# browser as *signed* URLs built from APP_URL — leave it at localhost and every
# photo 404s against the user's own machine.
APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}

# Drives config/cors.php. Without it the browser blocks every API call from the
# deployed frontend. Comma-separated if you add a custom domain later.
FRONTEND_URL=https://${{frontend.RAILWAY_PUBLIC_DOMAIN}}

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stack
LOG_STACK=single               # stdout — Railway captures it as deploy logs
LOG_LEVEL=warning

# AI_PROVIDER=fake needs no key and costs nothing; the whole photo-analysis
# flow still works end to end against generated sample data. Switch to
# anthropic once you want real analyses.
AI_PROVIDER=fake
AI_API_KEY=
AI_MODEL=

# Swagger. L5_SWAGGER_CONST_HOST cannot use ${APP_URL} here: that interpolation
# is a .env-file feature and does not apply to real environment variables.
L5_SWAGGER_CONST_HOST=https://${{RAILWAY_PUBLIC_DOMAIN}}
L5_SWAGGER_GENERATE_ALWAYS=false
```

With `L5_SWAGGER_GENERATE_ALWAYS=false` the spec is not rebuilt per request.
Generate it once at build time by adding to the service's build command, or run
`php artisan l5-swagger:generate` from the Railway shell after the first deploy.

`${{MySQL.*}}` and `${{frontend.*}}` are Railway variable references — type them
literally and Railway resolves them, so no secret is copied by hand. Match the
service names to what you actually named them.

## 3. Frontend service (`frontend/`)

Add a second service from the **same repo**:

| Setting | Value |
| --- | --- |
| Service name | `frontend` |
| Root Directory | `frontend` |
| Watch Paths | `/frontend/**` |

Railpack detects Next.js from `package.json` and runs `npm run build` then
`npm start`; `next start` binds to Railway's `$PORT` on its own.

### Frontend variables

```bash
NEXT_PUBLIC_API_URL=https://${{backend.RAILWAY_PUBLIC_DOMAIN}}/api
NEXT_PUBLIC_APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
```

Both are `NEXT_PUBLIC_*`, which Next.js **inlines at build time**. They have to
be set *before* the build that ships them — adding them to a running service does
nothing until you redeploy. `next.config.ts` also reads `NEXT_PUBLIC_API_URL` to
allow-list the API host for `next/image`, so a missing value there shows up as
meal photos failing to render rather than as a build error.

The `/api` suffix is required: `lib/env.ts` treats `apiUrl` as the full API base.

## 4. Order of the first deploy

The two services reference each other's domains, so neither has a complete
configuration until both exist:

1. Create the MySQL database.
2. Create both services with their Root Directories, and generate a public domain
   for each (**Settings → Networking → Generate Domain**).
3. Set all variables, now that both domains are known.
4. Redeploy **both**. The frontend in particular must be rebuilt after
   `NEXT_PUBLIC_API_URL` is set, for the inlining reason above.

---

## Known production gaps

Two things work locally but not on Railway's infrastructure. Neither blocks the
first deploy; both will bite later.

**Uploaded meal photos do not survive a redeploy.** `MealAnalysisController`
stores them on the `local` disk, i.e. under `backend/storage/`, and a Railway
container's filesystem is recreated on every deploy. Rows in `meal_images` will
outlive their files and `MealImageController` will start returning 404s. Fix by
either mounting a Railway **Volume** at `/app/storage`, or switching
`FILESYSTEM_DISK` to `s3` and filling in the `AWS_*` variables — the controller
reads `$mealImage->disk` per record, so it needs no code change.

**Signed photo URLs defeat Next.js image caching.** `MealResource` mints a fresh
signature on every response, so `next/image` sees a new URL each time and
re-optimises the same photo on every page load. Setting `images.unoptimized: true`
in `next.config.ts` trades optimisation for a large drop in wasted CPU; a stable
URL with the signature moved to a header would be the better long-term fix.

## Troubleshooting

**Build still says "could not determine how to build"** — the Root Directory did
not save, or it was set on the wrong service. Confirm under Settings → Source.

**Backend deploys, then 500s on every request** — almost always `APP_KEY`. Check
the deploy logs with `APP_DEBUG=true` temporarily, then set it back to `false`.

**A PHP extension is missing** — set `RAILPACK_PHP_EXTENSIONS` on the backend
service to the extra extensions you need, comma-separated.

**Migrations should not run on deploy** — set `RAILPACK_SKIP_MIGRATIONS=1`.

**Frontend loads but every API call fails with a CORS error** — `FRONTEND_URL` on
the *backend* is wrong or missing. It must be the frontend's full origin, scheme
included, with no trailing slash.

**Railway config files** — if you later add `railway.toml`, note that its path is
*not* resolved relative to the Root Directory. Give the absolute path
(`/backend/railway.toml`) in the service's config-file setting.
