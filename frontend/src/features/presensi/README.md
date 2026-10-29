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

## Laporan Tukin (MAKE-048)

Halaman (PRD 5C §7.3; API `Controllers/Api/Presensi` "Laporan Tukin MAKE-041"):

| Path | Role | Halaman |
|---|---|---|
| `/presensi/tukin` | 1, 3 | `LaporanTukinDaftarPage` — daftar pegawai, filter Unit/Satker (role 1), Jenis Pegawai, Cari; ⋮ Lihat laporan → Unduh PDF |
| `/presensi/tukin/il` | 1 | pegawai Instansi Lain (`LaporanTukinIlPage` membungkus halaman yang sama) |
| `/presensi/tukin/:nip` | 1–7 | `LaporanTukinPegawaiPage` — identitas, periode, tabel harian, ringkasan, Unduh PDF (2/6/7 hanya NIP sendiri, server menegakkan) |
| `/presensi/tukin/saya` | 2, 6, 7 | halaman yang sama dengan NIP akun |

- Service `services/laporanTukin.service.ts` (normalisasi string MySQLi, `kode[]` CR-075, `pesanGalatLaporan` memetakan `code`
  422: `konfigurasi_tukin_tidak_lengkap`, `data_tukin_belum_tersedia`, `periode_tidak_didukung`, dst.); diuji dengan klien asli.
- Tabel harian `TabelHarianTukin`: warna baris [K] legacy per `status`, chip kode [V2]. Baris `libur`/`akhir_pekan`/
  `di_luar_masa_kerja` tampil bila API mengirimnya (CR-076); tabel tidak mengarang tanggal.
- [CATAT] Kolom daftar pegawai mengikuti respons API (Nama+NIP, Gol/Ruang, Jabatan, Eselon, Unit/Satker, Pendidikan, Jenis
  Pegawai). Kolom legacy `list_lt.php` yang tidak ada di API v2: kolom tersembunyi filter (10 pertama) dan kolom setelah
  "Jurusan"; filter Jenis Pegawai legacy (ALL/PNS/PPPK/PPT) diganti opsi master `jenis-pegawai` (multi).
- [K] Role 4/5: tanpa menu Laporan Tukin, seperti legacy (tidak ada tautan ke `laporan_tukin/{nip}` di sidebar atau halaman mereka);
  route `/presensi/tukin/:nip` tetap terbuka (1–7) dan lingkup ditegakkan server (keputusan reviewer 29-10).
- [CATAT] Cetak dari layar legacy diganti PDF server (Q5C-04); varian Tukin Lama tidak ada (F5B-5).

## Penyesuaian Tukin (MAKE-050, PRD 5C §7.4, F5B-14)

Role 1 saja (`PENYESUAIAN_TUKIN_ROLES`, route `/presensi/tukin/penyesuaian`). Daftar dengan filter NIP, Bulan, dan Status;
tambah/ubah lewat dialog (`PenyesuaianTukinFormDialog.vue`, galat 422 per field); ⋮ berurutan Edit → Aktifkan/Nonaktifkan
→ Hapus (`danger`, `ConfirmDialog`). Status hanya diubah lewat ⋮, tidak ada di form.

- [CATAT] **PUT body lengkap untuk Aktifkan/Nonaktifkan** ([V2] deviasi dari "status saja"). `PenyesuaianTukinService::isian`
  mewajibkan `bulan_mulai`, `bulan_akhir`, `persen`, dan `alasan` pada setiap PUT, jadi `ubahStatus` mengirim ulang nilai
  baris dan hanya `status` yang berubah. Risiko kecil: bila dua admin mengubah baris yang sama hampir bersamaan, perubahan
  yang lebih dulu bisa tertimpa. Backend tidak diubah (di luar lingkup 5C); diterima reviewer CR.

## Rekap Tukin (MAKE-049)

| Path | Role | Halaman |
|---|---|---|
| `/presensi/tukin/rekap` | 1, 3 | `RekapTukinPage` — rekap potongan seluruh lingkup, filter Bulan/Unit/Satker (role 1)/Jenis Pegawai |
| `/presensi/tukin/rekap-il` | 1 | pegawai Instansi Lain (`RekapTukinIlPage`), hanya Bulan |

- Service `services/rekapTukin.service.ts` (klien asli diuji): `daftar`, `ekspor('xlsx'|'pdf')`, `pesanGalatRekap` (422 `unit_satker`
  batas PDF/lingkup `0`, `bulan`, `periode_tidak_didukung`). XLSX = seluruh lingkup tanpa paginasi; PDF hanya unit/satker ≤ batas baris.
- `TabelRekapTukin`: 16 kolom urut XLSX PRD 5B §7.3.4 (No, Nama, NIP, Unit Es.I/II, Pengurangan TL/PSW/TK/TA/CUTI/TB, Total
  Presensi/SKP/Potongan, Keterangan); No/Nama/NIP sticky kiri (`col-sticky-kiri`, `--sticky-kiri` 0/3rem/17rem), header dua baris
  sticky, angka rata kanan berkoma Indonesia. Baris galat tetap tampil (penanda "Galat" + pesan, kolom angka "—").
- [CATAT] Rekap legacy tanpa warna → v2 tanpa warna baris (hanya sel angka); blok penanda tangan hanya di berkas XLSX/PDF, tidak
  di layar (Q5C-08). Cetak HTML legacy diganti PDF/XLSX server (Q5C-04).
