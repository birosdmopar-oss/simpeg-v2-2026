# src/shared

Kode lintas modul (ADR-019): komponen/composable/util pindah ke sini **begitu dipakai modul kedua** (StatusBadge, DataTable, FormField, FileUploader, dsb.).

- `components/` — komponen UI reusable (Radix Vue headless + Tailwind, ADR-020)
- `composables/` — composable generik (format tanggal/currency, dsb.) — dilarang menghitung ulang business logic backend (ADR-025)
- `utils/` — helper murni

Instance HTTP tunggal ada di `src/lib/axios.ts` (F0-09), bukan di sini.
