# Progress Fase 5 — Modul D: Presensi & Remunerasi

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `05-Presensi.md`. Aturan Slip Gaji (sumber legacy, keputusan, hak akses, hal yang menunggu keputusan): `backend/app/Libraries/Presensi/README.md`.

**Status:** sebagian — pengecualian urutan fase, disetujui user.

**Terakhir diperbarui:** 2 Oktober 2026 (CR-039: kalkulasi Tukin diselaraskan ke rekap legacy aktif `laporan_tukin_us_skp` dan butir Slip Gaji yang menunggu terjawab dari legacy; CR-037: kalkulasi Tukin murni di `Presensi/Tukin`; CR-027: jalur "Slip Gaji (a)" disetujui user 01-10-2026 — dokumen perilaku + logika murni Slip Gaji mendahului urutan fase)
**Entry criteria:** Fase 2 (web_config) + Fase 3 (data jabatan) + Fase 4 (cuti/tugas belajar) DONE — belum terpenuhi. Pekerjaan di file ini sampai entry criteria terpenuhi hanya **persiapan yang tidak butuh tabel baru**.

| Task | Status | Ringkas |
|---|---|---|
| D-10 Slip Gaji: Import Excel | TODO | Aturan pratinjau murni tersedia (CR-027): parser nilai mentah sel, header/sheet, NIP/bulan/tahun, duplikat dalam berkas, pasangan GPP–TK, pencocokan nama (tolak + daftar untuk log), status valid/duplikat/galat, rencana commit. Pembaca berkas, unggah, simpan pratinjau, commit DB, dan log menunggu D-01 |
| D-11 Slip Gaji: Akses Pegawai | TODO | Gerbang waktu (tanggal 4 pukul 10:00 WIB, inklusif), status buka/buka ulang, token QR, dan kebijakan hak akses tersedia sebagai logika murni (CR-027). Endpoint, PDF, QR, dan verifikasi publik menunggu D-10/D-01 |
| D-04..D-07 Kalkulasi Tukin | **IN_PROGRESS** | Logika murni CR-037 di `Presensi/Tukin`, diselaraskan CR-039 ke rekap legacy aktif `laporan_tukin_us_skp` (periode 16–15/BKN dengan Januari mulai 16 Des, faktor presensi 0,2, cuti sakit hari ke-15+, cuti besar/melahirkan pengganti total, SKP periodik 0/16/32, pemutihan TB, cuti sepanjang periode, masa kerja; tanpa LKH, TB 25%, dan cuti alasan penting) beserta unit test sintetis. Tabel, adapter `web_config`/data pegawai, dan controller menunggu fase terkait (README `Presensi/Tukin`) |
| D-13 Unit Test Kalkulasi Tukin (Lengkap) | **IN_PROGRESS** | Unit test murni Tukin (CR-037, `tests/unit/Presensi/Tukin/`) dan Slip Gaji: butir "Gate waktu slip gaji" (tepat sebelum/sesudah ambang, zona, lintas tahun) dan bagian murni butir "Import slip gaji" (mismatch nama, duplikat dalam berkas). Belum: pelanggaran constraint saat commit (butuh tabel D-01), test integrasi di `tests/Presensi/`, dan test integrasi Tukin ber-DB |
| D-01..D-03, D-08, D-09, D-12 | TODO | Belum dimulai |

## Persiapan tanpa skema

### CR-037 — Kalkulasi Tukin murni (bagian D-04..D-07/D-13)

CR-037 menyelesaikan fondasi logika murni periode, hari kerja, jam kerja, precedence status, klasifikasi potongan, dan agregasi Tukin beserta unit test sintetis. Tabel presensi, cuti, tugas belajar, konket, adapter `web_config`, controller, dan laporan tetap menunggu fase terkait. Rincian aturan dan perbedaan [V2]: `app/Libraries/Presensi/Tukin/README.md`.

### CR-039 — Tukin ikut jalur aktif legacy; Slip Gaji terjawab dari legacy

CR-037 mengacu `laporan_tukin` (:3628), yang ternyata jalur lama (≤ Maret 2024). Jalur aktif adalah `laporan_tukin_skp` (per pegawai) dan rekap `laporan_tukin_us_skp` (Excel pembayaran); keputusan user 02-10-2026 (K-1): bila keduanya berbeda, v2 ikut rekap. CR-039 menyelaraskan library ke rekap (aturan dan rujukan baris di README Tukin) dan menambah input `DataPegawaiTukin` (predikat SKP, jumlah anak, TMT masuk/keluar). Deviasi [V2]: hari ≥ TMT keluar dikecualikan (bug rekap), cuti sepanjang periode dihitung atas periode tukin. Tidak ada lagi pertanyaan rumus untuk Biro SDM/Keuangan.

Slip Gaji (CR-027): opsi timpa impor bawaan tercentang (`PratinjauImpor::TIMPA_BAWAAN`), `RingkasanSlip::periksaKonsistensi` dihapus (legacy tidak punya cek invarian), format waktu dibuka "4 Oktober 2026, 10:00 WIB" mengikuti halaman pegawai legacy, dan butir menunggu Biro Keuangan/user dipindah ke "Terjawab dari legacy" di README modul. Cakupan role 3 per satker berasal dari PRD SG-2 (legacy tidak membatasi).

### CR-027 — Slip Gaji: dokumen perilaku + logika murni (bagian D-10/D-11/D-13) — pra-review internal sisi CR

Key review **CR-027** (CR saja). Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/` dan tidak perlu review DB Validator. Tanpa migration, controller, endpoint, FE, atau dependensi composer baru.

**Sudah jalan**
- `app/Libraries/Presensi/SlipGaji/` (namespace `App\Libraries\Presensi\SlipGaji`), 15 kelas murni: `HasilSlip`, `PeriodeSlip`, `GerbangPeriode`, `NominalGaji`, `KolomGaji`, `NilaiSel`, `BarisImpor`, `BarisSheet`, `PratinjauImpor`, `PencocokanNama`, `RingkasanSlip`, `StatusSlip`, `TokenQr`, `AksesSlip`, `FormatSlip`.
- Aturan legacy `39b6b15` (`libraries/hr/Lsl_gaji.php`, `controllers/hr/Sl_gaji.php`, view `sl_gaji/*`, `verify/slip.php`) dipetakan ke [K]/[V2] dengan keputusan IKUT/PERBAIKI di README modul (butir TUNGGU terjawab dari legacy di CR-039), termasuk perbaikan bug salah baca angka (×10 pada float bertitik, ×100 pada form koreksi), NIP numerik yang kehilangan presisi, dan kolom/filter yang di legacy diabaikan diam-diam.
- Hak akses ikut legacy (keputusan user 01-10-2026): pegawai 2/6/7 slip milik sendiri, admin 1 semua, admin 3 hanya satker sendiri (fail-closed), role 4/5/8 tanpa akses; deviasi dari Matriks/FSD/05-Presensi dicatat di README.
- Test `tests/unit/Presensi/SlipGaji/` (tanpa DB, data sintetis): `GerbangPeriodeTest`, `PeriodeSlipTest`, `NominalGajiTest`, `KolomGajiTest`, `BarisImporTest`, `PratinjauImporTest`, `PencocokanNamaTest`, `RingkasanSlipTest`, `StatusSlipTest`, `TokenQrTest`, `AksesSlipTest`, `FormatSlipTest`, `HasilSlipTest`, dan penjaga arsitektur `SlipGajiMurniTest` (tanpa DB/HTTP/session/jam/acak/I/O; semua `Time::`, `::createFromFormat`, dan `hariIni()` tanpa argumen dilarang; nama fungsi berawalan \ juga terdeteksi). 149 test, 909 assertion.
- Uji mutasi: 27 mutasi pada kode Slip Gaji (batas inklusif rilis, hari/jam rilis, zona WIB, periode awal, rumus total, toleransi float, batas digit, pembulatan rupiah, NBSP, NIP teks, kolom wajib, baris ganda, nama per sheet, cakupan satker, prioritas status, pola token/periode) semuanya membuat test gagal. Putaran review mandiri: 41 mutasi tambahan (batas bulan 1/12 dan tahun 1900/2100, filter periode, prioritas status, header/sheet wajib, rencana commit, penjaga int sen, jam sebelum periode awal, pemindai kemurnian); 38 membuat test gagal setelah test batas ditambah, 3 sisanya ekuivalen (penjaga luapan cast float, batas digit yang juga dijaga batas sen, panjang NIP pada urutan yang sudah numerik).

**Belum / di luar CR-027** (rincian di README modul, bagian "Menunggu Fase 5")
- D-01: skema `gaji_pegawai` (28 kolom nominal), tabel riwayat token, volume histori, konversi stempel WIB → UTC (DB Validator).
- D-10: pembaca berkas PhpSpreadsheet nilai mentah, unggah aman, pratinjau di server, commit per baris dengan tangkapan UNIQUE, log ketidakcocokan nama, template.
- D-11: endpoint daftar/status/buka (atomik)/cetak PDF/koreksi/buka ulang, verifikasi publik `/verify/slip/{token}`, generator QR, tukin kelas jabatan dan uang makan (D-08/D-09).
- D-12 FE (menu ⋮, `ConfirmDialog` untuk buka ulang) dan D-13 test integrasi di `tests/Presensi/`.

**Keputusan**
- Butir Biro Keuangan dan user/reviewer CR terjawab dari kode legacy (CR-039; README modul, bagian "Terjawab dari legacy").
- Masih terbuka, tidak memblokir: ekstraksi pemindai kemurnian ke `tests/_support/Libraries/` (U-8, reviewer CR).

**Catatan lokasi test:** kontrak D-13 menyebut `tests/Presensi/`. Test logika murni ditaruh di `tests/unit/Presensi/` mengikuti konvensi repo (sama dengan CR-025); test integrasi ber-DB D-13 tetap di `tests/Presensi/`.
