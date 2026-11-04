# Progres Fase 6 — Modul H: Integrasi & API Eksternal

Acuan: `docs/fase6/PRD_Fase6_Integrasi.md`. Pembagian: `docs/fase6/6_2.md` (Jalur A, Qoder B) dan `docs/fase6/6_1.md`
(Jalur B, Qoder A). ✅ GATE-F6 terbuka 02-11-2026 (UP-1 LOLOS, `docs/up1/Hasil_UP-1.md`).

## Status

| Task | Jalur | Key | Status |
|---|---|---|---|
| H-01 | A + B | DBV-026, DBV-027 | B: DBV-027 di main `c3c6213f` (+ DBV-030 `4c2d96a5`); A: diisi Jalur A |
| H-02 | B | MAKE-065 | B4a di main `07ac6711`; B4b `services/s_esign` menunggu MAKE-058 (A3) |
| H-03 | B | MAKE-067 | Di main `6cd50e69` |
| H-04 | B | MAKE-066 | Di main `9debb366` (dikerjakan sesi WS-2) |
| H-05/H-06/H-07 | A | MAKE-059, MAKE-060 | Belum mulai |
| H-08 | A | MAKE-061 | Belum mulai |
| H-09 | A | (pemetaan) | Belum mulai |
| H-10 | B | MAKE-064 | Di main `74039dc7` |
| H-11 | A | MAKE-062 | Belum mulai |
| H-12 | A | MAKE-062 | Belum mulai |
| Fondasi | A / B | MAKE-058 / MAKE-063 | B: MAKE-063 di main `74039dc7`; A: diisi Jalur A |

## Bagian A — API masuk & job terjadwal

(diisi Qoder B per milestone: keputusan, deviasi, catatan)

### A6 — analisis legacy `services/s_stat` (MAKE-061, H-08)

Analisis saja (belum ada kode). Sumber legacy: `controllers/services/S_stat.php`, `libraries/hr/L_employee.php`
(`get_all_kp` 2416, `get_all_kgb` 4095, `get_all_pensiun` 1324), `helpers/function_helper.php` 2400–2418. Berkas
terlarang tidak dibuka.

**Endpoint (PRD §7.6; varian bertanggal dikecualikan):** `rekomendasi_kp`, `rekomendasi_kp_uns` (menunggu keputusan
Q5), `rekomendasi_pensiun`, `rekomendasi_kgb`, `list_tb`, `list_ib`, `list_karpeg`, `list_kariskarsu`. Auth legacy
hanya aktif di `rekomendasi_kp`; v2 memakai `klienLayanan` (hak `stat`, F6-03).

**Aturan (Lampiran A.2, keputusan Q1–Q4 lead):**
- KGB: populasi status 1 dan `id_jenis_pegawai` ∈ {1, 6}; TMT terakhir = maks(`pegawai_kgb.tmtsk`, `pegawai_cpns.tmtsk`)
  untuk PNS, `pmj.tmtsk` untuk PPPK (deviasi [V2]: baris `pegawai_kgb` terbaru, bukan baris acak). Selisih bulan
  `y*12+m`: > 36 → +4 tahun lalu +2 tahun sampai ≥ sekarang; 24–36 → hari ini; < 24 → +2 tahun. Filter tahun TMT ∈
  {tahun ini, tahun depan}.
- Pensiun: populasi status 1 dan `id_jenis_pegawai` = 1; `tgl_pensiun` = tanggal lahir + masa kerja tahun + 1 bulan,
  lalu tanggal 1 (legacy FH:2400–2418). Masa kerja: `umur_pensiun` jabatan > 0 menang; selain itu 60 (grup 1, sub 1/2);
  selain itu 58. Filter tahun(`tgl_pensiun`) = tahun ini.
- KP: filter tahun(`tgl_kp`) = tahun ini; aturan periode 4-tahunan dan percepatan struktural sesuai AC-08.1.
- Waktu "sekarang" diinjeksi (tanggal bisnis WIB); kelas murni tidak membaca jam.

**Kolom keluaran (fixture kontrak, urutan = legacy; tanggal DD-MM-YYYY sesuai Q9):**
- KP: `nama, nip, gol_ruang, tmtsk_pangkat, tgl_kp, periode_kp, kp_bl, pangkat, jabatan, eselon, id_unit, id_satker,
  satker, strata, arsip_cpns[], arsip_pns[], arsip_last_kp[], arsip_skp{tahun_n1: [], tahun_n2: []}`.
- KGB: `nama, nip, tmtsk_kgb, kgb_th, kgb_bl, no_sk_last_kgb, tmtsk_last_kgb, gaji_last_kgb, eselon, jabatan, satker,
  id_unit, id_satker, gol_ruang, id_jenis_pegawai, jenis_pegawai, arsip_kp[], arsip_kgb[]`.
- Pensiun: `nama, nip, tgl_lahir, jabatan, tmtsk_jabatan, satker, eselon, pensiun_th, pensiun_bl, tgl_pensiun, id_unit,
  id_satker, usia_th, usia_bl, arsip_cpns[], arsip_pns[], arsip_last_kp[], arsip_skp{}`.

**Pemetaan arsip legacy → v2** (`id_riwayat` legacy = kode `jenis_rwy` v2, `database` migrasi CreateJenisRwy):

| Legacy `id_riwayat` | Kode v2 | Tabel riwayat | `id_entri` (padanan) | Dipakai |
|---|---|---|---|---|
| 11 | `kp` | `riwayat_kp` | `id_riwayat_kp` (pegawai_cpns / pegawai_pns / pegawai_kp) | KP, pensiun, KGB |
| 10 | `kgb` | `riwayat_kgb` | PK `riwayat_kgb` (Q10 disetujui) | KGB |
| 32 | `skp` | `riwayat_skp` | `id_riwayat_skp` | KP, pensiun |
| 9 (jabatan) | `jabatan` | `riwayat_mutasi_jabatan` | `id_riwayat_mutasi_jabatan` | KP varian `_uns` |
| — (`pegawai_ak.file_pak`) | tidak ada | — | — | KP (`arsip_last_pak`), Q8 |

Cek "ada/tidak": `AttachmentServiceInterface::daftar(nip, idRiwayat, idEntri)` lalu berkas di storage; URL unduh
`GET pegawai/{nip}/lampiran/{id}/unduh`. Legacy memanggil HTTP dan mengecek 200; v2 tidak memakai HTTP.

**Keputusan terbuka (diteruskan ke reviewer lewat lead):**
- Q5 **MENUNGGU HORII** (jangan dikode): varian `rekomendasi_kp_uns` (S_stat.php:433) tidak memakai identitas pemanggil.
  Filter diambil dari `$_GET['id_satker']` / `$_GET['id_unit']` (:456–457); auth dikomentari (:446–447); varian ini
  memuat `file_pak` (:627) dan arsip jabatan/PAK (:600–638). Usul reviewer ke Horii: pertahankan varian, filter dari
  parameter query, wajib auth klien [V2 auth].
- Q8 (PAK, `arsip_last_pak`): pakai preseden v2 (KolomBerkas/storage). Path ada di storage v2 → URL berkas v2; kosong atau
  path impor belum ada di storage → null/kosong. Dicatat [V2] di README modul, sama dengan `file_lckh` dan PMK
  (README Api/Layanan :105).
- Q10 **DISETUJUI**: `id_entri` arsip KGB = PK `riwayat_kgb`.
- Q9 (diputuskan lead): keluaran DD-MM-YYYY persis legacy; ISO internal di kelas murni, konversi di penyaji.

**Usulan struktur kelas (belum dibuat):** `Libraries/Integrasi/Stat/RekomendasiKp`, `RekomendasiKgb`,
`RekomendasiPensiun` (murni, `sekarang` diinjeksi); `StatService` (DB, filter tahun, batch `WHERE IN`, query builder
saja); penyaji ke amplop legacy `{status,msg,data}` lewat `ResponsSimapi` (A4); endpoint di bawah `klienLayanan:stat`
(A3, `RoutesServices` A4).

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

### B4b — `services/s_esign` (belum)

- Menunggu fondasi Jalur A MAKE-058 (A3, filter auth antar-sistem ADR-036).

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
- B4b menunggu MAKE-058.
