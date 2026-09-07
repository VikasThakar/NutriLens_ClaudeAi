# NutriLens — System Documentation

**Version:** 1.0
**Date:** 27 August 2026
**Status:** Deployed to Railway (production), single environment
**Repository:** `VikasThakar/NutriLens_ClaudeAi`

---

## How to read this document

Statements are labelled so that verified fact is never confused with inference:

| Label | Meaning |
|---|---|
| **[Confirmed]** | Verified directly in the source code, configuration, or a live response from the deployed system. |
| **[Assumption]** | A reasonable inference that has **not** been verified. Must be confirmed by the product owner. |
| **[Unknown]** | Information not derivable from the material available. Requires confirmation. |
| **[Recommendation]** | A suggested future change. **Does not exist today.** |

Unlabelled statements are **[Confirmed]** unless the surrounding section says otherwise.

---

## Table of contents

1. [Executive Summary](#1-executive-summary)
2. [System Overview](#2-system-overview)
3. [Business Problem](#3-business-problem)
4. [Goals and Objectives](#4-goals-and-objectives)
5. [User Roles and Personas](#5-user-roles-and-personas)
6. [Complete Functionalities](#6-complete-functionalities)
7. [User Journey and Workflows](#7-user-journey-and-workflows)
8. [System Architecture](#8-system-architecture)
9. [Technology Stack](#9-technology-stack)
10. [Frontend Architecture](#10-frontend-architecture)
11. [Backend Architecture](#11-backend-architecture)
12. [Database Documentation](#12-database-documentation)
13. [API Documentation](#13-api-documentation)
14. [External Integrations](#14-external-integrations)
15. [Authentication and Authorization](#15-authentication-and-authorization)
16. [Security Considerations](#16-security-considerations)
17. [Error Handling and Edge Cases](#17-error-handling-and-edge-cases)
18. [Screens and Modules](#18-screens-and-modules)
19. [Data Flow](#19-data-flow)
20. [Business Rules](#20-business-rules)
21. [System States and Statuses](#21-system-states-and-statuses)
22. [Notifications and Communication](#22-notifications-and-communication)
23. [Configuration and Environment](#23-configuration-and-environment)
24. [Deployment and Infrastructure](#24-deployment-and-infrastructure)
25. [Monitoring and Logging](#25-monitoring-and-logging)
26. [Known Limitations](#26-known-limitations)
27. [Future Improvements](#27-future-improvements)
28. [Glossary](#28-glossary)
29. [Missing Information / Assumptions / Questions](#29-missing-information--assumptions--questions)

---

# 1. Executive Summary

**NutriLens is an AI-powered nutrition tracking application. You photograph a meal, and it tells you what you ate and what that meal contains — calories, protein, carbohydrates and fat — item by item.**

## The core idea in one sentence

Traditional calorie tracking fails because it is slow: a user must search a database for every single food, pick the right entry from dozens of near-identical results, and enter a portion. NutriLens replaces that with one photograph.

## What the system does

1. A user photographs a plate of food.
2. A vision AI model identifies each food, estimates its portion, and estimates its macronutrients.
3. The user **reviews and corrects** anything the model got wrong — every number is editable.
4. The meal is saved and counted toward that day's targets.
5. Over time, the system reports trends, streaks, and a weekly written summary of eating patterns.

## Who uses it

Two distinct audiences, served by two separate interfaces:

| Audience | What they get |
|---|---|
| **End users** — individuals tracking their nutrition | A web application: dashboard, photo capture, history, analytics, an AI coach |
| **Partner developers** — other companies | A paid-style public REST API that returns nutrition data for a photo or a food list |

## The value it provides

- **Speed.** One photograph instead of a dozen database searches.
- **Honesty.** The system states plainly that its numbers are estimates, and it is architecturally prevented from inventing figures — the weekly-summary generator discards any AI output containing a number that cannot be traced back to the user's own data.
- **Correctability.** The AI produces a *draft*, never a fact. The user is always the final authority.
- **A second revenue surface.** The same analysis engine is exposed as a versioned public API with per-key rate limiting and self-service key management.

## Why this is not just "ChatGPT for food"

Three deliberate architectural choices distinguish it:

1. **Every AI response is re-validated server-side** against a schema, value ranges, and item caps. A model that ignores its contract produces an error, not a corrupted database row.
2. **Two major features deliberately make no AI call at all** — Smart Plate and NutriLens Tip are pure arithmetic over the user's own data, making them instant, free, and exactly consistent with what is on screen.
3. **The AI Coach is given a privacy-bounded context object** containing nutrition figures and nothing else — no name, no email, no identifiers, no photographs.

---

# 2. System Overview

| Attribute | Value |
|---|---|
| **System name** | NutriLens |
| **Tagline** | "Snap your food. See your nutrition." |
| **Type** | Web application + public REST API |
| **Architecture** | Decoupled two-application monorepo |
| **Primary users** | Individuals tracking nutrition; partner developers |
| **Business objective** | Make nutrition logging fast enough that people actually keep doing it |

## How the entire system works in simple terms

Think of NutriLens as **three cooperating parts**:

1. **A website** (Next.js) — everything the user sees and touches. It holds no business logic of its own; it asks the API for everything.
2. **An API server** (Laravel) — the brain. It stores data, enforces rules, talks to the AI, and decides what every user is allowed to see.
3. **A database** (MySQL) — the memory. Accounts, meals, goals, chat history, API keys.

When the user photographs a meal, the website sends the photo to the API. The API shrinks the photo, sends it to an AI provider, checks the answer is sane, and returns a draft. **Nothing is saved yet.** The user corrects the draft, and only when they press Save does anything reach the database.

## Main components

```mermaid
flowchart TB
    subgraph client ["User's browser"]
        UI["Next.js 16 web app<br/>React 19 · Tailwind 4"]
    end

    subgraph server ["Railway — backend service"]
        NG["nginx"] --> FPM["php-fpm"]
        FPM --> LAR["Laravel 12 REST API"]
    end

    subgraph data ["Railway — data"]
        DB[("MySQL")]
        VOL[["Volume<br/>/app/storage<br/>meal photos"]]
    end

    subgraph ext ["External"]
        AI["AI provider<br/>Anthropic / OpenAI"]
    end

    PARTNER["Partner systems<br/>server-to-server"]

    UI -->|"REST + JSON<br/>Bearer token"| NG
    PARTNER -->|"REST<br/>Bearer nl_live_..."| NG
    LAR --> DB
    LAR --> VOL
    LAR -->|"HTTPS<br/>key never leaves server"| AI
```

## High-level workflow

```mermaid
flowchart LR
    A["Register"] --> B["Onboarding<br/>set goals"]
    B --> C["Today dashboard"]
    C --> D["Add meal"]
    D --> E{"Photo or<br/>manual?"}
    E -->|Photo| F["AI analysis"]
    E -->|Manual| G["Type values"]
    F --> H["Review & correct<br/>+ Smart Plate"]
    G --> H
    H --> I["Save"]
    I --> C
    C --> J["History · Analytics<br/>Insights · Coach"]
```

---

# 3. Business Problem

## The problem before this system

Nutrition tracking has a well-documented abandonment problem. The cause is friction, and the friction is concentrated in one place: **data entry**.

| Step in a traditional tracker | Friction |
|---|---|
| Search for "chicken" | Returns hundreds of near-identical entries |
| Choose the right entry | "Chicken, breast, roasted, skin removed" vs. 40 variants |
| Enter a portion | The user must estimate grams by eye anyway |
| Repeat per food | A plate with 5 items = 5 full cycles |

A single mixed meal can take several minutes. Multiplied across three meals a day, tracking becomes a chore, and users stop.

## Why the system was needed

**[Assumption]** The specific commercial or personal motivation for building NutriLens is not documented in the repository. The problem statement above is inferred from the product's own README, its feature set, and the design decisions visible in the code. *This must be confirmed by the product owner.*

What *is* **[Confirmed]** from the codebase is the design intent: every architectural decision optimises for **reducing time-to-logged-meal** while **preserving user control over the numbers**.

## Challenges it solves

| Challenge | How NutriLens addresses it |
|---|---|
| Data entry is slow | One photograph replaces per-item search |
| Users don't know portion sizes | The vision model estimates them; the user adjusts |
| AI output cannot be trusted blindly | Every value is editable; server-side validation rejects malformed AI output |
| Corrections compound rounding errors | Portion changes rescale from the **original AI baseline**, not from the last edited value |
| Users over-correct and lose their edits | Typing a macro **locks** it, so later portion changes leave it alone |
| Tracking apps feel judgemental | Scores are framed as "fit against what's left of today", explicitly not a nutrition verdict |

## How the system improves the process

```mermaid
flowchart LR
    subgraph old ["Traditional tracker"]
        direction TB
        O1["Search food 1"] --> O2["Pick entry"] --> O3["Enter portion"]
        O3 --> O4["Repeat × N items"]
    end

    subgraph new ["NutriLens"]
        direction TB
        N1["Photograph plate"] --> N2["AI returns all items<br/>with portions + macros"]
        N2 --> N3["Correct only what's wrong"]
    end
```

## Expected business value

**[Assumption]** — not stated in the repository:

- Higher user retention through lower logging friction.
- A second revenue stream via the Partner API.
- Differentiation through demonstrable honesty about estimate accuracy.

---

# 4. Goals and Objectives

## Primary goals

| # | Goal | Evidence in the system |
|---|---|---|
| P1 | Reduce meal-logging time to seconds | Photo-first capture flow; `POST /api/meals/analyze` |
| P2 | Keep the user in control of every number | All AI values editable; macro locks; portion rescaling from baseline |
| P3 | Never present an estimate as a fact | Disclaimer on every AI meal and in every partner response |
| P4 | Provide longitudinal insight, not just daily totals | Analytics (7/30/90/365 days), streaks, weekly AI insights |

## Secondary goals

| # | Goal | Evidence |
|---|---|---|
| S1 | Make the product fully demonstrable with no AI cost | `AI_PROVIDER=fake` — a functional offline driver, not a stub |
| S2 | Provide actionable pre-save guidance | Smart Plate: Meal Fit Score + three simulated optimisations |
| S3 | Answer nutrition questions conversationally | AI Coach, grounded in the user's own logged figures |
| S4 | Be provider-agnostic | Four AI contracts × three interchangeable drivers |

## Business goals

| # | Goal | Status |
|---|---|---|
| B1 | Monetisable partner API | **[Confirmed]** Built — keys, abilities, per-key rate limits, Swagger UI |
| B2 | Usage reportable/billable per key | **[Confirmed]** *Not* built — limits are enforced in the rate limiter only, not persisted |
| B3 | Revenue model / pricing | **[Unknown]** No pricing, plan, or billing code exists |

## Technical goals

| # | Goal | Evidence |
|---|---|---|
| T1 | Strict frontend/backend separation | No shared runtime; REST + JSON only |
| T2 | Single source of truth for AI provider selection | One `AI_PROVIDER` setting drives all four capabilities |
| T3 | Server-side validation of all AI output | Schema, ranges, item caps, unit normalisation, traceable-number check |
| T4 | Deterministic features where AI adds no value | Smart Plate and NutriLens Tip make zero AI calls |
| T5 | Type safety end to end | TypeScript strict; PHP enums; typed API Resources |
| T6 | Comprehensive automated testing | 269 backend tests / 1,379 assertions on in-memory SQLite |

## Success criteria

| Criterion | Measurable? | Status |
|---|---|---|
| Backend test suite passes | Yes | **[Confirmed]** 269 tests |
| Frontend builds with 0 type errors | Yes | **[Confirmed]** 19 routes |
| Partner API returns consistent envelopes | Yes | **[Confirmed]** Including framework-level failures |
| User retention / DAU targets | **[Unknown]** | No analytics instrumentation exists |
| Partner adoption targets | **[Unknown]** | Not defined |

---

# 5. User Roles and Personas

The system has **two authenticated principals** and one unauthenticated visitor. There is **no administrator role, no role column, and no permission table** — authorisation is purely *ownership-based*.

| User Role | Description | Main Responsibilities | Permissions | Main Features Used |
|---|---|---|---|---|
| **Visitor** (unauthenticated) | Anyone who has not signed in | Evaluate the product; register | Landing page, `/terms`, `/privacy`, `POST /api/register`, `POST /api/login`, `GET /api/health` | Marketing landing page |
| **Registered User** | An individual tracking their own nutrition | Log meals, maintain goals, review AI output | Full CRUD on **their own** meals, goals, insights, conversations and API keys. **No access to any other user's data.** | Dashboard, Add Meal, History, Analytics, Insights, Coach, Goals, Settings, Developer |
| **Partner Application** (machine) | A third-party system holding an `nl_live_` API key | Send photos or food lists; receive nutrition data | **Only** `/api/v1/*`. Cannot read or write *any* user data — including the data of the user who owns the key | `POST /v1/nutrition/analyze`, `POST /v1/nutrition/estimate`, `GET /v1/ping` |

## Role relationships

```mermaid
flowchart TB
    V["Visitor"] -->|"register / login"| U["Registered User"]
    U -->|"creates at /developer"| K["API Key<br/>nl_live_..."]
    K -->|"authenticates"| P["Partner Application"]
    P -.->|"CANNOT access"| UD[("User's meals,<br/>goals, photos")]
    U -->|"owns"| UD

    style P fill:#4a2020,stroke:#a55
    style UD fill:#1e3a2e,stroke:#5a5
```

> **Critical design point [Confirmed]:** A partner key is *created by* a user but is **not a delegation of that user's access**. `AuthenticateApiKey` attaches the resolved key and its owner to the request but **does not log the owner in**. Partner endpoints operate solely on data supplied in the request body.

## Notable absences

| Expected role | Present? | Note |
|---|---|---|
| Administrator | **No** | No admin panel, no admin routes, no role/permission columns |
| Support agent | **No** | — |
| Nutritionist / coach (human) | **No** | The "Coach" is an AI feature, not a human role |
| Organisation / team | **No** | Every account is an individual |

**[Recommendation]** If moderation, support, or partner-usage reporting is ever required, an administrator role will need to be designed from scratch — there is currently no scaffolding for it.

---

# 6. Complete Functionalities

## 6.1 Account Registration

| Aspect | Detail |
|---|---|
| **Purpose** | Create a user account and issue an authentication token |
| **Users** | Visitor |
| **Trigger** | Submitting the form at `/register` |
| **Inputs** | `name`, `email`, `password` (+ confirmation) |
| **Process** | Validate → check email uniqueness → hash password (bcrypt, 12 rounds) → create `users` row → issue Sanctum personal access token |
| **Outputs** | `{ user, token }`; token stored in the `nutrilens_token` cookie; redirect to `/onboarding` |
| **Business rules** | Email must be unique. Password minimum 8 characters, must contain a letter and a number (enforced in the UI, validated server-side). Rate limited to **10/min per IP** to blunt credential stuffing. |
| **Dependencies** | `AuthController`, `User` model, Laravel Sanctum, MySQL |

## 6.2 Authentication (Login / Logout)

| Aspect | Detail |
|---|---|
| **Purpose** | Prove identity and obtain/revoke a bearer token |
| **Users** | Visitor (login), Registered User (logout) |
| **Trigger** | `/login` form; Sign Out in Settings |
| **Inputs** | `email`, `password` |
| **Process** | Validate → verify hash → issue token. Logout deletes **only the token used for that request** |
| **Outputs** | `{ user, token }` or `401` |
| **Business rules** | Login failures are **non-enumerating** — a wrong email and a wrong password return the same message, so an attacker cannot discover which addresses are registered. Logging out on one device leaves other devices signed in. |
| **Dependencies** | `AuthController`, Sanctum, `personal_access_tokens` |

## 6.3 Onboarding

| Aspect | Detail |
|---|---|
| **Purpose** | Capture the user's goal and daily targets before first use |
| **Users** | Registered User (once) |
| **Trigger** | Automatic redirect after registration |
| **Inputs** | Goal type; calorie/protein/carb/fat targets (optionally produced by the Goal Calculator) |
| **Process** | Create a `nutrition_goals` row with `source = onboarding`, `is_active = true`; stamp `users.onboarded_at` |
| **Outputs** | Active goal; redirect to `/today` |
| **Business rules** | Exactly one goal per user may be active at a time. |
| **Dependencies** | `OnboardingController`, `NutritionGoalService` |

## 6.4 AI Photo Analysis

**The system's flagship functionality.**

| Aspect | Detail |
|---|---|
| **Purpose** | Convert a meal photograph into an editable, itemised nutrition draft |
| **Users** | Registered User |
| **Trigger** | Uploading/capturing a photo at `/add-meal` |
| **Inputs** | One image (JPEG/PNG/WebP), max 12 MB, min 64×64 px |
| **Process** | 1. Validate the upload<br/>2. **Store the photo first** — so a later AI failure does not lose the user's photo<br/>3. Read dimensions via `getimagesize`<br/>4. Create a `meal_images` row with status `processing`<br/>5. `MealImagePreparer`: decode (GD) → auto-orient from EXIF → downscale long edge to `AI_IMAGE_MAX_EDGE` (1568 px) → re-encode as JPEG<br/>6. Send to the configured vision provider<br/>7. **Re-validate the response** — schema, value ranges, item cap (`AI_MAX_ITEMS` = 12), unit normalisation<br/>8. Update the image row to `completed` (or `failed` with the error) |
| **Outputs** | A **draft** — items with name, portion, macros and per-item confidence. **Nothing is written to `meals`.** |
| **Business rules** | • Photos are downscaled before upload — a 4000 px phone photo costs several times more per analysis with no accuracy gain<br/>• Hard cap of 12 detected items<br/>• If the AI fails, the **stored photo is returned in the error payload** so the user can still save the meal manually<br/>• Rate limited to **20/min** |
| **Dependencies** | `MealAnalysisController`, `MealAnalysisService`, `MealImagePreparer`, `MealAnalysisPrompt`, PHP **GD** + **EXIF** extensions, AI provider |

## 6.5 Meal Review, Correction and Save

| Aspect | Detail |
|---|---|
| **Purpose** | Let the user correct AI output before it becomes data |
| **Users** | Registered User |
| **Trigger** | Completion of AI analysis, or choosing manual entry |
| **Inputs** | Meal name, meal type, consumed-at time, notes; per item: name, portion amount, portion unit, calories, protein, carbs, fat |
| **Process** | Edits are held client-side in `lib/meal-draft.ts` until Save. `POST /api/meals` persists the meal and its items in one transaction, recalculating denormalised totals server-side. |
| **Outputs** | A saved `meals` row + `meal_items` rows; a **NutriLens Tip**; redirect to the dashboard |
| **Business rules** | **Two rules that define the editing model:**<br/>**(1) Portion rescaling from baseline.** Changing a portion rescales macros from the AI's *original* estimate (`base_portion_amount`, `base_calories`, `base_protein`, `base_carbs`, `base_fat`), not from the current displayed value — so repeated adjustments never compound rounding error.<br/>**(2) Macro locking.** Typing over a macro adds it to `locked_macros`; subsequent portion changes leave that macro untouched. |
| **Dependencies** | `MealController`, `MealService`, `MealPolicy`, `lib/meal-draft.ts` |

## 6.6 NutriLens Smart Plate

| Aspect | Detail |
|---|---|
| **Purpose** | Tell the user, *before saving*, how well this meal fits what is **left** of today |
| **Users** | Registered User |
| **Trigger** | Any meaningful edit on the review screen |
| **Inputs** | The unsaved draft + the user's active goal + today's already-logged meals |
| **Process** | 1. `MealFitScore` computes a 0–10 score from four weighted components<br/>2. `PlateOptimizer` proposes candidate changes, **simulates** each, **re-scores** it, and keeps only those that actually improve the score<br/>3. Suggestions returned: **Boost Protein**, **Reduce Calories**, **Balance Meal** |
| **Outputs** | Score, per-macro status breakdown, up to three applicable suggestions with exact promised deltas |
| **Business rules** | • **Zero AI calls** — entirely deterministic arithmetic<br/>• A suggestion is only offered if simulation proves it helps<br/>• An item with **hand-locked calories is never proposed for an increase** (the cost would be invisible)<br/>• Nothing is added to a meal already materially over budget<br/>• Additions come from a **curated list of 12 foods** only<br/>• One level of Undo per applied suggestion; does not survive leaving the screen<br/>• Rate limit **90/min** — a runaway-client backstop, not a cost control |
| **Dependencies** | `SmartPlateController`, `SmartPlateService`, `MealFitScore`, `PlateOptimizer`, `FoodNutritionTable`, `lib/smart-plate.ts` |

> **Engineering note [Confirmed]:** Smart Plate's promises are only true because `PlateItem::withPortion()` (PHP) reproduces `setItemPortion()` (TypeScript) **exactly** — same baseline, same locked-macro rule, same rounding. `SmartPlateTest` asserts this by replaying every suggestion through an independent implementation of the frontend's rules.

## 6.7 NutriLens Tip

| Aspect | Detail |
|---|---|
| **Purpose** | A one-line read on how a saved meal sits against the rest of the day |
| **Users** | Registered User |
| **Trigger** | Immediately after saving a meal; on the meal detail sheet |
| **Inputs** | The saved meal + the day's remaining targets |
| **Process** | `MealTipService` selects one of **five** rule-based observations |
| **Outputs** | One sentence |
| **Business rules** | **No AI call** — instant, free, and exactly consistent with the on-screen numbers |
| **Dependencies** | `MealController::tip`, `MealTipService` |

## 6.8 Today Dashboard

| Aspect | Detail |
|---|---|
| **Purpose** | Single-glance view of the current day |
| **Users** | Registered User |
| **Trigger** | Opening `/today` |
| **Process** | `GET /api/dashboard/today` returns everything in **one** request |
| **Outputs** | Calorie ring, macro bars, current streak, 7-day trend, recent meals, latest weekly insight, quick-add |
| **Business rules** | New accounts get a distinct first-run experience rather than empty charts |
| **Dependencies** | `DashboardController`, `DailyNutritionAggregator`, `StreakService` |

## 6.9 History

| Aspect | Detail |
|---|---|
| **Purpose** | Browse, edit and delete past meals one day at a time |
| **Users** | Registered User |
| **Inputs** | A date; or a month (calendar view) |
| **Process** | `GET /api/history/day` returns totals, meals, and the **nearest logged days either side** — so "previous/next" skips empty days rather than stepping through them |
| **Outputs** | Day totals + meal list; calendar heat indication |
| **Business rules** | Deletion is a **soft delete**. Meals bucket by `consumed_on` (the user's *local* calendar date), not UTC. |
| **Dependencies** | `HistoryController`, `MealController`, `MealPolicy` |

## 6.10 Analytics

| Aspect | Detail |
|---|---|
| **Purpose** | Longitudinal macro trends |
| **Inputs** | `range=week\|month\|quarter\|year`, or explicit `from`/`to` |
| **Process** | `AnalyticsService` aggregates daily rows; long ranges bucket by week |
| **Outputs** | Four single-series charts, averages, totals, meal count, "days close to your target", and a table view of the same numbers |
| **Business rules** | • Every chart is **one series** — four macros means four charts, never four lines on one axis<br/>• "Days close to target" uses a **transparent, stated rule**<br/>• Past days are compared against **current** targets, not historical ones (see [Known Limitations](#26-known-limitations)) |
| **Dependencies** | `AnalyticsController`, `AnalyticsService`, `DailyNutritionAggregator`, Recharts |

## 6.11 Daily Streaks

| Aspect | Detail |
|---|---|
| **Purpose** | Behavioural reinforcement |
| **Process** | `StreakService` computes current and longest streak plus a 14-day activity strip |
| **Business rules** | A day counts **once**, however many meals are on it. Handles month boundaries, a grace day, and soft-deleted meals. |
| **Dependencies** | `StreakController`, `StreakService` |

## 6.12 Weekly AI Insights

| Aspect | Detail |
|---|---|
| **Purpose** | A short written read on the user's week |
| **Trigger** | User action, or automatic surfacing on the dashboard |
| **Inputs** | A week's aggregates (computed in PHP, never by the model) |
| **Process** | Aggregate → generate → **validate that every number in the prose traces back to the supplied aggregates** → store with a `data_hash` for reuse |
| **Outputs** | Headline, summary, highlights, recommendations, comparison to the previous week |
| **Business rules** | • Requires **3 logged days** in the week; a comparison requires 3 in the previous week too — otherwise `insufficient_data`<br/>• **An insight containing an untraceable number is discarded, not shown**<br/>• Reused when `data_hash` is unchanged, so regeneration is free<br/>• One row per `(user, week_start)` — unique constraint<br/>• Rate limited **10/min** |
| **Dependencies** | `WeeklyInsightController`, `WeeklyInsightService`, `WeeklyInsightPrompt`, AI provider |

## 6.13 NutriLens AI Coach

| Aspect | Detail |
|---|---|
| **Purpose** | Conversational answers grounded in the user's own logged data |
| **Trigger** | Sending a message at `/coach` |
| **Inputs** | A question + a fixed replay window of prior messages |
| **Process** | 1. `CoachContextService` builds a `CoachContext` — **every figure computed in PHP before the model sees it**<br/>2. `CoachPrompt` instructs the model to *quote* those figures, never derive them<br/>3. Provider call<br/>4. Validate shape; check for **clinical overreach**; strip markdown<br/>5. Store question and reply **together** |
| **Outputs** | An assistant message + suggested follow-ups |
| **Business rules** | • **Privacy boundary:** the context carries nutrition figures, meal names and dates — and *nothing else*. No name, email, password hash, tokens, database ids, photos or body metrics<br/>• Reuses `AnalyticsService`/`StreakService` so the coach can never quote a number that disagrees with the Analytics screen<br/>• **A failed provider call leaves no orphaned user message** — retry cannot duplicate a question<br/>• Replays **10 messages**, each truncated to **1,200 characters**<br/>• "Your week" = the trailing **7 days including today** (labelled `last_7_days`)<br/>• General food knowledge is permitted but must be labelled an estimate<br/>• Rate limits: **15/min, 150/day** per user for messages; **30/min** for new threads |
| **Dependencies** | `AiCoachController`, `CoachService`, `CoachContextService`, `CoachPrompt`, `AiConversationPolicy`, AI provider |

## 6.14 Nutrition Goals & Goal Calculator

| Aspect | Detail |
|---|---|
| **Purpose** | Set daily targets, optionally estimated from body metrics |
| **Inputs** | Goal type + four targets; *or* age, height, weight, activity level, biological sex |
| **Process** | `GoalCalculatorService` computes BMR via **Mifflin-St Jeor (1990)**, multiplies by an activity factor to get maintenance (TDEE), then applies a **proportional** calorie adjustment |
| **Outputs** | An estimate — **calculation does not save**. Saving is a separate explicit `PUT`. |
| **Business rules** | See [§20 Business Rules](#20-business-rules) for the full calculation table. Biological sex is optional, and the UI explains why it is asked for. Full goal history is retained. |
| **Dependencies** | `NutritionGoalController`, `GoalCalculatorController`, `GoalCalculatorService` |

## 6.15 Partner API Key Management

| Aspect | Detail |
|---|---|
| **Purpose** | Self-service issuance and revocation of partner API keys |
| **Users** | Registered User, at `/developer` |
| **Process** | Generate `nl_live_` + 40 chars from a 32-symbol alphabet (**200 bits of CSPRNG output**) → store **only** `hash('sha256', $key)` behind a unique index → return the plaintext **once** |
| **Outputs** | The plaintext key, shown once and never again |
| **Business rules** | • Only a SHA-256 digest is stored — a database dump yields no usable keys<br/>• `key_prefix` (first 14 chars) is stored in clear **purely so two keys can be told apart in the UI**<br/>• SHA-256 rather than bcrypt is deliberate: the secret is high-entropy, so a slow hash buys nothing, and a salted hash could not be looked up without scanning every row<br/>• Sanctum-only — **a partner key can never mint another partner key**<br/>• Rate limited **20/min** |
| **Dependencies** | `ApiKeyController`, `ApiKeyService`, `api_keys` table |

## 6.16 Partner Nutrition API

| Aspect | Detail |
|---|---|
| **Purpose** | Expose the analysis engine to third-party systems |
| **Users** | Partner Application |
| **Endpoints** | `GET /v1/ping`, `POST /v1/nutrition/analyze`, `POST /v1/nutrition/estimate` |
| **Process** | `AuthenticateApiKey` resolves the key → ability check → per-key throttle → analysis → consistent envelope |
| **Business rules** | • **Partner requests never touch user data** — nothing is written to `meals`/`meal_items`/`meal_images`, and nothing belonging to the key's owner is read<br/>• Partner image uploads are held **in memory for the request only** and are never stored<br/>• `totals` always equals the sum of `items`<br/>• Portions are echoed back **exactly as sent** — unit conversion is deliberately not performed<br/>• Key resolution runs **before** throttling, so limits bucket by key rather than by IP |
| **Dependencies** | `PartnerNutritionController`, `PartnerStatusController`, `AuthenticateApiKey`, `PartnerApiResponse`, `PartnerExceptionRenderer` |

## 6.17 Settings & Profile

| Aspect | Detail |
|---|---|
| **Purpose** | Manage profile, appearance and goals |
| **Available** | Name, email, timezone; theme (light/dark/system); nutrition goals; developer keys; sign out |
| **Not available** | **Account deletion and email change are not built** — deliberately absent rather than present and broken |
| **Dependencies** | `UserController`, `next-themes` |

---

# 7. User Journey and Workflows

## 7.1 First-time user — registration to first meal

```mermaid
flowchart TD
    A["Visitor lands on /"] --> B["Clicks Get Started"]
    B --> C["/register — name, email, password"]
    C --> D{"Valid &<br/>email unique?"}
    D -->|No| C
    D -->|Yes| E["POST /api/register<br/>bcrypt hash · Sanctum token"]
    E --> F["Token → nutrilens_token cookie"]
    F --> G["/onboarding"]
    G --> H{"Use goal<br/>calculator?"}
    H -->|Yes| I["Enter age, height, weight,<br/>activity, sex"]
    I --> J["POST /nutrition-goals/calculate<br/>Mifflin-St Jeor — does NOT save"]
    J --> K["Review suggested targets"]
    H -->|No| K
    K --> L["POST /api/onboarding<br/>saves goal · stamps onboarded_at"]
    L --> M["/today — first-run dashboard"]
```

## 7.2 The core loop — photograph to saved meal

```mermaid
sequenceDiagram
    actor U as User
    participant FE as Next.js
    participant API as Laravel
    participant GD as GD / EXIF
    participant AI as AI Provider
    participant DB as MySQL
    participant V as Volume

    U->>FE: Take / choose photo
    FE->>API: POST /api/meals/analyze (multipart)
    API->>API: Validate — type, size, dimensions
    API->>V: Store photo FIRST
    Note over API,V: Stored before analysis so an<br/>AI failure never loses the photo
    API->>DB: meal_images row — status: processing
    API->>GD: Decode · auto-orient (EXIF) · downscale to 1568px · re-encode JPEG
    GD-->>API: Prepared image
    API->>AI: Vision request + prompt
    AI-->>API: JSON — items, portions, macros, confidence
    API->>API: Re-validate: schema, ranges, cap 12 items, units
    alt Validation fails
        API->>DB: status: failed + error
        API-->>FE: 502 + the stored photo
        FE-->>U: "Analysis didn't work" + Enter manually
    else Success
        API->>DB: status: completed + raw payload
        API-->>FE: 200 — DRAFT (nothing in `meals` yet)
        FE-->>U: Review screen
        U->>FE: Correct portions / macros
        FE->>API: POST /api/meals/smart-plate (on each edit)
        API-->>FE: Fit score + suggestions (no AI call)
        U->>FE: Press Save
        FE->>API: POST /api/meals
        API->>DB: meals + meal_items (transaction)
        API-->>FE: Saved meal
        FE->>API: GET /api/meals/{id}/tip
        API-->>FE: NutriLens Tip (no AI call)
        FE-->>U: Dashboard + Tip
    end
```

**Key insight:** the photo is stored **before** the AI is called, and the draft exists **only in the browser** until Save. Two deliberate protections: an AI failure never costs the user their photograph, and a rejected draft never leaves a partial row in the database.

## 7.3 AI Coach conversation

```mermaid
flowchart TD
    A["User opens /coach"] --> B["GET /ai-coach/context<br/>live figures · no AI call"]
    B --> C["Progress strip + quick actions"]
    C --> D["User asks a question"]
    D --> E["POST /ai-coach/conversations<br/>if no thread yet"]
    E --> F["POST .../messages"]
    F --> G["CoachContextService builds CoachContext"]
    G --> H{"Context contains ONLY:<br/>nutrition figures, meal names, dates"}
    H --> I["Replay last 10 messages<br/>truncated to 1,200 chars each"]
    I --> J["AI provider"]
    J --> K{"Reply valid?"}
    K -->|"Empty / clinical<br/>overreach / failed"| L["Error — NO orphaned<br/>user message stored"]
    L --> M["Retry offered — cannot duplicate"]
    K -->|Valid| N["Strip markdown · repair suggestions"]
    N --> O["Store question + reply together"]
    O --> P["Render reply + follow-ups"]

    style H fill:#1e3a2e,stroke:#5a5
    style L fill:#4a2020,stroke:#a55
```

## 7.4 Partner API integration

```mermaid
sequenceDiagram
    actor D as Partner Developer
    participant UI as /developer
    participant API as Laravel
    participant DB as MySQL
    participant SYS as Partner System
    participant AI as AI Provider

    D->>UI: Create key "Acme production"
    UI->>API: POST /api/api-keys (Sanctum)
    API->>API: 200 bits CSPRNG → nl_live_...
    API->>DB: Store SHA-256 hash + prefix only
    API-->>D: Plaintext key — SHOWN ONCE

    Note over D,SYS: Key deployed server-side.<br/>A key in browser JS is a leaked key.

    SYS->>API: POST /v1/nutrition/analyze<br/>Authorization: Bearer nl_live_...
    API->>API: AuthenticateApiKey — hash lookup
    alt Invalid / revoked / expired
        API-->>SYS: 401 {success:false, error:{code}}
    else Valid
        API->>API: Ability check → per-KEY throttle
        API->>AI: Analyse (image held in memory only)
        AI-->>API: Result
        API->>DB: Update last_used_at
        Note over API,DB: NOTHING written to meals /<br/>meal_items / meal_images
        API-->>SYS: 200 {success:true, data:{...}}
    end
```

---

# 8. System Architecture

## 8.1 Architectural style

**Decoupled two-application monorepo.** One Git repository contains two independently built and independently deployed applications that share **no runtime, no code and no session**. Their only contract is HTTP + JSON.

| Property | Value |
|---|---|
| Communication | REST over HTTPS; JSON and `multipart/form-data` |
| Authentication | Stateless bearer tokens (no server-side session for the API) |
| Coupling | One environment variable (`NEXT_PUBLIC_API_URL`) |
| Build | Independent — each service has its own root directory on Railway |

## 8.2 Full architecture diagram

```mermaid
flowchart TB
    subgraph browser ["Client — Browser"]
        direction TB
        PAGES["App Router pages<br/>19 routes"]
        PROXY["proxy.ts<br/>server-side route guard"]
        SVC["services/*.service.ts"]
        CLIENT["lib/api-client.ts<br/>THE ONLY fetch call site"]
        STORE["auth-storage.ts<br/>nutrilens_token cookie"]
        PAGES --> SVC --> CLIENT
        PROXY -.->|"checks cookie<br/>before render"| PAGES
        CLIENT --> STORE
    end

    subgraph rw ["Railway — production"]
        direction TB
        subgraph fesvc ["frontend service"]
            NEXT["next start<br/>Railpack build"]
        end
        subgraph besvc ["backend service — Dockerfile"]
            NGINX["nginx :8080"]
            SUP["supervisord"]
            PHPFPM["php-fpm :9000"]
            NGINX --> PHPFPM
            SUP -.-> NGINX
            SUP -.-> PHPFPM
        end
        subgraph lar ["Laravel 12"]
            MW["Middleware<br/>CORS · Sanctum · AuthenticateApiKey · Throttle"]
            CTRL["Controllers<br/>first-party + V1 partner"]
            POL["Policies<br/>Meal · NutritionGoal · AiConversation"]
            SRVC["Services<br/>AI · Analytics · Nutrition · Goals"]
            RES["API Resources"]
            MW --> CTRL --> POL
            CTRL --> SRVC --> RES
        end
        MYSQL[("MySQL<br/>MySQL-TAbX")]
        VOLUME[["Volume /app/storage<br/>meal photos"]]
        PHPFPM --> MW
    end

    EXTAI["AI Provider<br/>Anthropic · OpenAI"]
    PARTNER["Partner systems<br/>server-to-server"]

    CLIENT -->|"HTTPS · Bearer"| NGINX
    PARTNER -->|"HTTPS · Bearer nl_live_"| NGINX
    PAGES --> NEXT
    SRVC --> MYSQL
    SRVC --> VOLUME
    SRVC -->|"key never leaves the server"| EXTAI
```

## 8.3 Component responsibilities

| Component | Responsibility | Explicitly **not** responsible for |
|---|---|---|
| **Next.js frontend** | Rendering, client-side draft state, portion-scaling UX, route guarding | Business rules, AI calls, persistence, authorisation decisions |
| **`lib/api-client.ts`** | The single place that calls `fetch`; error normalisation; token attachment | Feature logic |
| **`proxy.ts`** | First-line route protection — checks cookie *existence* before a protected page renders | Validating the token (it cannot) |
| **nginx** | TLS termination via Railway edge, static files, request-body spooling, reverse proxy to php-fpm | Application logic |
| **php-fpm** | Executing PHP | Routing |
| **Laravel middleware** | CORS, Sanctum auth, API-key auth, rate limiting | — |
| **Controllers** | HTTP shape — parse, delegate, respond | Business calculation |
| **Policies** | Ownership authorisation | Authentication |
| **Services** | All business logic | HTTP concerns |
| **API Resources** | Response serialisation; **suppressing secrets** | — |
| **MySQL** | Persistence, plus cache, sessions and queue backing | — |
| **Volume** | Meal photograph storage | — |
| **AI provider** | Vision, insight prose, food estimation, coach replies | Being trusted — **all output is re-validated** |

## 8.4 The AI abstraction layer

The single most important structural decision in the backend.

```mermaid
flowchart TB
    ENV["AI_PROVIDER<br/>one setting"]

    subgraph contracts ["Four contracts"]
        C1["MealVisionAnalyzer<br/>photo → items + macros"]
        C2["NutritionInsightGenerator<br/>week → prose"]
        C3["FoodNutritionEstimator<br/>foods → macros"]
        C4["NutritionCoach<br/>question + figures → answer"]
    end

    subgraph drivers ["Three driver sets"]
        A["Anthropic"]
        O["OpenAI"]
        F["Fake — offline"]
    end

    VALID["Shared validation<br/>schema · ranges · caps · units<br/>+ traceable-number check for insights<br/>+ clinical-overreach check for coach"]

    ENV --> contracts
    contracts --> drivers
    drivers --> VALID
    VALID -->|"contract violated"| ERR["502 AI_INVALID_RESPONSE"]
    VALID -->|"valid"| OK["Typed data object"]

    style VALID fill:#1e3a2e,stroke:#5a5
```

**Why this matters:** one `AI_PROVIDER` setting selects **all four** capabilities, so the system can never end up in the incoherent state of a real vision model paired with a fake nutrition table. Adding a provider means writing four classes and four lines in `AppServiceProvider::DRIVERS` — prompts, JSON schemas and response validation are shared, so every provider is held to an identical contract.

---

# 9. Technology Stack

## Backend

| Layer | Technology | Version | Purpose | Why it is used |
|---|---|---|---|---|
| Language | PHP | 8.2 | Runtime | Required by Laravel 12; enums used extensively |
| Framework | Laravel | 12.67 | REST API | Eloquent, policies, form requests, rate limiting built in |
| Auth | Laravel Sanctum | 4.3 | Bearer tokens | Stateless SPA/API token auth without OAuth overhead |
| AI SDK | `anthropic-ai/sdk` | 0.43 | Anthropic client | Official SDK |
| API docs | `darkaonline/l5-swagger` | 11.1 | Swagger UI | Served locally — docs work offline |
| OpenAPI | `zircote/swagger-php` | 6.7 | Spec from PHP attributes | Spec lives beside the code it documents |
| Images | PHP **GD** + **EXIF** | bundled | Decode, orient, downscale | No extra dependency; declared in `composer.json` and the Dockerfile |
| Database | MySQL | 8.x *(Railway managed)* | Persistence | — |
| Web server | nginx | Alpine pkg | HTTP front end | Production-grade; handles body spooling |
| Process mgr | supervisord | Alpine pkg | Runs nginx + php-fpm in one container | Railway runs one container per service |

## Frontend

| Layer | Technology | Version | Purpose | Why it is used |
|---|---|---|---|---|
| Framework | Next.js (App Router) | 16.3 | React framework | Server-side route guarding via `proxy.ts` |
| UI | React / React DOM | 19.2 | Component model | — |
| Language | TypeScript | 5.x (strict) | Type safety | Build fails on type errors |
| Styling | Tailwind CSS | 4.x | Utility CSS | — |
| Components | shadcn/ui (`base-nova`, Base UI) | 4.19 | Accessible primitives | Owned, not vendored from a CDN |
| Charts | Recharts | 3.10 | Analytics | — |
| Validation | Zod / React Hook Form | 4.4 / 7.86 | Form validation | Mirrors server-side rules |
| Theming | next-themes | 0.4 | Light/dark/system | — |
| Toasts | Sonner | 2.0 | Notifications | — |
| Dates | date-fns | 4.4 | Date maths | — |
| Icons | Lucide | 1.34 | Iconography | — |

## Infrastructure

| Layer | Technology | Purpose | Status |
|---|---|---|---|
| Hosting | **Railway** | Both services + MySQL | **[Confirmed]** — deployed 27 Aug 2026 |
| Backend build | **Docker** (`backend/Dockerfile`) | Pinned PHP + extension set | **[Confirmed]** |
| Frontend build | **Railpack** (Railway auto-detect) | `npm ci` → `npm run build` → `npm start` | **[Confirmed]** |
| Storage | Railway Volume at `/app/storage` | Meal photos | **[Confirmed]** — *required; not attached at time of writing* |
| Source control | GitHub | — | **[Confirmed]** |
| CI/CD | **None** | — | **[Confirmed]** No `.github/` directory exists. Railway auto-deploys on push to `main`. |
| Monitoring / APM | **None** | — | **[Confirmed]** |
| Error tracking | **None** | — | **[Confirmed]** No Sentry/Bugsnag |
| Product analytics | **None** | — | **[Confirmed]** |
| Email delivery | **None** | `MAIL_MAILER=log` | **[Confirmed]** |

---

# 10. Frontend Architecture

## 10.1 Project structure

```
frontend/
├── app/
│   ├── page.tsx                  Landing page (public)
│   ├── privacy/  terms/          Public legal pages
│   ├── (auth)/                   login · register · forgot-password
│   ├── onboarding/               Goal setup — post-registration
│   └── (app)/                    Authenticated shell: sidebar + bottom nav
│       ├── today/                Dashboard
│       ├── add-meal/             Capture → AI → review → save
│       ├── meals/[id]/edit/
│       ├── coach/  history/  analytics/  insights/
│       ├── goals/  developer/  settings/
├── components/
│   ├── ui/                       shadcn/ui primitives
│   ├── charts/                   Recharts wrappers, tooltip, sparkline
│   ├── coach/  meals/  dashboard/  history/  analytics/
│   ├── insights/  developer/  add-meal/  goals/  settings/
│   └── layout/  marketing/  auth/
├── lib/
│   ├── api-client.ts             The one place that calls fetch
│   ├── meal-draft.ts             Portion scaling + macro locks
│   ├── smart-plate.ts            Request shaping, applying a suggestion
│   ├── dates.ts                  Calendar-date helpers (LOCAL, not UTC)
│   ├── nutrition.ts              Macro palette + formatting
│   ├── coach.ts  env.ts  validations.ts  auth-storage.ts  navigation.ts
├── services/                     One typed module per API area (10 files)
├── hooks/                        use-auth · use-hydrated · use-media-query
├── types/
└── proxy.ts                      Server-side route protection
```

## 10.2 Routing

| Route | Group | Access | Purpose |
|---|---|---|---|
| `/` | — | Public | Marketing landing page |
| `/terms`, `/privacy` | — | Public | Legal |
| `/login`, `/register` | `(auth)` | Guest-only | Authentication |
| `/forgot-password` | `(auth)` | Guest-only | **See §26 — no backend endpoint exists** |
| `/onboarding` | — | Protected | Goal setup |
| `/today` | `(app)` | Protected | Dashboard |
| `/add-meal` | `(app)` | Protected | Capture (`?mode=manual` for manual entry) |
| `/meals/[id]/edit` | `(app)` | Protected | Edit a saved meal |
| `/coach`, `/history`, `/analytics`, `/insights`, `/goals`, `/developer`, `/settings` | `(app)` | Protected | Feature screens |

## 10.3 API communication

Strictly layered, with one enforced rule:

```
Page / Component
      ↓  (never calls fetch)
services/*.service.ts        typed, one module per API area
      ↓
lib/api-client.ts            THE ONLY fetch call site
      ↓
Laravel REST API
```

`lib/api-client.ts` exports an `ApiError` class exposing `status`, `errors`, `payload`, plus derived helpers `isNetworkError` (status 0), `isValidation` (422), `isUnauthenticated` (401) and `retryable`.

> **Diagnostic note:** because `isNetworkError` is `status === 0`, a response that arrives **without CORS headers** is indistinguishable from an unreachable server, and surfaces to the user as *"Could not reach the NutriLens API."* This was the observed symptom of an nginx-level 500 during deployment — see [§17](#17-error-handling-and-edge-cases).

## 10.4 State management

**[Confirmed]** No global state library (no Redux, Zustand or Jotai). State is:

| Kind | Mechanism |
|---|---|
| Server data | Fetched per screen through `services/` |
| Auth token | `nutrilens_token` cookie via `auth-storage.ts` |
| Meal draft | Local component state driven by `lib/meal-draft.ts` |
| Theme | `next-themes` |
| Forms | React Hook Form + Zod |

## 10.5 Authentication handling — three layers

```mermaid
flowchart LR
    A["proxy.ts<br/>server, pre-render"] -->|"cookie exists?"| B["RequireAuth<br/>client"]
    B -->|"token still valid?"| C["Laravel<br/>final authority"]
    C -->|"401"| D["clearToken → /login"]
```

`proxy.ts` checks only whether the cookie **exists** — it cannot know whether the token is still valid. Laravel is always the final authority.

## 10.6 Design conventions

| Convention | Rationale |
|---|---|
| `lib/dates.ts` parses `YYYY-MM-DD` as **local** midnight | `new Date("2026-08-25")` is UTC and renders as the 24th in the Americas |
| Every macro has one fixed colour everywhere, always paired with a text label | Identity never rests on colour alone (accessibility) |
| Charts are **one series each** | Four macros = four charts, never four lines on one axis |
| Feature code never calls `fetch` | One place to change auth, error handling or base URL |

---

# 11. Backend Architecture

## 11.1 Directory structure

```
backend/app/
├── Enums/                 ActivityLevel · AnalysisStatus · BiologicalSex · ChatRole
│                          GoalSource · GoalType · MealType · MealSource
│                          MealStatus · PortionUnit
├── Http/
│   ├── Controllers/Api/   Auth · Dashboard · Meal · MealAnalysis · MealImage
│   │   │                  NutritionGoal · GoalCalculator · History · Analytics
│   │   │                  Streak · WeeklyInsight · AiCoach · ApiKey · Onboarding
│   │   │                  User · SmartPlate
│   │   └── V1/            PartnerNutrition · PartnerStatus
│   ├── Middleware/        AuthenticateApiKey
│   ├── Requests/          One Form Request per write, per area
│   └── Resources/         Meal · MealItem · NutritionGoal · User · WeeklyInsight
│                          ApiKey · AiConversation · AiChatMessage
├── Models/                User · Meal · MealItem · MealImage · NutritionGoal
│                          WeeklyInsight · ApiKey · AiConversation · AiChatMessage
├── OpenApi/               ApiDocumentation — spec root + shared schemas
├── Policies/              MealPolicy · NutritionGoalPolicy · AiConversationPolicy
├── Providers/             AppServiceProvider (AI drivers) · RateLimitServiceProvider
├── Services/
│   ├── AI/
│   │   ├── Contracts/     MealVisionAnalyzer · NutritionInsightGenerator
│   │   │                  FoodNutritionEstimator · NutritionCoach
│   │   ├── Providers/     {Anthropic, OpenAi, Fake} × the four contracts
│   │   ├── MealAnalysisService  + MealAnalysisPrompt + MealImagePreparer
│   │   ├── WeeklyInsightService + WeeklyInsightPrompt
│   │   ├── FoodEstimationService + FoodEstimationPrompt
│   │   ├── CoachService + CoachContextService + CoachPrompt
│   │   ├── MealTipService       (rule-based — no AI call)
│   │   ├── Data/                Typed DTOs
│   │   └── Exceptions/          AiException hierarchy (status + user message)
│   ├── Analytics/         DailyNutritionAggregator · AnalyticsService · StreakService
│   ├── Nutrition/         SmartPlateService · MealFitScore · PlateOptimizer
│   │                      FoodNutritionTable · Data/
│   ├── Goals/             GoalCalculatorService · GoalEstimate
│   └── MealService · NutritionGoalService · ApiKeyService
└── Support/               PartnerApiResponse · PartnerExceptionRenderer
```

## 11.2 Request lifecycle

```mermaid
flowchart TD
    R["HTTP request"] --> CORS["HandleCors<br/>allowed_origins ← FRONTEND_URL"]
    CORS --> TP["TrustProxies at: '*'"]
    TP --> RT{"Route group?"}

    RT -->|"/api/v1/*"| AK["AuthenticateApiKey<br/>SHA-256 lookup"]
    AK --> ABIL{"Has ability?"}
    ABIL -->|No| E403["403 FORBIDDEN"]
    ABIL -->|Yes| THR1["throttle — bucketed by KEY"]
    THR1 --> PC["Partner controller"]
    PC --> PENV["PartnerApiResponse<br/>{success, data|error}"]

    RT -->|"/api/* authed"| SANC["auth:sanctum"]
    SANC --> THR2["throttle — bucketed by user"]
    THR2 --> FR["Form Request validation"]
    FR --> POL["Policy — ownership"]
    POL --> C["Controller → Service"]
    C --> RES["API Resource<br/>{data|message|errors}"]

    style AK fill:#2a2a4a,stroke:#88a
    style POL fill:#1e3a2e,stroke:#5a5
```

> **Middleware ordering [Confirmed]:** `bootstrap/app.php` explicitly prepends `AuthenticateApiKey` **before** `ThrottleRequests` in the priority list. Without this, Laravel's default ordering would run throttling first and every partner behind one NAT would share a single rate-limit budget.

## 11.3 Layer responsibilities

| Layer | Responsibility | Example |
|---|---|---|
| **Form Requests** | Validate and normalise input | `EstimateNutritionRequest` constrains `portion_unit` to the `PortionUnit` enum |
| **Policies** | Ownership authorisation only | `MealPolicy`, `AiConversationPolicy` |
| **Controllers** | HTTP shape — parse, delegate, respond | `MealAnalysisController::store` |
| **Services** | All business logic | `MealFitScore`, `GoalCalculatorService` |
| **Data objects** | Typed transfer between layers | `AnalyzedMeal`, `CoachContext`, `PlateScore` |
| **API Resources** | Serialisation; **secret suppression** | `ApiKeyResource` never emits `key_hash` |
| **Enums** | Domain vocabulary, DB-enforced | 10 enums; `::values()` feeds both migrations and validation |

## 11.4 Exception handling

Registered in `bootstrap/app.php`, in deliberate order:

1. **`shouldRenderJsonWhen`** — every `api/*` failure is JSON, never an HTML error page.
2. **`PartnerExceptionRenderer`** — registered **first**, because the first-party renderers' `api/*` guard would otherwise also match `api/v1/*`.
3. **First-party renderers** — `AiException` (503/502), `AuthenticationException` (401), `AuthorizationException` (403), `ModelNotFoundException` (404), `NotFoundHttpException` (404).

## 11.5 Background processing

| Mechanism | Configured | Used |
|---|---|---|
| Queue | `QUEUE_CONNECTION=database`; `jobs` tables migrated | **No `app/Jobs` classes exist** — nothing is queued |
| Scheduled tasks | `routes/console.php` present | **No scheduled tasks defined** |
| Notifications | — | **No `app/Notifications` or `app/Mail` classes exist** |

**[Confirmed]** All AI calls are **synchronous** and block a PHP worker for the duration. See [§26](#26-known-limitations) and [§27](#27-future-improvements).

---

# 12. Database Documentation

**Database name:** `nutrilens_db` locally; `railway` on Railway's managed MySQL.
**Migrations:** 18, all applied.

## 12.1 Entity Relationship Diagram

```mermaid
erDiagram
    USERS ||--o{ NUTRITION_GOALS : "has history of"
    USERS ||--o{ MEALS : logs
    USERS ||--o{ MEAL_IMAGES : uploads
    USERS ||--o{ API_KEYS : issues
    USERS ||--o{ WEEKLY_INSIGHTS : receives
    USERS ||--o{ AI_CONVERSATIONS : starts
    MEALS ||--o{ MEAL_ITEMS : contains
    MEALS ||--o{ MEAL_IMAGES : "photographed by"
    AI_CONVERSATIONS ||--o{ AI_CHAT_MESSAGES : contains

    USERS {
        bigint id PK
        string name
        string email UK
        string password
        string avatar_path
        string timezone "default UTC"
        timestamp onboarded_at
        tinyint age "nullable"
        smallint height_cm "nullable"
        decimal weight_kg "nullable"
        enum activity_level "nullable"
        enum biological_sex "nullable"
    }

    NUTRITION_GOALS {
        bigint id PK
        bigint user_id FK
        enum goal_type
        smallint calorie_target
        smallint protein_target
        smallint carb_target
        smallint fat_target
        boolean is_active "exactly one true"
        enum source "onboarding|manual|calculator"
        smallint estimated_maintenance_calories
        date effective_from
    }

    MEALS {
        bigint id PK
        bigint user_id FK
        string meal_name
        enum meal_type "breakfast|lunch|dinner|snack"
        enum source "ai_photo|manual"
        enum status "draft|logged"
        decimal ai_confidence
        string ai_provider
        string ai_model
        timestamp consumed_at
        date consumed_on "user LOCAL date"
        int total_calories "denormalised"
        decimal total_protein "denormalised"
        decimal total_carbs "denormalised"
        decimal total_fat "denormalised"
        text notes
        timestamp deleted_at "soft delete"
    }

    MEAL_ITEMS {
        bigint id PK
        bigint meal_id FK
        string name
        string brand
        decimal portion_amount
        string portion_unit
        decimal base_portion_amount "AI baseline"
        int base_calories "AI baseline"
        decimal base_protein "AI baseline"
        decimal base_carbs "AI baseline"
        decimal base_fat "AI baseline"
        int calories
        decimal protein
        decimal carbs
        decimal fat
        decimal confidence
        boolean is_ai_generated
        boolean was_edited
        json locked_macros
        smallint position
    }

    MEAL_IMAGES {
        bigint id PK
        bigint user_id FK
        bigint meal_id FK "nullable"
        string disk "default local"
        string path
        string mime_type
        bigint size_bytes
        smallint width
        smallint height
        enum analysis_status
        json analysis_payload "raw model response"
        text analysis_error
        timestamp analyzed_at
    }

    API_KEYS {
        bigint id PK
        bigint user_id FK
        string name
        string key_prefix "first 14 chars, clear"
        string key_hash UK "SHA-256 ONLY"
        json abilities
        timestamp last_used_at
        timestamp expires_at
        timestamp revoked_at
    }

    WEEKLY_INSIGHTS {
        bigint id PK
        bigint user_id FK
        date week_start "UK with user_id"
        date week_end
        string headline
        text summary
        json highlights
        json recommendations
        json comparison
        int meals_logged
        int days_logged
        tinyint days_close_to_target
        int avg_calories
        decimal avg_protein
        decimal avg_carbs
        decimal avg_fat
        decimal goal_adherence
        string ai_provider
        string ai_model
        string data_hash "reuse key"
    }

    AI_CONVERSATIONS {
        bigint id PK
        bigint user_id FK
        string title "from first question"
        timestamp last_message_at "denormalised"
        int message_count "denormalised"
    }

    AI_CHAT_MESSAGES {
        bigint id PK
        bigint conversation_id FK
        enum role "user|assistant"
        text content
        json suggestions
        string ai_provider
        string ai_model
    }
```

## 12.2 Entity reference

| Entity | Purpose | Important fields | Relationships |
|---|---|---|---|
| `users` | Accounts + optional body metrics for the calculator | `email` (unique), `timezone`, `onboarded_at`, `age`, `height_cm`, `weight_kg`, `activity_level`, `biological_sex` | Parent of everything |
| `nutrition_goals` | Targets **with full history** | `is_active` (exactly one true per user), `source`, `estimated_maintenance_calories` | `belongsTo users` |
| `meals` | A logged meal | `consumed_on` (local date), denormalised totals, AI provenance | `belongsTo users`; `hasMany meal_items`, `meal_images` |
| `meal_items` | One food per row | `base_*` (AI baseline for rescaling), `locked_macros`, `confidence`, `position` | `belongsTo meals` |
| `meal_images` | Uploaded photos | `disk`, `path`, `analysis_status`, `analysis_payload` | `belongsTo users`, `meals` (nullable) |
| `api_keys` | Partner keys | `key_hash` (SHA-256, **unique**), `key_prefix`, `abilities`, `revoked_at` | `belongsTo users` |
| `weekly_insights` | AI weekly summaries + their source aggregates | `data_hash` (reuse), unique `(user_id, week_start)` | `belongsTo users` |
| `ai_conversations` | One coach thread | `title`, `last_message_at`, `message_count` (both denormalised) | `belongsTo users`; `hasMany ai_chat_messages` |
| `ai_chat_messages` | One turn | `role`, `content`, `suggestions`, provider/model | `belongsTo ai_conversations` |
| `personal_access_tokens` | Sanctum tokens | — | Polymorphic to `users` |
| `password_reset_tokens`, `sessions` | Laravel defaults | — | — |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Cache + queue backing (`database` driver) | — | — |

## 12.3 Key design decisions

| Decision | Rationale |
|---|---|
| **Every user-owned table has `user_id` with `ON DELETE CASCADE`** | Deleting a user removes all their data atomically |
| **`ai_chat_messages` has NO `user_id`** | Ownership lives only on the conversation, so it cannot drift out of step |
| **Denormalised totals on `meals`** | Daily aggregation reads one row per meal instead of summing items |
| **`consumed_on` separate from `consumed_at`** | Enables fast index-backed daily lookups in the *user's local* calendar |
| **`base_*` columns on `meal_items`** | The AI baseline that portion rescaling works from — the mechanism that stops rounding compounding |
| **`locked_macros` as JSON** | Records which macros the user typed by hand |
| **`key_hash` unique-indexed** | Enables single-row lookup; makes a DB dump useless |
| **Soft deletes on `meals` only** | Meals are user content that may be recovered; nothing else is |

### Indexes

| Table | Index | Serves |
|---|---|---|
| `meals` | `(user_id, consumed_on)` | Dashboard, History, Analytics |
| `meals` | `(user_id, status)` | Draft filtering |
| `meal_items` | `(meal_id, position)` | Ordered item rendering |
| `meal_images` | `(user_id, analysis_status)` | Pending/failed lookups |
| `api_keys` | `key_hash` **unique** | Auth lookup on every partner request |
| `api_keys` | `(user_id, revoked_at)` | Key list |
| `weekly_insights` | `(user_id, week_start)` **unique** | One insight per week |
| `ai_conversations` | `(user_id, last_message_at)` | Thread list ordering |
| `ai_chat_messages` | `(conversation_id, id)` | Ordered replay |
| `nutrition_goals` | `(user_id, is_active)` | Active-goal lookup |

---

# 13. API Documentation

**Base URL (production):** `https://nutrilensclaudeai-production.up.railway.app`
**Interactive docs:** `/api/documentation` · **OpenAPI JSON:** `/docs`

Two audiences, two schemes, two envelopes:

| | First-party | Partner |
|---|---|---|
| Path | `/api/*` | `/api/v1/*` |
| Auth | Sanctum personal access token | Hashed API key (`nl_live_…`) |
| Header | `Authorization: Bearer <token>` | `Authorization: Bearer <key>` (or `X-API-Key`) |
| Success | `{ data }` / `{ message }` | `{ success: true, data }` |
| Failure | `{ message, errors }` | `{ success: false, error: { code, message, details } }` |
| Versioned | No | **Yes** — partners cannot be redeployed alongside the API |

> The two schemes **cannot be crossed**: an API key is rejected by first-party endpoints, and a session token is rejected by `/api/v1/*`. Both directions are covered by tests.

## 13.1 Partner API

### `GET /api/v1/ping`

| | |
|---|---|
| **Purpose** | Verify a key and read its limits. Costs nothing — no AI call. |
| **Auth** | API key |
| **Rate limit** | 120/min per key |

```bash
curl https://nutrilensclaudeai-production.up.railway.app/api/v1/ping \
  -H "Authorization: Bearer nl_live_YOUR_KEY_HERE"
```

### `POST /api/v1/nutrition/analyze`

| | |
|---|---|
| **Purpose** | Multipart image → nutrition analysis |
| **Auth** | API key with ability `nutrition:analyze` |
| **Content-Type** | `multipart/form-data` |
| **Rate limit** | **10/min, 250/day per key** |

**Request parameters**

| Field | Type | Required | Rules |
|---|---|---|---|
| `image` | file | Yes | JPEG/PNG/WebP · ≤ 12 MB · ≥ 64×64 · ≤ 12000×12000 |
| `reference` | string | No | ≤ 120 chars; echoed back, never influences the model |

```bash
curl -X POST https://nutrilensclaudeai-production.up.railway.app/api/v1/nutrition/analyze \
  -H "Authorization: Bearer nl_live_YOUR_KEY_HERE" \
  -F "image=@/path/to/meal.jpg" \
  -F "reference=order-12345"
```

### `POST /api/v1/nutrition/estimate`

| | |
|---|---|
| **Purpose** | Structured foods → nutrition estimate |
| **Auth** | API key with ability `nutrition:estimate` |
| **Content-Type** | `application/json` |
| **Rate limit** | **30/min, 1000/day per key** |

**Validation rules**

| Field | Type | Required | Rules |
|---|---|---|---|
| `meal_name` | string | No | ≤ 120 chars |
| `items` | array | **Yes** | 1–20 items |
| `items[].name` | string | **Yes** | 2–120 chars |
| `items[].brand` | string | No | ≤ 120 chars |
| `items[].portion_amount` | numeric | **Yes** | > 0, ≤ 10000 |
| `items[].portion_unit` | string | **Yes** | Must be one of the 12 accepted units |

**Accepted `portion_unit` values:**
`g` · `ml` · `oz` · `fl oz` · `cup` · `tbsp` · `tsp` · `slice` · `piece` · `serving` · `bowl` · `plate`

```bash
curl -X POST https://nutrilensclaudeai-production.up.railway.app/api/v1/nutrition/estimate \
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

**Example success response**

```json
{
  "success": true,
  "data": {
    "meal_name": "Post-gym lunch",
    "confidence": 0.68,
    "totals": { "calories": 611, "protein": 54.3, "carbs": 74.2, "fat": 8.4 },
    "items": [
      { "name": "Chicken breast", "portion_amount": 150, "portion_unit": "g",
        "calories": 248, "protein": 46.5, "carbs": 0, "fat": 5.4, "confidence": 0.86 },
      { "name": "Brown rice", "portion_amount": 1, "portion_unit": "cup",
        "calories": 295, "protein": 6.5, "carbs": 61.4, "fat": 2.4, "confidence": 0.68 },
      { "name": "Broccoli", "portion_amount": 90, "portion_unit": "g",
        "calories": 32, "protein": 2.2, "carbs": 6.5, "fat": 0.4, "confidence": 0.86 }
    ],
    "notes": null,
    "model": { "provider": "fake", "name": "nutrilens-fake-nutrition-table" },
    "disclaimer": "All values are estimates and are not medical or nutritional advice."
  }
}
```

**Guarantees:** `totals` always equals the sum of `items`. Portions are echoed back **exactly as sent** — unit conversion is deliberately not performed on the caller's behalf.

### Partner error codes

**Branch on `error.code`, never on `error.message`.**

| Code | HTTP | Meaning |
|---|---|---|
| `MISSING_API_KEY` | 401 | No key on the request |
| `INVALID_API_KEY` | 401 | Key not recognised |
| `REVOKED_API_KEY` | 401 | Key revoked by its owner |
| `EXPIRED_API_KEY` | 401 | Key past its expiry |
| `FORBIDDEN` | 403 | Key lacks the ability for this endpoint |
| `VALIDATION_FAILED` | 422 | Body or file failed validation; `details` names the fields |
| `INVALID_IMAGE` | 422 | Image could not be decoded |
| `UNSUPPORTED_FILE_TYPE` | 422 | Not JPEG, PNG or WebP |
| `FILE_TOO_LARGE` | 413 | Over 12 MB |
| `NO_FOOD_DETECTED` | 422 | No identifiable food — retrying the same image will not help |
| `NOT_FOUND` | 404/405 | Unknown endpoint or method |
| `RATE_LIMITED` | 429 | Over the limit; see `Retry-After` |
| `AI_NOT_CONFIGURED` | 503 | No provider key on the server |
| `AI_UNAVAILABLE` | 503 | Provider unreachable, timed out, or rate-limited us. Retry. |
| `AI_INVALID_RESPONSE` | 502 | Model returned something unusable. Retry. |
| `INTERNAL_ERROR` | 500 | Unexpected fault. Detail is logged, **never returned**. |

> **CORS note [Confirmed]:** `/api/v1/*` is **not** reachable from a browser on an arbitrary origin, by design — only `FRONTEND_URL` is allowed. Call the partner API server-to-server. *An API key in browser JavaScript is a leaked API key.*

## 13.2 First-party API

| Method | Endpoint | Purpose | Auth |
|---|---|---|---|
| `GET` | `/api/health` | Liveness check | Public |
| `GET` | `/up` | Laravel health route | Public |
| `POST` | `/api/register` | Create account → token | Public · 10/min/IP |
| `POST` | `/api/login` | Sign in → token | Public · 10/min/IP |
| `POST` | `/api/logout` | Revoke **only** the current token | Sanctum |
| `GET` | `/api/user` | Current user + active goal | Sanctum |
| `PATCH` | `/api/user` | Update name / email / timezone | Sanctum |
| `GET` | `/api/nutrition-goals` | Active goal, or `null` | Sanctum |
| `PUT` | `/api/nutrition-goals` | Replace the active goal | Sanctum |
| `GET` | `/api/nutrition-goals/history` | Every goal the user has had | Sanctum |
| `GET` | `/api/nutrition-goals/calculator` | Calculator options + saved inputs | Sanctum |
| `POST` | `/api/nutrition-goals/calculate` | Estimate targets — **does not save** | Sanctum |
| `POST` | `/api/onboarding` | Save goal + mark onboarded | Sanctum |
| `GET` | `/api/dashboard/today` | Totals, meals, streak, trend, latest insight | Sanctum |
| `POST` | `/api/meals/analyze` | Photo → validated AI draft | Sanctum · 20/min |
| `GET` | `/api/meals` | Paginated list (`date`, `from`, `to`, `meal_type`) | Sanctum |
| `POST` | `/api/meals` | Save a meal | Sanctum |
| `GET` | `/api/meals/{id}` | One meal with items | Sanctum + Policy |
| `PUT` | `/api/meals/{id}` | Update name, type, notes, items | Sanctum + Policy |
| `DELETE` | `/api/meals/{id}` | **Soft-delete** a meal | Sanctum + Policy |
| `GET` | `/api/meals/{id}/tip` | NutriLens Tip — **no AI call** | Sanctum + Policy |
| `POST` | `/api/meals/smart-plate` | Score an **unsaved** meal — **no AI call**, stateless | Sanctum · 90/min |
| `GET` | `/api/meal-images/{id}/file` | Serve a photo | **Signed URL — not Sanctum** |
| `GET` | `/api/history/day` | Day totals + nearest logged days either side | Sanctum |
| `GET` | `/api/history/calendar` | Which days in a month have meals | Sanctum |
| `GET` | `/api/analytics` | Series + summary (`range=week\|month\|quarter\|year`) | Sanctum |
| `GET` | `/api/streak` | Current + longest streak, 14-day activity | Sanctum |
| `GET` | `/api/insights` | Stored summaries, newest first | Sanctum |
| `GET` | `/api/insights/current` | One week's aggregates + staleness | Sanctum |
| `POST` | `/api/insights/generate` | Generate or reuse | Sanctum · 10/min |
| `GET` | `/api/insights/{id}` | One stored summary | Sanctum |
| `GET` | `/api/ai-coach/context` | Live context — **no AI call** | Sanctum |
| `GET` | `/api/ai-coach/conversations` | Threads, newest activity first | Sanctum |
| `POST` | `/api/ai-coach/conversations` | Start a thread — **no AI call** | Sanctum · 30/min |
| `GET` | `/api/ai-coach/conversations/{id}` | Thread + messages | Sanctum + Policy |
| `DELETE` | `/api/ai-coach/conversations/{id}` | Delete thread + messages | Sanctum + Policy |
| `POST` | `/api/ai-coach/conversations/{id}/messages` | Ask a question | Sanctum + Policy · **15/min, 150/day** |
| `GET` | `/api/api-keys` | The caller's partner keys | Sanctum |
| `POST` | `/api/api-keys` | Create a key — plaintext returned **once** | Sanctum · 20/min |
| `DELETE` | `/api/api-keys/{id}` | Revoke a key | Sanctum + ownership |

### Why meal photos use a signed URL

`GET /api/meal-images/{id}/file` sits **deliberately outside `auth:sanctum`**. An HTML `<img>` tag cannot send a bearer token. Access is granted instead by a **short-lived signature** that appears only in the owner's own API responses. The signature is derived from `APP_KEY` — which is why `APP_KEY` must remain stable across deployments.

---

# 14. External Integrations

## 14.1 AI provider — the only true external dependency

| Aspect | Detail |
|---|---|
| **Services** | Anthropic (default model `claude-opus-5`) · OpenAI (default `gpt-4o`) · any OpenAI-compatible gateway via `AI_BASE_URL` |
| **Purpose** | Four capabilities: vision analysis, weekly insight prose, food estimation, coach replies |
| **Data sent** | A downscaled JPEG (vision) · aggregate numbers (insights) · food names + portions (estimation) · a privacy-bounded `CoachContext` (coach) |
| **Data received** | JSON conforming to a shared schema |
| **Auth** | `AI_API_KEY`, read from server environment only — **never sent to the frontend** |
| **When called** | `POST /api/meals/analyze` · `POST /api/insights/generate` · `POST /api/v1/nutrition/*` · `POST /api/ai-coach/.../messages` |
| **Timeouts** | `AI_TIMEOUT` 90 s; `AI_COACH_TIMEOUT` **45 s** — deliberately lower, because a user waiting on a chat reply gives up long before 90 s |
| **On failure** | Mapped to a typed `AiException` → `503 AI_UNAVAILABLE` / `503 AI_NOT_CONFIGURED` / `502 AI_INVALID_RESPONSE`. **Nothing crashes**; the UI offers manual entry and the uploaded photo is preserved. |

```mermaid
flowchart LR
    subgraph app ["NutriLens backend"]
        SVC["AI Service layer"]
        VAL["Server-side re-validation"]
        EXC["AiException hierarchy"]
    end
    SVC -->|"HTTPS + AI_API_KEY"| PROV["Anthropic / OpenAI"]
    PROV -->|"JSON"| VAL
    VAL -->|valid| OK["Typed DTO"]
    VAL -->|invalid| EXC
    PROV -.->|"timeout / 5xx / 429"| EXC
    EXC --> UI["503 / 502 + manual-entry fallback"]

    style VAL fill:#1e3a2e,stroke:#5a5
    style EXC fill:#4a2020,stroke:#a55
```

### The `fake` driver

**[Confirmed]** `AI_PROVIDER=fake` is **not a stub returning canned JSON**:

- Photo analysis returns realistic multi-item analyses **derived from the image bytes**, so the same photo always yields the same result.
- Structured estimation uses a real per-100 g table for **~70 common foods**, scaled to the requested portion. Unrecognised foods fall back to a generic average **and say so, with low confidence**.
- Weekly insights are written from actual aggregates, so every number is real.
- The coach classifies the question and answers from real figures, and states once per conversation that it is the offline coach — which the UI also badges.

The entire product, including the partner API and Swagger, is demonstrable this way at zero cost.

## 14.2 Integrations that do **not** exist

| Integration | Status |
|---|---|
| Payment / billing | **None** |
| Email / SMS / push delivery | **None** — `MAIL_MAILER=log` |
| OAuth / social login | **None** |
| Nutrition database (USDA FoodData Central, etc.) | **None** — see [§27](#27-future-improvements) |
| Object storage (S3) | **Configured but unused** — `FILESYSTEM_DISK=local` |
| Error tracking / APM | **None** |
| Product analytics | **None** |

---

# 15. Authentication and Authorization

## 15.1 Authentication — "Who are you?"

### First-party: Sanctum personal access tokens

```mermaid
sequenceDiagram
    actor U as User
    participant FE as Next.js
    participant API as Laravel
    participant DB as MySQL

    U->>FE: email + password
    FE->>API: POST /api/login
    API->>DB: Find user by email
    API->>API: Verify bcrypt hash
    alt Wrong email OR wrong password
        API-->>FE: 401 — IDENTICAL message either way
        Note over API,FE: Non-enumerating: an attacker<br/>cannot discover valid addresses
    else Valid
        API->>DB: Create personal_access_token
        API-->>FE: { user, token }
        FE->>FE: Store in nutrilens_token cookie
    end

    U->>FE: Visit /today
    FE->>FE: proxy.ts — does the cookie exist?
    FE->>API: GET /api/dashboard/today + Bearer
    API->>DB: Resolve token
    API-->>FE: 200 or 401
```

**Logout** deletes **only the token used for that request** — other devices stay signed in.

**Token expiry:** `SANCTUM_TOKEN_EXPIRATION` is empty ⇒ **tokens do not expire**. **[Confirmed]** See [§16](#16-security-considerations).

### Partner: hashed API keys

| Property | Value |
|---|---|
| Format | `nl_live_` + 40 chars from a 32-symbol alphabet |
| Entropy | **200 bits** of CSPRNG output |
| Storage | `hash('sha256', $key)` **only**, behind a unique index |
| Visible remnant | `key_prefix` — first 14 chars, stored in clear **solely** so two keys can be told apart in the UI |
| Transport | `Authorization: Bearer <key>`, or `X-API-Key` for clients that reserve the Authorization header |
| Lifecycle | Create → use → revoke. **Shown once at creation.** |

**Why SHA-256 rather than bcrypt:** the secret is high-entropy, so a slow hash buys nothing against brute force; and a salted hash could not be looked up without scanning every row.

## 15.2 Authorization — "What are you allowed to do?"

**There is no role system.** Authorisation is **ownership-based**, enforced by three policies:

| Policy | Protects | Rule |
|---|---|---|
| `MealPolicy` | `meals`, `meal_items`, `meal_images` | `meal.user_id === auth()->id()` |
| `NutritionGoalPolicy` | `nutrition_goals` | `goal.user_id === auth()->id()` |
| `AiConversationPolicy` | `ai_conversations`, `ai_chat_messages` | `conversation.user_id === auth()->id()` |

Accessing another user's resource returns **403** — or **404** where returning 403 would itself confirm the resource exists (Smart Plate does this deliberately, so another account's meal id cannot leak its macros).

### Partner abilities

| Ability | Grants |
|---|---|
| `nutrition:analyze` | `POST /v1/nutrition/analyze` |
| `nutrition:estimate` | `POST /v1/nutrition/estimate` |
| *(none required)* | `GET /v1/ping` |

Missing ability ⇒ `403 FORBIDDEN`.

## 15.3 Password management

| Capability | Status |
|---|---|
| Registration with password | **Built** — bcrypt, 12 rounds |
| Login | **Built** |
| Change password | **[Unknown]** — not visible in the routes |
| **Password reset / forgot password** | **NOT BUILT.** A `/forgot-password` page exists in the frontend and the `password_reset_tokens` table is migrated, but **there is no API endpoint**, no mailer, and no notification class. The page directs the user to make contact instead. **This is a functional gap — see [§26](#26-known-limitations).** |

---

# 16. Security Considerations

## 16.1 Implemented controls

| Area | Control |
|---|---|
| **Password storage** | bcrypt, `BCRYPT_ROUNDS=12` |
| **Login enumeration** | Non-enumerating — identical response for unknown email and wrong password; covered by tests |
| **Token scope** | Logout revokes only the current token |
| **API key storage** | SHA-256 digest only; unique index; a DB dump yields no usable keys |
| **Privilege separation** | A partner key can never mint another partner key (`/api/api-keys` is Sanctum-only) |
| **Scheme crossing** | API key rejected by first-party endpoints; session token rejected by `/api/v1/*`. Both directions tested. |
| **CORS** | Driven by `FRONTEND_URL`; no wildcard. Partner API therefore unreachable from arbitrary browser origins **by design**. |
| **Input validation** | A Form Request per write; `portion_unit` constrained to an enum rather than free text |
| **Mass assignment** | Covered by a dedicated security test suite |
| **Upload safety** | MIME + extension + dimension validation; path-traversal covered by tests |
| **Photo access** | Short-lived signed URLs, not public paths; private disk |
| **Cross-user reads** | Policies + a dedicated test suite |
| **Rate limiting** | Per key, per user, or per IP as appropriate — see the table below |
| **Error exposure** | `INTERNAL_ERROR` detail is logged, **never returned**. `api/*` always answers JSON, never a stack-trace page. |
| **Secret logging** | A rejected API key is logged as a near-miss **without the key itself** |
| **AI privacy boundary** | `CoachContext` carries nutrition figures, meal names and dates — no name, email, password hash, tokens, ids, photos or body metrics. Asserted by tests. |
| **Partner data isolation** | `/api/v1/*` reads and writes **no** user data; partner uploads are held in memory only |
| **AI output validation** | Schema, ranges, caps, unit normalisation; traceable-number check for insights; clinical-overreach check for coach replies |

## 16.2 Rate limits

| Scope | Limit | Bucketed by |
|---|---|---|
| `POST /api/v1/nutrition/analyze` | 10/min, 250/day | **Key** |
| `POST /api/v1/nutrition/estimate` | 30/min, 1000/day | **Key** |
| `GET /api/v1/ping` | 120/min | **Key** |
| `POST /api/register`, `/api/login` | 10/min | IP |
| `POST /api/meals/analyze` | 20/min | User |
| `POST /api/insights/generate` | 10/min | User |
| `POST /api/ai-coach/.../messages` | 15/min, 150/day | User |
| `POST /api/ai-coach/conversations` | 30/min | User |
| `POST /api/meals/smart-plate` | 90/min | User |
| `POST /api/api-keys` | 20/min | User |

A `429` carries `Retry-After`.

## 16.3 Known security weaknesses

| # | Weakness | Impact | Status |
|---|---|---|---|
| SEC-1 | **The token cookie is JS-readable** (not `httpOnly`), because the browser calls Laravel directly | XSS could exfiltrate a session token | **[Confirmed]** Documented; fix is a BFF proxy — only `lib/api-client.ts` would change |
| SEC-2 | **Tokens never expire** (`SANCTUM_TOKEN_EXPIRATION` empty) | A leaked token is valid indefinitely | **[Confirmed]** |
| SEC-3 | **No password reset** | A locked-out user cannot self-recover | **[Confirmed]** |
| SEC-4 | **`expires_at` on API keys is honoured but nothing sets it** | No key rotation policy is possible | **[Confirmed]** |
| SEC-5 | **No 2FA / MFA** | — | **[Confirmed]** |
| SEC-6 | **No audit log** | Security events are not reconstructable | **[Confirmed]** |
| SEC-7 | **Rate limiter uses the `database` cache store** | Correct for one host; incorrect across replicas | **[Confirmed]** |

**[Recommendation]** SEC-1, SEC-2 and SEC-3 should be addressed before any real user base is onboarded.

---

# 17. Error Handling and Edge Cases

## 17.1 Error handling philosophy

Three principles, visible throughout the codebase:

1. **Never lose the user's work.** The photo is stored *before* analysis, so an AI failure never costs the photograph.
2. **Never leave a partial record.** A failed coach call stores **no** user message, so retry cannot duplicate a question.
3. **Never leak internals.** `INTERNAL_ERROR` detail is logged, never returned.

## 17.2 Scenario matrix

| Scenario | What can go wrong | How the system handles it | What the user sees |
|---|---|---|---|
| **Form validation** | Missing/invalid fields | `422` with a per-field `errors` object; Zod mirrors rules client-side | Inline field errors |
| **Login failure** | Wrong email or password | Identical `401` either way | "Those credentials don't match" |
| **Expired/invalid token** | Token revoked or bad | `401`; `api-client` calls `clearToken()` | Redirect to `/login` |
| **Accessing another user's data** | Guessed id | `403` — or `404` where 403 would confirm existence | "Not found" / "Unauthorized" |
| **AI not configured** | No `AI_API_KEY` | `503 AI_NOT_CONFIGURED` | "Analysis unavailable" + **manual entry offered** |
| **AI unreachable / timeout** | Provider down, 429, network | `503 AI_UNAVAILABLE`, marked retryable | "Try again" + manual entry |
| **AI returns malformed output** | Schema/range violation | `502 AI_INVALID_RESPONSE`; **photo preserved** | "Analysis didn't work" + the photo is still there |
| **No food in the photo** | Non-food image | `422 NO_FOOD_DETECTED`, marked **non-retryable** | "Retrying won't help — choose another photo" |
| **Unsupported image format** | HEIC from iPhone | `UnsupportedImageException` → `422` | "This image format could not be processed" |
| **Image too large** | > 12 MB | `413 FILE_TOO_LARGE` | Size guidance |
| **Insight with untraceable number** | Model invented a figure | **Insight discarded, not shown** | Falls back to no insight |
| **Coach clinical overreach** | Model gives medical advice | Reply rejected | Error + retry |
| **Coach provider failure** | Timeout at 45 s | **No orphaned user message** | Retry, no duplicate |
| **Insufficient data for insight** | < 3 logged days | `insufficient_data` | Explains exactly what is missing |
| **Rate limit exceeded** | Too many requests | `429` + `Retry-After` | "Slow down" with the wait |
| **Database unreachable** | MySQL down at boot | Entrypoint retries **6×** at 5 s, then exits | Railway restarts the container |
| **Network failure in browser** | Offline | `ApiError` status `0`, `isNetworkError` | "Could not reach the NutriLens API" |

## 17.3 A worked example — the misleading CORS error

A real failure observed during deployment, worth recording because the symptom pointed away from the cause:

```mermaid
flowchart TD
    A["Browser POSTs a photo"] --> B["nginx spools the body to disk"]
    B --> C{"Can the worker<br/>write the temp file?"}
    C -->|No — EACCES| D["nginx returns 500 ITSELF"]
    D --> E["Laravel never runs"]
    E --> F["CORS middleware never runs"]
    F --> G["Response has NO<br/>Access-Control-Allow-Origin"]
    G --> H["Browser blocks it → fetch throws"]
    H --> I["api-client sets status 0"]
    I --> J["'Could not reach the NutriLens API'"]

    style D fill:#4a2020,stroke:#a55
    style J fill:#4a2020,stroke:#a55
```

**The lesson, generalised:** a `500` **with** CORS headers is a Laravel error. A `500` **without** them means PHP never ran, or died before the response could unwind back through the middleware stack. Only file uploads exhibited this, because nginx keeps small JSON bodies in memory and only spills larger ones to disk.

---

# 18. Screens and Modules

## 18.1 Landing page — `/`

| | |
|---|---|
| **Purpose** | Explain the product and drive registration |
| **Access** | Public |
| **Components** | Hero ("Snap your food. See your nutrition."), three feature blocks — *One photo, the whole plate* · *Targets that fit your goal* · *Weekly insights* — CTA |
| **Navigation** | → `/register`, `/login` |
| **APIs** | None |

## 18.2 Register / Login — `/register`, `/login`

| | |
|---|---|
| **Purpose** | Account creation and sign-in |
| **Access** | Guest-only — `proxy.ts` redirects authenticated users to `/today` |
| **Components** | Split layout: marketing panel left, form right; live password-strength indicators ("At least 8 characters", "Contains a letter", "Contains a number"); theme toggle |
| **Actions** | Create account · Sign in · Back to site |
| **APIs** | `POST /api/register`, `POST /api/login` |
| **Business logic** | Client-side Zod validation mirrors server rules; a network failure renders an inline banner rather than a toast |

## 18.3 Onboarding — `/onboarding`

| | |
|---|---|
| **Purpose** | Capture goal + targets before first use |
| **Components** | Goal-type selector; optional calculator (age, height, weight, activity, biological sex); target review |
| **APIs** | `GET /nutrition-goals/calculator`, `POST /nutrition-goals/calculate`, `POST /api/onboarding` |
| **Business logic** | Calculation **never saves**; saving is an explicit second step |

## 18.4 Today dashboard — `/today`

| | |
|---|---|
| **Purpose** | Single-glance view of the current day |
| **Components** | Calorie ring · macro bars · streak · 7-day trend sparkline · recent meals · latest weekly insight · quick-add |
| **Data** | Consumed vs. target for all four macros; current streak; last 7 days |
| **APIs** | `GET /api/dashboard/today` — **one request for the whole screen** |
| **Business logic** | New accounts get a distinct first-run experience rather than empty charts |

## 18.5 Add a meal — `/add-meal`

**The most complex screen in the product.**

| | |
|---|---|
| **Purpose** | Capture → analyse → review → save |
| **Components** | Camera/file capture · analysis progress · **review editor** (per-item cards with portion + four macro inputs) · **Smart Plate panel** · meal metadata (name, type, time, notes) |
| **Actions** | Take/choose photo · Try again · Choose another photo · **Enter this meal manually** · edit any value · apply/undo a Smart Plate suggestion · Save |
| **APIs** | `POST /api/meals/analyze` → `POST /api/meals/smart-plate` (on edit) → `POST /api/meals` → `GET /api/meals/{id}/tip` |
| **Business logic** | Portion rescaling from the AI baseline; macro locking; the draft lives **only in the browser** until Save |
| **Error state** | On AI failure: "Analysis didn't work — No connection to NutriLens", with **Try again**, **Choose another photo**, and **Enter this meal manually**. The stored photo is preserved throughout. |

## 18.6 Coach — `/coach`

| | |
|---|---|
| **Purpose** | Conversational answers grounded in the user's own data |
| **Components** | Progress strip (today's remaining figures) · quick actions · conversation list · message thread · composer · thinking state |
| **APIs** | `GET /ai-coach/context` · conversations CRUD · `POST .../messages` |
| **Business logic** | The thinking state **names what the backend is actually doing** rather than faking a progress bar. Replies arrive whole — there is no streaming. |

## 18.7 History — `/history`

| | |
|---|---|
| **Purpose** | Day-by-day browsing, editing, deletion |
| **Components** | Date stepper · date picker · calendar · day totals · meal list · meal detail sheet (with NutriLens Tip) |
| **APIs** | `GET /history/day`, `GET /history/calendar`, `GET/PUT/DELETE /meals/{id}` |
| **Business logic** | Previous/next **jump to the nearest day that actually has meals**. Deletion is soft. |

## 18.8 Analytics — `/analytics`

| | |
|---|---|
| **Purpose** | Longitudinal trends |
| **Components** | Range selector (7/30/90/365) · four single-series charts · summary cards · **table view** |
| **APIs** | `GET /api/analytics` |
| **Business logic** | Long ranges bucket by week. The table view exists so the data is readable without relying on colour. |

## 18.9 Insights — `/insights`

| | |
|---|---|
| **Purpose** | Weekly AI-written summaries |
| **Components** | Insight list · headline · summary · highlights · recommendations · week-over-week comparison · generate button |
| **APIs** | `GET /insights`, `GET /insights/current`, `POST /insights/generate`, `GET /insights/{id}` |
| **Business logic** | Requires 3 logged days; reuses a stored summary when `data_hash` is unchanged |

## 18.10 Goals — `/goals`

| | |
|---|---|
| **Purpose** | View and change targets |
| **Components** | Active goal · four target inputs · goal-type selector · calculator · **goal history** |
| **APIs** | `GET/PUT /nutrition-goals`, `GET /nutrition-goals/history`, calculator endpoints |

## 18.11 Developer — `/developer`

| | |
|---|---|
| **Purpose** | Partner API key management |
| **Components** | "Create a key" form (name field) · key list showing masked key, Created / Last used / Revoked · **API reference** link |
| **Actions** | Create · copy-once · revoke |
| **APIs** | `GET/POST /api-keys`, `DELETE /api-keys/{id}` |
| **Business logic** | *"The key itself is shown once, immediately after it is created, and is stored here only as a hash."* Naming guidance is explicit: *"Name it after wherever you plan to use it — the name is the only way to tell two keys apart later."* |

## 18.12 Settings — `/settings`

| | |
|---|---|
| **Purpose** | Profile, appearance, goals, keys, sign out |
| **Not available** | **Account deletion and email change** — deliberately absent rather than present and broken |
| **APIs** | `GET/PATCH /api/user`, `POST /api/logout` |

## 18.13 Navigation

| Viewport | Pattern |
|---|---|
| Desktop | Sidebar |
| Mobile | Bottom bar — **five tabs**: Today · History · **Add (FAB)** · Coach · Analytics · Insights |

**[Confirmed]** Every label clears its own width at 360 px; below ~340 px "Analytics" truncates rather than pushing the row sideways.

---

# 19. Data Flow

## 19.1 Canonical write path

```text
User input (browser)
      ↓  Zod / React Hook Form
Client-side validation
      ↓  services/*.service.ts
lib/api-client.ts  — attaches Bearer token
      ↓  HTTPS
nginx :8080 → php-fpm :9000
      ↓
CORS → TrustProxies → Sanctum / AuthenticateApiKey → Throttle
      ↓
Form Request  — authoritative validation
      ↓
Policy  — ownership check
      ↓
Controller  — HTTP shape only
      ↓
Service  — business logic
      ↓
┌─────────────┬──────────────┬─────────────┐
│  Eloquent   │  Volume      │  AI provider│
│  → MySQL    │  → photos    │  → HTTPS    │
└─────────────┴──────────────┴─────────────┘
      ↓
Server-side re-validation of AI output
      ↓
API Resource  — serialisation + secret suppression
      ↓  JSON
lib/api-client.ts  — normalises errors
      ↓
Component state → render
```

## 19.2 Photo analysis — detailed data flow

```mermaid
flowchart TD
    A["Raw photo — up to 12 MB"] --> B["Validate: MIME, size, dimensions"]
    B --> C["Store to volume<br/>storage/app/private/meals/{user_id}/{ulid}.jpg"]
    C --> D["meal_images row — processing"]
    D --> E["GD: imagecreatefromstring"]
    E --> F["EXIF: auto-orient"]
    F --> G["imagescale → long edge 1568px"]
    G --> H["imagejpeg → quality 82"]
    H --> I["Send to provider"]
    I --> J["Raw JSON"]
    J --> K{"Schema · ranges ·<br/>≤12 items · units"}
    K -->|Fail| L["502 · status: failed"]
    K -->|Pass| M["AnalyzedMeal DTO"]
    M --> N["meal_images: completed<br/>+ raw payload stored"]
    N --> O["DRAFT to browser<br/>NOTHING in `meals` yet"]
    O --> P["User edits — client-side only"]
    P --> Q["POST /api/meals"]
    Q --> R["meals + meal_items<br/>in one transaction"]

    style C fill:#1e3a2e,stroke:#5a5
    style K fill:#2a2a4a,stroke:#88a
    style O fill:#3a3a1e,stroke:#aa5
```

**Why the photo is stored at step C, before analysis:** so that a failure at step K never costs the user their photograph. The error response carries the stored image back, and the user can save the meal manually.

## 19.3 Aggregation read path

```text
meals (denormalised totals, indexed on user_id + consumed_on)
      ↓
DailyNutritionAggregator     one row per day
      ↓
   ┌──────────────┬───────────────┬──────────────────┐
   │ Dashboard    │ AnalyticsSvc  │ StreakService    │
   │ (today)      │ (ranges)      │ (day presence)   │
   └──────────────┴───────────────┴──────────────────┘
      ↓                  ↓                  ↓
   /dashboard/today   /analytics         /streak
                          ↓
                    CoachContextService
                    (reuses both, never recomputes)
```

**[Confirmed]** `CoachContextService` reuses `AnalyticsService` and `StreakService` rather than recomputing — so the coach **cannot** quote a figure that disagrees with the Analytics screen.

---

# 20. Business Rules

| Rule ID | Description | Trigger | Condition | Result |
|---|---|---|---|---|
| **BR-01** | Exactly one active nutrition goal per user | Save a goal | A goal already active | Previous goal deactivated; history retained |
| **BR-02** | Portion changes rescale from the **AI baseline** | Portion input changes | `base_*` present | Macros = base × (new ÷ base portion) — never compounding from the current value |
| **BR-03** | A hand-typed macro is **locked** | Macro input edited | — | Added to `locked_macros`; excluded from all future rescaling |
| **BR-04** | Detected items are hard-capped | AI analysis | Items > `AI_MAX_ITEMS` (12) | Truncated |
| **BR-05** | Photos are downscaled before upload | AI analysis | Long edge > 1568 px | Resized — cost control, no accuracy loss |
| **BR-06** | The photo is stored **before** analysis | Photo upload | Always | AI failure never loses the photo |
| **BR-07** | Meals bucket by the user's **local** date | Meal save | Always | `consumed_on` stamped from the user's stored timezone |
| **BR-08** | Meal deletion is **soft** | Delete | Always | `deleted_at` set; excluded from all aggregates |
| **BR-09** | Weekly insight needs **3 logged days** | Generate | `days_logged < 3` | `insufficient_data` |
| **BR-10** | Week-over-week comparison needs 3 days in **both** weeks | Generate | Previous week < 3 days | Comparison omitted |
| **BR-11** | **Every number in an insight must trace to the user's aggregates** | Generate | A figure cannot be traced | **Insight discarded, not shown** |
| **BR-12** | Insights are reused when data is unchanged | Generate | `data_hash` matches | Stored summary returned; no AI call |
| **BR-13** | One insight per user per week | Generate | — | Unique `(user_id, week_start)` |
| **BR-14** | Coach context excludes all identity data | Coach message | Always | Nutrition figures, meal names and dates only |
| **BR-15** | A failed coach call stores nothing | Provider error | Always | No orphaned user message; retry cannot duplicate |
| **BR-16** | Coach replays **10 messages**, 1,200 chars each | Coach message | Thread longer | Older detail dropped; nutrition context always regenerated in full |
| **BR-17** | Coach "your week" = trailing 7 days incl. today | Coach message | Always | Labelled `last_7_days` — *not* the Mon–Sun week used by Insights |
| **BR-18** | API key shown exactly once | Create key | Always | Only the SHA-256 digest is stored |
| **BR-19** | A partner key cannot mint another key | `POST /api-keys` | API key presented | Rejected — Sanctum only |
| **BR-20** | Partner requests never touch user data | Any `/v1/*` | Always | Nothing read from or written to user tables; uploads in memory only |
| **BR-21** | Partner `totals` = sum of `items` | Partner response | Always | Guaranteed |
| **BR-22** | Portions echoed back exactly as sent | Partner response | Always | **No unit conversion performed** |
| **BR-23** | Rate limits bucket by **key**, not IP | Any `/v1/*` | Always | `AuthenticateApiKey` runs before `ThrottleRequests` |
| **BR-24** | Goal calculation does **not** save | `POST /calculate` | Always | Estimate only; saving is a separate `PUT` |
| **BR-25** | Calorie targets are clamped | Calculate | Result < 1200 or > 6000 | Clamped to the bound |
| **BR-26** | Deficit/surplus is **proportional**, not flat | Calculate | — | Scales with body size (see table below) |
| **BR-27** | Smart Plate suggestions are simulated before being offered | Draft edit | Simulation shows no improvement | Suggestion withheld |
| **BR-28** | An item with locked calories is never grown | Smart Plate | `locked_macros` contains calories | Excluded from increase suggestions — the cost would be invisible |
| **BR-29** | Nothing is added to an over-budget meal | Smart Plate | Meal materially over remaining calories | No "add food" suggestion |
| **BR-30** | Smart Plate can only add from 12 curated foods | Smart Plate | — | Chicken, tuna, white fish, prawns, eggs, Greek yoghurt, cottage cheese, paneer, tofu, lentils, protein shake, broccoli/salad |
| **BR-31** | A streak counts a day once | Streak | Multiple meals on a day | Counted once |
| **BR-32** | Login is non-enumerating | Login | Unknown email OR wrong password | Identical response |
| **BR-33** | Logout revokes only the current token | Logout | Always | Other devices unaffected |

## 20.1 Goal calculator — the full calculation

**Formula:** Mifflin-St Jeor (1990) → BMR → × activity multiplier → TDEE → proportional adjustment.

| Goal type | Calorie adjustment | Protein (g/kg) | Fat share of calories |
|---|---|---|---|
| Lose weight | **−20%** of maintenance | **2.0** — higher, to protect lean mass in a deficit | 28% |
| Maintain weight | 0% | 1.6 | 30% |
| Build muscle | **+10%** | 1.9 | 25% |
| Improve nutrition | 0% | 1.6 | 30% |

**Constants:** `MIN_CALORIES = 1200` · `MAX_CALORIES = 6000` · energy per gram — protein 4, carbs 4, fat 9.

**Macro derivation order:** protein is computed first from body weight, then fat from its calorie share, and carbohydrate takes the remainder. If protein + fat already exceed the calorie budget — possible for a heavy person on an aggressive deficit — **fat is trimmed first, because protein is protected**.

## 20.2 Meal Fit Score — the four weighted components

| Component | Weight | Scoring behaviour |
|---|---|---|
| **Protein** | **35%** | The heaviest weight, because protein is the macro people miss. **Self-calibrating**: a meal using 40% of the day's remaining energy is expected to deliver 40% of the remaining protein — so a light breakfast is not punished for being light, while a meal consuming the whole remaining budget *is* expected to close the protein gap, because after it there is no room left to. |
| **Calories** | 30% | Penalised **only for overshooting** what is left. A small meal is not a bad meal. |
| **Carbs** | 17.5% | Overshoot penalty on the same curve, scaled to the macro's own target |
| **Fat** | 17.5% | As carbs |

Named constants: `CALORIE_ZERO_POINT = 0.30` · `MACRO_ZERO_POINT = 0.35` · `MIN_ENERGY_SHARE = 0.15` · `PROTEIN_GAP_FLOOR = 1.0`.

> The overshoot denominator is the **daily** target, not the remaining amount — because "remaining" can be zero or negative, and being 200 kcal over means the same thing whether it happened at breakfast or at dinner.

**Scope limit [Confirmed]:** the score answers *"how well does this meal use what is left of today's targets"*. It knows nothing about fibre, micronutrients, sodium, or whether a meal is a good idea. It is a **fit measure, not a nutrition verdict**.

---

# 21. System States and Statuses

## 21.1 Meal status

```mermaid
stateDiagram-v2
    [*] --> draft : created but not committed
    [*] --> logged : saved directly
    draft --> logged : user saves
    logged --> logged : edited (PUT)
    logged --> deleted : soft delete
    deleted --> logged : restore (data retained)
    note right of draft
        In practice the review draft lives
        only in the browser — nothing is
        written until Save.
    end note
```

| Status | Meaning | Allowed transitions |
|---|---|---|
| `draft` | Created but not committed | → `logged` |
| `logged` | Counted in all totals and aggregates | → edited, → soft-deleted |
| *(soft deleted)* | `deleted_at` set; excluded from every aggregate | Restorable — the row is retained |

## 21.2 Meal image analysis status

```mermaid
stateDiagram-v2
    [*] --> pending
    pending --> processing : analysis begins
    processing --> completed : validated payload stored
    processing --> failed : provider error or validation failure
    failed --> [*] : photo retained; manual entry offered
    completed --> [*]
```

| Status | Meaning |
|---|---|
| `pending` | Uploaded, not yet analysed |
| `processing` | Sent to the provider |
| `completed` | Valid response stored in `analysis_payload` |
| `failed` | Error recorded in `analysis_error`; **the photo is retained** |

## 21.3 API key lifecycle

```mermaid
stateDiagram-v2
    [*] --> active : created — plaintext shown ONCE
    active --> active : used (last_used_at updated)
    active --> revoked : owner revokes
    active --> expired : expires_at passes
    revoked --> [*] : 401 REVOKED_API_KEY
    expired --> [*] : 401 EXPIRED_API_KEY
    note right of expired
        expires_at is HONOURED but
        nothing currently SETS it.
    end note
```

## 21.4 Nutrition goal lifecycle

| State | Meaning |
|---|---|
| `is_active = true` | The current targets — exactly one per user |
| `is_active = false` | Historical; retained permanently for auditability |

## 21.5 AI conversation lifecycle

```
created (title null) → first question → title derived from that question
       → message_count and last_message_at updated per turn
       → deleted (hard delete, cascades to ai_chat_messages)
```

---

# 22. Notifications and Communication

## Current state — **[Confirmed]**

**The system sends no notifications of any kind.**

| Channel | Status | Evidence |
|---|---|---|
| Email | **Not implemented** | `MAIL_MAILER=log`; no `app/Mail` or `app/Notifications` classes exist |
| SMS | **Not implemented** | No provider integration |
| Push | **Not implemented** | No service worker, no push subscription |
| In-app toast | **Implemented** | Sonner — transient UI feedback only, not persisted |

## What this means in practice

| Expected notification | Exists? | Consequence |
|---|---|---|
| Welcome email on registration | **No** | — |
| Email verification | **No** | `email_verified_at` exists but is never set |
| **Password reset email** | **No** | **Users cannot recover a lost password** — see [§26](#26-known-limitations) |
| Weekly insight ready | **No** | User must open the app to discover it |
| Streak-at-risk reminder | **No** | — |
| API key created / revoked alert | **No** | — |
| Rate-limit warning to partners | **No** | Only a `429` at the moment of breach |

**[Recommendation]** Password reset is the only one of these that is a **functional defect** rather than a missing enhancement. It requires: a mailer (`MAIL_MAILER=smtp` + credentials), a notification class, and two API endpoints. The `password_reset_tokens` table is already migrated.

---

# 23. Configuration and Environment

> **No real secret appears in this document.** All sensitive values are shown as placeholders.

## 23.1 Environments

| Environment | Status | Notes |
|---|---|---|
| **Local development** | **[Confirmed]** | XAMPP (PHP 8.2, MySQL); `php artisan serve` :8000 + `npm run dev` :3000 |
| **Production** | **[Confirmed]** | Railway, project `nutrilens`, environment `production` |
| **Staging** | **Does not exist** | **[Confirmed]** No separate staging environment |
| **Test** | **[Confirmed]** | In-memory SQLite; `tests/TestCase.php` **refuses to run against anything else** |

## 23.2 Backend environment variables

### Application

| Variable | Production value | Notes |
|---|---|---|
| `APP_NAME` | `NutriLens` | |
| `APP_ENV` | `production` | |
| `APP_KEY` | `base64:<PLACEHOLDER>` | **Signs meal-photo URLs.** Changing it invalidates every existing photo link. |
| `APP_DEBUG` | `false` | **Must stay false** — `true` renders `DB_PASSWORD` onto error pages |
| `APP_TIMEZONE` | `UTC` | |
| `APP_URL` | Backend public domain | Also the Swagger server URL |
| `FRONTEND_URL` | Frontend public domain | **Drives CORS.** Comma-separate for multiple origins. |

### Database

| Variable | Production value |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `${{MySQL-TAbX.MYSQLHOST}}` → Railway **private** domain |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | `railway` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | `${{MySQL-TAbX.MYSQLPASSWORD}}` — **reference, never a literal** |

> **Warning:** `config/database.php` defaults to **SQLite** when `DB_CONNECTION` is unset. On a container platform that means a file destroyed on every redeploy — the application appears to work, then silently loses every account.

### Sessions, cache, queue, logging

| Variable | Value | Notes |
|---|---|---|
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | All three back onto MySQL |
| `SESSION_DOMAIN` | **unset in production** | `localhost` locally; carried over it scopes the cookie to a domain the browser never matches |
| `SANCTUM_TOKEN_EXPIRATION` | *(empty)* | Empty ⇒ **non-expiring tokens** |
| `LOG_CHANNEL` | `stderr` | **Required on Railway.** `single` writes to a file inside the container. |
| `LOG_LEVEL` | `warning` | |

### AI

| Variable | Default | Purpose |
|---|---|---|
| `AI_PROVIDER` | `fake` | `fake` / `anthropic` / `openai` — selects **all four** capabilities |
| `AI_API_KEY` | *(empty)* | **Never sent to the frontend** |
| `AI_MODEL` | *(empty)* | Blank ⇒ `claude-opus-5` / `gpt-4o` |
| `AI_TIMEOUT` | `90` | Seconds |
| `AI_IMAGE_MAX_EDGE` | `1568` | Downscale target |
| `AI_MAX_ITEMS` | `12` | Hard cap on detected items |
| `AI_FAKE_DELAY_MS` | `1400` | Simulated latency for `fake` |
| `AI_INSIGHTS_MODEL` / `_MAX_TOKENS` / `_EFFORT` | — / `2000` / `low` | Weekly insights |
| `AI_ESTIMATION_MODEL` / `_MAX_TOKENS` / `_EFFORT` / `_MAX_ITEMS` | — / `4000` / `low` / `20` | Partner estimation |
| `AI_COACH_MODEL` / `_MAX_TOKENS` / `_EFFORT` / `_TIMEOUT` | — / `1200` / `low` / **`45`** | Coach |

### Swagger

| Variable | Production | Notes |
|---|---|---|
| `L5_SWAGGER_CONST_HOST` | Backend domain | **Cannot use `${APP_URL}`** — that interpolation is a `.env`-file feature and does not apply to real environment variables |
| `L5_SWAGGER_GENERATE_ALWAYS` | `false` | `true` regenerates per page load — wasteful in production |

## 23.3 Frontend environment variables

| Variable | Value | Notes |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | `https://<backend-domain>/api` | The **only** place the backend URL is defined. The `/api` suffix is required. |
| `NEXT_PUBLIC_APP_URL` | `https://<frontend-domain>` | Metadata / canonical links |

> **Critical:** `NEXT_PUBLIC_*` values are **inlined into the JavaScript bundle at build time**. Setting them on a running service changes nothing until a **full redeploy** — a restart reuses the existing bundle.

There is deliberately **no** `NEXT_PUBLIC_AI_*` variable and no separate API-docs variable: the frontend never talks to an AI provider, and the Swagger URL is derived from `NEXT_PUBLIC_API_URL` so the two cannot drift apart.

---

# 24. Deployment and Infrastructure

## 24.1 Platform

**[Confirmed]** Railway, project `nutrilens`, environment `production`, region EU West.

| Service | Builder | Root directory | Port |
|---|---|---|---|
| `backend` | **Dockerfile** | `backend` | **8080** (nginx) |
| `frontend` | Railpack (auto-detect) | `frontend` | 8080 (`next start`) |
| `MySQL-TAbX` | Railway managed | — | 3306 (private) |

Because neither application lives at the repository root — and the root has no manifest — **each service must have its Root Directory set explicitly**. Without it, the builder reports *"could not determine how to build the app"*. This is what Railway calls an *isolated monorepo*.

## 24.2 Backend container

```mermaid
flowchart TB
    subgraph img ["backend/Dockerfile — php:8.2-fpm-alpine"]
        E1["install-php-extensions<br/>gd · exif · pdo_mysql · zip · bcmath · opcache · pcntl"]
        E2["composer install --no-dev --optimize-autoloader"]
        E3["nginx + supervisor + gettext"]
        E4["ENV PORT=8080"]
    end
    subgraph boot ["docker/entrypoint.sh — every boot"]
        B1["envsubst → render nginx.conf with $PORT"]
        B2["Rebuild the storage tree<br/>(the volume mounts EMPTY)"]
        B3["php artisan storage:link --force"]
        B4["config:cache · route:cache · view:cache"]
        B5["migrate --force — retry 6× at 5s"]
        B6["l5-swagger:generate"]
        B7["chown -R www-data storage bootstrap/cache<br/>+ /var/lib/nginx"]
        B8["exec supervisord"]
    end
    img --> boot
    B8 --> RUN["nginx :8080 ⇄ php-fpm :9000"]
```

### Deployment lessons recorded in the Dockerfile

Three real failures encountered during the initial deployment, each now prevented in code:

| # | Failure | Root cause | Fix |
|---|---|---|---|
| 1 | **502 on every request** | `php:8.2-fpm-alpine` carries an inherited `EXPOSE 9000`; the platform targeted php-fpm's FastCGI socket, which does not speak HTTP | `ENV PORT=8080` declares nginx's port explicitly |
| 2 | **500 on every request** *(would have followed)* | `APP_KEY` pasted as `base64:base64:…`, decoding to 4 bytes instead of 32 | Corrected value; documented in the deploy guide |
| 3 | **500 on uploads only** | nginx workers run as `www-data`, but Alpine ships `/var/lib/nginx` owned by `nginx` — spooling a request body to disk failed with `EACCES`, and nginx answered 500 *itself*, so Laravel's CORS middleware never ran | Dockerfile creates and chowns the temp tree; nginx.conf declares the paths explicitly; entrypoint re-asserts ownership on boot |

Failure 3 is the instructive one: it presented in the browser as a **CORS error** on a correctly configured CORS setup, and affected uploads only — because nginx keeps small JSON bodies in memory and spills only larger ones to disk.

### Why the entrypoint rebuilds the storage tree

A Railway volume mounted at `/app/storage` arrives **empty** and hides the directory tree baked into the image. Without recreating `framework/{cache,sessions,views}`, `logs` and `app/{public,private}` on every boot, Laravel fails on its first write.

## 24.3 Build and deploy process

```mermaid
flowchart LR
    A["git push origin main"] --> B["GitHub"]
    B --> C{"Watch paths"}
    C -->|"/backend/**"| D["Docker build"]
    C -->|"/frontend/**"| E["Railpack build"]
    D --> F["entrypoint: migrate + cache"]
    E --> G["npm ci → npm run build → npm start"]
    F --> H["Health check /up"]
    G --> H
    H --> I["Live"]
```

**[Confirmed]** There is **no CI/CD pipeline** — no `.github/` directory, no test gate, no build gate. Railway deploys directly on push to `main`. Tests must be run manually.

## 24.4 Environment separation

**[Confirmed]** There is **one** deployed environment. Local development connects to a local XAMPP MySQL. There is no staging environment and no promotion process.

---

# 25. Monitoring and Logging

## 25.1 What exists

| Capability | Mechanism | Retention |
|---|---|---|
| Application logs | Laravel → `LOG_CHANNEL=stderr` → Railway deploy logs | Railway's default |
| nginx access log | `/dev/stdout` → Railway | Railway's default |
| nginx error log | `/dev/stderr` → Railway | Railway's default |
| PHP errors | `php-fpm --force-stderr` → Railway | Railway's default |
| Health endpoints | `/up` (Laravel) and `/api/health` | — |
| Container metrics | Railway Metrics tab | Railway's default |
| Interactive shell | Railway Console tab | — |

### Deliberate logging decisions

| Decision | Rationale |
|---|---|
| `LOG_CHANNEL=stderr`, not `single` | `single` writes to a file inside a disposable container — invisible where you would actually look |
| A rejected API key logs the **path and IP, never the key** | A near-miss is worth seeing; the secret is not worth writing to disk |
| `INTERNAL_ERROR` detail is logged but **never returned** | No internal detail reaches a caller |
| `Log::info` on successful analysis and partner calls | Records provider, model and item count for cost attribution |

## 25.2 What does not exist — **[Confirmed]**

| Capability | Status | Consequence |
|---|---|---|
| Error tracking (Sentry, Bugsnag) | **None** | Exceptions must be found by reading logs |
| APM / tracing | **None** | No latency breakdown; no slow-query visibility |
| Uptime monitoring / alerting | **None** | An outage is discovered by a user |
| Structured logging with a request id | **None** | A request cannot be correlated across the two applications |
| Log aggregation / search | **None** | Only Railway's log viewer |
| AI cost tracking | **None** | Provider spend is not attributable to a user or key |
| Partner usage reporting | **None** | Limits are enforced in the rate limiter but **not persisted** — usage is not billable |
| Product analytics | **None** | No retention or funnel data |

**[Recommendation]** The two highest-value additions are (1) error tracking, and (2) alerting on the `AI_UNAVAILABLE` / `AI_INVALID_RESPONSE` rate — those are the signals that a provider is degrading.

---

# 26. Known Limitations

## 26.1 Functional gaps

| # | Limitation | Impact |
|---|---|---|
| L-01 | **No password reset.** A `/forgot-password` page exists and `password_reset_tokens` is migrated, but there is **no API endpoint and no mailer** | **A locked-out user cannot recover their account.** The most serious functional gap. |
| L-02 | **No account deletion and no email change** | Deliberately absent rather than present and broken; may pose a GDPR concern |
| L-03 | **No email verification** | `email_verified_at` exists but is never set |
| L-04 | **No administrator role or admin panel** | No moderation, support tooling, or partner-usage reporting |

## 26.2 Data and correctness

| # | Limitation | Impact |
|---|---|---|
| L-05 | `meals.consumed_on` is **stamped at save time** from the stored timezone | Changing timezone later does not re-bucket historical meals |
| L-06 | Analytics compares past days against **current** targets | Historical comparison is not strictly fair. Goal history is retained, so a fairer version could be built. |
| L-07 | Smart Plate scores against **today's current** targets | Editing last week's meal scores it against today's goals |
| L-08 | Weekly insights need **3 logged days**; comparison needs 3 in both weeks | Below that, `insufficient_data` |

## 26.3 AI

| # | Limitation | Impact |
|---|---|---|
| L-09 | **Only the Anthropic *coach* driver has run against a live endpoint.** The Anthropic vision/insight/estimation drivers and **all** OpenAI drivers are built against the SDK and documented wire formats but not verified live | Live behaviour of those paths is unproven. The `fake` driver and every validation/error path *are* fully tested. |
| L-10 | **No streaming in the coach** | A reply arrives whole. Streaming needs SSE + a queue. |
| L-11 | The coach replays **10 messages**, 1,200 chars each | A long thread loses early detail. The nutrition context is regenerated in full every turn. |
| L-12 | "Your week" in the coach = trailing 7 days; Insights uses Mon–Sun | Two different windows in one product; the payload labels it `last_7_days` to mitigate |
| L-13 | The offline nutrition table covers **~70 foods** | Only affects `AI_PROVIDER=fake` |
| L-14 | **All AI calls are synchronous** and block a PHP worker | Throughput ceiling under load |

## 26.4 Smart Plate

| # | Limitation |
|---|---|
| L-15 | Makes **no AI call by choice** — prose is templated; it does not comment on food quality, cooking method, or anything beyond the four macros |
| L-16 | Can only **add** from a curated list of **12 foods** |
| L-17 | Assumes macros scale **linearly** with portion — right for a weight or volume, only as good as the original estimate for "1 plate" or "1 serving" |
| L-18 | **Undo is one level** per applied suggestion, does not survive leaving the screen, and clears itself if the user then edits by hand |
| L-19 | The score is a **fit measure, not a nutrition verdict** — it knows nothing about fibre, micronutrients or sodium |

## 26.5 Security

See [§16.3](#163-known-security-weaknesses): JS-readable token cookie (SEC-1), non-expiring tokens (SEC-2), no password reset (SEC-3), unused `expires_at` (SEC-4), no 2FA (SEC-5), no audit log (SEC-6), database-backed rate limiter (SEC-7).

## 26.6 Operations and scalability

| # | Limitation | Impact |
|---|---|---|
| L-20 | **No CI/CD** — no test gate before deploy | A broken commit reaches production |
| L-21 | **No staging environment** | Changes are validated in production |
| L-22 | **Cache and rate limits use the `database` driver** | Correct for one host, wrong for several |
| L-23 | **A Railway volume attaches to one instance** | The backend cannot be scaled horizontally without moving photos to S3 |
| L-24 | **No monitoring or alerting** | Outages are user-reported |
| L-25 | **Partner usage is not persisted** | Not reportable, not billable |
| L-26 | `php artisan test` and the rate limiter **share the cache store** | A manual `curl` session can start returning 429; fix with `php artisan cache:clear` |

## 26.7 UI

| # | Limitation |
|---|---|
| L-27 | **Two macro colours are close together** — carbs (amber) and fat (coral) sit just under the perceptual separation threshold, and amber is below 3:1 contrast on white. Mitigated rather than fixed: single-series charts, colour always paired with a text label, and a table view in Analytics. |
| L-28 | **No testing on real mobile hardware.** Layouts were audited for overflow, touch-target size and safe-area handling, but nothing was verified on a physical phone. |
| L-29 | Signed photo URLs **defeat Next.js image caching** — a fresh signature per response means `next/image` re-optimises the same photo on every page load |
| L-30 | Below ~340 px the "Analytics" bottom-bar label truncates |

---

# 27. Future Improvements

> Everything in this section **does not exist today**. It is separated from current functionality deliberately.

## 27.1 Current functionality — summary

| Area | Built today |
|---|---|
| Auth | Register, login, logout (per-token), Sanctum bearer tokens |
| Tracking | AI photo analysis, manual entry, review + correction, portion scaling, macro locks |
| Insight | Dashboard, history, analytics, streaks, weekly AI insights |
| Guidance | Smart Plate (deterministic), NutriLens Tip (deterministic), AI Coach |
| Goals | Manual targets + Mifflin-St Jeor calculator, full history |
| Partner | Versioned API, hashed keys, abilities, per-key limits, Swagger UI |
| Ops | Dockerised backend, Railway deployment, health checks |

## 27.2 Recommended improvements

### Priority 1 — functional defects

| # | Recommendation | Why |
|---|---|---|
| R-01 | **Build password reset** | Users cannot currently recover an account. Needs a mailer, a notification class, two endpoints. The table already exists. |
| R-02 | **Add account deletion** | Likely a GDPR obligation |
| R-03 | **Set up CI** — run the 269-test suite and the frontend build before deploy | A broken commit currently reaches production directly |

### Priority 2 — security

| # | Recommendation | Why |
|---|---|---|
| R-04 | **Move the token to an `httpOnly` cookie** via Next.js route handlers (a BFF) | Removes XSS token theft. Only `lib/api-client.ts` would change. |
| R-05 | **Set `SANCTUM_TOKEN_EXPIRATION`** | Bounds the value of a leaked token |
| R-06 | **Add optional key expiry and a rotate action** | `expires_at` is already honoured; nothing sets it |
| R-07 | **Add an audit log** for auth events and key lifecycle | Security events are currently unreconstructable |

### Priority 3 — scale and cost

| # | Recommendation | Why |
|---|---|---|
| R-08 | **Queue the AI calls** — `QUEUE_CONNECTION=database` is already configured | Photo analysis and insight generation block a PHP worker for seconds |
| R-09 | **Redis for cache and rate limits** | The database driver is fine for one host and wrong for several |
| R-10 | **S3 for meal photos** using the existing private-disk abstraction, plus a lifecycle rule | Enables horizontal scaling; `MealImageController` already reads `disk` per record, so **no code change** |
| R-11 | **Persist per-key usage counters** | Makes partner usage reportable and billable |
| R-12 | **A nutrition database in front of the model** (USDA FoodData Central or similar) for structured estimates | Cheaper, faster and more accurate than asking an LLM for a memorised number |

### Priority 4 — observability

| # | Recommendation |
|---|---|
| R-13 | Error tracking (Sentry or equivalent) |
| R-14 | **Structured logging with a request id across both applications** |
| R-15 | **Alerting on the `AI_UNAVAILABLE` / `AI_INVALID_RESPONSE` rate** — the leading indicator of provider degradation |
| R-16 | Product analytics to measure the retention hypothesis the product is built on |

### Priority 5 — product

| # | Recommendation |
|---|---|
| R-17 | Streaming coach replies (needs SSE + a queue) |
| R-18 | Compare historical days against the targets in force **on that day** — goal history is already retained |
| R-19 | Expand the offline food table beyond ~70 foods |
| R-20 | Re-step the carbs/fat colour pair — noting this ripples through the whole shipped design system |
| R-21 | Verify on real mobile hardware |
| R-22 | Stable photo URLs with the signature in a header, restoring `next/image` caching |

---

# 28. Glossary

## Technical terms

| Term | Meaning |
|---|---|
| **API** | Application Programming Interface |
| **BFF** | Backend For Frontend — a server-side layer that proxies API calls, enabling `httpOnly` cookies |
| **Bearer token** | A credential sent as `Authorization: Bearer <value>` |
| **CORS** | Cross-Origin Resource Sharing — the browser rule that decides which origins may call an API |
| **CSPRNG** | Cryptographically Secure Pseudo-Random Number Generator |
| **Eloquent** | Laravel's ORM |
| **EXIF** | Metadata embedded in a photo, including orientation |
| **FastCGI** | The protocol nginx uses to talk to php-fpm. **Does not speak HTTP.** |
| **GD** | PHP's bundled image manipulation library |
| **Nixpacks / Railpack** | Railway's automatic build systems |
| **ORM** | Object-Relational Mapper |
| **php-fpm** | PHP FastCGI Process Manager |
| **Policy** | A Laravel class holding authorisation rules for a model |
| **Sanctum** | Laravel's token authentication package |
| **Signed URL** | A URL carrying a cryptographic signature that grants time-limited access without a session |
| **Soft delete** | Marking a row deleted (`deleted_at`) without removing it |
| **supervisord** | A process manager that keeps several processes alive in one container |
| **Volume** | Persistent disk that survives container replacement |

## Domain terms

| Term | Meaning |
|---|---|
| **Macro / Macronutrient** | Protein, carbohydrate or fat |
| **BMR** | Basal Metabolic Rate — calories burned at complete rest |
| **TDEE** | Total Daily Energy Expenditure — BMR × activity multiplier |
| **Mifflin-St Jeor** | The 1990 BMR equation used by the goal calculator |
| **Portion unit** | One of 12 accepted units (`g`, `ml`, `oz`, `fl oz`, `cup`, `tbsp`, `tsp`, `slice`, `piece`, `serving`, `bowl`, `plate`) |

## NutriLens-specific terms

| Term | Meaning |
|---|---|
| **Draft** | An AI analysis result that has **not** been saved. Lives only in the browser. |
| **Baseline** (`base_*`) | The AI's original estimate for an item, from which all portion rescaling is computed |
| **Macro lock** | A macro the user typed by hand, excluded from future rescaling |
| **Smart Plate** | The pre-save panel scoring a draft against the day's remaining targets. **No AI call.** |
| **Meal Fit Score** | A 0–10 score from four weighted components. A *fit* measure, not a nutrition verdict. |
| **NutriLens Tip** | A one-line rule-based observation shown after saving. **No AI call.** |
| **AI Coach** | Conversational feature answering from the user's own logged data |
| **CoachContext** | The privacy-bounded object given to the model — nutrition figures, meal names and dates only |
| **Traceable number** | A figure in a generated insight that can be matched to the user's own aggregates. Untraceable numbers cause the insight to be **discarded**. |
| **Partner API** | The versioned public API at `/api/v1/*`, authenticated by `nl_live_` keys |
| **Ability** | A permission attached to an API key (`nutrition:analyze`, `nutrition:estimate`) |
| **`fake` driver** | A fully functional offline AI implementation — not a stub |
| **Days close to target** | An Analytics metric computed by a transparent, stated rule |

---

# 29. Missing Information / Assumptions / Questions

## 29.1 Business questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| BQ-1 | **Why was NutriLens built?** Commercial product, portfolio piece, client work, or technical assessment? | Determines whether Priority-1 recommendations are urgent or irrelevant | Product owner |
| BQ-2 | **Is there a real or intended user base?** | Password reset (L-01) is critical for real users and moot for a demo | Product owner |
| BQ-3 | **What is the revenue model?** No pricing, plan or billing code exists | Determines whether R-11 (usage metering) is needed | Product owner |
| BQ-4 | **Who are the intended partners?** | Shapes rate limits, SLA and abilities design | Product owner |
| BQ-5 | **What are the success metrics?** No analytics instrumentation exists | Nothing currently measures the retention hypothesis the product is built on | Product owner |
| BQ-6 | **Is there a support process?** No admin role or support tooling | A locked-out user has no path to help | Product owner |

## 29.2 Technical questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| TQ-1 | **Is `AI_PROVIDER=anthropic` intended for production?** It was set during deployment | Every analysis and coach reply then costs real money, uncapped | Owner / engineering |
| TQ-2 | **What is the expected AI spend, and is there a budget cap?** No cost tracking exists | Uncapped provider spend | Owner / finance |
| TQ-3 | **Have the untested drivers been exercised since?** (L-09) | Anthropic vision/insight/estimation and all OpenAI drivers are unverified live | Engineering |
| TQ-4 | **Is `password_reset_tokens` intended to be used?** The table is migrated but nothing writes to it | Confirms L-01 is a gap, not a deliberate omission | Engineering |
| TQ-5 | **Is a change-password endpoint present?** Not visible in the routes | Security posture | Engineering |
| TQ-6 | **What log retention does Railway provide on the current plan?** | Determines whether external log storage is needed | Engineering |

## 29.3 Architecture questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| AQ-1 | **Is horizontal scaling anticipated?** | The volume (L-23) and database-backed cache (L-22) both block it | Architecture |
| AQ-2 | **Is a staging environment planned?** | Changes are currently validated in production | Architecture |
| AQ-3 | **Is a mobile app planned?** The API is well-suited but no client exists | Affects auth design (token lifetime, refresh) | Product / architecture |
| AQ-4 | **Was the BFF pattern (R-04) considered and deferred, or not considered?** | Determines the effort estimate for SEC-1 | Architecture |
| AQ-5 | **Is the `jobs` infrastructure intended for use?** Tables are migrated; no job classes exist | Confirms R-08 is planned rather than abandoned | Engineering |

## 29.4 Database questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| DQ-1 | **What is the backup and restore policy?** Railway defaults are unconfirmed | Data loss risk | Engineering / owner |
| DQ-2 | **What is the data retention policy?** Soft-deleted meals are never purged | Storage growth; possible GDPR exposure | Legal / owner |
| DQ-3 | **Is the production database currently populated?** The README notes the local DB was emptied | Affects go-live readiness | Owner |
| DQ-4 | **Expected data volume per user?** | Informs index and partitioning strategy | Product |
| DQ-5 | **Are `meal_images.analysis_payload` blobs retained indefinitely?** Raw model responses are stored per analysis | Storage growth | Engineering |

## 29.5 API questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| APQ-1 | **What is the API versioning and deprecation policy?** `/v1/` exists; no policy is documented | Partners need notice periods | Product / engineering |
| APQ-2 | **Is there an intended partner SLA?** | Current limits are hard-coded, not tiered | Product |
| APQ-3 | **Should abilities be extended?** Only two exist today | Affects future endpoint design | Product |
| APQ-4 | **Are webhooks or async partner callbacks planned?** Everything is synchronous | Affects partner integration patterns | Product |

## 29.6 Security questions

| # | Missing information | Why it matters | Who should confirm |
|---|---|---|---|
| SQ-1 | **Has a security review or penetration test been performed?** | Unknown residual risk | Owner |
| SQ-2 | **What compliance regime applies (GDPR, HIPAA)?** The system stores body metrics and dietary data — potentially health data | Determines whether L-02 and DQ-2 are legal obligations | Legal |
| SQ-3 | **Was the non-expiring-token decision (SEC-2) deliberate?** | Determines whether R-05 is a fix or a change of policy | Owner / engineering |
| SQ-4 | **Is 2FA required for the intended user base?** | — | Owner |
| SQ-5 | **Have the credentials exposed during deployment been rotated?** An Anthropic API key and the MySQL root password were shared in a support conversation | **Live credential exposure** | Owner — **immediate** |
| SQ-6 | **Is `APP_KEY` backed up?** It signs every meal-photo URL | Losing it invalidates every existing photo link | Engineering |

## 29.7 Summary of assumptions made in this document

| # | Assumption | Section | Verification needed |
|---|---|---|---|
| A-1 | The business problem is nutrition-tracking abandonment caused by data-entry friction | [§3](#3-business-problem) | Inferred from the product's own framing and design decisions — **not stated anywhere in the repository** |
| A-2 | The target user is an individual consumer tracking personal nutrition | [§5](#5-user-roles-and-personas) | Consistent with the feature set; not explicitly documented |
| A-3 | The Partner API is intended as a revenue stream | [§4](#4-goals-and-objectives) | No pricing or billing code exists |
| A-4 | Expected business value is retention + partner revenue + differentiation through honesty | [§3](#3-business-problem) | Entirely inferred |

---

## Document control

| Field | Value |
|---|---|
| **Version** | 1.0 |
| **Date** | 27 August 2026 |
| **Basis** | Direct analysis of the source code, migrations, routes, configuration, deployed API responses, and UI screenshots |
| **Not based on** | Interviews, product requirement documents, or design specifications — none were available |
| **Next review** | On resolution of the Priority-1 items in [§27.2](#272-recommended-improvements) |
