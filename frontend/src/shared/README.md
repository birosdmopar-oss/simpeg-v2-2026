# src/shared

Kode lintas modul (ADR-019): komponen/composable/util pindah ke sini **begitu dipakai modul kedua** (StatusBadge, DataTable, FormField, FileUploader, dsb.).

- `components/` — komponen UI reusable (Radix Vue headless + Tailwind, ADR-020)
- `composables/` — composable generik (format tanggal/currency, dsb.) — dilarang menghitung ulang business logic backend (ADR-025)
- `utils/` — helper murni
- `ui/` — komponen design system redesign (Laporan Redesign SIMPEG bab 3, CR-028/MIG-001b): `UiButton`, `UiTextField`,
  `UiSelect`, `UiCheckbox`, `UiCard`, `UiBadge`, dst. lewat barrel `@/shared/ui`. Murni tampilan, tanpa logika domain.
  Token warna/tipografi/radius/shadow ada di `tailwind.config.js` (`brand-*`, `success|warning|danger|info|muted`,
  `text-h1..overline`, `rounded-card`, `shadow-card|panel|float`). `placeholderAssets.ts` = registry aset SEMENTARA.

## Shell & menu (Sprint 0-2, MAKE-003)

- `layouts/RedesignShell.vue` = satu-satunya shell setelah login (sidebar `AppSidebar` + topbar `AppTopbar` +
  breadcrumb); `AppShell` lama sudah dipensiunkan. Halaman membungkus isinya dengan `<RedesignShell :breadcrumbs>`.
- `layouts/nav.config.ts` = satu sumber menu sidebar (urutan, ikon, role, `phase`). Menu dengan `phase > ACTIVE_PHASE`
  disembunyikan; `ACTIVE_PHASE = 2` sampai halaman Fase 3 tersambung API (dinaikkan WS-2 di B-20 penutup). Ganti
  Password & Keluar ada di menu profil sidebar. Pemilik `nav.config.ts` dan `router/index.ts`: WS-2.
- Route Modul B dipecah per workstream: `features/kepegawaian/routes.pegawai.ts` (WS-2) dan `routes.riwayat.ts`
  (WS-1), diimpor sekali dari `router/index.ts`.

## Komponen approval & re-export (Sprint 0-2)

- `components/StatusBadge.vue` — status verifikasi riwayat/usulan (`statusRiwayat.ts`: 0 Menunggu, 1 Disetujui,
  2 Ditolak, 10 Dihapus; 3 Diproses hanya dengan `diprosesEnabled`). Badge Aktif/Tidak Aktif master data tetap
  `features/master-data/components/StatusBadge.vue`.
- `components/ApprovalDialog.vue` — Setujui/Tolak (alasan wajib saat Tolak, maks. 255 byte; slot `diff`), emit
  `confirm` `{ aksi, reason_note }`. Item ⋮ dari `approvalRowActions()` (`approvalActions.ts`), disisipkan sesudah
  Edit/ubah status/urutan dan sebelum Hapus. Props/emit dibekukan; perubahan diajukan ke WS-2.
- `composables/useCascadeOptions.ts` dan `services/masterOptions.ts` me-re-export cascade & opsi master dari
  `features/master-data` (berkas asal tidak dipindah); modul lain mengimpor lewat `@/shared/...`.

## Konvensi form (CR-028)

- Field form tetap memakai `components/FormField.vue` (API lama: `label`, `error`, `hint`, `type`, ...). FormField
  merender `UiTextField` (text/password/number/date), `UiSelect` (select), dan `UiCheckbox` (checkbox), jadi label selalu
  di atas field, pesan error ber-`role="alert"`, dan wajib ditandai `aria-required` (validasi tetap Zod/VeeValidate).
- Form di dialog admin memakai `components/formLayout.ts`: `FORM_GRID_CLASS` pada `<form>` (dua field per baris mulai
  `md`, satu kolom di layar sempit), `FORM_WIDE_CLASS` untuk field panjang (textarea/html), `FORM_ALERT_CLASS` untuk
  banner error, dan `FORM_ACTIONS_CLASS` untuk baris tombol Batal (`UiButton` soft secondary) / Simpan (`UiButton`
  primary).

Instance HTTP tunggal ada di `src/lib/axios.ts` (F0-09), bukan di sini.
