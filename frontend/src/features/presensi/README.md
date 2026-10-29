# Modul D — Presensi & Remunerasi (Fase 5)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Fondasi laporan 5C (MAKE-047)

Dipakai ulang oleh halaman Laporan Tukin, Rekap Tukin, Rekap Kehadiran, dan Rekap Uang Makan (PRD 5C §7):

- `types.laporan.ts` — tipe bersama laporan (`StatusHarian`, `KodeKejadian`, `HarianLaporan`, `FilterLaporan`, `ParamLaporan`).
- `statusPresensi.ts` — satu peta kode status → label, teks singkat, dan warna (default Q5C-03; legacy tanpa warna).
  `chipHarian(status, kode)` dan `nadaBaris(status, kode)` memetakan dari `harian[].status` + `kode[]` (field aditif
  CR-075; tanpa `kode`, chip dari `status` saja). Pasangan teks/latar ≥ 4,5:1, diuji.
- `components/StatusPresensiChip.vue`, `LegendaStatusPresensi.vue` — chip (teks kode selalu tampil, arti di `aria-label`,
  tanpa `title`) dan legenda dari sumber yang sama.
- `composables/useFilterLaporan.ts` — filter Bulan (bawaan bulan WIB), Unit/Satker (`bisaPilihUnit` = role 1), Jenis
  Pegawai, Cari, paginasi; disimpan di query string URL; `param` mengikuti kontrak API (`unit_satker` `0`/`<id_unit>`/
  `sat_<id_satker>`). `components/FilterLaporanBar.vue` adalah tampilannya (`v-model:bulan|idUnit|idSatker|jenisPegawai|search`).
- Tabel densitas tinggi: kelas `col-sticky-kiri` (`assets/main.css`) pada `<th>`/`<td>` kolom identitas; kolom kedua dst.
  memberi `style="--sticky-kiri: <lebar kolom sebelumnya>"`. Dipakai bersama `page-fill` + `table-fill` + `UiCard fill`.
- Unduh berkas laporan: `ambilBerkas`/`simpanBerkas` dari `features/kepegawaian/services/berkasUnduhan.ts` (pola MAKE-046).
