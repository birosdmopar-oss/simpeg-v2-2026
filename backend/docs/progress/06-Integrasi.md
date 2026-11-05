# Progres Fase 6 — Modul H: Integrasi & API Eksternal

Acuan: `docs/fase6/PRD_Fase6_Integrasi.md`. Pembagian: `docs/fase6/6_2.md` (Jalur A, Qoder B) dan `docs/fase6/6_1.md`
(Jalur B, Qoder A). ✅ GATE-F6 terbuka 02-11-2026 (UP-1 LOLOS, `docs/up1/Hasil_UP-1.md`).

## Status

| Task | Jalur | Key | Status |
|---|---|---|---|
| H-01 | A + B | DBV-026, DBV-027 | B: DBV-027 di main `c3c6213f` (+ DBV-030 `4c2d96a5`); A: diisi Jalur A |
| H-02 | B | MAKE-065 | B4a di main `07ac6711`; B4b `services/s_esign` di branch `fase6/make-065-s-esign` |
| H-03 | B | MAKE-067 | Di main `6cd50e69` |
| H-04 | B | MAKE-066 | Di main `9debb366` (dikerjakan sesi WS-2) |
| H-05/H-06/H-07 | A | MAKE-059, MAKE-060 | Belum mulai |
| H-08 | A | MAKE-061 | A6 selesai (versi lokal, Worker Fable) di branch `fase6/a-lokal/make-061-s-stat`, menunggu review |
| H-09 | A | (pemetaan) | Belum mulai |
| H-10 | B | MAKE-064 | Di main `74039dc7` |
| H-11 | A | MAKE-062 | A2a selesai (versi lokal), menunggu review |
| H-12 | A | MAKE-068 | Kode di branch `fase6/a-lokal/make-068-lkh-auto-approval`, belum merge |
| Fondasi | A / B | MAKE-058 / MAKE-063 | B: MAKE-063 di main `74039dc7`; A: diisi Jalur A |

## Bagian A — API masuk & job terjadwal

(diisi Jalur A per milestone: keputusan, deviasi, catatan)

### A2a — MAKE-062 status TB/CLTN harian (H-11), versi lokal

`php spark layanan:status-harian [--dry-run] [--tanggal=YYYY-MM-DD]` (PRD §7.9, F6-20, CR-098). Aturan murni di
`Libraries/Layanan/StatusHarian/PenentuStatusHarian` (tanggal diinjeksi), lapisan DB `StatusHarianService`, command
`Commands/LayananStatusHarian`. ADR-037; jadwal dan langkah run pertama di `README-deploy.md` bagian 1f.

Keputusan implementasi (reviewer CR 04-11-2026):
- Induk TB dan tiap perpanjangan (`riwayat_tb_perpan`) = segmen terpisah, sama dengan `BebasPresensiTb`. Bentrok memakai
  `tgl_mulai` segmen yang sedang aktif. Celah antara akhir TB dan perpanjangan yang belum mulai: kembali 1 lalu 2 saat
  perpanjangan mulai (INFO `celah_tb`); perpanjangan bersambung tidak berkedip.
- Pegawai `status ≠ 1` dilewati (INFO); `id_jenis_status` selain 1/2/3 tidak disentuh.
- Pelaku audit = `AkunSistemIntegrasi::id()`; belum siap → `EXIT_ERROR` sebelum menulis; dry-run tanpa akun.
- Tanpa `integrasi_log` (bukan integrasi): ringkasan jumlah ke CLI + `log_message('info')`; audit per perubahan di
  `audit_logs` (`jenis_audit` `layanan.status_harian`).
- Transaksi kecil per pegawai (kunci baris, cek belum berubah, update, audit); kegagalan satu pegawai dihitung, kode keluar
  `EXIT_ERROR`. `GET_LOCK` sibuk → `EXIT_ERROR`.
- Dry-run tanggal lampau dihitung terhadap status pegawai SAAT INI (diberi label di keluaran).
- Pegawai berstatus 3 dengan TB aktif tanpa CLTN aktif menjadi 2 (Q6-24 butir "selain itu"); status 2/3 hanya dipulihkan ke 1
  bila ada riwayat TB (untuk 2) atau CLTN status 1 (untuk 3) yang sudah berakhir.

`pengguna:sinkron-nama [--dry-run]` (F6-21 bersyarat; padanan legacy `Cron.php:2818-2877` `sync_nama_pengguna`, reviewer CR 04-11-2026):
`Libraries/Kepegawaian/Pegawai/SinkronNamaPengguna`, `Commands/PenggunaSinkronNama`. `pengguna.name` (kolom ada di v2) disamakan dengan
`StrukturService::namaGelar` untuk pengguna `status = 1`, `user_level = 2` (`Role::PEGAWAI`, padanan `UserLevel 2` legacy), ber-NIP, pegawai
`status = 1`; hanya bila berbeda dan nama tidak kosong; akun sistem dan soft-delete dikecualikan; dipotong 150 karakter (INFO);
transaksi kecil per akun + `audit_logs` (`jenis_audit` `pengguna.sinkron_nama`, pelaku akun sistem); `GET_LOCK`. Jadwal harian 01:00
WIB masih USULAN (PN-5).

### A2b — MAKE-068 auto-approval LKH (H-12, F6-25)

- Command `lkh:auto-approval [--bulan=YYYY-MM] [--dry-run]` (`App\Commands\LkhAutoApproval`), jadwal runbook `README-deploy.md` §1g.
- Lapisan: `Libraries/Kepegawaian/Lkh/AutoApproval/` — `PenentuAutoApproval` (murni: bulan lalu, rentang, penolakan bulan),
  `AutoApprovalService` (kunci `GET_LOCK` tunggu 0, transaksi per LKH, hitungan), `PenyetujuLkh` (kunci baris `FOR UPDATE`,
  cek status 0 dan bulan, ubah lewat `LkhModel` beraudit).
- Keputusan CR-093: PDF tidak dibangkitkan atau disimpan job; catatan = kolom `riwayat_lckh.auto_approval` + `audit_logs`, tanpa
  tabel `aa_lkh`. `LkhModel::allowedFields` menambah `auto_approval` (tanpa migrasi).
- Ikut legacy: pegawai tidak aktif dan atasan tidak difilter; job tidak mengirim push (`show_notif` tidak diubah).
- Test: `tests/unit/Kepegawaian/Lkh/AutoApproval/PenentuAutoApprovalTest.php`, `tests/database/Kepegawaian/LkhAutoApprovalTest.php`
  (AC-12.1, AC-12.2, AC-12.3; status revisi; dry-run; galat sebagian; kunci sibuk; pelaku wajib).

### A3 — MAKE-058 auth antar-sistem + ADR-036 (versi lokal)

Filter `klienLayanan:<hak>` (Basic) dan `simapiToken`, `Libraries/Integrasi/Klien/*`, `Config\KlienLayanan`, service `klienSaatIni`,
command `integrasi:klien buat|rotasi|nonaktif|aktifkan|hapus|daftar|bersihkan-token`. ADR-036; runbook `README-deploy.md` §1h.

Keputusan implementasi:
- Syarat DBV-026 §13.2 "A4" (pencarian PK `token_hash`, panjang dibatasi sebelum hash, `status = 1 AND expired_at > UTC_TIMESTAMP()`,
  klien status 1) dipenuhi di MAKE-058 karena filter token termasuk fondasi Jalur A (6_2.md §A3); A4 (MAKE-059) hanya memakainya.
  Konfirmasi reviewer CR 05-11-2026; dokumen DBV tidak diubah.
- A1 (cabut token saat nonaktif/hapus) dan A2 (pembersihan berkala) dan A3 (validasi allowlist/hak) DBV-026 §13.2 dipenuhi dan diuji.
- `rotasi` ikut mencabut semua token klien (rahasia lama dianggap bocor) — keputusan MAKE-058, bukan syarat DBV.
- Hanya kegagalan auth yang ditulis ke `integrasi_log` (arah masuk, `id_klien`); keberhasilan tidak, karena volume SIMAPI tinggi.
- Throttle: bucket kegagalan per IP (sukses tidak menghabiskannya) dan per klien; log penolakan maks sekali per kunci per jendela; ambang di `Config\KlienLayanan` (120/IP, 600/klien per 60 detik) — usulan
  awal, disetel setelah ada data trafik klien nyata.
- Tidak ada perubahan kontrak: amplop gagal SIMAPI HTTP 200 `{"status":"02"}`, `services/*` HTTP 401 `{"status":2}` (PRD §6.3).

### A6 — MAKE-061 `services/s_stat` (H-08, versi lokal)

Pelaksana: Worker Fable (take over dari Worker Haiku 05-11-2026; analisis Haiku di branch `fase6/a-lokal/make-061-analisis`).
Branch `fase6/a-lokal/make-061-s-stat`. Isi: `Controllers/Services/SStatController`, `Config/RoutesServices.php` (berkas bersama
`services/*`, didaftarkan di `Routing::$routeFiles`), `Libraries/Integrasi/Stat/{KalenderStat,RekomendasiKp,RekomendasiKgb,
RekomendasiPensiun,PenyajiStat,StatService}`, `Libraries/Integrasi/Layanan/ResponsLayanan` (amplop INT bersama A4/B4b). Dokumen:
`Libraries/Integrasi/README.md` (bagian s_stat: keputusan, anomali [K], deviasi [V2], tabel perbedaan vs `Kalkulasi/*`, gap),
`Controllers/Services/README.md` (endpoint & kolom).

Keputusan implementasi (reviewer/Horii 05-11-2026):
- Q6-37 = A: `rekomendasi_kp_uns` berfilter → `data: []` (`[K] bug legacy Q6-37`, dikunci AC-08.4).
- Q6-38 = A: URL arsip MuDig = endpoint unduh klien `GET services/s_stat/berkas/{jenis}/{nip}/{id}[/{kolom}]` di balik
  `klienLayanan:stat`; daftar putih jenis (kp/skp/jabatan/kgb; pak/tb/ib/karpeg/kariskarsu + kolom `file_*` persis migration v2);
  404 seragam; `skp` dibatasi status 1 tahun Y-1/Y-2; tanpa pembatasan per populasi rekomendasi; semua URL lewat
  `PenyajiStat::urlBerkas()`.
- Format tanggal ikut kode legacy (`d M Y`, `M Y`, `F`), bukan DD-MM-YYYY (Q9 dikoreksi); edge `gol_ruang` kosong direplikasi;
  KGB hanya `arsip_last_kgb`; `file_*` daftar = URL/kosong [V2].
- Perbedaan aturan dengan `Kepegawaian/Kalkulasi/{KenaikanPangkat,KenaikanGajiBerkala,Pensiun}` dicatat di README modul;
  `Pensiun::batasUsia()` dipakai ulang (identik), `tanggalPensiun()` TIDAK (legacy meluap bulan).

Test: `tests/unit/Integrasi/Stat/{RekomendasiKpTest,RekomendasiKgbPensiunTest,StatMurniTest}` (AC-08.1 matriks tanggal batas,
anomali [K], penyaji, kemurnian), `tests/database/Integrasi/StatServiceTest` (AC-08.2 kolom per endpoint, arsip storage,
Q6-37, aturan unduh), `tests/database/Integrasi/SStatKontrakTest` (AC-08.3 401 amplop INT, AC-08.4, HTTP 200 amplop INT,
`list_tb` tanpa path internal, unduh 200 header aman / 404 seragam).

## Bagian B — Klien integrasi keluar

Pelaksana Jalur B (`docs/fase6/6_1.md`); reviewer CR; merge per paket atas izin Horii. Dokumentasi modul:
`backend/app/Libraries/Integrasi/README.md` (indeks + fondasi), `Bkn/`, `Siasn/`, `Esign/`, `Push/README.md`. Runbook:
`README-deploy.md` §1b–§1e.

### B1 — DBV-027 skema integrasi keluar (PR #35, main `c3c6213f`)

- Tabel `integrasi_log`, `siasn_status_pegawai`, `siasn_upload_arsip`, `double_riwayat_kp`, `siasn_ref_unor`,
  `siasn_referensi`; ALTER `firebase_token` (platform web/android, `token_hash` UNIQUE per pemilik, DBV-Q8) dan UNIQUE
  `riwayat_skp_periodik` (Q6-10). Dokumen `backend/docs/db-review/DBV-027-integrasi-keluar.md`. Migration murni skema;
  akun sistem lewat `integrasi:siapkan-akun-sistem` (Z-1 opsi c, level 1 ikut legacy DBV-Q10).
- CR-096 bersama PR #35: akun sistem tidak bisa login (401) dan tersembunyi + 403 di Manajemen Akun.

### B2 — MAKE-063 fondasi klien keluar & token BKN (main `74039dc7`)

- `KlienHttp` (retry hanya GET, POST tanpa retry, tanpa redirect), `Redaktor`, `IntegrasiLog` (kontrak dikunci CR-091),
  `KonfigurasiIntegrasi` (AC-K.4), `TokenBkn` (bentuk auth diekstrak tanpa nilai, CR-092/Q6-17; refresh sekali saat 401),
  command `integrasi:status`/`bersihkan-log`/`uji-koneksi`.
- Deviasi: token e-Kinerja literal di kode legacy → v2 `ekinerjaToken` dari `.env` (atau SSO). `bkn.tlsVerify` wajib true.
- Pelajaran review: test tidak mengunci teks `curl_strerror` (beda versi libcurl di CI).

### B3 — MAKE-064 e-Kinerja BKN (H-10, main `74039dc7`)

- `bkn:tarik-periode`, `bkn:tarik-skp-periodik [--sebelumnya]`, job `bkn-tarik` antrean `integrasi`, tombol admin
  `POST api/v1/integrasi/bkn/tarik`, peringatan periode Tukin tanpa SKP di `integrasi:status`.
- Ikut legacy: periode hanya insert, SKP update bila `hasil_akhir` berubah, aturan tanggal > 19. Deviasi: semua id
  berawalan `SKPManual` dilewati (legacy satu literal, Q5 B0). Temuan review B3 #1: transaksi `SinkronEkinerja` tidak lagi
  rollback diam-diam.
- Antrean bernama `integrasi` (putusan 03-11; 6_1.md semula menulis `siasn`, dikoreksi di B7).

### B4a — MAKE-065 BSrE & surat cuti ber-TTE (H-02, main `07ac6711`)

- `BsreEsignAdapter`; F6-18 K1 dua langkah, K2 batas 5/15 menit, K3a passphrase hanya Setujui/Tangguhkan, K3b QR TTE ke PDF
  publik, K3c halaman verifikasi publik; token DBV-030 (`surat_cuti_token`, main `4c2d96a5`); `surat-cuti:token`;
  `layanan.urlPublikSurat` (https wajib di production).
- Keputusan Horii 03-11 (Q6-18): TLS BSrE ikut legacy (`tlsVerify = false` diizinkan khusus e-sign, peringatan + jalur
  upgrade, README-deploy §1c). Logo QR = berkas logo legacy (`backend/app/Assets/esign/logo_qr_ttd.jpg`).
- Deviasi dicatat: gagal tanda tangan tidak membatalkan persetujuan (legacy membatalkan); Tolak tanpa passphrase.
- ISSUE-055 (saran S5 review B4a) ditahan.

### B4b — `services/s_esign` untuk MuDig (F6-16, branch `fase6/make-065-s-esign`)

- Dimulai sesudah A3 MAKE-058 di main `c926770f`. `check_user` + `sign_pdf` dengan filter `klienLayanan:esign`; route =
  sub-group `s_esign` di `Config/RoutesServices.php` + responder `ResponsLayanan` mode string (kerangka Jalur A main `539f4c7c`).
- Putusan reviewer U1–U6 (05-11): ikut legacy untuk kontrak klien — `status` string "1"/"2" (PRD §6.3 akan dikoreksi CR docs),
  `msg` `check_user` selalu null (bug legacy), parameter wajib hanya `nik`/`passphrase`/`file`/`imageTTD`, `ip_address`/
  `user_agent` dari POST.
- Deviasi [V2] F6-16: unggahan multipart (bukan path server), `esign_hist` selalu dicatat (isian kosong diisi bawaan: nama dari
  `pegawai`, `modul` 'MuDig'), `data.signed_file` = base64 PDF (legacy: path server), sukses hanya bila gateway mengembalikan
  PDF, batas 10 MB/2 MB di `Config\EsignMudig`.
- Review kode B4b @85b9b515 (0 blokir, 3 perlu, 7 saran) diperbaiki: TINYTEXT dipotong byte; PDF tetap dikembalikan bila
  `esign_hist` gagal sesudah TTE (pengecualian dicatat); `SensitiveParameter` pada NIK/PDF adapter; kunci `.env` huruf kecil;
  MIME/nama gambar & `image` diteruskan; validasi NIK; `check_user` GET/POST; `zend.exception_ignore_args` di runbook.

### B5 — MAKE-066 Push FCM HTTP v1 (H-04, main `9debb366`)

- Dipindah ke sesi WS-2 (keputusan Horii 03-11); temuan review B5 = CR-099. Kanal ikut legacy (web `fcmQueue_*`, Android
  `andr_notif_queue`, topik presensi), PMK web (Q-B5a). Penulis token Android = pertanyaan cutover DEPLOY-001.1.
- `integrasi:uji-koneksi fcm` semula masih "belum tersedia" (temuan B7) → diperbaiki CR-101: token OAuth baru +
  `messages:send` `validate_only` ke topik uji, tanpa kirim ke perangkat.

### B6 — MAKE-067 SIASN (H-03, main `6cd50e69`)

- M1 `siasn:tarik` (data utama + pairing, golongan, pendidikan, PMK, SKP ≥ 2022, AK) dan `ref-unor` (pemetaan lokal tidak
  disentuh, tanpa hapus); M2 `siasn:dorong` (jabatan, diklat, sertifikasi, kursus, SKP 2022; kriteria CR-090); M3
  `siasn:unggah-arsip` (antre + unggah 1 baris/jalan).
- Bentuk `postApi_siasn`/`postApi_siasnArsip` dikonfirmasi ekstraksi 04-11 (keputusan Horii, opsi A): JSON + header
  `accept`, sukses truthy `true`/`"true"`/`1`/`"1"`; multipart field `file`.
- Pengaman (bukan perubahan perilaku, F6-14): hapus fisik SKP hanya bila API NIP itu sukses; `Rw_ak:65` diperbaiki; POST
  tidak pernah di-retry; hasil POST yang gagal ditulis tidak memicu POST ulang (`hasil_tidak_tersimpan`, review M2 B-1 / M3
  B-1/P-1; jabatan tetap flag 3 + `siasn_lu` sekarang agar aturan 6 jam berlaku — disetujui reviewer).
- Deviasi dicatat: redirect tidak diikuti (legacy `FOLLOWLOCATION`), 3xx = gagal; saring `file_size < 1200` saat antre tidak
  dipakai (unit campuran KB/byte); `%PDF` dicari di 1024 byte pertama; nama berkas = basename tanpa NIP; `trim` nilai
  ref-unor; `riwayat_skp.updated_at` diisi eksplisit.
- Jadwal = usulan sementara di README-deploy §1e, dicocokkan dengan crontab legacy (PN-5).

### B7 — penutup B (dokumen)

- Progres ini, README modul (Integrasi indeks, Bkn, Esign, Push baru; Siasn diperbarui), README-deploy §1e (BKN/SIASN:
  aktivasi, worker `integrasi`, jadwal, kode keluar, risiko duplikat BKN, lokasi berkas rahasia), koreksi `6_1.md` §B7.

### Sisa / pertanyaan terbuka Jalur B

- PN-5 crontab legacy → validasi jadwal §1e. PN-3 proyek Firebase + service account. DEPLOY-001.1 penulis token Android.
- ADR-037 (pola penjadwalan, Jalur A): §1e menyesuaikan bila berbeda.
- B4b: kontrak baru untuk PIC MuDig (PN-4, re-handshake) — lihat README-deploy §1c.
