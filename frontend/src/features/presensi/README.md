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
- `statusPresensi.ts` — satu peta kode status → label, teks singkat, dan warna (tabel [CATAT] di bawah).
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

### [CATAT] Warna status: legacy vs v2

Legacy mewarnai LATAR BARIS laporan per pegawai (`laporan_tukin_skp.php:235-262`, CSS `:471-495`) hanya untuk baris
non-presensi; baris presensi tidak berwarna, dan rekap/daftar legacy tanpa warna. v2 menjaga warna baris yang sama
(token desain setara, bukan `rgba` lepas) dan menambah chip kode.

| Status / kode | Legacy | v2 |
|---|---|---|
| Akhir pekan (`libur` dengan tanggal Sabtu/Minggu) | baris abu | [K] baris abu + chip "Akhir pekan" |
| Libur (`libur` hari kerja) | baris biru | [K] baris biru + chip "Libur" |
| Konket (`konket`) | baris jingga | [K] baris jingga + chip "Konket" |
| Cuti (`cuti`) | baris ungu | [K] baris ungu + chip "Cuti" |
| Tugas belajar (`tugas_belajar`) | baris merah muda (jatuh ke `bg-others`) | [K] baris merah muda + chip "TB" |
| Di luar masa kerja | baris abu (`disable`) | [I] baris abu + chip |
| Hadir / TK / TPM / TPP | tanpa warna baris | tanpa warna baris |
| Kode TL1–3, PSW1–3 | tanpa warna | [V2] chip kuning (Q5C-03, DoD 087) |
| Kode TK, TPM, TPP | tanpa warna | [V2] chip merah |
| Kode CS (cuti sakit hari ke-15+) | tanpa warna | [V2] chip jingga |
| Rekap Tukin / Rekap Kehadiran | tanpa warna | hanya chip/sel kode, tanpa warna baris |

Warna memakai token tema `status-{kuning|merah|jingga|biru|abu|hijau|ungu|pink}` di `tailwind.config.js` (`text-status-*`,
`bg-status-*-soft`, `bg-status-*-titik`; nilai heks hanya di sana dan di uji kontras). Teks kode selalu tampil (warna tidak
berdiri sendiri) dan setiap pasangan teks/latar ≥ 4,5:1 (diuji). Legenda dua bagian: "Warna baris" ([K]) dan "Kode" ([V2]).
