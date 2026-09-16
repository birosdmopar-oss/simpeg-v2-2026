# src/stores

Pinia store **lintas modul** (ADR-021): hanya untuk state yang dipakai 2+ modul (mis. `useAuthStore` — user login & role, dibuat di Fase 1).

Store yang khusus satu modul ditaruh di `src/features/{modul}/stores/`, bukan di sini. Satu store per domain, bukan satu store global.
