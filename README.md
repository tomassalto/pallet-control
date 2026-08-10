# 📦 Pallet Control

Warehouse logistics management system for tracking pallets, orders, customers, and deliveries. Supports photo documentation with canvas annotations, OCR receipt scanning, and automatic photo uploads via WhatsApp.

---

## Screenshots

> Replace each placeholder with a real screenshot. Suggested folder: `docs/screenshots/`.

| Dashboard | My Pallets | Pallet Detail |
|:---------:|:----------:|:-------------:|
| <!-- screenshot: home --> <br>_Pending_ | <!-- screenshot: my-pallets --> <br>_Pending_ | <!-- screenshot: pallet-detail --> <br>_Pending_ |

| My Orders | Order Detail | Create Order |
|:---------:|:------------:|:------------:|
| <!-- screenshot: my-orders --> <br>_Pending_ | <!-- screenshot: order-detail --> <br>_Pending_ | <!-- screenshot: create-order --> <br>_Pending_ |

| Base Gallery (with annotations) | Pending Items | Public View (QR) |
|:-------------------------------:|:-------------:|:----------------:|
| <!-- screenshot: base-gallery --> <br>_Pending_ | <!-- screenshot: pending-items --> <br>_Pending_ | <!-- screenshot: public-view --> <br>_Pending_ |

---

## Features

### Pallets
- Create pallets with auto-assigned unique codes
- Assign multiple orders to a single pallet
- Split pallets into **bases** (physical layers/sections)
- Photo gallery per pallet and per base
- **Canvas annotations on photos** — number units directly over the image with pinch-zoom and pan support
- Full audit log of every action
- Finalize / reopen pallets
- **Public QR view** — share pallet contents without requiring login

### Orders
- Create orders and link them to customers
- **Import items from Excel/CSV files**
- Assign orders to pallets
- **Delivery ticket OCR** — photograph a receipt and the system extracts the numbers automatically (Azure Computer Vision or Tesseract)
- Finalize orders with completeness validation
- Full status history

### Customers
- Customer directory
- Order history per customer

### Pending Items
- Log products with missing units from a delivery
- Mark as delivered / reopen
- Dashboard alert when unresolved pending items exist

### Dashboard
- Live stats: open pallets, orders in progress, unassigned units
- Quick access to the last open pallet and order
- Orders-per-day chart (last 30 days)
- Monthly OCR request counter

### Admin Panel
- User management and role assignment
- Storage cleanup: view and delete orphaned photos from deleted pallets/orders

### WhatsApp Bot
- Monitors a WhatsApp group and automatically uploads photos to the corresponding pallet or base
- Commands: `p <code>` (pallet photo), `b <code>` (base photo), `t <code>` (order ticket)

---

## Tech Stack

### Backend — `pallet-backend/`

| | |
|---|---|
| Framework | Laravel 12 |
| PHP | 8.4 |
| Auth | Laravel Sanctum (Bearer token) |
| ORM | Eloquent |
| Queue | Database queue (async OCR jobs) |
| Storage | Local disk (dev) / Cloudflare R2 (prod) |
| Images | `intervention/image` — auto-convert uploads to WebP |
| OCR | Azure Computer Vision · Tesseract · PaddleOCR |
| Database | SQLite (dev) · PostgreSQL (prod) |

### Frontend — `pallet-frontend/`

| | |
|---|---|
| Framework | React 19 |
| Build | Vite 7 + PWA (vite-plugin-pwa) |
| Styles | Tailwind CSS 4 |
| Routing | React Router 7 |
| Server state | TanStack React Query 5 |
| Canvas | Konva / react-konva (photo annotations) |
| Scanner | html5-qrcode (EAN / QR codes) |
| Toasts | react-toastify |

### WhatsApp Bot — `whatsapp-bot/`

| | |
|---|---|
| Runtime | Node.js |
| Library | Baileys (WhatsApp Web Multi-Device) |
| Session | Persisted in PostgreSQL |
| HTTP API | Express (port 3001) |

---

## Architecture

```
┌────────────────────────────────────────────────────────┐
│                     Client / PWA                        │
│         React 19 + Vite · React Query · Tailwind        │
└──────────────────────┬─────────────────────────────────┘
                       │ HTTPS  /api/v1/*
┌──────────────────────▼─────────────────────────────────┐
│                 Laravel 12 REST API                      │
│    Sanctum auth · Eloquent · Queue jobs (OCR async)      │
│    Storage: local disk (dev) / Cloudflare R2 (prod)      │
└────────────┬───────────────────────────────────────────┘
             │
      ┌──────▼──────┐        ┌──────────────────────────┐
      │  PostgreSQL  │        │      WhatsApp Bot         │
      │  (production)│        │   Baileys · Node.js       │
      └─────────────┘        │   POST /api/v1/bot/upload  │
                             │   Header: X-Bot-Secret     │
                             └──────────────────────────┘
```

**Development:** Vite proxies `/api` and `/storage` to `localhost:8000`.  
**Production:** the frontend is compiled into `pallet-backend/public/app/` and served directly by Laravel.

---

## Repository Structure

```
pallet-control/
├── pallet-backend/              # Laravel 12 API
│   ├── app/
│   │   ├── Console/Commands/    # ResetForProduction, etc.
│   │   ├── Helpers/             # ActivityLogger, ImageConverter
│   │   ├── Http/
│   │   │   ├── Controllers/Api/
│   │   │   └── Middleware/      # AdminMiddleware, BotSecretMiddleware
│   │   └── Models/
│   ├── database/migrations/
│   ├── routes/api.php
│   └── storage/app/public/      # Photos (dev)
│
├── pallet-frontend/             # React 19 PWA
│   ├── src/
│   │   ├── api/                 # client.js (apiGet / apiPost / …)
│   │   ├── auth/                # AuthContext, RequireAuth
│   │   ├── context/             # ThemeContext
│   │   ├── features/
│   │   │   └── base-gallery/    # PhotoPreview (Konva, lazy-loaded)
│   │   ├── pages/               # One file per route
│   │   └── ui/                  # Shared components
│   └── vite.config.js
│
├── whatsapp-bot/                # WhatsApp bot (Baileys)
│   └── index.js
│
├── Dockerfile                   # Multi-stage: Node (build) + PHP (runtime)
├── docker-entrypoint.sh
└── supervisord.conf
```

---

## Local Setup

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+
- npm

### 1. Backend

```bash
cd pallet-backend

composer install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

# Start all services concurrently
composer dev
```

> `composer dev` starts: PHP server · queue worker · Pail log viewer · Vite dev server

### 2. Frontend (optional — only if you want standalone HMR)

```bash
cd pallet-frontend
npm install
npm run dev    # → http://localhost:5173
```

### 3. WhatsApp Bot (optional)

```bash
cd whatsapp-bot
npm install

# Set environment variables (see table below)
npm start

# Open http://localhost:3001/qr and scan with WhatsApp
```

---

## Environment Variables

### Backend (`pallet-backend/.env`)

| Variable | Description | Default |
|---|---|---|
| `APP_KEY` | Encryption key — generate with `artisan key:generate` | — |
| `DB_CONNECTION` | `sqlite` (dev) · `pgsql` (prod) | `sqlite` |
| `DATABASE_URL` | PostgreSQL connection string (prod) | — |
| `FILESYSTEM_DISK` | `public` (local) · `r2` (Cloudflare) | `public` |
| `R2_ACCESS_KEY_ID` | Cloudflare R2 API key | — |
| `R2_SECRET_ACCESS_KEY` | Cloudflare R2 API secret | — |
| `R2_BUCKET` | R2 bucket name | — |
| `R2_ENDPOINT` | `https://<account_id>.r2.cloudflarestorage.com` | — |
| `R2_URL` | Public URL of the bucket | — |
| `OCR_PROVIDER` | `azure` · `tesseract` · `paddle` | `tesseract` |
| `AZURE_VISION_ENDPOINT` | Azure Computer Vision endpoint | — |
| `AZURE_VISION_KEY` | Azure Computer Vision API key | — |
| `REGISTRATION_ENABLED` | `true` to allow new user sign-ups | `true` |
| `BOT_SECRET` | Shared secret for the WhatsApp bot (`X-Bot-Secret` header) | — |
| `RESET_ON_STARTUP` | `true` wipes all data on container start (prod reset only) | `false` |

### WhatsApp Bot

| Variable | Description |
|---|---|
| `BOT_API_SECRET` | Must match `BOT_SECRET` on the backend |
| `WHATSAPP_GROUP_ID` | Target WhatsApp group ID (e.g. `120363xxx@g.us`) |
| `LARAVEL_URL` | Backend base URL (e.g. `https://my-app.onrender.com`) |
| `DATABASE_URL` | PostgreSQL — used to persist the WhatsApp session |
| `SESSION_ID` | Session identifier (default: `pallet-bot`) |

---

## Roles & Permissions

| Role | Capabilities |
|---|---|
| `user` | Read and write pallets, orders, bases, photos. No admin access. |
| `admin` | Everything above + user management and storage cleanup. |
| `superadmin` | Everything above. Automatically assigned to the first registered user. |

The first registered user becomes `superadmin` and skips email verification.  
All subsequent users must verify their email before logging in.

Once your team is set up, set `REGISTRATION_ENABLED=false` in the backend `.env` to close public sign-ups.

---

## OCR — Ticket Scanning

Upload a photo of a delivery receipt and the system extracts numbers and quantities automatically.

| Provider | Setup | Notes |
|---|---|---|
| **Azure Computer Vision** | Create a free F0 resource at [portal.azure.com](https://portal.azure.com) → Computer Vision | 5,000 requests/month free · ~300ms latency · recommended for production |
| **Tesseract** | `apt install tesseract-ocr` / `brew install tesseract` | Good for local development |
| **PaddleOCR** | Python + PaddleOCR installed locally | Alternative local option |

Set the provider with `OCR_PROVIDER=azure|tesseract|paddle` in your `.env`.  
The dashboard displays how many OCR requests have been used this month.

---

## API Reference

All endpoints are prefixed with `/api/v1`.  
Most require `Authorization: Bearer <token>`. Exceptions are noted.

```
# Auth
POST   /auth/register              Create account (public)
POST   /auth/login                 Login → returns token (public)
GET    /auth/me                    Authenticated user
POST   /auth/logout

# Dashboard
GET    /dashboard                  Stats + last open pallet/order

# Pallets
GET    /pallets                    Paginated list
POST   /pallets                    Create
GET    /pallets/:id                Detail (includes bases, photos, orders)
PATCH  /pallets/:id                Update status
POST   /pallets/:id/finalize       Finalize pallet
POST   /pallets/:id/reopen         Reopen pallet
GET    /pallets/:id/activity-logs  Audit log

# Orders
GET    /orders                     Paginated list (filters: date_from, date_to, search)
POST   /orders                     Create
POST   /orders/:id/import          Import items from file
POST   /orders/:id/finalize        Finalize order
POST   /orders/can-finalize-batch  Check if multiple orders can be finalized

# Tickets (delivery receipts)
POST   /orders/:id/tickets/:tid/photos                    Upload photo
POST   /orders/:id/tickets/:tid/photos/:pid/trigger-ocr   Run OCR on photo

# Bases
POST   /pallets/:id/bases                                 Create base
POST   /pallets/:id/bases/:bid/photos                     Upload base photo
POST   /pallets/:id/bases/:bid/photos/:pid/annotations    Save annotations

# Customers
GET    /customers                  List all
GET    /customers/:id              Detail with order history
POST   /customers                  Create

# Pending Items
GET    /pending-items              List (pending + resolved)
POST   /pending-items              Create
PATCH  /pending-items/:id          Update status (resolve / reopen)
DELETE /pending-items/:id          Delete

# Public (no auth required)
GET    /public/pallets/:code       Public pallet view (for QR codes)
GET    /products/by-ean/:ean       Look up product by EAN barcode

# Bot (X-Bot-Secret header instead of Bearer token)
POST   /bot/upload                 Upload photo from WhatsApp

# Admin (admin / superadmin role required)
GET    /admin/users                List users
PATCH  /admin/users/:id/role       Update user role
POST   /admin/users/:id/toggle-active  Enable / disable user
GET    /admin/storage/stats        Storage usage overview
DELETE /admin/storage/pallets/:id  Delete pallet and its files
DELETE /admin/storage/orders/:id   Delete order and its files
```

---

## Deployment (Render)

The app deploys to [Render](https://render.com) using the `Dockerfile` at the repository root.

### Multi-stage Docker build
1. **Stage 1** (node:22-alpine) — compiles the React frontend → `pallet-backend/public/app/`
2. **Stage 2** (php:8.4-cli-alpine) — installs PHP dependencies, copies the backend and the compiled frontend

`node_modules` never reach the final image.

### Environment variables on Render

```env
APP_ENV=production
APP_KEY=base64:...
APP_URL=https://my-app.onrender.com
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://...
FILESYSTEM_DISK=r2
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=...
R2_ENDPOINT=...
R2_URL=...
OCR_PROVIDER=azure
AZURE_VISION_ENDPOINT=...
AZURE_VISION_KEY=...
BOT_SECRET=...
REGISTRATION_ENABLED=false
```

### Production data reset

To wipe all data (except products) on a deployed instance:

1. Add `RESET_ON_STARTUP=true` to the Render environment variables
2. Deploy
3. Once the deploy completes, remove the variable
4. Deploy again

> ⚠️ This permanently deletes all pallets, orders, photos, and users. Cannot be undone.

---

## Useful Commands

```bash
# Backend
php artisan test                        # Run test suite
php artisan test --filter SomeName      # Run a single test
php artisan migrate:fresh               # Reset the database
php artisan tinker                      # Interactive REPL
php artisan app:reset --confirm         # Production data reset (local)

# Frontend
npm run build                           # Production build
npm run lint                            # ESLint
```

---

## License

Private project. All rights reserved.
