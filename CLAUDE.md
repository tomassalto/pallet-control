# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Pallet Control** is a warehouse/logistics management system for tracking pallets, orders, customers, and products. It has three components:

- `pallet-backend/` — Laravel 12 REST API
- `pallet-frontend/` — React 19 + Vite PWA (builds into `pallet-backend/public/app/`)
- `whatsapp-bot/` — Node.js bot (Baileys) that receives photos from a WhatsApp group and uploads them to the API

## Development Commands

### Backend (run inside `pallet-backend/`)

```bash
composer dev          # Starts Laravel server + queue + pail log viewer + Vite concurrently
composer test         # Run test suite (clears config first)
php artisan test --filter TestName   # Run a single test
php artisan migrate   # Run migrations
php artisan tinker    # REPL
```

### Frontend (run inside `pallet-frontend/`)

```bash
npm run dev     # Vite dev server (proxies /api and /storage to localhost:8000)
npm run build   # Build to ../pallet-backend/public/app/
npm run lint    # ESLint
```

### WhatsApp bot (run inside `whatsapp-bot/`)

```bash
npm start       # node index.js
```

Visit `GET /qr` (port 3001) to scan the WhatsApp QR code.

### First-time setup

```bash
cd pallet-backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Architecture

### API

All routes are versioned under `/api/v1`. Most require `auth:sanctum` middleware (Bearer token). Bot photo upload uses `X-Bot-Secret` header instead.

The `/api/v1/admin/*` routes require the `admin` custom middleware (`AdminMiddleware`), which checks `user.role` for `admin` or `superadmin`.

### Auth & Roles

- Sanctum token stored in browser `localStorage`, sent as `Authorization: Bearer`.
- Roles: `user`, `admin`, `superadmin`.
- First registered user is automatically `superadmin` and skips email verification.
- Subsequent users must verify email before login.

### Frontend ↔ Backend Integration

- In dev: Vite proxies `/api` and `/storage` → `http://127.0.0.1:8000`.
- In prod: frontend is built into `pallet-backend/public/app/`, served directly by Laravel.
- `VITE_API_BASE_URL` env var overrides the default `/api/v1` base (used when frontend and backend are on different domains).
- API calls go through `src/api/client.js` (`apiGet`, `apiPost`, `apiPatch`, `apiDelete`).

### Image Handling

All uploaded images are converted to WebP via `App\Helpers\ImageConverter` (uses `intervention/image` with GD driver). Files are stored on the `public` disk under `storage/app/public/`. Images are scaled down to max 4000×4000 and oriented automatically.

### Activity Logging

Every significant action calls `App\Helpers\ActivityLogger::log(...)`, which writes to the `activity_logs` table with `user_id`, `action`, `entity_type`, `entity_id`, `pallet_id`, `order_id`, and JSON `old_values`/`new_values`.

### WhatsApp Bot

The bot monitors a single WhatsApp group (`WHATSAPP_GROUP_ID`). When a user sends a photo with a caption, it parses the command and POSTs the image to `POST /api/v1/bot/upload`. Commands: `p` (pallet), `b` (base), `t` (ticket/order). Session credentials are persisted in the PostgreSQL `whatsapp_sessions` table.

The bot also exposes an Express HTTP API on port 3001:

- `GET /qr` — scan QR to connect WhatsApp
- `GET /health` — connection status
- `POST /send` — send a message to the group (requires `X-Api-Key`)
- `GET /groups` — list all groups (to find `WHATSAPP_GROUP_ID`)
- `POST /logout` — disconnect and clear session

### Database

- Local dev: SQLite (default in `.env.example`)
- Production (Railway): PostgreSQL — set `DB_CONNECTION=pgsql` and `DATABASE_URL`

### Deployment

- **Railway**: uses `nixpacks.toml` (PHP 8.3 + Node 22). `PORT` env var is injected automatically.
- **Docker**: `Dockerfile` at root builds frontend then backend in a single PHP 8.4-alpine image. On start it runs migrations, `storage:link`, and `php artisan serve`.
