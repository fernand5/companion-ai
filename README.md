# Companion AI — Adaptive AI Fitness Coach

An MVP of an AI-powered adaptive fitness coach: a Laravel API + Vue 3 SPA where the AI reasons over **structured fitness data** (MySQL), **long-term memory** (preferences/patterns), and **recent conversation** to give adaptive, explainable daily recommendations — not a generic chatbot that regenerates a workout from scratch every time.

```
Vue 3 SPA  →  Laravel API  →  MySQL (source of truth)
                            →  Memory Provider (preferences/patterns)
                            →  AI Tools (structured, authorized data access)
                                   ↓
                          FitnessContextBuilder (compact, selective context)
                                   ↓
                                 LLM (Gemini)
                                   ↓
                            Adaptive Coach reply
```

## Contents

- [Installation](#installation)
- [Environment variables](#environment-variables)
- [Database setup](#database-setup)
- [AI configuration](#ai-configuration)
- [Memory configuration](#memory-configuration)
- [Hermes evaluation](#hermes-evaluation)
- [Running the backend](#running-the-backend)
- [Running the frontend](#running-the-frontend)
- [Running tests](#running-tests)
- [Architecture](#architecture)
- [Architecture decisions](#architecture-decisions)
- [How persistent memory works](#how-persistent-memory-works)
- [How adaptive recommendations work](#how-adaptive-recommendations-work)
- [Demo data](#demo-data)

---

## Installation

Prerequisites: PHP 8.3+, Composer, Node 20+, MySQL 8+ (any local MySQL — Homebrew, Herd, Docker, etc. all work).

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Frontend
cd ../frontend
npm install
cp .env.example .env
```

## Environment variables

**`backend/.env`** (see `.env.example` for the full file):

| Variable | Purpose |
|---|---|
| `DB_*` | MySQL connection — see [Database setup](#database-setup) |
| `AI_PROVIDER` | AI provider to bind (`gemini` today) |
| `AI_API_KEY` | Your provider API key — required for any AI conversation to work |
| `AI_MODEL` | Model name, e.g. `gemini-3.6-flash` |
| `AI_DEBUG` | `true` to log full context/tool-call traces to `storage/logs/ai-debug-*.log` (dev only) |
| `MEMORY_PROVIDER` | `database` (working) or `hermes` (documented placeholder, throws if selected) |

**`frontend/.env`**:

| Variable | Purpose |
|---|---|
| `VITE_API_URL` | Base URL of the backend API, e.g. `http://localhost:8000/api` |

AI credentials live only in `backend/.env` and are never sent to the Vue app.

## Database setup

MySQL is the authoritative source of truth for all fitness data (profile, schedule, activity, workouts, weight, conversations) — memory is advisory only, never structured state.

```sql
CREATE DATABASE companion_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Point `backend/.env`'s `DB_*` variables at it, then:

```bash
cd backend
php artisan migrate
php artisan db:seed   # optional demo data, see "Demo data" below
```

## AI configuration

The app is not coupled to one LLM vendor. `App\Services\Ai\Contracts\AiProvider` is a small interface (`chat(messages, tools, options): AiResponse`); `App\Services\Ai\GeminiProvider` is the current implementation, selected via `AI_PROVIDER=gemini` and bound in `App\Providers\AiServiceProvider`. Adding another provider (Anthropic, OpenAI, ...) means writing one class implementing the interface and adding a case to that provider's `match`.

Get a Gemini API key from [Google AI Studio](https://aistudio.google.com/), set `AI_API_KEY` and `AI_MODEL` (default `gemini-3.6-flash`) in `backend/.env`. Without a key, the app still runs — the Coach page and dashboard recommendation show a clear "AI coach is not configured yet" message instead of crashing (see `ConversationController::sendMessage` / `DashboardController::index`).

## Memory configuration

`App\Services\Memory\MemoryProvider` (`remember()` / `recall()`) is the abstraction. `MEMORY_PROVIDER=database` (default) binds `DatabaseMemoryProvider`, a MySQL-backed implementation that ships working today — no vector database, per the MVP scope. `MEMORY_PROVIDER=hermes` binds `HermesMemoryProvider`, which is a **placeholder that throws** with a clear message; see the next section for why.

## Hermes evaluation

The spec asked us to evaluate [Hermes Agent](https://hermes-agent.nousresearch.com) (Nous Research) as an optional memory/agent component, without forcing it into the MVP if it complicates the architecture.

**Finding (researched via Hermes's own docs):** Hermes Agent is built as a **self-contained interactive agent** — a CLI plus 20+ chat-platform integrations (Telegram, Discord, Slack, etc.) — with memory kept in flat files (`MEMORY.md` / `USER.md`) and FTS5 full-text search. Its documented HTTP surface is an OpenAI-compatible `/v1/chat/completions` proxy for routing chat traffic *through* Hermes as a client. There is **no documented REST API for an external backend to call `remember(user, content)` / `recall(user, query)` against** — nothing suitable for embedding as a Laravel service.

**Conclusion:** Hermes is primarily useful as an interactive agent, not an embeddable backend memory service (exactly the case the spec anticipated). It is **not** integrated as a working provider in this MVP. The abstraction is ready for it: `HermesMemoryProvider` implements the `MemoryProvider` interface today and throws `MemoryProviderUnavailableException` with this explanation, so if Nous ships a proper memory API in the future, only that one class needs to change.

## Running the backend

```bash
cd backend
php artisan serve   # http://localhost:8000
```

## Running the frontend

```bash
cd frontend
npm run dev          # http://localhost:5173
```

Log in with the seeded demo account (`demo@example.com` / `password`) or register a new one.

## Running tests

```bash
# Backend — PHPUnit, DB via sqlite in-memory (fast, portable), the AI provider is
# always mocked/faked (see tests/Support/FakeAiProvider.php) — no real network calls.
cd backend
php artisan test
./vendor/bin/pint --test    # lint

# Frontend — Vitest + Vue Test Utils
cd frontend
npm run test:unit
npm run lint

# Production build (type-checks with vue-tsc, then builds)
npm run build
```

## Architecture

```
backend/app/
 ├── Http/{Controllers,Requests,Resources}/   Thin controllers; validation in Form Requests
 ├── Models/                                   Eloquent models + relationships
 ├── Services/Ai/                              AiProvider + GeminiProvider, FitnessContextBuilder,
 │                                              SystemPromptBuilder, AiCoachService (the chat lifecycle)
 ├── Services/Fitness/                         ScheduleService, ActivityService, WorkoutService,
 │                                              WeightService, ProgressService — query/aggregation
 │                                              logic shared by controllers AND AI tools
 ├── Services/Memory/                          MemoryProvider + DatabaseMemoryProvider/HermesMemoryProvider
 ├── Services/Tools/                           AiTool interface, ToolRegistry, one class per tool
 └── Providers/                                Config-driven bindings (AiServiceProvider,
                                                MemoryServiceProvider, ToolServiceProvider)

frontend/src/
 ├── views/          One per nav destination: Dashboard, Coach, Activity, Progress, Profile, Login, Register
 ├── components/      ChatBubble/ChatInput/QuickActions, ActivityQuickLogForm/WorkoutLogForm/WeightLogForm,
 │                     ScheduleEditor, ProgressChart, StatCard, AppShell (nav)
 ├── stores/          Pinia: auth, chat, profile, activity, dashboard — no business logic in components
 ├── services/        One axios-based client module per API resource
 └── types/           TS interfaces mirroring the API Resources
```

Database tables: `users`, `fitness_profiles`, `training_schedules`, `activity_logs`, `workout_sessions` → `workout_exercises`, `conversations` → `messages`, `fitness_memories`. `activity_logs` is a single unified timeline (`type` + a `metadata` JSON column for type-specific fields — steps count, treadmill intervals, sport name, recovery energy/soreness/sleep, or body weight) so the dashboard, progress charts, and the AI's "recent activity" queries all read one table. `workout_sessions`/`workout_exercises` stay separately normalized for strength-training exercise breakdown; logging a workout also writes a mirrored `activity_logs` row (`type=strength`) so it shows up in the unified timeline too.

## Architecture decisions

- **Auth:** Laravel Sanctum in **API token mode** (Bearer tokens), not cookie/SPA mode — the simplest correct choice for a Vite dev server on a different port than the API, no CORS/cookie-domain configuration needed.
- **AI provider:** Gemini (`gemini-3.6-flash`), function-calling via the `generateContent` REST endpoint. Gemini's "thinking" models require echoing back a `thoughtSignature` value attached to each `functionCall` part on the next turn, or the API rejects the request — `GeminiProvider` round-trips this via `ToolCallRequest::$meta`, an opaque bag other providers can ignore.
- **Memory writing is explicit, not a hidden classifier.** The AI has a `remember_preference` tool it calls only when it recognizes a durable pattern/preference (e.g. "prefers short workouts on football days"); one-off facts ("did 6200 steps") go through `log_activity`/`log_workout` into structured tables, never into memory. This keeps "why did it remember this" answerable and matches the spec's explicit distinction between structured state and memory.
- **Memory recall** is a lightweight keyword-overlap score with a recency backfill (`DatabaseMemoryProvider::recall`) — no vector database, per the MVP scope, while still surfacing the user's active preferences even when the current message shares no literal words with a stored memory.
- **Context is selective, not a database dump** (`FitnessContextBuilder`): profile, ~10 days of recent activity, next 5 days of schedule, today's activity, the last 10 conversation messages, and the top memories relevant to the current message. Tools remain available for the model to drill into anything not included.
- **Every AI tool re-derives ownership from the authenticated `User` the container gives it** — arguments from the model are never trusted to identify *whose* data to touch (see `tests/Feature/Ai/ToolAuthorizationTest.php`).
- **A failed AI call never leaves an orphaned user message.** `AiCoachService::respond()` saves the user's message, then deletes it again if the provider call throws, so a "not configured" or network failure doesn't leave a question with no answer sitting in the conversation.
- **Streaming:** not implemented for this MVP (spec says "if practical") — the chat UI shows a "Coach is thinking…" loading state instead. Documented as the one explicit scope deviation.
- **Dashboard recommendation:** a short, standalone AI call (context built, no tool loop) that is *not* persisted as a conversation message — it's a widget, not a chat turn.

## How persistent memory works

Nothing about the underlying LLM is fine-tuned per user. "Learning" is implemented as `store → retrieve → reason → adapt`:

1. **Store** — structured facts (a logged workout, updated weight, a schedule change) go straight into MySQL via the relevant tool/endpoint. Durable preferences/patterns the AI notices go into `fitness_memories` via the `remember_preference` tool.
2. **Retrieve** — every chat turn, `FitnessContextBuilder` pulls a compact snapshot: profile, recent activity, upcoming schedule, today's activity, and the memories most relevant to the current message (`MemoryProvider::recall`).
3. **Reason** — the compact context plus the last 10 messages of the current conversation go to the LLM as a system message; the model can call tools for anything not already included.
4. **Adapt** — the model's reply reasons over that context (e.g. "you played football yesterday and have another match tomorrow, so today is upper body/mobility only").

## How adaptive recommendations work

The system prompt (`SystemPromptBuilder`) instructs the model to distinguish **planned** (schedule) vs. **actual** (logged/confirmed) vs. **recommended** activity, never claim something was completed without confirmation, and to briefly explain its reasoning. Combined with the context above, this is what produces answers like:

> "Today, I recommend a light 25-minute upper-body dumbbell and core session, followed by gentle stretching. **Why:** You played a 90-minute football match yesterday and have another match tomorrow evening, so keeping intense work off your legs today ensures you recover fully while staying on track with your fat-loss and muscle-retention goals."

— generated live against the seeded demo data in this repo, not a canned response.

## Demo data

`php artisan db:seed` (or `migrate:fresh --seed`) creates `demo@example.com` / `password` with:

- A fitness profile (goal, equipment, preferred duration) and a Tue/Thu football schedule
- The last 3 days of activity: a strength workout ("10 rounds of Cindy"), a treadmill session, and a football match — deliberately arranged so asking the coach "What should I do today?" demonstrates real adaptive reasoning (recent hard training + an upcoming match)
- A short example conversation walking through that history
- A few example long-term memories (training preferences)
- Weight history showing a small downward trend

Today is left open on purpose — log in, open **Coach**, and ask "What should I do today?" to see the adaptive recommendation generated live.
