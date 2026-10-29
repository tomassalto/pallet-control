# AGENTS.md

## Repo structure

Three-component monorepo:

- `pallet-backend/` — Laravel 12 REST API (PHP 8.4)
- `pallet-frontend/` — React 19 + Vite 7 PWA (builds into `pallet-backend/public/app/`)
- `whatsapp-bot/` — legacy/inactive WhatsApp bot; excluded from active gates and deploys

## Mandatory context routing

Before planning or changing code, read `ROUTER.md` and follow every route that applies.
Technical theory comes only from the canonical `BE-*`, `FE-*`, `DO-*`, `SEC-*` and
`QA-*` sections in the five biblias. `BIBLIAS-GOBERNANZA.md` defines ownership and
precedence; `DOMINIO.md` defines business invariants and `PRODUCTO.md` product intent.
Operational documents may apply that knowledge to Pallet Control, but must not invent or
duplicate it. If the router identifies a theoretical gap, stop and follow its `GAP-XXX`
protocol before implementing the affected behavior.

Backend `package.json` is Laravel's Vite integration (axios + concurrently), **not** the frontend.

## Critical commands

All run inside each subdirectory — there is no root `package.json`.

| Component | Command | Notes |
|---|---|---|
| Backend | `composer dev` | Starts 4 processes concurrently: artisan serve, queue:listen, pail logs, Vite |
| Backend | `composer test` | Runs `config:clear` then `php artisan test` (uses SQLite `:memory:`) |
| Backend | `php artisan test --filter TestName` | Single test |
| Frontend | `npm run dev` | Vite dev server, proxies `/api` and `/storage` to `localhost:8000` |
| Frontend | `npm run build` | Outputs to `../pallet-backend/public/app/` |
| Frontend | `npm run lint` | ESLint |
| Telegram | Laravel webhook | Active bot implementation lives in the backend |

Laravel backend scripts (`composer.json` scripts section) are the source of truth for commands.

## API quirks

- All routes under `/api/v1`, most require `auth:sanctum` (Bearer token in `localStorage`)
- Bot upload uses `X-Bot-Secret` header instead of Bearer
- `/api/v1/admin/*` requires `AdminMiddleware` (role `admin` or `superadmin`)
- First registered user is auto-`superadmin` and skips email verification
- VITE_API_BASE_URL env var overrides default `/api/v1` base (cross-domain setups)
- Frontend API calls go through `src/api/client.js` (apiGet/apiPost/apiPatch/apiDelete)

## Tech stack specifics

- **CSS**: Tailwind CSS 4 + PostCSS — no `tailwind.config.js`
- **Canvas annotations**: Konva / react-konva (lazy-loaded in `features/base-gallery/`)
- **Server state**: TanStack React Query 5
- **Routing**: React Router 7
- **Images**: All uploads auto-converted to WebP (intervention/image GD driver), max 4000×4000
- **OCR**: Azure Computer Vision / Tesseract / PaddleOCR, set via `OCR_PROVIDER` env
- **DB**: SQLite (dev, default), PostgreSQL (prod/OnRender)
- **Storage**: local `public` disk (dev), Cloudflare R2 (prod)
- **Queue**: database driver (async OCR jobs)

## Conventions

- Code comments & variable names: **Spanish** (existing codebase style)
- Commit messages: **English**, imperative mood
- Never commit unrelated `*.md` files. Modernization work must update the tracking
  documents required by `ROUTER.md` when their state changes.
- Every significant action logs via `App\Helpers\ActivityLogger::log(...)`
- No typecheck command exists — only ESLint (frontend) and Pint (backend) for linting

## Testing

- PHPUnit, tests in `tests/Unit/` and `tests/Feature/`
- Runs with SQLite `:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`
- No frontend test runner exists yet; `QUALITY-BIBLIA.md` and `FE-GATE` define the gap

## Deployment

- Multi-stage Docker: Node 22 builds frontend → PHP 8.4-alpine runs backend
- OnRender: `PORT` injected automatically, `RESET_ON_STARTUP=true` wipes all data
- `node_modules` never reaches final image

## Existing instruction files

- `BIBLIAS-GOBERNANZA.md` — ownership, precedence, template and GAP lifecycle
- `ROUTER.md` — mandatory entry point and context selector
- `BACKEND-BIBLIA.md`, `FRONTEND-BIBLIA.md`, `DEVOPS-BIBLIA.md`,
  `SECURITY-BIBLIA.md`, `QUALITY-BIBLIA.md` — sole technical sources
- `DOMINIO.md`, `PRODUCTO.md` — business language and product intent
- `PLAN.md`, `AUDITORIA.md`, `DECISIONES.md`, `EJECUCION.md` — operational state
- `AUDITORIA-BIBLIAS.md` — trace of the foundational documentation restructuring
