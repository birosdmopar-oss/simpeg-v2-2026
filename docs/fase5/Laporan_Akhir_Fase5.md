# Laporan Akhir Fase 5 — Modul D Presensi & Remunerasi (MAKE-054)

Acuan: PRD 5A v1.1, PRD 5B v1.0, PRD 5C v1.1 (§7.7 MAKE-054, §8 AC, §5.1 K5C-1..10), `TaskList_Fase5_Paralel.md`
§6–§7 (Tahap UP-1, prasyarat Fase 6). Disusun 29-10-2026 dari `main` `6c116ac` (seluruh paket koding 5A, 5B, 5C dan
perbaikan QA CR-075..081 sudah di `main`).

> **Status:** **FASE 5 DONE — sign-off Horii 29-10-2026** (`main` `d0f12c1`). 5A sign-off 28-10-2026, 5B sign-off
> 29-10-2026, 5C seluruh AC berbukti (§3), QA Lapis 1 5C **lolos** (§4), QA Lapis 1 FE 5A (Q5A-18) **lolos**; temuan
> rendah T5A-01/02/04 diperbaiki CR-082 sebelum sign-off (§4.1, §7). Uji paritas UP-1 **bukan** syarat sign-off Fase 5 (K5C-10); UP-1 menjadi gerbang masuk Fase 6. Fase 5 DONE
> **tidak** berarti siap go-live: blocker go-live di §8 tetap berlaku.

## 1. Laporan

```
Status: 5A DONE (sign-off 28-10-2026), 5B DONE (sign-off 29-10-2026),
        5C: D-12 sisa (FE laporan), D-13 suite, MAKE-053 penutup — semua di main; QA Lapis 1 5C LOLOS;
        D-12 FE 5A: QA Lapis 1 (Q5A-18) LOLOS; T5A-01/02/04 diperbaiki CR-082 (§4.1, §7)
        FASE 5 DONE: sign-off Horii 29-10-2026 pada main d0f12c1
Branch/SHA/CI: main 6c116ac — gate penuh ./check.sh lolos pada 6c116ac (2.988 PHPUnit, 1.436 Vitest, build);
               CI Quality gate #421 hijau. Laporan ini: docs saja (docs/make-054-laporan-akhir-fase5)
               CR-082 → main d0f12c1 — gate penuh lolos (2.988 PHPUnit, 1.443 Vitest, build), CI #427 hijau
Berkas utama: §2
Test & validasi: §3 (AC 5C → test), §4 (QA Lapis 1), laporan 5A/5B untuk AC 5A/5B
Deviasi [V2] & keputusan: §6
UP-1: §5 (dicatat, bukan syarat)
Blocker / pertanyaan terbuka: §7; blocker go-live: §8
```

## 2. Paket Fase 5 di `main`

| Paket | Isi | Key | Masuk `main` | Laporan |
|---|---|---|---|---|
| 5A | Skema presensi & gaji, impor/akses/verifikasi Slip Gaji, presensi online/manual, adapter Tukin, FE 5A | DBV-023, MAKE-030..035, CR-067 | s.d. `54cb441` | `Laporan_Akhir_5A_MAKE-035.md` |
| 5B | Sumber cuti/TB nyata, uang makan slip, penyesuaian Tukin, Laporan & Rekap Tukin, rekap kehadiran & uang makan | MAKE-039..045, DBV-024 | s.d. `87f77c8` | `Laporan_Akhir_5B_MAKE-043.md` |
| 5C fondasi | Peta kode status, chip, legenda, `useFilterLaporan`, token warna `status.*` | MAKE-047 | `b1aa2ea` | §3 |
| 5C Laporan Tukin | Daftar pegawai, IL, per pegawai, "Saya", unduh PDF | MAKE-048 | `2b5903e` | §3 |
| 5C Rekap Tukin | Rekap + IL, ekspor XLSX/PDF | MAKE-049 | `fde58a3` | §3 |
| 5C Penyesuaian Tukin | CRUD role 1 lewat ⋮ | MAKE-050 | `52e6bf4` | §3 |
| 5C Rekap Kehadiran & Uang Makan | Rekap ringkas/harian, uang makan + IL | MAKE-051 | `7387e71` | §3 |
| 5C D-13 | Suite regresi 12 skenario | MAKE-052 | `d86a04a` | §3 |
| 5C penutup | `ACTIVE_PHASE` 4 → 5, hapus `rilis` Presensi | MAKE-053 | `12f2f46` | §3 |
| Perbaikan review | `harian[].kode` (CR-075), baris non-kerja harian & PDF (CR-076) | CR-075, CR-076 | `a477fc5`, `74a2e35` | §6 |
| Perbaikan QA Lapis 1 | Tata letak & laporan FE (CR-077, CR-078), tata letak lanjutan (CR-079), picker meluap (CR-081) | CR-077..079, CR-081 | `7eac943`, `e9027e5`, `6c116ac` | §4 |
| Perbaikan alat | Opsi CLI `--k=v` (`presensi:paritas-tukin`, `cuti:saldo-tahunan`) | CR-080 | `57122dd` | §5 |
| Dokumen | PRD 5C v1.1, task list paralel + Tahap UP-1 | — | `15beef1` | — |

## 3. Keterlacakan AC 5C → bukti

AC 5A dan 5B sudah ditelusuri ke test di laporan masing-masing (disetujui saat sign-off 5A/5B) dan tidak diulang.
Path FE relatif ke `frontend/src/features/presensi/__tests__/`, BE relatif ke `backend/tests/`.

| AC | Bukti |
|---|---|
| AC-FE5C.1 halaman hanya untuk role-nya; role lain → 403 | `navPresensi.spec` (route & role Laporan/Rekap Tukin), `RekapKehadiranPage.spec` / `RekapUangMakanPage.spec` / `PenyesuaianTukinPage.spec` ("akses role"), `shared/layouts/__tests__/nav.config.spec`; server: `Presensi/Laporan/RoutePresensiLaporanKontrakTest` (23 route × role 1–8); QA §B, §C |
| AC-FE5C.2 filter → query API; role 3 tanpa filter lingkup; 422 per kolom | `useFilterLaporan.spec` (param role 1, role 3/4, URL), `LaporanTukin.spec` ("role 3: tidak ada pemilih Unit/Satker"), `RekapTukin.spec`, `RekapKehadiranPage.spec` ("filter lingkup"), `laporanTukin.amplop.spec`, `rekapTukin.amplop.spec`; 422: `PenyesuaianTukinPage.spec` ("galat 422 per field"); QA D1, D2, F1, F8, H1 |
| AC-FE5C.3 warna + teks + legenda; kontras ≥ 4,5:1 | `statusPresensi.spec` (kode lengkap, warna legacy K5C-1, kontras token ≥ 4,5:1, tanpa heks lepas, legenda); QA §E |
| AC-FE5C.4 densitas tinggi di 1440/1024/390 | `RekapTukin.spec` ("densitas tinggi: No/Nama/NIP sticky"), `LaporanTukin.spec` (CR-077/079); QA F2, H2, A2, A3 setelah CR-077/079/081 |
| AC-FE5C.5 nama berkas dari server; 422 batas baris sebagai pesan | `laporanTukin.amplop.spec` ("pdf(): nama berkas dari Content-Disposition"), `rekapTukin.amplop.spec` ("galat: 422 unit_satker"), `RekapTukin.spec` ("ekspor PDF di atas batas … tampil sebagai pesan"); QA D9, F5, F6, H3, H5 |
| AC-FE5C.6 CRUD penyesuaian lewat ⋮ + `ConfirmDialog`, hanya role 1 | `PenyesuaianTukinPage.spec` (⋮ Edit → Nonaktifkan/Aktifkan → Hapus, hapus dengan konfirmasi, NIP terkunci, akses role 1); QA §G |
| AC-FE5C.7 klien HTTP asli, tanpa amplop ganda | `laporanTukin.amplop.spec`, `rekapTukin.amplop.spec`, `penyesuaianTukin.amplop.spec`, `rekapKehadiran.amplop.spec` (+ uang makan); `res.data.data` hanya muncul di komentar test (grep MAKE-054) |
| AC-FE5C.8 komponen custom saja; keyboard & ARIA | Grep MAKE-054: halaman & komponen 5C tanpa `<select>`, `<datalist>`, input tanggal/bulan native, atribut `title`, `window.confirm/alert/prompt`; chip `role="img"` + `aria-label` (`statusPresensi.spec`); `shared/ui` baru `pickerControl` (CR-081) + `shared/ui/__tests__/pickers.spec`; QA A1, A9 |
| AC-D13.1 12 kelas skenario, grup `d13`, `composer test:d13`, `./check.sh` | `Presensi/RegresiD13/S01…S12*Test.php` (12 berkas, masing-masing `#[Group('d13')]`), script `composer test:d13`; ikut gate penuh `6c116ac` (2.988 PHPUnit) dan CI #421 |
| AC-5C.X1 `ACTIVE_PHASE = 5`, tanpa `rilis` Presensi | `navPresensi.spec` ("ACTIVE_PHASE = 5 sejak penutup 5C"), `nav.config.spec`; QA B9 |
| AC-5C.X2 QA Lapis 1 5C lolos; laporan memuat blocker go-live & status UP-1 | §4, §5, §8 laporan ini |

### 3.1 DoD Trello QASMTASK-076..088 → tempat bukti

| DoD | Task | Bukti |
|---|---|---|
| 076 | D-01 skema (DBV-023) | Laporan 5A §3 D-01 |
| 077 | D-02 presensi online + geofence | Laporan 5A §3 D-02 |
| 078 | D-03 presensi manual | Laporan 5A §3 D-03 |
| 079, 080, 082 | D-04, D-05, D-07 kalkulasi Tukin | Rumus murni CR-037/039; adapter Laporan 5A §3 5A-4; integrasi Laporan 5B §3 D-06 |
| 081 | D-06 sumber cuti & TB | Laporan 5B §3 D-06 |
| 083 | D-08 Laporan Tukin (BE + FE) | Laporan 5B §3 D-08; FE §3 di atas, QA §D, §F, §G |
| 084 | D-09b rekap kehadiran & uang makan (BE + FE) | Laporan 5B §3 D-09b; FE §3, QA §H |
| 085 | D-10 impor Slip Gaji | Laporan 5A §3 D-10 |
| 086 | D-11 Slip Gaji + D-09a uang makan slip | Laporan 5A §3 D-11, Laporan 5B §3 D-09a |
| 087 | D-12 FE (5A + 5C), color-coding, map picker | FE 5A: Laporan 5A §3 FE 5A + QA Lapis 1 FE 5A §4.1; FE 5C: §3, §4; map picker: §6 |
| 088 | D-13 regresi | §3 AC-D13.1 |
| (kartu baru) | D-14 verifikasi QR publik | Laporan 5A §3 D-14 |

## 4. QA Lapis 1 5C (AC-FE5C.4, AC-5C.X2)

Checklist `docs/fase5/Checklist_QA_Lapis1_Fase5C.md` (hasil diisi per butir). Dijalankan reviewer CR di app demo lokal
(data sintetis, captcha mock) untuk role 1–8 pada `12f2f46`, lalu QA ulang pada `15beef1`, `e9027e5`, `57122dd`,
`6c116ac`. Lebar 1440/1024 dicek lewat DOM, 423/375 langsung di layar.

| Hasil | Rincian |
|---|---|
| Role 2–8 | Lolos tanpa temuan (menu, 403 FE + API, lingkup, laporan "Saya") |
| Role 1 + QA ulang | 14 temuan (T-01..T-15, T-08 batal): T-01..T-09 perilaku/tampilan FE, T-10/T-11 tata letak (temuan Horii), T-12..T-15 dari QA ulang (regresi CR-077, layar sempit, kepadatan, picker meluap) |
| Penutupan | CR-077 + CR-078 (`7eac943`), CR-079 (`e9027e5`), CR-081 (`6c116ac`); semua diverifikasi ulang di demo |
| Map picker | I1/I2 lolos (geolokasi disimulasikan di halaman; tanpa permintaan ke penyedia peta) |

### 4.1 QA Lapis 1 FE 5A (Q5A-18, AC-FE.9)

Checklist `docs/fase5/Checklist_QA_Lapis1_FE5A_Q5A-18.md` (hasil diisi per butir). Dijalankan reviewer CR di app demo
pada `6c116ac` untuk role 1, 2, 3, 6 (menu role 4/5/7/8 dari QA 5C), data slip sintetis lewat impor UI, geolokasi
disimulasikan di halaman (pengaturan browser tidak diubah).

| Hasil | Rincian |
|---|---|
| Lolos | Menu & 403 per role, Slip Gaji (empat keadaan, gerbang tanggal 4, modal buka sekali, isi A/B/C tanpa Pajak TK, muat ulang tanpa nominal, Cetak PDF), PDF (legacy `0cf7e67`, QR hanya bila dibuka), Kelola Slip (monitoring, detail, koreksi 28 kolom, buka ulang, ajakan impor), Impor (validasi berkas, filter periode, pratinjau galat/duplikat, timpa bawaan, simpan), Presensi Saya (jam lokal, status lokasi, radius, jendela waktu, pegawai tanpa aturan), Data Presensi (lingkup, detail, tambah/ubah/hapus manual, detik tetap), Verifikasi publik (pesan seragam, nama tersamar, 429, `noindex`) |
| Temuan rendah | T5A-01 verifikasi tidak memuat ulang saat token berganti di SPA; T5A-02 label baris ganda di rincian galat impor; T5A-03 zona kosong QR minim (pindai fisik belum); T5A-04 fokus ke BODY setelah aksi ⋮ yang memuat ulang daftar (Kelola Slip, Data Presensi) |
| Deviasi | D-01 PDF ikut legacy, bukan PNG Figma export PDF yang lebih lama; D-02 pilihan periode admin memuat periode masa depan yang sudah diimpor |
| Tidak teruji di demo | G3/G4 GPS perangkat nyata lewat HTTPS, pindai QR fisik → blocker go-live §8 butir 4; F6 409 hanya pada balapan (BE `ImporSlipKonflikUniqueTest`) |

## 5. Status Tahap UP-1 (dicatat, bukan syarat sign-off Fase 5 — K5C-10)

UP-1 (paritas Tukin v2 vs legacy pada data nyata, dua periode terakhir, Q5C-06) adalah **tahap tersendiri** antara Fase 5
dan Fase 6 (`TaskList_Fase5_Paralel.md` §6). Fase 6 terkunci sampai UP-1 di-sign-off Horii (Trello `GATE-F6`).

| No | Butir | Status 29-10-2026 |
|---|---|---|
| U1 | Keputusan P-01..P-09 | P-01 (DBA = DBV) dan P-06 (alat impor resmi) diputus; P-02 sebagian; P-03, P-04, P-05, P-07, P-08, P-09 terbuka |
| U2 | Paket siap-jalan UP-1 (MAKE-055) | PR #31 terbuka, **tidak di-merge** sampai P-01..P-09 dikunci |
| U3 | Alat impor resmi `php spark impor:legacy` (MAKE-056) | PR #32: review CR selesai, CI hijau; menunggu review DBV atas "Keputusan DBV yang dibutuhkan" + izin merge Horii |
| U4..U10 | Ekstraksi, impor, paritas, perbaikan, sign-off UP-1, bersih-bersih, buka GATE-F6 | Belum mulai |

Alat paritas `php spark presensi:paritas-tukin` (5B M7) siap dan menerima opsi `--csv=`/`--toleransi=` sejak CR-080.
Data nyata UP-1 tidak pernah masuk repo, Trello, Notion, atau percakapan agen.

## 6. Deviasi [V2] & keputusan 5C

Keputusan 5A (P-1..P-4, F5-1..F5-24) dan 5B (F5B-1..F5B-23, Q5B-xx) tetap berlaku (lihat laporan masing-masing).
Keputusan pelaksanaan 5C lengkap di PRD 5C §5.1 (K5C-1..K5C-10). Ringkas:

- **Map picker (Q5C-01):** tetap `IndikatorRadius` SVG (lingkaran radius + posisi relatif), tanpa peta tile, tanpa kunci
  API. **Gap:** tanpa peta jalan/latar; titik lokasi admin dipilih lewat input koordinat di Master Data, bukan peta.
- **Warna (K5C-1):** latar baris tabel harian ikut legacy [K]; chip kode `TL`/`PSW`/`TPM`/`TPP`/`TK`/`CS` [V2]; rekap
  tanpa warna baris (ikut legacy); PDF memakai palet soft yang sama dengan layar (legacy PDF berbeda palet, dicatat V2).
- **Role 4/5 (K5C-4):** tanpa menu Laporan Tukin per pegawai (ikut legacy); route tetap terbuka, lingkup ditegakkan server.
- **Bulan bawaan (K5C-6):** halaman Tukin = periode 16–15 [K]; Rekap Kehadiran & Uang Makan = bulan kalender.
- **Penyesuaian Tukin:** ⋮ Edit → Nonaktifkan/Aktifkan → Hapus (AGENTS §1; PRD §7.4 semula Edit → Hapus); status tidak
  di form; PUT mengirim body lengkap (K5C-5).
- **Lain:** tanpa cetak HTML dari layar (Q5C-04 ◻); blok penanda tangan hanya di berkas (Q5C-08 ◻); status memuat berupa
  teks "Memuat…" (pola seluruh modul); app belum punya tema gelap (kontras diuji untuk tema terang); Figma halaman
  laporan belum ada (QAUI-005) — dinilai terhadap pola halaman 5A/Fase 4.

## 7. Butir terbuka sebelum sign-off Fase 5

| No | Butir | Keterangan | Usulan reviewer |
|---|---|---|---|
| 1 | Temuan rendah QA FE 5A T5A-01, T5A-02, T5A-04 | Perbaikan FE kecil (muat ulang saat token berganti, label galat impor, fokus ⋮ saat daftar dimuat ulang) | Satu CR (CR-082) sebelum sign-off, **atau** sign-off dengan temuan dicatat lalu CR-082 sesudahnya — keputusan Horii |
| 2 | D-01 PDF slip: legacy vs PNG Figma | PDF v2 = tata letak legacy `0cf7e67` (keputusan CR-067 ikut legacy); PNG Figma export PDF versi lebih lama | Tetap ikut legacy (tidak ada perubahan); ubah ke Figma = CR baru |
| 3 | T5A-03 zona kosong QR | Belum terbukti mengganggu | Pindai fisik di uji perangkat (§8 butir 4); CR bila gagal terbaca |

**Keputusan Horii 29-10-2026:** butir 1 → CR-082 dikerjakan sebelum sign-off (`d0f12c1`); butir 2 → PDF slip tetap
ikut legacy; butir 3 → pindai fisik saat uji perangkat (blocker go-live §8 butir 4). Ketiganya ditutup dengan sign-off
Fase 5.

Tidak ada pertanyaan ⛔ 5C lain yang terbuka (Q5C-01, Q5C-03, Q5C-06, Q5C-09 diputus; Q5C-02/04/05/07/08 ◻ default berlaku).

## 8. Blocker go-live (bukan blocker sign-off Fase 5)

1. **Tahap UP-1** — paritas Tukin data nyata (Q5B-09, K5C-10); juga gerbang Fase 6.
2. **Cutover:** QC-1 (koreksi NIP pada volume `dh_online` realistis), QC-2 (akar aset & logo kop slip), QC-3 (konfirmasi
   Biro Keuangan data verifikasi QR publik, Q5A-08), QC-4 (`Config\App::$proxyIPs`), QC-5 (`encryption.key` + HTTPS);
   akun bootstrap admin saat impor (catatan MAKE-056).
3. **Data produksi:** checksum kode legacy produksi (Q5A-14 / Q5B-15); nama `jenis_pegawai` id 6 (Q5A-25); cek ulang
   `gaji_pegawai` (DEPLOY-002.1); isi `penyesuaian_tukin` (lewat CRUD), tarif uang makan dan `gol_pppk` produksi.
4. **Uji perangkat:** GPS perangkat nyata lewat HTTPS (Q5A-22, checklist FE 5A G3/G4); pindai QR slip fisik (T5A-03).
5. **Konfirmasi bisnis:** Q5B-01 (Biro SDM/Keuangan X-1, X-5, X-6, X-10, X-12), Q5B-03 (penanda TB uang makan), Q5B-19
   (uang makan PTT/golongan di luar I–IV).
6. **Runbook 5B:** R5B-1 (audit DBV-024 C-2), R5B-3 (batas PDF diukur di produksi), R5B-4 (id unit penanda tangan) —
   `backend/docs/progress/05-Presensi.md`.

## 9. Setelah sign-off

Sinkron Trello DEV-006 (+ QASMTASK-076..088, QASMTASK-119 D-14) dan Notion baris Fase 5 ke selesai — **selesai
29-10-2026**. Butir DoD yang sengaja tidak dicentang: "Lolos MTC-0xx" / "Sesuai MTC-033" (MT fase tersendiri),
cross-check rumus 079 (Q5B-01, blocker go-live) dan rekap per golongan PPPK 084 (F5B-20). Fase 6 tetap terkunci oleh
`GATE-F6` sampai UP-1 di-sign-off.
