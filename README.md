# SIMPEG v2 — Rewrite (CodeIgniter 4 + Vue 3)

Rewrite SIMPEG Kementerian Pariwisata dari CI3 monolitik menjadi **REST API CodeIgniter 4** (`backend/`) + **SPA Vue 3** (`frontend/`). Sumber kebenaran requirement: `Bahan Baku SIMPEG/00-INDEX.md` s.d. `08-HaloSimpeg.md`, ADR (`SIMPEG_v2_ADR.docx`), SOP, dan Matriks Role x Endpoint.

Repo ini berisi hasil **Fase 0 — Fondasi (F0-01 s.d. F0-18)**: infrastruktur, base class, auth skeleton, tooling. Belum ada fitur bisnis (Modul A-H).

```
.
├── backend/            CI4 4.7 (PHP 8.2+, MySQL 8.x) — REST API, prefix api/v1
├── frontend/           Vue 3 + Vite + TypeScript + Tailwind + Pinia
├── deploy/             git hook post-receive + rollback.sh untuk server dev (F0-18)
├── check.sh            quality gate gabungan backend -> frontend (F0-12)
└── README-deploy.md    cara push ke server dev & rollback
```

## Menjalankan lokal

Prasyarat: PHP 8.2+ (ext mysqli, intl, mbstring, json), Composer 2, Node 20+, MySQL 8.x berjalan.

```bash
# Backend
cd backend
cp .env.example .env            # isi database.*, jwt.secret (>= 32 char), cors.allowedOrigins
composer install
mysql -uroot -e "CREATE DATABASE simpeg_v2; CREATE DATABASE simpeg_v2_testing;"
php spark migrate --all         # --all: termasuk migration paket CodeIgniter Queue (queue_jobs)
php spark serve                 # http://localhost:8080  -> GET / = healthcheck 200

# Frontend
cd frontend
cp .env.example .env.local      # VITE_API_BASE_URL
npm install
npm run dev                     # http://localhost:5173
```

Queue worker (push notification, ADR-013) dijalankan terpisah:

```bash
cd backend
php spark queue:dummy "tes"                              # enqueue job dummy (verifikasi F0-13)
php spark queue:work default --max-jobs 1 --stop-when-empty
```

## Quality gate (wajib sebelum push)

```bash
./check.sh              # backend: PHPStan lvl5 -> PHP-CS-Fixer PSR-12 -> PHPUnit | frontend: ESLint -> vue-tsc -> Vitest -> build
./check.sh backend      # atau salah satu
./check.sh frontend
```

Setara dengan `composer check` di `backend/` dan `npm run check` di `frontend/`. Fail-fast: berhenti di langkah pertama yang gagal. PHPUnit memakai database group `tests` (`database.tests.*` di `.env`) dan menjalankan `migrate:refresh` di sana — jangan arahkan ke database berisi data.

Perbaikan style otomatis: `composer cs-fix` (backend), `npm run format` (frontend).

## Peta fondasi (Fase 0)

| Task | Implementasi |
|---|---|
| F0-01 Backend CI4 | `backend/` (appstarter 4.7), healthcheck `GET /` dan `GET /api/v1/health`, `.env.example` |
| F0-02 Frontend Vue 3 | `frontend/` (Vite 8, TS, Tailwind 3, Pinia, Vue Router) |
| F0-03 Struktur feature-based | `backend/app/{Controllers/Api,Models,Libraries}/{Modul}/README.md`, `frontend/src/features/{modul}/`, `src/stores`, `src/shared` |
| F0-04 Audit log | `app/Models/BaseAuditableModel.php`, `AuditLogModel`, migration `audit_logs` |
| F0-05 Dual snapshot | `app/Models/BaseSnapshotModel.php`, `app/Interfaces/SyncsToSnapshot.php` |
| F0-06 JWT | `app/Libraries/Auth/JwtService.php`, `app/Filters/JwtAuthFilter.php`, `app/Models/Auth/TokenModel.php`, migration `token`, `Config/Jwt.php` |
| F0-07 Role | `app/Constants/Role.php` (8 role) |
| F0-08 RBAC | `app/Filters/RoleFilter.php`, alias `jwt`/`role` di `Config/Filters.php`, endpoint dummy `api/v1/_rbac/*` (non-production) |
| F0-09 Axios terpusat | `frontend/src/lib/axios.ts` (+ `__tests__/axios.spec.ts`) |
| F0-10/11/12 check | `backend/composer.json` (`check`), `frontend/package.json` (`check`), `check.sh` |
| F0-13 Queue DB | `Config/Queue.php`, `app/Jobs/DummyJob.php`, `app/Commands/QueueDummy.php`, migration paket `queue_jobs` |
| F0-14 Cache file | `Config/Cache.php` (handler file, prefix `simpeg_`), `app/Libraries/CacheService.php` |
| F0-15/16/17 Adapter | `app/Interfaces/{Esign,Siasn,PushNotif}GatewayInterface.php`, `app/Libraries/{Esign,Siasn,Push}/Mock*Adapter.php`, resolver di `Config/Services.php` (driver `mock` via `.env`) |
| F0-18 Deploy dev | `deploy/post-receive`, `deploy/rollback.sh`, `README-deploy.md` |

## Modul A — Autentikasi & Akun (Fase 1)

| Task | Implementasi |
|---|---|
| A-01 Migration Auth | migration `pengguna`, `login_attempts`, `forgot_attempts`, `audit_logs.event` (+login/logout); review DB Validator: `backend/docs/db-review/A-01-auth-schema.md` |
| A-02/A-02b Login + lazy rehash | `LoginController`, `Libraries/Auth/AuthService.php`, `PasswordVerifier.php` (MD5 → Argon2id saat login pertama) |
| A-03 Turnstile | `Libraries/Auth/TurnstileVerifier.php` (+ `MockCaptchaVerifier` untuk lokal), `frontend/.../TurnstileWidget.vue` |
| A-04 Lockout | `Libraries/Auth/LockoutService.php` (N=5, `Config/Auth.php`) |
| A-05 Refresh & logout | `TokenController` (`/auth/refresh`, `/auth/logout`, `/auth/me`); reuse refresh token → seluruh sesi dicabut |
| A-06 Change password | `PasswordController`, `Libraries/Auth/PasswordService.php` |
| A-07 Forgot/reset | `ResetPasswordController`, `Libraries/Auth/ResetPasswordService.php` |
| A-08 CRUD akun scoped | `UserController`, `Libraries/Auth/UserService.php` (role 3 dibatasi `id_satker`) |
| A-09 Lifecycle akun | `Libraries/Auth/AccountProvisioner.php` (dipanggil Modul B saat pegawai baru) |
| A-10 Audit | `PenggunaModel` (auditable, hash dimasking) + event `login`/`logout` dari `AuthService` |
| A-11 Login FE | `frontend/src/features/auth/views/LoginView.vue`, `stores/auth.store.ts`, `schemas/login.schema.ts` |
| A-12 Manajemen Akun FE | `views/UserManagementView.vue`, `components/UserFormDialog.vue`; menu hanya role 1 & 3 (`shared/components/AppShell.vue`), route guard `src/router/index.ts` |
| A-13 Test RBAC & JWT | `backend/tests/Auth/*` (94 test), bagian dari `check.sh` |

Dokumentasi endpoint: [backend/app/Controllers/Api/Auth/README.md](backend/app/Controllers/Api/Auth/README.md).
Akun uji lokal: `php spark db:seed 'Tests\Support\Database\Seeds\AuthSeeder'` (12 akun README_local_seed, password `Password123!`), set `auth.captchaDriver = mock` di `.env`.

## Konvensi penting (dari ADR)

- **Response envelope** (ADR-001): `{status:'success', data}` / `{status:'error', message, errors?}` — helper di `App\Controllers\Api\ApiController`.
- **Semua write lewat Model** (ADR-012) agar audit otomatis; `syncToActiveSnapshot()` memakai raw builder dan menulis audit manual, dipanggil **hanya** di final approval step.
- **Auth**: `JwtAuthFilter` (401) → `RoleFilter` (403) dideklarasikan di route: `['filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]]`. Scoping data per satker ditulis di Service, bukan filter (ADR-005).
- **Token**: access 1 jam + refresh 7 hari, keduanya cookie httpOnly; refresh token rotating, di DB hanya hash SHA-256.
- **Integrasi eksternal**: consumer bergantung ke interface (`service('esignGateway')`, dst.), driver `mock` sampai Fase 6.
- **snake_case end-to-end** (ADR-023), tanpa transformasi di axios.
