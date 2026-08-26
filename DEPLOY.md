# Deploying NutriLens to Railway

NutriLens is a **monorepo**: a Laravel 12 API in `backend/` and a Next.js 16 app
in `frontend/`. Neither lives at the repo root, and the root has no
`composer.json` or `package.json` of its own.

That matters because Railway's builder inspects the **root of the build
context**. Point a service at this repo without further configuration and it
sees two directories and a README, which is why it reports:

```
✖ Railpack could not determine how to build the app.
```

The fix is not a build script — it is to create **two services**, each with its
**Root Directory** set to the app it should build. The two apps share no code,
so they need no shared build step (Railway calls this an *isolated* monorepo).

You will end up with four things in one Railway project:

| Component | What it is |
| --- | --- |
| `MySQL` | Railway's managed database |
| `backend` | The Laravel API, built from `backend/Dockerfile` |
| `frontend` | The Next.js app, built by Railpack |
| A volume | Persistent disk for uploaded meal photos, mounted into `backend` |

---

## 0. Before you start

Generate the application key. It encrypts sessions and **signs the meal-photo
URLs**, so it has to be a real random key and it has to stay the same across
deploys — regenerating it invalidates every existing photo link:

```bash
cd backend
php artisan key:generate --show
```

Copy the `base64:…` string it prints. You will paste it into Railway in step 4.
Do not commit it.

---

## 1. Create the project and the database

The API stores users, meals and photos in MySQL, and also keeps its sessions,
cache and queue there (`SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` are
all `database`). It cannot boot without one, so create it first.

1. **New Project → Deploy from GitHub repo → `NutriLens_ClaudeAi`**.
   Railway creates one service and immediately tries to build it. Let that first
   build fail — step 2 tells it what to build.
2. In the project: **New → Database → Add MySQL**.

> Leaving `DB_*` unset is not a safe default here. `config/database.php` falls
> back to SQLite, which on Railway means a file on a container filesystem that is
> discarded on every redeploy — the app would appear to work, then silently lose
> every account.

## 2. Backend service (`backend/`)

Take the service Railway created in step 1, rename it `backend`, and open
**Settings**:

| Setting | Value |
| --- | --- |
| Service name | `backend` |
| Root Directory | `backend` |
| Watch Paths | `/backend/**` |
| Healthcheck Path | `/up` |
| Builder | *(leave on default — see below)* |

`Watch Paths` stops a frontend-only commit from rebuilding the API. `/up` is
Laravel's built-in health route, registered in `bootstrap/app.php`.

**You do not need to choose a builder.** Railway detects `backend/Dockerfile`
and uses it automatically, which is the point: the PHP version, the extension
set and the web server are pinned in the repo rather than inferred. Two of those
extensions are load-bearing — `MealImagePreparer` downscales every uploaded
photo with **GD** and reads its orientation with **EXIF**. They are declared
twice on purpose, in `composer.json` and in the Dockerfile, so neither a rebuild
nor a change of builder can quietly drop them.

The container runs nginx in front of php-fpm. On boot, `docker/entrypoint.sh`
rebuilds the storage tree, caches config/routes/views, runs `php artisan migrate
--force` (retrying while the database wakes up), and regenerates the Swagger
spec.

## 3. Add the volume for meal photos

Uploads go to the `local` disk, which is `backend/storage/app/private`. A
container filesystem is recreated on every deploy, so without a volume the rows
in `meal_images` outlive their files and photos start returning 404.

On the **backend** service: **Settings → Volumes → New Volume**, mount path:

```
/app/storage
```

The volume arrives empty and hides the directory tree baked into the image,
which would normally break Laravel's first write. `docker/entrypoint.sh`
recreates `framework/cache`, `framework/sessions`, `framework/views`, `logs` and
`app/{public,private}` on every boot, so this works from the first deploy.

## 4. Backend variables

**Variables → Raw Editor**, then paste the block below. Replace the `APP_KEY`
line with the key from step 0:

```bash
APP_NAME=NutriLens
APP_ENV=production
APP_KEY=base64:PASTE_YOUR_GENERATED_KEY_HERE
APP_DEBUG=false
APP_TIMEZONE=UTC

# Must be this service's own public domain. Meal photos are handed to the
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
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

# `single` writes to storage/logs/laravel.log — a file inside the container,
# which is not where you will look for it. `stderr` is what Railway captures
# and shows in the deploy log.
LOG_CHANNEL=stderr
LOG_LEVEL=warning

# AI_PROVIDER=fake needs no key and costs nothing; photo analysis, weekly
# insights and the coach all still work end to end against generated sample
# data. Switch to `anthropic` and set AI_API_KEY when you want real analyses —
# that is a backend-only change, the frontend needs no redeploy.
AI_PROVIDER=fake
AI_API_KEY=
AI_MODEL=

# Swagger. L5_SWAGGER_CONST_HOST cannot use ${APP_URL} here: that interpolation
# is a .env-file feature and does not apply to real environment variables.
L5_SWAGGER_CONST_HOST=https://${{RAILWAY_PUBLIC_DOMAIN}}
L5_SWAGGER_GENERATE_ALWAYS=false
```

`${{MySQL.*}}` and `${{frontend.*}}` are Railway variable references — type them
literally and Railway resolves them, so no secret is copied by hand. Match the
service names to whatever you actually named them.

Two variables from `.env.example` are deliberately **not** in that block:

- **`SESSION_DOMAIN`** — it is `localhost` locally. Carried into production it
  scopes the session cookie to a domain the browser will never match. Leave it
  unset and Laravel uses the request host.
- **`APP_DEBUG=true`** — it renders environment variables, `DB_PASSWORD`
  included, onto any error page.

## 5. Frontend service (`frontend/`)

Add a second service from the **same repo**: **New → GitHub Repo →
`NutriLens_ClaudeAi`**, then in **Settings**:

| Setting | Value |
| --- | --- |
| Service name | `frontend` |
| Root Directory | `frontend` |
| Watch Paths | `/frontend/**` |

Railpack detects Next.js from `package.json`, runs `npm ci` and `npm run build`,
then `npm start`. `next start` binds to Railway's `$PORT` on its own, so there
is nothing further to configure.

### Frontend variables

```bash
NEXT_PUBLIC_API_URL=https://${{backend.RAILWAY_PUBLIC_DOMAIN}}/api
NEXT_PUBLIC_APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
```

Both are `NEXT_PUBLIC_*`, which Next.js **inlines at build time**. They have to
be set *before* the build that ships them — adding them to a running service
does nothing until you redeploy. `next.config.ts` also reads
`NEXT_PUBLIC_API_URL` to allow-list the API host for `next/image`, so a missing
value shows up as meal photos failing to render rather than as a build error.

The `/api` suffix is required: `lib/env.ts` treats `apiUrl` as the full API base.

## 6. Order of the first deploy

The two services reference each other's domains, so neither has a complete
configuration until both exist. Do it in this order:

1. Create the MySQL database.
2. Create both services and set their **Root Directory**.
3. Generate a public domain for each: **Settings → Networking → Generate
   Domain**. Nothing resolves until this is done.
4. Add the volume to `backend`.
5. Paste both variable blocks, now that both domains are known.
6. **Redeploy both.** The frontend in particular must be rebuilt after
   `NEXT_PUBLIC_API_URL` is set, for the inlining reason above.

## 7. Verify it worked

```bash
# 1. Laravel is up                        (expects: 200)
curl -i https://<backend-domain>/up

# 2. The API answers JSON, not HTML       (expects: 401 and a JSON body)
curl -i https://<backend-domain>/api/user

# 3. CORS allows the real frontend origin
#    (expects: access-control-allow-origin matching your frontend domain)
curl -I -H "Origin: https://<frontend-domain>" https://<backend-domain>/api/user

# 4. Swagger renders
#    https://<backend-domain>/api/documentation
```

Then, in the browser: register an account, complete onboarding, and log a meal
with a photo. That last step exercises GD, EXIF, the volume and the signed-URL
path all at once — if the photo still renders after a page refresh, everything
in this document is working.

---

## Troubleshooting

**"Railpack could not determine how to build the app"** — the Root Directory did
not save, or it was set on the wrong service. Confirm under **Settings →
Source**.

**Backend deploys, then 500s on every request** — almost always `APP_KEY`. Set
`APP_DEBUG=true` temporarily, read the deploy log, then set it back to `false`.

**Deploy log shows `database not ready, retry n/6`** — the `DB_*` references are
wrong, or the MySQL service is named something other than `MySQL`. The retry
loop gives the database about 30 seconds; past that the service exits rather
than serving a broken app.

**Frontend loads but every API call fails with a CORS error** — `FRONTEND_URL`
on the *backend* is wrong or missing. It must be the frontend's full origin,
scheme included, no trailing slash.

**Frontend builds but still talks to `localhost:8000`** — `NEXT_PUBLIC_API_URL`
was added after the build. Redeploy the frontend; the value is compiled in, not
read at runtime.

**Meal photos 404 after a redeploy** — the volume is missing, or mounted
somewhere other than `/app/storage`.

**Migrations should not run on deploy** — set `RUN_MIGRATIONS=false` on the
backend service.

**A PHP extension is missing** — add it to the `install-php-extensions` line in
`backend/Dockerfile` and to `require` in `backend/composer.json`, then push.
Unlike a builder-detected app, nothing here is guessed at deploy time.

**Railway config files** — if you later add `railway.toml`, note that its path is
*not* resolved relative to the Root Directory. Give the absolute path
(`/backend/railway.toml`) in the service's config-file setting.

---

## Known production gaps

Neither of these blocks the first deploy.

**Signed photo URLs defeat Next.js image caching.** `MealResource` mints a fresh
signature on every response, so `next/image` sees a new URL each time and
re-optimises the same photo on every page load. Setting `images.unoptimized:
true` in `next.config.ts` trades optimisation for a large drop in wasted CPU; a
stable URL with the signature moved to a header would be the better long-term
fix.

**The volume does not scale past one container.** A Railway volume attaches to a
single service instance. If the backend is ever scaled to multiple replicas,
switch `FILESYSTEM_DISK` to `s3` and fill in the `AWS_*` variables —
`MealImageController` reads `$mealImage->disk` per record, so that needs no code
change.
