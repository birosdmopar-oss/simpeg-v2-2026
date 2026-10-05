# src/shared

Kode lintas modul (ADR-019): komponen/composable/util pindah ke sini **begitu dipakai modul kedua** (StatusBadge, DataTable, FormField, FileUploader, dsb.).

- `components/` — komponen UI reusable (Radix Vue headless + Tailwind, ADR-020)
- `composables/` — composable generik (format tanggal/currency, dsb.) — dilarang menghitung ulang business logic backend (ADR-025)
- `utils/` — helper murni
- `ui/` — komponen design system redesign (Laporan Redesign SIMPEG bab 3, CR-028/MIG-001b): `UiButton`, `UiTextField`,
  `UiSelect`, `UiCheckbox`, `UiCard`, `UiBadge`, dst. lewat barrel `@/shared/ui`. Murni tampilan, tanpa logika domain.
  Token warna/tipografi/radius/shadow ada di `tailwind.config.js` (`brand-*`, `success|warning|danger|info|muted`,
  `text-h1..overline`, `rounded-card`, `shadow-card|panel|float`). `placeholderAssets.ts` = registry aset SEMENTARA.

## Konvensi form (CR-028)

- Field form tetap memakai `components/FormField.vue` (API lama: `label`, `error`, `hint`, `type`, ...). FormField
  merender `UiTextField` (text/password/number/date), `UiSelect` (select), dan `UiCheckbox` (checkbox), jadi label selalu
  di atas field, pesan error ber-`role="alert"`, dan wajib ditandai `aria-required` (validasi tetap Zod/VeeValidate).
- Form di dialog admin memakai `components/formLayout.ts`: `FORM_GRID_CLASS` pada `<form>` (dua field per baris mulai
  `md`, satu kolom di layar sempit), `FORM_WIDE_CLASS` untuk field panjang (textarea/html), `FORM_ALERT_CLASS` untuk
  banner error, dan `FORM_ACTIONS_CLASS` untuk baris tombol Batal (`UiButton` soft secondary) / Simpan (`UiButton`
  primary).

Instance HTTP tunggal ada di `src/lib/axios.ts` (F0-09), bukan di sini.
