# NutriLens

**Snap your food. See your nutrition.**

An AI-powered macronutrient tracking application. Photograph a meal, let a vision
model identify every item and estimate portions and macros, correct anything it
got wrong, and track your nutrition over days and weeks. A versioned public API
lets other products do the same thing programmatically.

Two applications, REST only, no shared runtime:

```
Next.js frontend (:3000)
        │  REST + JSON / multipart, Bearer token, CORS
        ▼
Laravel REST API (:8000)  ──────►  AI provider (Anthropic / OpenAI)
        │  Eloquent                 key read from backend/.env only
        ▼
MySQL — nutrilens_db
```

---

## 1. Features

### Tracking

| Feature | What it does |
|---|---|
| **AI photo analysis** | Upload a meal photo; get every food, its portion, and calories/protein/carbs/fat with a calibrated confidence per item. |
| **Review and correct** | Every AI number is editable. Changing a portion rescales macros from the AI's original estimate rather than compounding rounding. Typing over a macro *locks* it so later portion changes leave it alone. |
| **Manual entry** | Know the numbers? Type them. No photo required. |
| **Today dashboard** | Calorie ring, macro bars, current streak, 7-day trend, recent meals, latest weekly insight, and quick-add. A distinct first-run experience for new accounts. |
| **History** | Browse one day at a time. Step by day, jump to the nearest day that actually has meals, or pick a date. View, edit or delete any meal. |
| **Analytics** | Calories, protein, carbs and fat over 7 / 30 / 90 / 365 days. Averages, totals, meal count, and "days close to your target". Long ranges bucket by week. A table view of the same numbers. |
| **Daily streaks** | Current and longest streak plus a 14-day activity strip. A day counts once, however many meals are on it. |
| **Weekly AI insights** | A short written read on your week, generated from your own aggregates only, compared against the previous week when there is enough data. |
| **NutriLens AI Coach** | A conversation that answers from your own logged data. Ask "what should I eat for dinner?" and it replies against your *actual* remaining calories and macros. Today's progress, quick actions, chat history in MySQL, and per-user rate limits. |
| **NutriLens Tip** | A one-line read on how a saved meal sits against the rest of your day, shown the moment a meal is saved and on the meal detail sheet. Computed, not generated — no AI call, no cost, no latency. |
| **Nutrition goals** | Daily calorie and macro targets, with full goal history. |
| **Goal calculator** | Optional. Estimates targets from age, height, weight, activity level and goal using Mifflin-St Jeor. Explains why it asks for biological sex, and works without it. |
| **Settings** | Profile, appearance (light / dark / system), nutrition goals, developer keys, sign out. |

### Partner API

| Feature | What it does |
|---|---|
| **`POST /api/v1/nutrition/analyze`** | Multipart image upload → nutrition analysis. |
| **`POST /api/v1/nutrition/estimate`** | Structured foods and portions → nutrition estimate. |
| **`GET /api/v1/ping`** | Verify a key and read the limits. Costs nothing. |
| **API key management** | Create, name, copy-once, see created / last-used, revoke. Only a SHA-256 digest is stored. |
| **Swagger UI** | Interactive documentation at `/api/documentation`. Paste a key, send a request, get a real response. |
| **Consistent envelopes** | `{success, data}` / `{success, error:{code, message, details}}` on every response, including framework failures. |
| **Per-key rate limits** | Buckets on the API key, not the IP. |

### Design and honesty

Values are **estimates**, and the product says so wherever they appear:
confidence bands per item, a disclaimer on every AI meal, a disclaimer field in
every partner response, and a transparent, stated rule behind "days close to your
target". Weekly insights are validated so that **every number in the generated
prose must be traceable to the user's own aggregates** — an insight that invents a
figure is discarded rather than shown.

The AI Coach follows the same rule from the other direction: **every figure about
the user is computed in PHP before the model sees it** — remaining macros,
percentages of target, averages, the largest recent meal — and the prompt tells
the model to quote those rather than derive them. General food knowledge is
allowed, but only labelled as an estimate. If the coach was not given something,
it says so instead of guessing.

---

## 2. Tech stack

### Backend

| Package | Version |
|---|---|
| Laravel Framework | 12.67 |
| PHP | 8.2 (XAMPP) |
| Laravel Sanctum | 4.3 |
| anthropic-ai/sdk | 0.43 |
| darkaonline/l5-swagger | 11.1 |
| zircote/swagger-php | 6.7 |
| MySQL | via XAMPP |

Image handling uses PHP's bundled **GD** — no extra dependency. Swagger UI ships
as a Composer package and is served locally, so the docs work offline.

### Frontend

| Package | Version |
|---|---|
| Next.js (App Router) | 16.3 |
| React / React DOM | 19.2 |
| TypeScript | 5.x (strict) |
| Tailwind CSS | 4.x |
| shadcn/ui (`base-nova`, Base UI) | 4.19 |
| Recharts | 3.10 |
| Zod / React Hook Form | 4.4 / 7.86 |
| next-themes / Sonner / date-fns / Lucide | 0.4 / 2.0 / 4.4 / 1.34 |

---

## 3. Architecture

### Frontend

```
frontend/
├── app/
│   ├── page.tsx                     Landing page
│   ├── (auth)/{login,register,forgot-password}/
│   ├── onboarding/
│   └── (app)/                       Authenticated shell: sidebar + bottom nav
│       ├── today/                   Dashboard
│       ├── add-meal/                Capture → AI → review → save
│       ├── meals/[id]/edit/
│       ├── coach/                   AI Coach — chat over your own data
│       ├── history/                 Day-by-day browsing
│       ├── analytics/               Charts + summary + table
│       ├── insights/                Weekly AI summaries
│       ├── goals/                   Targets + calculator
│       ├── developer/               API key management
│       └── settings/
├── components/
│   ├── ui/                          shadcn/ui primitives
│   ├── charts/                      Recharts wrappers, tooltip, sparkline
│   ├── coach/                       Progress strip, quick actions, thread,
│   │                                composer, conversation list, thinking state
│   ├── dashboard/  history/  analytics/  insights/  developer/
│   ├── add-meal/  meals/  goals/  settings/  layout/  marketing/  auth/
├── lib/
│   ├── api-client.ts                The one place that calls fetch
│   ├── meal-draft.ts                Portion scaling + macro locks
│   ├── dates.ts                     Calendar-date helpers (local, not UTC)
│   ├── nutrition.ts                 Macro palette + formatting
│   ├── coach.ts                     Quick actions, thinking phrases, text blocks
│   └── env.ts  confidence.ts  validations.ts  auth-storage.ts  navigation.ts
├── services/                        One typed module per API area
├── hooks/  types/
└── proxy.ts                         Server-side route protection
```

**Conventions worth knowing:**

- Feature code never calls `fetch`. It goes through `services/`, which is built on
  `lib/api-client.ts`.
- `lib/dates.ts` parses `YYYY-MM-DD` as **local** midnight. `new Date("2026-08-25")`
  is UTC and renders as the 24th in the Americas; every date in this app avoids
  that path.
- Every macro has one fixed colour everywhere — rings, bars, charts, dots — and is
  always paired with a text label, so identity never rests on colour alone.
- Charts are one series each. Four macros means four charts, never four lines on
  one axis.

### Backend

```
backend/app/
├── Enums/                  ActivityLevel BiologicalSex ChatRole GoalSource GoalType
│                           MealType MealSource MealStatus PortionUnit AnalysisStatus
├── Http/
│   ├── Controllers/Api/    First-party: Auth Dashboard Meal MealAnalysis MealImage
│   │   │                   NutritionGoal GoalCalculator History Analytics Streak
│   │   │                   WeeklyInsight AiCoach ApiKey Onboarding User
│   │   └── V1/             Partner: PartnerNutrition PartnerStatus
│   ├── Middleware/         AuthenticateApiKey
│   ├── Requests/           One Form Request per write, per area
│   └── Resources/          Meal MealItem NutritionGoal User WeeklyInsight ApiKey
│                           AiConversation AiChatMessage
├── Models/                 User Meal MealItem MealImage NutritionGoal
│                           WeeklyInsight ApiKey AiConversation AiChatMessage
├── OpenApi/                ApiDocumentation — spec root + shared schemas
├── Policies/               MealPolicy NutritionGoalPolicy AiConversationPolicy
├── Providers/              AppServiceProvider (AI drivers) RateLimitServiceProvider
├── Services/
│   ├── AI/
│   │   ├── Contracts/      MealVisionAnalyzer NutritionInsightGenerator
│   │   │                   FoodNutritionEstimator NutritionCoach
│   │   ├── Providers/      {Anthropic,OpenAi,Fake} × the four contracts
│   │   ├── MealAnalysisService  + MealAnalysisPrompt  + MealImagePreparer
│   │   ├── WeeklyInsightService + WeeklyInsightPrompt
│   │   ├── FoodEstimationService + FoodEstimationPrompt
│   │   ├── CoachService + CoachContextService + CoachPrompt
│   │   ├── MealTipService  (rule-based; makes no AI call)
│   │   ├── Data/           AnalyzedMeal AnalyzedFoodItem PreparedImage
│   │   │                   FoodQuery WeeklyNutritionSummary GeneratedInsight
│   │   │                   CoachContext
│   │   └── Exceptions/     AiException hierarchy (status + user message)
│   ├── Analytics/          DailyNutritionAggregator AnalyticsService StreakService
│   ├── Goals/              GoalCalculatorService GoalEstimate
│   ├── MealService  NutritionGoalService  ApiKeyService
└── Support/                PartnerApiResponse PartnerExceptionRenderer
```

**The AI layer has four capabilities and three drivers each.** One `AI_PROVIDER`
setting selects all four, so you can never end up with a real vision model and a
fake nutrition table. Adding a provider means writing four classes and adding
four lines to `AppServiceProvider::DRIVERS` — the prompts, JSON schemas and
response validation are shared, so every provider is held to the same contract.

| Contract | Job |
|---|---|
| `MealVisionAnalyzer` | Meal photo → items, portions and macros |
| `NutritionInsightGenerator` | A week of aggregates → a short written summary |
| `FoodNutritionEstimator` | Named foods and portions → macros (partner API) |
| `NutritionCoach` | A question + the user's own live figures → an answer |

**Every AI response is re-validated server-side.** Schema, ranges, item caps, unit
normalisation. For weekly insights there is an additional check that every number
in the prose traces back to the supplied aggregates. Coach replies are checked for
shape and for clinical overreach, and markdown is stripped before storage. A model
that ignores the contract produces a 502, not a corrupt row.

**The coach's context is a privacy boundary, not just a payload.**
`CoachContextService` reads the caller's own rows and hands the model a
`CoachContext` carrying nutrition figures, meal names and dates — and nothing
else. No name, no email, no password hash, no tokens, no database ids, no photos,
no body metrics. It reuses `AnalyticsService` and `StreakService` rather than
recomputing them, so the coach cannot quote a number that disagrees with the
Analytics screen.

---

## 4. Database

Database name: **`nutrilens_db`** — it already exists; do not recreate it.

18 migrations, all applied.

| Table | Purpose |
|---|---|
| `users` | Accounts, plus `avatar_path`, `timezone`, `onboarded_at`, and optional calculator metrics (`age`, `height_cm`, `weight_kg`, `activity_level`, `biological_sex`). |
| `nutrition_goals` | Goal type + daily targets, `source`, `estimated_maintenance_calories`. Keeps history: one row per goal, exactly one `is_active`. |
| `meals` | A logged meal. Denormalised macro totals + `consumed_on` (the user's local date) for fast daily lookups. AI provenance columns. Soft-deletes. |
| `meal_items` | One food per row: portion, macros, confidence, the AI baseline used for portion scaling, and `locked_macros`. |
| `meal_images` | Uploaded photos: disk/path, analysis status, raw model payload. Private disk. |
| `api_keys` | Partner keys: name, `key_prefix` (shown), `key_hash` (SHA-256, unique), abilities, `last_used_at`, `revoked_at`. |
| `weekly_insights` | AI weekly summaries + the aggregates they were written from + `data_hash` for reuse. Unique per (user, week). |
| `ai_conversations` | One AI Coach thread per row: `title` (derived from its first question), `last_message_at` and `message_count` denormalised so the chat list renders without touching the messages table. |
| `ai_chat_messages` | One turn per row: `role` (`user` / `assistant`), `content`, the assistant's `suggestions`, and the `ai_provider` / `ai_model` that produced it. Ownership lives only on the conversation — there is no `user_id` here to drift out of step. |
| `personal_access_tokens` | Sanctum tokens. |
| `password_reset_tokens`, `sessions` | Laravel defaults. |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Cache + queue (both `database`). |

Every user-owned table has a `user_id` foreign key with `ON DELETE CASCADE`.

---

## 5. Environment variables

### `backend/.env`

| Variable | Value | Notes |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | Also the Swagger server URL. |
| `FRONTEND_URL` | `http://localhost:3000` | **Drives CORS.** Comma-separate for multiple origins. |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` | `nutrilens_db` | Already exists — do not change. |
| `DB_USERNAME` | `root` | XAMPP default. Change if yours differs. |
| `DB_PASSWORD` | *(empty)* | XAMPP default. Change if yours differs. |
| `SANCTUM_TOKEN_EXPIRATION` | *(empty)* | Empty = non-expiring tokens. |
| **`AI_PROVIDER`** | **`fake`** | `fake` / `anthropic` / `openai`. See §8. |
| **`AI_API_KEY`** | *(empty)* | Your provider key. Never sent to the frontend. |
| `AI_MODEL` | *(empty)* | Blank = provider default (`claude-opus-5` / `gpt-4o`). |
| `AI_TIMEOUT` | `90` | Seconds to wait for the model. |
| `AI_IMAGE_MAX_EDGE` | `1568` | Photos downscaled to this long edge before upload. |
| `AI_MAX_ITEMS` | `12` | Hard cap on detected items. |
| `AI_FAKE_DELAY_MS` | `1400` | Simulated latency for the `fake` driver. |
| `AI_INSIGHTS_MODEL` | *(empty)* | Blank = same as `AI_MODEL`. |
| `AI_INSIGHTS_MAX_TOKENS` | `2000` | |
| `AI_INSIGHTS_EFFORT` | `low` | Anthropic only: `low`…`max`. |
| `AI_ESTIMATION_MODEL` | *(empty)* | Blank = same as `AI_MODEL`. |
| `AI_ESTIMATION_MAX_TOKENS` | `4000` | |
| `AI_ESTIMATION_EFFORT` | `low` | Anthropic only. |
| `AI_ESTIMATION_MAX_ITEMS` | `20` | Max foods in one partner estimate request. |
| `AI_COACH_MODEL` | *(empty)* | Blank = same as `AI_MODEL`. |
| `AI_COACH_MAX_TOKENS` | `1200` | A coach reply is two or three short paragraphs. |
| `AI_COACH_EFFORT` | `low` | Anthropic only. |
| `AI_COACH_TIMEOUT` | `45` | Seconds. Deliberately below `AI_TIMEOUT`: someone waiting on a chat reply gives up long before 90s, so failing fast and offering a retry is better. |
| `L5_SWAGGER_GENERATE_ALWAYS` | `true` | Regenerates the spec per page load. Set `false` in production. |
| `L5_SWAGGER_UI_PERSIST_AUTHORIZATION` | `true` | Keeps a pasted key across Swagger reloads. |
| `L5_SWAGGER_CONST_HOST` | `${APP_URL}` | The server URL shown in the spec. |

### `frontend/.env.local`

| Variable | Value | Notes |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | `http://localhost:8000/api` | The **only** place the backend URL is defined. |
| `NEXT_PUBLIC_APP_URL` | `http://localhost:3000` | Metadata / canonical links. |

There is deliberately **no** `NEXT_PUBLIC_AI_*` variable and no API-docs
variable. The frontend never talks to an AI provider, and the Swagger URL is
derived from `NEXT_PUBLIC_API_URL` so the two can never drift apart.

---

## 6. Installation

Requires PHP 8.2+, Composer, Node 20+, and a running MySQL with `nutrilens_db`.

```bash
# Backend
cd backend
composer install
cp .env.example .env          # then set DB_USERNAME / DB_PASSWORD if not XAMPP defaults
php artisan key:generate
php artisan migrate
php artisan l5-swagger:generate

# Frontend
cd ../frontend
npm install
cp .env.example .env.local
```

`php artisan storage:link` is **not** needed — meal photos are served through a
signed API route, never from `public/`.

---

## 7. Running

Start **Laravel first** — the frontend needs the API to sign in.

```bash
# Terminal 1 — Laravel API on :8000
cd backend
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 — Next.js on :3000
cd frontend
npm run dev
```

### Useful commands

```bash
# Backend
php artisan migrate                # apply migrations (safe to re-run)
php artisan migrate:status
php artisan test                   # 196 tests, in-memory SQLite — never touches nutrilens_db
php artisan route:list --path=api
php artisan l5-swagger:generate    # regenerate the OpenAPI spec
php artisan config:clear           # after any .env change
php artisan cache:clear            # also resets rate-limit counters

# Frontend
npm run dev
npm run build                      # production build + full type check
npm run lint
```

### URLs

| Surface | URL |
|---|---|
| Landing page | http://localhost:3000 |
| Sign up / Sign in | /register · /login |
| Today dashboard | /today |
| Add a meal | /add-meal (`?mode=manual` opens manual entry) |
| History | /history |
| Analytics | /analytics |
| Insights | /insights |
| Goals | /goals |
| **API keys** | **/developer** |
| Settings | /settings |
| API root / health | http://localhost:8000/api/health |
| **Swagger UI** | **http://localhost:8000/api/documentation** |
| OpenAPI spec (JSON) | http://localhost:8000/docs |

---

## 8. Configuring the AI provider

NutriLens ships with **`AI_PROVIDER=fake`**, which needs no key, makes no network
call and costs nothing. It is not a stub returning canned JSON:

- Photo analysis returns realistic multi-item analyses derived from the image
  bytes, so the same photo always gives the same result.
- Structured estimation uses a real per-100g nutrition table for ~70 common
  foods, scaled to the requested portion. Send 150 g of chicken breast and you
  get the nutrition of 150 g of chicken breast. Unrecognised foods fall back to a
  generic average **and say so, with a low confidence**.
- Weekly insights are written from your actual aggregates, so every number in the
  text is real.
- The AI Coach classifies your question — what to eat, protein, today's balance,
  the week, your streak, your biggest meal — and answers it from your real
  figures. Ask it for dinner with 580 kcal and 68 g of protein left and that is
  what it talks about. Food quantities it cannot know are labelled as estimates,
  and it says once per conversation that it is the offline coach rather than a
  model, which the UI also badges.

The whole product — including the partner API and Swagger — is demonstrable this
way. To use a real model, set two values in `backend/.env`:

**Anthropic** (recommended; default model `claude-opus-5`):

```dotenv
AI_PROVIDER=anthropic
AI_API_KEY=sk-ant-...
```

**OpenAI**, or any OpenAI-compatible gateway:

```dotenv
AI_PROVIDER=openai
AI_API_KEY=sk-...
# AI_MODEL=gpt-4o
# AI_BASE_URL=https://your-gateway/v1
```

Then `php artisan config:clear`.

**If the key is missing or wrong, nothing crashes.** The app returns a clear
503 (`AI_NOT_CONFIGURED` on the partner API), the UI offers manual entry
instead, and an uploaded photo is preserved so the user does not lose it.

---

## 9. API authentication

Two audiences, two schemes, both using the same header.

### First-party (the Next.js app) — Sanctum personal access tokens

1. `POST /api/register` or `/api/login` returns `{ user, token }`.
2. The token is stored in a `nutrilens_token` cookie.
3. Every request sends `Authorization: Bearer <token>`.
4. `proxy.ts` gates protected paths on the server before render; `RequireAuth`
   re-checks on the client; Laravel is the final authority and returns 401.
5. `POST /api/logout` deletes only the token used for that request, so other
   devices stay signed in.

### Partner API — hashed API keys

1. Create a key at **/developer**. It is shown once.
2. Send it on every `/api/v1/*` request:

```
Authorization: Bearer nl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

`X-API-Key: <key>` is also accepted for clients that reserve the Authorization
header.

**Key storage.** A key is `nl_live_` plus 40 characters from a 32-symbol
alphabet — 200 bits of CSPRNG output. Only `hash('sha256', $key)` is stored,
behind a unique index, so a database dump does not yield usable keys. SHA-256
rather than bcrypt is deliberate: the secret is high-entropy so a slow hash buys
nothing, and a salted hash could not be looked up without scanning every row.
`key_prefix` keeps the first 14 characters in clear purely so you can tell two
keys apart in the UI.

The two schemes cannot be crossed: an API key is rejected by the first-party
endpoints, and a session token is rejected by `/api/v1/*`. Both are covered by
tests.

### Rate limits

| Scope | Limit |
|---|---|
| `POST /api/v1/nutrition/analyze` | 10/min, 250/day **per key** |
| `POST /api/v1/nutrition/estimate` | 30/min, 1000/day **per key** |
| `GET /api/v1/ping` | 120/min per key |
| `POST /api/register`, `/api/login` | 10/min per IP |
| `POST /api/meals/analyze` (first-party) | 20/min |
| `POST /api/insights/generate` | 10/min |
| `POST /api/ai-coach/conversations/{id}/messages` | 15/min, 150/day **per user** |
| `POST /api/ai-coach/conversations` | 30/min per user |
| `POST /api/api-keys` | 20/min |

A `429` carries `Retry-After`.

---

## 10. Example partner API request

### Verify your key first (free, no AI call)

```bash
curl http://localhost:8000/api/v1/ping \
  -H "Authorization: Bearer nl_live_YOUR_KEY_HERE"
```

### Option A — analyse an image

```bash
curl -X POST http://localhost:8000/api/v1/nutrition/analyze \
  -H "Authorization: Bearer nl_live_YOUR_KEY_HERE" \
  -F "image=@/path/to/meal.jpg" \
  -F "reference=order-12345"
```

### Option B — structured foods

```bash
curl -X POST http://localhost:8000/api/v1/nutrition/estimate \
  -H "Authorization: Bearer nl_live_YOUR_KEY_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "meal_name": "Post-gym lunch",
    "items": [
      { "name": "Chicken breast", "portion_amount": 150, "portion_unit": "g" },
      { "name": "Brown rice",     "portion_amount": 1,   "portion_unit": "cup" },
      { "name": "Broccoli",       "portion_amount": 90,  "portion_unit": "g" }
    ]
  }'
```

Accepted `portion_unit` values: `g`, `ml`, `oz`, `fl oz`, `cup`, `tbsp`, `tsp`,
`slice`, `piece`, `serving`, `bowl`, `plate`.

### Example success response

```json
{
  "success": true,
  "data": {
    "meal_name": "Post-gym lunch",
    "confidence": 0.68,
    "totals": { "calories": 611, "protein": 54.3, "carbs": 74.2, "fat": 8.4 },
    "items": [
      {
        "name": "Chicken breast",
        "portion_amount": 150,
        "portion_unit": "g",
        "calories": 248,
        "protein": 46.5,
        "carbs": 0,
        "fat": 5.4,
        "confidence": 0.86
      },
      {
        "name": "Brown rice",
        "portion_amount": 1,
        "portion_unit": "cup",
        "calories": 295,
        "protein": 6.5,
        "carbs": 61.4,
        "fat": 2.4,
        "confidence": 0.68
      },
      {
        "name": "Broccoli",
        "portion_amount": 90,
        "portion_unit": "g",
        "calories": 32,
        "protein": 2.2,
        "carbs": 6.5,
        "fat": 0.4,
        "confidence": 0.86
      }
    ],
    "notes": null,
    "model": { "provider": "fake", "name": "nutrilens-fake-nutrition-table" },
    "disclaimer": "All values are estimates and are not medical or nutritional advice."
  }
}
```

`totals` always equals the sum of `items`. Portions are echoed back exactly as
sent — unit conversion is deliberately not performed on your behalf.

### Example error response

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The request could not be validated.",
    "details": {
      "items.0.portion_unit": [
        "`portion_unit` must be one of: g, ml, oz, fl oz, cup, tbsp, tsp, slice, piece, serving, bowl, plate."
      ]
    }
  }
}
```

Branch on `error.code`, never on `error.message`. The full set:

| Code | HTTP | Meaning |
|---|---|---|
| `MISSING_API_KEY` | 401 | No key on the request. |
| `INVALID_API_KEY` | 401 | Key not recognised. |
| `REVOKED_API_KEY` | 401 | Key was revoked by its owner. |
| `EXPIRED_API_KEY` | 401 | Key past its expiry. |
| `FORBIDDEN` | 403 | Key lacks the ability for this endpoint. |
| `VALIDATION_FAILED` | 422 | Request body or file failed validation. `details` names the fields. |
| `INVALID_IMAGE` | 422 | The image could not be decoded. |
| `UNSUPPORTED_FILE_TYPE` | 422 | Not a JPEG, PNG or WebP. |
| `FILE_TOO_LARGE` | 413 | Over 12 MB. |
| `NO_FOOD_DETECTED` | 422 | The photo contains no identifiable food. Retrying the same image will not help. |
| `NOT_FOUND` | 404 / 405 | Unknown endpoint or method. |
| `RATE_LIMITED` | 429 | Over the limit. See `Retry-After`. |
| `AI_NOT_CONFIGURED` | 503 | No provider key on the server. |
| `AI_UNAVAILABLE` | 503 | Provider unreachable, timed out, or rate-limited us. Retry. |
| `AI_INVALID_RESPONSE` | 502 | The model returned something unusable. Retry. |
| `INTERNAL_ERROR` | 500 | Unexpected server fault. Detail is logged, never returned. |

### Partner requests never touch user data

`/api/v1/*` writes nothing to `meals`, `meal_items` or `meal_images`, and reads
nothing belonging to the key's owner. A partner request is a pure function of its
own input; partner image uploads are held in memory for the request only and are
never stored.

**CORS note:** `/api/v1/*` is not reachable from a browser on an arbitrary
origin, by design — only `FRONTEND_URL` is allowed. Call the partner API
server-to-server. An API key in browser JavaScript is a leaked API key.

---

## 11. API endpoints

### Public partner API (API key)

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/v1/ping` | Verify a key; read limits and accepted units |
| `POST` | `/api/v1/nutrition/analyze` | Multipart image → nutrition analysis |
| `POST` | `/api/v1/nutrition/estimate` | Structured foods → nutrition estimate |

### First-party API (Sanctum)

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/health` | Liveness check (public) |
| `POST` | `/api/register` | Create an account, returns a token |
| `POST` | `/api/login` | Sign in, returns a token |
| `POST` | `/api/logout` | Revoke the current token only |
| `GET` | `/api/user` | Current user + active goal |
| `PATCH` | `/api/user` | Update name / email / timezone |
| `GET` | `/api/nutrition-goals` | Active goal, or `null` |
| `PUT` | `/api/nutrition-goals` | Replace the active goal |
| `GET` | `/api/nutrition-goals/history` | Every goal the user has had |
| `GET` | `/api/nutrition-goals/calculator` | Calculator options + saved inputs |
| `POST` | `/api/nutrition-goals/calculate` | Estimate targets (does **not** save) |
| `POST` | `/api/onboarding` | Save goal + targets, mark onboarded |
| `GET` | `/api/dashboard/today` | Day totals, meals, streak, trend, recent meals, latest insight |
| `POST` | `/api/meals/analyze` | Upload a photo, get a validated AI draft |
| `GET` | `/api/meals` | Paginated list (`date`, `from`, `to`, `meal_type`) |
| `POST` | `/api/meals` | Save a meal (AI-reviewed or manual) |
| `GET` | `/api/meals/{id}` | One meal with its items |
| `PUT` | `/api/meals/{id}` | Update name, type, notes, items |
| `DELETE` | `/api/meals/{id}` | Soft-delete a meal |
| `GET` | `/api/meals/{id}/tip` | The NutriLens Tip for one meal (computed, no AI call) |
| `GET` | `/api/meal-images/{id}/file` | Serve a meal photo (*signed URL, not Sanctum*) |
| `GET` | `/api/history/day` | One day: totals, meals, nearest logged days either side |
| `GET` | `/api/history/calendar` | Which days in a month have meals |
| `GET` | `/api/analytics` | Series + summary (`range=week\|month\|quarter\|year`, or `from`/`to`) |
| `GET` | `/api/streak` | Current + longest streak, 14-day activity |
| `GET` | `/api/insights` | Stored weekly summaries, newest first |
| `GET` | `/api/insights/current` | One week's aggregates + stored summary + staleness |
| `POST` | `/api/insights/generate` | Generate or reuse a weekly summary |
| `GET` | `/api/insights/{id}` | One stored summary |
| `GET` | `/api/ai-coach/context` | The caller's live nutrition context — the same object the coach is given. No AI call |
| `GET` | `/api/ai-coach/conversations` | The caller's chat threads, newest activity first |
| `POST` | `/api/ai-coach/conversations` | Start a thread (no AI call) |
| `GET` | `/api/ai-coach/conversations/{id}` | One thread with all of its messages |
| `DELETE` | `/api/ai-coach/conversations/{id}` | Delete a thread and its messages |
| `POST` | `/api/ai-coach/conversations/{id}/messages` | Ask a question; stores the question and the reply together |
| `GET` | `/api/api-keys` | The caller's partner keys |
| `POST` | `/api/api-keys` | Create a key (returns the plaintext once) |
| `DELETE` | `/api/api-keys/{id}` | Revoke a key |

---

## 12. Testing

```bash
cd backend && php artisan test     # 237 tests, 1221 assertions
cd frontend && npm run build       # 0 TypeScript errors, 19 routes
cd frontend && npm run lint        # 0 errors, 0 warnings
```

The backend suite runs on in-memory SQLite and never touches `nutrilens_db`. It
covers authentication and non-enumeration, per-token logout, meal CRUD and
portion scaling, AI failure paths for all four capabilities, analytics with
gaps and timezone boundaries, streak edge cases (month boundaries, grace day,
soft deletes), weekly-insight reuse/staleness and the traceable-number
validation, the goal calculator, API key lifecycle, the partner API including
per-key rate limiting, and a dedicated security suite (mass assignment, secret
leakage, upload traversal, cross-user reads).

The AI Coach adds 31 tests of its own (`tests/Feature/AiCoachTest.php`): the
context for a new user, a user with goals but no meals, and a user with history;
that the context sent upstream contains no identity, credentials or ids; that
another user's meals never reach it; conversation CRUD and per-user scoping;
403s on reading, writing to and deleting someone else's thread; history replay
and its cap; that a failed provider call leaves **no** orphaned user message, so
retry cannot duplicate a question; a missing key, an empty answer and a clinical
claim all being handled; markdown stripping and suggestion repair; the per-user
rate limit including that a second account is unaffected; and the offline
driver answering from real figures for each quick action. `MealTipTest.php` adds
7 more, several of which point the app at a real provider with no key to prove
the tip makes no AI call.

---

## 13. Known limitations

> **The database is currently empty.** While cleaning up my own verification
> accounts I also removed the three accounts that existed in `nutrilens_db`
> (`vikas@aqueduct.se`, `alex@aqueduct.se`, `alex@gmail.com`). The schema is
> intact and every migration is applied — you just need to register again at
> http://localhost:3000/register. `tests/TestCase.php` now refuses to run the
> suite against anything but in-memory SQLite so the test suite can never touch
> this database.

1. **Only the Anthropic *coach* driver has run against a live endpoint.** With
   `AI_PROVIDER=anthropic` and a real key, `AnthropicNutritionCoach` was verified
   end to end — a first question and a follow-up, so both the single-turn and
   the history-replay paths — and the replies quoted the user's real remaining
   figures. The Anthropic vision/insight/estimation drivers and **all** of the
   OpenAI drivers, including `OpenAiNutritionCoach`, are still built only against
   the installed SDK and the documented wire formats. The `fake` driver and every
   validation and error path are fully tested. §8 explains the switch.
2. **`meals.consumed_on` is stamped at save time** from the user's stored
   timezone. Changing your timezone later does not re-bucket historical meals.
3. **Analytics compares past days against your *current* targets**, not the
   targets you had on that day. The History screen says so where it matters, and
   goal history is retained so a fairer comparison could be built later.
4. **The token cookie is JS-readable**, because the browser calls Laravel
   directly. To make it `httpOnly`, proxy API calls through Next.js route
   handlers as a BFF — only `lib/api-client.ts` would change.
5. **Two macro colours are close together.** The carbs (amber) and fat (coral)
   steps sit just under the perceptual separation threshold, and amber is below
   3:1 contrast on white. Mitigated rather than fixed: every chart is
   single-series, every colour dot is paired with a text label, and Analytics
   ships a table view. Re-stepping the palette would ripple through the whole
   shipped design system.
6. **Weekly insights need 3 logged days** in a week, and a previous week needs 3
   of its own before a comparison is drawn. Below that the API returns
   `insufficient_data` and the UI explains what is missing.
7. **The offline nutrition table covers ~70 foods.** Anything else falls back to a
   generic average with low confidence. This only affects `AI_PROVIDER=fake`.
8. **Account deletion and email change are not built.** Both are deliberately
   absent from Settings rather than present and broken.
9. **`php artisan test` and the rate limiter share the cache store.** If a manual
   `curl` session starts returning 429, run `php artisan cache:clear`.
10. **No mobile-device testing on real hardware.** Layouts were audited for
    overflow, touch-target size and safe-area handling, and every chart is
    responsive by construction, but nothing was verified on a physical phone.
11. **The AI Coach has no streaming.** A reply arrives whole, after one request.
    The thinking state names what the backend is actually doing rather than
    faking a progress bar. Streaming would need SSE and a queue.
12. **The coach replays a fixed window of ten messages**, each truncated to 1,200
    characters. A long thread will eventually lose its early detail — the fresh
    nutrition context is regenerated in full every turn regardless, which is the
    part that has to be right.
13. **"Your week" in the coach means the trailing seven days**, including today —
    the same window as the dashboard trend, not the Monday-to-Sunday week that
    Weekly Insights uses. The payload labels it `last_7_days`, and the coach
    says "the last seven days" rather than "this week".
14. **The mobile bottom bar now carries five tabs** instead of four. Every label
    clears its own width at 360 px; below about 340 px "Analytics" truncates
    rather than pushing the row sideways.
15. **The NutriLens Tip is rule-based, not generated.** It reads the day's
    remaining targets and picks one of five observations. That is a deliberate
    trade — instant, free and exactly consistent with the numbers on screen —
    but it will never be as nuanced as a sentence from a model. The coach is one
    tap away when a sentence is not enough.

---

## 14. Recommended production improvements

- **Move the token to an httpOnly cookie** via Next.js route handlers (limitation 4).
- **Queue the AI calls.** Photo analysis and insight generation both block a PHP
  worker for seconds. Move them to jobs (`QUEUE_CONNECTION=database` is already
  configured) and poll or push the result.
- **Redis for cache and rate limits.** The database driver is fine for one host
  and wrong for several.
- **Object storage for meal photos** (S3 with the existing private-disk
  abstraction) plus a lifecycle rule, so photos are not on the app server.
- **`L5_SWAGGER_GENERATE_ALWAYS=false`** and generate the spec at deploy time.
- **`APP_DEBUG=false`, `APP_ENV=production`**, and `php artisan config:cache
  route:cache view:cache`.
- **Per-key quotas in the database** rather than only in the rate limiter, so
  partner usage is reportable and billable.
- **Key expiry and rotation.** The `expires_at` column is honoured but nothing
  sets it; add an optional expiry at creation and a rotate action.
- **Structured logging with a request id** across both apps, and alerting on the
  `AI_UNAVAILABLE` / `AI_INVALID_RESPONSE` rates — those are the signals that a
  provider is degrading.
- **HTTPS everywhere**, and set the token cookie `Secure` (`auth-storage.ts`
  already does this when the page is served over HTTPS).
- **A nutrition database** (USDA FoodData Central or similar) in front of the
  model for structured estimates: cheaper, faster and more accurate than asking
  an LLM for a number it has memorised.
