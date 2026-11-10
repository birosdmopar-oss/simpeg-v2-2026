# Checklist QA Lapis 1 — Fase 6 Integrasi & API Eksternal (Modul H, mode mock)

Untuk reviewer CR (pelaksana QA Lapis 1 di app demo; PRD Fase 6 §11 DoD). Acuan: PRD Fase 6 **v1.0o** (`main` `75361d0d`)
§6 (matriks endpoint & job), §7.0–§7.10 (AC per task), register Q6-01..Q6-42 (khususnya Q6-40 TTE atas nama, Q6-41 API
SIMAPI lama, Q6-42 penulis log SIMAPI), `README-deploy.md` §1b–§1h, `backend/docs/progress/06-Integrasi.md`. Pola: Checklist QA
Lapis 1 Fase 4 (C-11). Checklist ini **disepakati reviewer CR sebelum QA dimulai**; pertanyaan pra-QA di §K.

**Cakupan mode mock.** Semua driver eksternal `mock` (`integrations.esign/siasn/push.driver = mock`), tanpa jaringan keluar.
Uji endpoint real (BSrE, SIASN, e-Kinerja, FCM) **tidak** di sini — dilacak MT-010 / DEPLOY-001.1 (PRD §11, F6-23). Bagian yang
di mode mock hanya bisa diuji lewat penolakan/pesan (H-03, H-10) ditandai **[mock-terbatas]**; perilaku lengkapnya dibuktikan
test otomatis (regresi gate penuh) dan MT-010.

**Lingkungan:** app demo lokal (worktree demo reviewer, DB `simpeg_v2_t_app_demo` @3307, backend port 8120, frontend 5190,
captcha mock), data sintetis. Akun di `AKUN_DEMO.md` folder demo-seed (password tidak ditulis di sini). Prasyarat data:
`reset_demo.sh` → `qa_fase4_data.php` → `qa_fase6_data.php` (idempoten; penanda `[QA-F6 <kode>]` di keterangan/kegiatan/nama
klien). Rahasia klien layanan uji ditulis skrip ke `KLIEN_QA_F6.md` di folder demo-seed (tidak dicetak, tidak di repo).

| Kode | Data | Untuk bagian |
|---|---|---|
| Akun | akun demo Fase 4 (`demo.admin` 1, `demo.pemohon`, `demo.atasan`, `demo.pyb`, `demo.pegawai`, dst.; `demo.admin`/`demo.eselon1`/`demo.menteri`/`demo.pimpinan`/`demo.satker` = akun peran tanpa NIP) + akun sistem `sistem.integrasi` (dibuat `integrasi:siapkan-akun-sistem`) | semua |
| K1 | Klien `qa.simapi` — jenis 2 (SIMAPI baru), hak `simapi`, tanpa allowlist | F |
| K2 | Klien `qa.lama` — jenis 1 (SIMAPI lama), hak `simapi`, allowlist `127.0.0.1` (Q6-41 IP wajib) | G |
| K3 | Klien `qa.mudig` — jenis 3, hak `stat,dm,esign`, tanpa allowlist | C5, H |
| K4 | Klien `qa.nonaktif` — jenis 3, hak `stat`, lalu dinonaktifkan | A |
| K5 | Klien `qa.ipsalah` — jenis 3, hak `stat`, allowlist `203.0.113.0/24` (bukan IP demo) | A |
| C6 | Cuti Tahunan `demo.pegawai` menunggu AL; AL = `demo.atasan` (memproses sendiri), PyB = pegawai demo **tanpa akun** (dipilih skrip, tak terjangkau → menu atas nama untuk `demo.admin` di tahap PyB); NIK sintetis PyB diisi bila kosong. `demo.pyb` tidak dipakai karena terjangkau | C3 (Q6-40) |
| C7 | Cuti Tahunan `demo.pemohon` final (disetujui PyB sendiri), belum ber-TTE | C1, C2 |
| T6 | TB `demo.pppk` disetujui, rentang sudah berakhir, `pegawai.id_jenis_status` = 2 (status usang) | L |
| S6 | Pegawai `demo.ptt` `id_jenis_status` = 3 tanpa CLTN pendukung (isian manual) | L |
| L6 | LKH `demo.pegawai` bulan lalu status 0 (2 baris) + 1 baris status 3 (revisi), atasan `demo.atasan` | M |

Isi kolom Hasil: ✅ lolos / ❌ gagal (+ nomor temuan di §T) / — tidak berlaku. Bukti: keluaran perintah (tanpa rahasia), tangkapan
layar UI, atau isi baris DB demo yang relevan (tanpa NIK/NIP di laporan).

**Cara memanggil API antar-sistem** (Git Bash di mesin demo; rahasia dari `KLIEN_QA_F6.md`, jangan ditempel di laporan):

```bash
B=http://localhost:8120
curl -s -u "qa.mudig:<rahasia>" "$B/services/s_stat/rekomendasi_kgb"
curl -s -u "qa.simapi:<rahasia>" "$B/simapi/v1/a_auth/token"           # → data.token
curl -s -H "Authorization: Bearer <token>" "$B/simapi/v1/a_employee/data_umum/<nip uji>"
```

Bentuk header token SIMAPI mengikuti legacy (elemen kedua header `Authorization`, AC-F/`AE:30-47`); bila berbeda, catat di §T.

## A. Fondasi auth antar-sistem (MAKE-058, ADR-036, §7.0.1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A1 | F.1 | `php spark integrasi:klien daftar`; cek kolom `rahasia_hash` tabel `klien_layanan`; cari rahasia K1 di `writable/logs/*` dan `integrasi_log` | Daftar tanpa rahasia; DB hanya hash; rahasia tidak ada di log mana pun | |
| A2 | F.2 | Panggil `services/s_stat/list_tb` dengan K4 (nonaktif) | HTTP 401 `{"status":2,"msg":"Authentication failed","data":{}}`; `integrasi_log` arah masuk alasan tanpa username/rahasia/IP | |
| A3 | F.3 | Panggil `services/s_stat/list_tb` dengan K5 (IP di luar allowlist) | 401 seragam (pesan sama dengan A2) | |
| A4 | — | Hak salah: K1 (hak `simapi`) memanggil `services/s_stat/list_tb`; K3 memanggil `simapi/v1/a_auth/token` | 401 seragam / SIMAPI `{"status":"02","msg":"Authentication failed"}` HTTP 200 | |
| A5 | F.4 | Ulangi A2 dengan rahasia salah sampai melewati kapasitas throttle IP (`Config\KlienLayanan`) | 429 `Too many requests` + `Retry-After`; log throttle maksimal sekali per jendela | |
| A6 | F.5 | Token SIMAPI K1: pakai sesudah `integrasi:klien rotasi qa.simapi` (token dicabut) | `{"status":"02","msg":"Token is not valid"}` HTTP 200 | |
| A7 | — | `integrasi:klien rotasi qa.simapi` → perbarui `KLIEN_QA_F6.md` (skrip `--rotasi` atau manual) | Rahasia baru tampil sekali; rahasia lama ditolak | |

## B. H-01 Skema (DBV-026, DBV-027, DBV-030, DBV-031)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B1 | 01.3 | Cek tabel di DB demo: `klien_layanan`, `simapi_token`, `simapi_jd`, `simapi_auth_jd`, `simapi_ch`, `simapi_auth_ch`, `simapi_update`, `simapi_update_hist`, `integrasi_log`, `siasn_*`, `bkn_periode_ekinper`, `surat_cuti_token` | Semua ada (migrate demo dari `main`); collation `utf8mb4_unicode_ci` | |
| B2 | 01.2 | `firebase_token` di DB demo hasil impor/seed | Kosong sebelum login berizin notifikasi (tidak diimpor) | |

## C. H-02 E-sign BSrE (mode mock) — C-05, F6-18, Q6-19..Q6-23, Q6-40, F6-16

| # | AC | Langkah (UI kecuali disebut) | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C1 | 02.6 | `demo.pyb` → Cuti → C7 ⋮ "Tanda tangani" → cek sertifikat → passphrase apa saja → Tanda tangani | Surat ber-TTE terbit (PDF mock, trailer mock); `esign_hist` status 1 penanda tangan PyB; tanpa passphrase di log/DB | |
| C2 | 02.6 | Pada cuti final lain: akun selain PyB (mis. `demo.admin` tanpa atas nama, `demo.pemohon`) membuka ⋮ | Item "Tanda tangani" tidak tampil; panggilan API langsung `POST api/v1/cuti/{id}/esign` → 403 | |
| C3 | Q6-40 | `demo.atasan` → Cuti → C6 ⋮ "Setujui" (tahap AL, alur biasa) → `demo.admin` → Cuti → C6 ⋮ "Setujui atas nama" tahap PyB (alasan wajib) | Sesudah keputusan atas nama yang final: dialog **"Tanda tangani atas nama"** terbuka otomatis dengan nama pejabat. Atas nama di tahap AL yang belum final tidak membuka TTE — tidak bisa dipicu dengan C6 (AL terjangkau), bukti test otomatis `EsignAtasNamaTest` | |
| C3a | Q6-40 | Tutup dialog C3 tanpa passphrase | Surat terbit tanpa TTE; ⋮ "Tanda tangani atas nama" tersedia untuk mengulang | |
| C3b | Q6-40 / Q6-20 | ⋮ "Tanda tangani atas nama" → passphrase → Tanda tangani | TTE terbit; `esign_hist.nip` = PyB; audit `cuti.esign_terbit` pelaku = admin, `atas_nama: true`; sesudahnya item ⋮ tersembunyi dan PyB tidak bisa menandatangani ulang | |
| C3c | Q6-40 | Akun bukan role 1 (mis. `demo.pemohon`) memanggil `GET api/v1/cuti/{id C6}/esign/status?atas_nama=1` | 403 | |
| C4 | 02.7 / 02.8 | Buka QR kaki surat C1 (halaman `/verify/surat-cuti/{token}`) dan URL PDF publik di QR TTE; ubah satu karakter token | Data minimal tanpa NIP, tanpa unduh; PDF publik ber-TTE; token salah → 404 seragam; header `no-store` + `noindex`; `php spark surat-cuti:token --id=<C7> --nonaktif` → keduanya 404, `--aktif` pulih | |
| C5 | F6-16 | `GET services/s_esign/check_user?nik=<NIK sintetis>` dengan K3; lalu `POST services/s_esign/sign_pdf` multipart `file` (PDF kecil) + `imageTTD` (PNG kecil) + `nik`, `passphrase` | check_user `{"status":"1","msg":null,"data":[]}` (status STRING, msg null [K]); sign_pdf `status "1"`, `data.signed_file` base64 PDF; `esign_hist` modul `MuDig` | |
| C5a | F6-16 | sign_pdf tanpa `imageTTD` / berkas bukan PDF / NIK berisi `..` | `{"status":"2","msg":"Invalid parameter.","data":[]}`; tanpa baris `esign_hist` | |
| C6x | 02.5 | `php spark integrasi:status` | Baris status TLS BSrE tampil (mock: tidak relevan → catat isi); exit 0 bila tanpa kegagalan | |

## D. H-03 SIASN [mock-terbatas] (MAKE-067, F6-14)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D1 | 03.7 | `php spark siasn:tarik --jenis=data-utama --dry-run`, `siasn:dorong --jenis=jabatan --dry-run`, `siasn:unggah-arsip --dry-run` dengan driver mock | Ditolak eksplisit (driver wajib `bkn`), exit ≠ 0, tanpa mengubah data/antrean; pesan tanpa rahasia | |
| D2 | 03.1–03.10 | Rujukan bukti otomatis: kelas `tests/database/Integrasi/Siasn*Test.php` di log gate penuh regresi | Hijau (dilaporkan pelaksana regresi) | |

## E. H-04 Push FCM (mode mock) — C-06, F6-11..F6-13

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| E1 | F6-13 | Login `demo.pegawai`; bila konfigurasi Firebase web kosong di demo, banner izin notifikasi tidak muncul (lihat §K2); uji API: `POST api/v1/integrasi/fcm-token {token:"qa-f6-token-1"}` dua kali (Bearer JWT dari login) | Satu baris `firebase_token` platform 1 (AC-04.5 dedup), `updated_at` diperbarui; `DELETE` yang sama menghapus baris | |
| E2 | 04.1 / C-06 | Dengan token E1 terdaftar: proses cuti/TB/IB/PMK milik `demo.pegawai` dari akun approver (mis. setujui IB I1 sebagai PIC) | Push diantre (`queue:work push`), payload mock tertulis di `writable/logs` `[MockFcmAdapter]` dengan judul/isi/tautan ikut legacy; tanpa galat di alur UI | |
| E3 | 04.3 | Presensi masuk/pulang `demo.pegawai` (bila menu presensi online tersedia di demo) | Job `fcm-topik` diantre sesudah commit; presensi tetap sukses walau worker mati | |
| E4 | — | `php spark integrasi:uji-koneksi fcm` (driver mock) | Ditolak "hanya untuk integrations.push.driver = fcm", exit 7 (CR-101) | |

## F. H-05/H-06/H-07 SIMAPI v1 (MAKE-059/060)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| F1 | 05 | `GET/POST simapi/v1/a_auth/token` dengan K1 | `{"status":"01","msg":…,"data":{"token":"…"}}` (data.token saja, CR-106) | |
| F2 | 06.2 | `a_employee/data_umum/<nip>` tanpa token / token acak; tanpa NIP | `{"status":"02","msg":"Token is not valid","data":[]}` HTTP 200; tanpa NIP → "Invalid paramater" [K ejaan legacy] | |
| F3 | 06.1 | Dengan token: beberapa dari 25 method Lampiran A.1 (mis. `data_umum`, `detil`, `riwayat_jabatan`, `riwayat_pendidikan`, `riwayat_kgb`) untuk NIP demo; ikuti URL `foto` di `data_umum` (`a_employee/foto/…`); NIP tak ada; NIP status 10 | Field & urutan sesuai Lampiran A.1; data kosong → pesan "Data … tidak tersedia"; NIP tak ada/status 10 → "NIP not valid"; foto hanya dengan token | |
| F4 | 06.3 / Q6-42 | Edit biodata `demo.pegawai` lewat UI Kepegawaian, lalu `change_history` dua kali dengan K1 (jenis 2; klien jenis 1 ditolak) | Panggilan pertama memuat perubahan (field_value sesuai trigger legacy D1); kedua tidak mengulang (ack-on-read); filter `simapi_auth_jd` berlaku bila diisi | |
| F5 | 07.1 | `a_dm/jabatan` tanpa token; `a_dm/group_jabatan` dengan token | Tanpa token → 02; dengan token → data | |
| F6 | F6-07 | `a_sinkron/ambil_riwayat_mutasi` dengan token | Amplop `"01"`, data sesuai Lampiran A.1 | |

## G. API SIMAPI lama (Q6-41, MAKE-076) + penulis log (Q6-42)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| G1 | Q6-41 | `simapi/a_pegawai/token` dengan K2 dari 127.0.0.1; ulangi dengan klien tanpa allowlist cocok | Token terbit; IP tidak cocok → `"02"` (cek IP wajib) | |
| G2 | Q6-41 | `simapi/a_pegawai/detail/<nip>` dengan token G1 | Amplop `"01"`, field ikut legacy termasuk `password` = hash akun v2 (Argon2id) — risiko diterima Q6-41 | |
| G3 | Q6-42 | Ubah data `demo.pegawai` (biodata/jabatan) atau password via UI; lalu `simapi/a_pegawai/pegawai_update` dua kali | Panggilan pertama memuat perubahan (`simapi_update`), kedua tidak mengulang (`_hist` ack-on-read); data:[] saat kosong | |
| G4 | Q6-42 | Simpan form riwayat yang ditolak validasi (422) | Tidak ada baris `simapi_ch`/`simapi_update` baru (rollback = log hilang) | |

## H. H-08 `services/s_stat` + `s_dm` (MAKE-061, MAKE-059)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| H1 | 08.2 | K3: `s_stat/rekomendasi_kp`, `rekomendasi_kp_uns`, `rekomendasi_pensiun`, `rekomendasi_kgb`, `list_tb`, `list_ib`, `list_karpeg`, `list_kariskarsu` | Amplop INT `{"status":1,…}`; kolom sesuai Lampiran A.2 | |
| H2 | 08.4 | `rekomendasi_kp_uns?id_satker=<id>` | `{"status":1,"msg":"","data":[]}` (bug legacy Q6-37) | |
| H3 | 08.5 | Ikuti satu URL `arsip_*` dari H1 dengan K3; tanpa auth; ubah NIP/id | Dengan auth → berkas; tanpa auth → 401; selain itu 404 seragam; path internal tidak tampil | |
| H4 | 08.3 | H1 tanpa auth | 401 | |
| H5 | F6-10 | K3: `s_dm/list_unit`, `list_satker`, `p_list_unit`, `p_get_unit?id_unit=`, `p_get_satker?id_satker=` | Amplop INT, data gagal `[]` [K]; `p_web_config` tidak ada (404) | |

## I. H-09 Pemetaan

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| I1 | 09.1 | Baca tabel pemetaan `Local.php` di `backend/docs/progress/06-Integrasi.md` | Setiap method punya padanan atau alasan "tidak di-port" | |

## J. H-10 e-Kinerja BKN [mock-terbatas] (MAKE-064)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| J1 | K.4 | `php spark bkn:tarik-periode --dry-run` dengan `.env` demo tanpa konfigurasi BKN | Galat konfigurasi menyebut nama kunci saja, exit ≠ 0, tanpa nilai rahasia | |
| J2 | §6.2 | `POST api/v1/integrasi/bkn/tarik` sebagai `demo.admin` `{jenis:"periode"}`; sebagai `demo.pegawai` | Admin → 202 (job diantre antrean `integrasi`); bukan role 1 → 403 | |
| J3 | F6-15 | `php spark integrasi:status` | Peringatan periode Tukin berjalan tanpa SKP periodik (DB demo kosong `riwayat_skp_periodik`) | |
| J4 | 10.1–10.3 | Rujukan bukti otomatis: `tests/database/Integrasi/Bkn*`/`Ekinerja*` + Tukin Fase 5 di log gate penuh regresi | Hijau | |

## L. H-11 Status TB/CLTN harian (MAKE-062)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| L1 | Q6-26 | `php spark layanan:status-harian --dry-run` | Ringkasan: T6 2→1 (pemulihan), S6 tanpa riwayat pendukung = INFO (tidak disentuh); tanpa perubahan DB | |
| L2 | 11.5 / 11.6 | Run nyata dua kali | Run 1 menerapkan; run 2 tanpa perubahan; setiap perubahan teraudit | |
| L3 | 11.10 | `--tanggal=<besok>`; `--tanggal=<kemarin>` tanpa `--dry-run` | Keduanya ditolak (exit 7) | |

## M. H-12 Auto-approval LKH (MAKE-068)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| M1 | 12.1 | `php spark lkh:auto-approval --dry-run` lalu run nyata | L6 status 0 bulan lalu → 1 + `auto_approval=1`; baris status 3 tidak disentuh | |
| M2 | 12.2 | Run nyata kedua | Tanpa efek ganda, tanpa audit baru | |
| M3 | 12.3 | Unduh PDF LKH bulan lalu `demo.pegawai` dari UI | PDF dibangkitkan saat diminta (job tidak menyimpan PDF) | |

## N. Operasional log & runbook

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| N1 | F6-24 | `php spark integrasi:status --jam=24` sesudah bagian A–M | Agregat sukses/gagal per layanan; exit 1 bila ada kegagalan (A2–A5 sengaja gagal) | |
| N2 | Q6-16 | `php spark integrasi:bersihkan-log --dry-run` | Jumlah calon hapus > 180 hari (0 di demo), tanpa menghapus | |
| N3 | K.1 | Cari rahasia klien, token SIMAPI, passphrase, NIK sintetis di `writable/logs/*` dan `integrasi_log` | Tidak ditemukan | |
| N4 | §11 | Baca `README-deploy.md` §1b–§1h | Runbook cron + Nginx (`/simapi/`, `/services/`, `Authorization`) + daftar re-handshake klien tercatat | |
| N5 | F6-24 / §6.2 | `GET api/v1/integrasi/log` (role 1, filter layanan/status/tanggal, paginasi) | — | N/A — deviasi K1 |
| N6 | §6.2 FE | Halaman Admin → Integrasi (log integrasi + tombol tarik ulang e-Kinerja per periode, aksi baris ⋮) | — (tarik ulang tetap diuji lewat API di J2) | N/A — deviasi K1 |

## K. Keputusan / pertanyaan pra-QA (ketidaksesuaian PRD ↔ kode)

| # | Temuan | Usulan |
|---|---|---|
| K1 | PRD §6.2 + F6-24 mencantumkan `GET api/v1/integrasi/log` (filter layanan/status/tanggal, paginasi, role 1) dan halaman FE **Admin → Integrasi** (log + tombol tarik ulang e-Kinerja per periode). Di `main` `75361d0d` belum ada: route hanya `bkn/tarik` dan `fcm-token`, fitur FE `integrasi` hanya push. Pemantauan hanya lewat `integrasi:status`. | **Keputusan: (b) deviasi, Horii 10-11-2026** — ditunda ke fase berikutnya dan dicatat di laporan akhir Fase 6; pemantauan Fase 6 lewat `integrasi:status` + log berkas. Butir yang bergantung ditandai "N/A — deviasi K1" (N5, N6) |
| K2 | Push web (E1) butuh konfigurasi Firebase web `VITE_FIREBASE_*`; di demo kosong → banner izin & pendaftaran token otomatis nonaktif (sesuai desain). | QA E1 lewat API langsung; uji SPA + service worker di MT-010 dengan proyek Firebase (PN-3) |
| K3 | H-03 SIASN dan H-10 e-Kinerja tidak punya jalur mock (driver wajib `bkn`/konfigurasi BKN, by design B6 M1 B-1c). | QA Lapis 1 hanya penolakan + bukti test otomatis (D2, J4); alur nyata di MT-010 |
| K4 | Q6-42 butir rollback (G4) hanya teramati bila form gagal di dalam transaksi pemilik; validasi 422 biasanya ditolak sebelum transaksi. | Bila G4 tidak bisa memicu rollback di UI, terima bukti test otomatis `PencatatPerubahanTest` |
| K5 | Header token SIMAPI dibaca dari elemen kedua `Authorization` [K `AE:30-47`] — contoh curl memakai `Bearer <token>`. | Informasi; tidak memblokir |

## T. Temuan

(diisi reviewer saat QA)
