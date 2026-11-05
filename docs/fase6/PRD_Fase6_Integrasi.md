# PRD Fase 6 — Integrasi & API Eksternal (Modul H, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase6/PRD_Fase6_Integrasi.md` — **acuan utama** coder/agen AI Fase 6 (Trello DEV-007, QASMTASK-089..097, QASMTASK-120..122). Pembagian kerja paralel: [`6_1.md`](6_1.md) (Qoder A — klien integrasi keluar) dan [`6_2.md`](6_2.md) (Qoder B — API masuk & job terjadwal) |
| Versi | **v1.0m — 05-11-2026** (CR-107: ISSUE-055 TTE persetujuan "atas nama" ikut legacy Q6-40; CR-106: koreksi kontrak sesuai legacy — status `s_esign` string, `data` gagal SIMAPI `[]`, `a_auth` `data.token` saja; URL foto SIMAPI lewat endpoint khusus klien Q6-39; CR-105: URL arsip `services/s_stat` lewat endpoint unduh khusus klien layanan Q6-38; CR-104: B-9 `unit_es_3/4` ikut legacy Q6-36; filter `rekomendasi_kp_uns` ikut legacy Q6-37; DB scratch per agen di 6_1/6_2; CR-100: aturan B6 SIASN tarik/dorong/unggah arsip Q6-30..Q6-35; CR-099: kanal Android F6-13 ikut legacy penuh Q6-28..Q6-29; CR-098: aturan H-11 status TB/CLTN harian Q6-24..Q6-27; CR-097: TLS BSrE ikut legacy Q6-18 + e-sign cuti F6-18 Q6-19..Q6-23; CR-093: keputusan A0 v2 Jalur A; CR-092: bentuk auth BKN Q6-17; CR-091: kontrak log bersama A/B; CR-090: AC-03.5 ikut legacy; v0.1 `ee6ce5a6`; v1.0 memuat keputusan Horii 01-11-2026 atas Q6-01..Q6-16 — prinsip umum: **"intinya ikut legacy"**; riwayat §Lampiran C) |
| Status | **BERLAKU setelah masuk `main`** (izin Horii). Keputusan dikunci di §5.2 dan §12. Koding Fase 6 terkunci **GATE-F6** (§0) |
| Prinsip | **Ikut legacy** (keputusan Horii 01-11) untuk perilaku bisnis & kontrak. Deviasi [V2] hanya bila (a) diputus eksplisit Horii (F6-03, F6-07, F6-16) atau (b) teknis wajib/keamanan dasar (F6-05, F6-11, BP-3/5/8/9) — setiap deviasi tercatat di §5.2 |
| Basis kode | `origin/main` = **`a320f919`** (rujukan `path:baris` repo dari commit ini) |
| Acuan legacy | Salinan kerja legacy CI3 **HEAD `0cf7e67`**. Rujukan baris bercatatan AS-09 (belum dicocokkan checksum produksi, Q5A-14). Rujukan relatif ke `application/`: `C` = `controllers/hr/services/Cron.php`, `L` = `controllers/hr/services/Local.php`, `SI` = `controllers/hr/services/Siasn.php`, `SS/` = `controllers/hr/services/siasn_sync/`, `EB` = `controllers/hr/services/Ekin_bkn.php`, `AE` = `controllers/simapi/v1/A_employee.php`, `FH` = `helpers/function_helper.php`, `LE` = `libraries/hr/L_employee.php` |
| Scope | **Fase 6** = H-01..H-09 (`Bahan Baku SIMPEG/06-Integrasi.md`) + **H-10 e-Kinerja BKN** (baru, Tukin bergantung padanya) + **H-11 status TB/CLTN harian** (warisan Fase 4 §14.6 #2, keputusan Horii 27-10) + fondasi auth antar-sistem dan penjadwalan (§7.0) |
| Pelaksana | **Qoder B** (`6_2.md`, Jalur A) dan **Qoder A** (`6_1.md`, Jalur B — sesi reviewer CR sendiri; milestone-nya tetap lewat gate penuh + CI + izin Horii per paket) paralel (penugasan Horii 01-11-2026); **Reviewer** = reviewer CR (review, gate, merge, QA, uji real); **Horii** = keputusan & sign-off; **DBV** = review skema |
| Key | Qoder B: **DBV-026**, **MAKE-058..062**; Qoder A: **DBV-027**, **MAKE-063..067**. Perbaikan review/QA: CR-088 dst. ADR baru: **ADR-036** (auth antar-sistem), **ADR-037** (penjadwalan job) |
| Sumber | `06-Integrasi.md` (H-01..H-09, exit criteria); FSD §6 FSD-FN-H-01..09; SRS-FR-007/009/011/033/034/051, SRS-NFR-041; PRD-FR-015/027; RTM; PRD Fase 4 v1.5 (§1.2, D9, D18, Q-09, §14.6); PRD 5A (F5-12, §9); PRD 5B (§1.2, §9); PRD Fase 7 (§1.2 H-08); `PRD_20261001.md` J2.07, J3.01..J3.06, AS-09; DBV-022 §2.19/2.20, DQ-25; Matriks Role × Endpoint Modul H; Trello DEV-007, GATE-F6, QAFUNC-007, QASMTASK-089..097; riset kode legacy & v2 01-11-2026 (Lampiran A) |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh PRD ini + berkas pembagianmu (`6_1.md` atau `6_2.md`) sebelum menulis satu baris kode.** Laporan M0
   membuktikan pemahaman.
2. Bila bertentangan dengan sumber lain (Trello, `06-Integrasi.md`, FSD/SRS, Matriks, README modul), **PRD ini + keputusan
   Horii (§5) MENANG** untuk lingkup Fase 6. Keputusan fase sebelumnya (§5.1) tetap berlaku.
3. Hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG MENEBAK.**
4. **⛔** = titik tunggu / wajib dijawab. **◻ default** = berlaku bila Horii tidak menolak saat review PRD.
5. Label: **[K]** ikut legacy (rujukan berkas:baris), **[V2]** deviasi v2 disengaja (alasan + keputusan), **[I]** inferensi.
6. **Repo publik.** Dokumen, kode, test, fixture, dan commit **DILARANG** memuat host/IP/URL server internal, kredensial,
   token, NIP/nama/NIK nyata. Legacy `application/config/constants.php`, `database.php`, `config.php`, serta
   `libraries/bsre_esign/config/bsre_config.php`, `views/template/header.php` (konfigurasi Firebase) dan helper token SIASN di
   `FH:2513-2921` **memuat rahasia**: jangan dibuka/dicetak. Grep legacy wajib
   `--exclude=constants.php --exclude=database.php --exclude=config.php --exclude=bsre_config.php`. Kebutuhan isi berkas itu →
   minta reviewer CR.

---

## 0. Gerbang & prasyarat

### 0.1 GATE-F6 (keputusan Horii 29-10, K5C-10)

- **Koding Fase 6 (termasuk migration DBV) tidak boleh dimulai sebelum Tahap UP-1 di-sign-off Horii** (UP1-08 → UP1-10,
  Trello `GATE-F6`). **Status 02-11-2026: TERBUKA** — UP-1 LOLOS disetujui Horii (`docs/up1/Hasil_UP-1.md`), gerbang dibuka atas perintah Horii.
- **Boleh sebelum gerbang terbuka** (◻ F6-01): membaca PRD, M0 (laporan pemahaman tanpa commit kode), menyiapkan permintaan
  akses non-teknis (§0.2), dan menjawab pertanyaan §12.

### 0.2 Prasyarat non-teknis (blocker H-02/H-03/H-04/H-10 real, SRS-NFR-041)

| ID | Kebutuhan | Pemilik | Menahan |
|---|---|---|---|
| PN-1 | Akses jaringan + akun klien sandbox/produksi **BSrE** (gateway e-sign) untuk server v2 dev | Horii/TL → infra | H-02 real, exit criteria |
| PN-2 | Akun layanan **SIASN** (API gateway + SSO) dan **e-Kinerja BKN** milik instansi; cara memperoleh token SSO produksi saat ini (F6-26, kartu pertanyaan Catatan Teknis) | Horii/TL → Biro SDM/BKN | H-03, H-10 real |
| PN-3 | Akses ke **proyek Firebase yang sama dengan legacy** (topik presensi & app Android, F6-12/F6-13) + service account FCM HTTP v1; siapa penulis token Android setelah cutover | Horii/TL | H-04 real |
| PN-4 | Daftar **klien SIMAPI** aktif (Pusdatin/Simanta/E-Talenta), **PIC MuDig** (pemakai `services/s_stat`, `s_dm`, `s_esign`), dan jadwal re-handshake (J3.04) | Horii/TL | H-05..H-08 go-live |
| PN-5 | Crontab server legacy (J1.03) — daftar job yang benar-benar terjadwal | Horii → DBA | Validasi §Lampiran A.3 |

Kredensial dan alamat **tidak pernah** ditulis di repo, Trello, atau chat agen; hanya di `.env` server.

---

## 1. Scope

### 1.1 Di dalam scope Fase 6

| Task | Judul | Inti v2 | QASMTASK | Pelaksana |
|---|---|---|---|---|
| H-01 | Skema integrasi | Dibagi dua: **DBV-026** (klien layanan, SIMAPI) dan **DBV-027** (integrasi keluar). `esign_hist` & `firebase_token` **sudah ada** (DBV-022), hanya ALTER bila perlu | 089 | A + B |
| H-02 | Adapter real BSrE e-sign | `BsreEsignAdapter` implementasi `EsignGatewayInterface` tanpa ubah kontrak; `services/s_esign` untuk MuDig (bila F6-16) | 090 | B |
| H-03 | Adapter real SIASN | **Ikut legacy penuh** (F6-14): klien auth BKN + pairing `id_pns_siasn` + tarik generasi 2 langsung ke tabel utama + dorong otomatis berbasis flag | 091 | B |
| H-04 | Adapter real FCM | FCM **HTTP v1** + kanal web & Android ikut legacy + push riwayat/layanan + keanggotaan topik presensi ikut legacy (F6-11..F6-13) | 092 | B |
| H-05 | SIMAPI autentikasi | `simapi/v1/a_auth/token`, token opak ber-hash, 1 jam | 093 | A |
| H-06 | SIMAPI data pegawai | 26 endpoint `a_employee/*` kontrak legacy (F6-04), termasuk `change_history` (F6-06) | 094 | A |
| H-07 | SIMAPI data mart | `a_dm/*` (4 endpoint) + `services/s_dm` (F6-10) | 095 | A |
| H-08 | Rekomendasi KP/KGB/pensiun | `services/s_stat/*` (pull MuDig) aturan [K] (F6-09) | 096 | A |
| H-09 | Service lokal | **Ditutup sebagai pemetaan** (F6-19): `calculate_lc` = Fase 4, `record_geolocation` = F5-12, `register_fb_token` = H-04 | 097 | A (dokumen) |
| **H-10** | **e-Kinerja BKN** [baru, Q6-09] | Tarik `bkn_periode_ekinper` + `riwayat_skp_periodik` terjadwal [K] + tombol tarik ulang admin. **Tukin bergantung** (potongan SKP, jendela periode) | QASMTASK-120 | B |
| **H-11** | **Status TB/CLTN harian** [warisan] | Command harian idempoten aktivasi/pengakhiran `pegawai.id_jenis_status` (F6-20) | QASMTASK-121 | A |
| **H-12** | **Auto-approval LKH** [Q6-15, ikut legacy] | Job tanggal 5: LKH bulan lalu yang belum disetujui atasan → disetujui otomatis; PDF on-demand v2 (F6-25, CR-093) | QASMTASK-122 | A |
| §7.0 | Fondasi | Filter auth antar-sistem (ADR-036), klien HTTP keluar + log integrasi, pola penjadwalan command + crontab (ADR-037) | — | A (masuk) / B (keluar) |

### 1.2 DI LUAR scope Fase 6

| Di luar scope | Milik | Catatan |
|---|---|---|
| Endpoint baca inbox/lonceng notifikasi in-app | Fase 7 F-08 | Fase 6 hanya push |
| Adapter penyimpanan remote / server Arsip (J3.06, F-10) | Ditunda (F6-22) | Tetap `LocalStorageAdapter` |
| View kompatibilitas mudig/E-Talenta (J3.02/J3.03, DBV-016) | Cutover | Fase 6 hanya API |
| SAPK lama (`C:1223-2240`, skema `sapk_simpeg`) | Tidak dimigrasi (F6-21) | Digantikan SIASN |
| Siasn generasi 1 (backfill sekali jalan `SI:121-8078` kecuali yang dipilih F6-14) | Tidak dimigrasi | Data hasilnya ikut impor cutover |
| `Cron` email ultah, popup notif, `fix_regen_*`, `sync_nama_pengguna`, `bwyydshyfwwvkfozwpnc` | Tidak dimigrasi (F6-21) | Nonaktif/tidak relevan di v2 |
| Antrean email (`email_pool`) | Sudah Fase 1 (CR-014, queue `email`) | — |
| Saldo cuti tahunan (`calculate_yearly_sc`) | Sudah Fase 4 (`cuti:saldo-tahunan`) | Hanya dijadwalkan di runbook |
| API mobile untuk app Android (backend penulis `firebase_token` legacy ada di luar repo) | Cutover (Q6-12) | Fase 6 menyediakan tabel + pengiriman push Android ikut legacy; siapa yang menulis token Android ke DB v2 dicatat sebagai pertanyaan cutover di DEPLOY-001.1 |
| UI admin perbandingan SIMPEG vs SIASN | Tidak ada di legacy | Usulan masa depan |
| MT (manual testing terpisah) | Fase MT | — |

---

## 2. Aturan proyek & best practice kode (WAJIB)

### 2.1 Commit, key, branch, gate

- Satu milestone = satu key = satu branch dari `origin/main` terbaru: `fase6/<key>-<ringkas>`. Pesan Bahasa Indonesia
  `feat(integrasi): MAKE-0xx …` / `fix(...)`. **Tanpa** penyebutan AI/tool dan **tanpa** trailer `Co-Authored-By`.
- Milestone skema = PR `[DBV]`; DBV review, **reviewer CR** yang merge (pola Fase 4–5). Migration yang sudah di `main`
  **tidak diedit**; perubahan = migration ALTER baru.
- Gate cepat per paket boleh; **`./check.sh` penuh** sebelum merge (reviewer). Gate lokal meniru CI: tanpa `backend/.env`,
  env `database_tests_*` ke DB scratch 3307 bernama eksplisit + `auth_captchaDriver=mock`. Satu `./check.sh` penuh per mesin.
- Lapor ke reviewer setiap push (SHA + run CI). Tertahan kuota API GitHub → segera kabari reviewer.

### 2.2 Best practice integrasi (LOCKED)

| No | Aturan | Alasan |
|---|---|---|
| BP-1 | **Interface + adapter** (ADR-015). Adapter real = kelas baru di `Libraries/<Esign|Push|Siasn|Bkn>/`, didaftarkan di cabang `match` `Config/Services.php:466-506`. **Kontrak interface tidak berubah**; kebutuhan baru = interface baru | SRS-FR-009, regresi C-05/C-06 |
| BP-2 | Semua alamat & kredensial dari `Config\Integrations` + `.env` (`.env.example` diisi **kosong**). Driver `real` dengan konfigurasi kosong → **gagal saat dibuat** (exception jelas), bukan saat dipanggil | Repo publik; fail fast |
| BP-3 | Satu klien HTTP keluar bersama (`Libraries/Integrasi/Http/KlienHttp`, di atas `CURLRequest` CI4): timeout koneksi 5 dtk / total 30 dtk (dapat dikonfigurasi), **TLS verify aktif** (pengecualian hanya untuk gateway internal tanpa TLS, dicatat di `.env`; **e-sign BSrE boleh tanpa verifikasi TLS ikut legacy — Q6-18 = B, CR-097**: bawaan tetap aktif, `esign.tlsVerify=false` hanya di `.env` server, peringatan log + `integrasi:status`, jalur upgrade `esign.caBundle`; BKN tetap wajib aktif), retry **hanya** untuk GET idempoten (maks 3, backoff + jitter). **POST bertransaksi (sign, save) tidak di-retry otomatis** | Legacy mematikan verify (B-14) dan tanpa retry |
| BP-4 | **Panggilan eksternal tidak pernah di dalam transaksi DB.** Catat percobaan di log integrasi di luar transaksi bisnis (pola `EsignCutiService.php:19-58`) | Rollback tidak bisa membatalkan efek eksternal |
| BP-5 | **Redaksi log:** passphrase, token, header `Authorization`, NIK, client secret **tidak pernah** masuk log/DB/respons. Body respons eksternal disimpan maksimal 1.000 karakter setelah redaksi | Legacy menyimpan payload mentah (Lampiran B) |
| BP-6 | **Idempoten:** job & command aman diulang (upsert per kunci alami, cek sebelum tulis), punya `--dry-run`, dan dilindungi kunci (`flock` di crontab + kunci aplikasi bila job bisa tumpang tindih) | Cron legacy berjalan berulang |
| BP-7 | **Tidak ada silent-fail** (PRD-FR-027): kegagalan dicatat dengan status code + pesan; push/sinkron gagal **tidak menggagalkan** operasi utama (SRS, interface Siasn :12) | — |
| BP-8 | **Auth masuk:** rahasia klien di-hash (`password_hash`), token disimpan sebagai **SHA-256**, dibandingkan dengan `hash_equals`, token dibuat dari `random_bytes`. Semua endpoint masuk diberi throttle | Legacy plaintext + `mt_rand` (Lampiran B) |
| BP-9 | **Query builder / binding saja** — tidak ada SQL dari string + `addslashes` | Legacy (Lampiran B) |
| BP-10 | **Waktu:** DB menyimpan UTC (sesi DB UTC sejak CR-086); tanggal bisnis WIB lewat helper yang ada. Tanggal BKN (`DD-MM-YYYY`) di-parse ketat, gagal parse = baris ditolak + dicatat | Legacy `explode` manual |
| BP-11 | **Test tanpa jaringan:** adapter real diuji dengan transport tiruan (`FakeTransport`) + fixture JSON **sintetis** di `tests/_support/Integrasi/`. CI tidak pernah memanggil layanan nyata. Uji koneksi nyata lewat command manual `integrasi:uji-koneksi <layanan>` (tidak di `check.sh`) | ADR-016, repo publik |
| BP-12 | **Endpoint kompatibilitas legacy** (SIMAPI, `services/*`) memakai amplop **legacy** (§6.3) di controller khususnya saja; endpoint baru v2 (`api/v1/*`) tetap amplop v2 + field `code` | Klien eksternal tidak diubah |
| BP-13 | Kualitas: PHPStan level 5, PHP-CS-Fixer, ESLint/vue-tsc, satu test per AC. README modul `Libraries/Integrasi/README.md` + `Controllers/Api/Integrasi/README.md` diperbarui per endpoint (SOP bagian 2 langkah 4) | AGENTS.md §2 |
| BP-14 | UI baru (bila ada) mengikuti AGENTS.md §1 (menu ⋮ `RowActionsMenu`) dan aturan komponen custom (`shared/ui`) | AGENTS.md |

---

## 3. Pembagian paralel & anti-tabrakan

| Jalur | Pelaksana | Berkas | Isi |
|---|---|---|---|
| **A — API masuk & job terjadwal** | Qoder B (`6_2.md`) | `Controllers/Simapi/*`, `Controllers/Services/*`, `Libraries/Integrasi/Simapi/*`, `Libraries/Integrasi/Stat/*`, `Libraries/Integrasi/Klien/*`, `Filters/KlienLayananFilter.php`, `Filters/SimapiTokenFilter.php`, `Commands/Integrasi*Klien*.php`, `Commands/LayananStatusHarian.php`, `Config/RoutesSimapi.php`, `Config/RoutesServices.php`, migration DBV-026 | H-05..H-09, H-11, ADR-036, ADR-037 |
| **B — Klien integrasi keluar** | Qoder A (`6_1.md`) | `Libraries/Integrasi/Http/*`, `Libraries/Esign/Bsre*`, `Libraries/Push/Fcm*`, `Libraries/Siasn/*` (selain mock), `Libraries/Bkn/*`, `Jobs/*Fcm*|*Siasn*|*Bkn*`, `Commands/Bkn*`, `Commands/Siasn*`, `Commands/Integrasi*UjiKoneksi*`, `Config/RoutesIntegrasi.php`, `frontend/src/features/integrasi/*`, `frontend/public/firebase-messaging-sw.js`, migration DBV-027 | H-02, H-03, H-04, H-10 |

**Berkas BERSAMA — hanya ADITIF, commit TERPISAH, yang merge belakangan menggabungkan `origin/main`:**
`Config/Services.php`, `Config/Filters.php` (alias baru milik A; B hanya memakai), `Config/Routing.php`,
`Config/Integrations.php` (B pemilik; A menambah entri bila perlu), `Config/Queue.php` (`$jobHandlers`), `.env.example`,
`README-deploy.md` (bagian cron), `backend/docs/progress/06-Integrasi.md` (bagian A / bagian B), `frontend` router/nav (B).

**Titik temu:** `services/s_esign` (B, M4) memakai filter `klienLayanan` dari A (MAKE-058). Bila MAKE-058 belum di `main`,
B mengerjakan bagian adapter dulu dan menunda endpoint `s_esign`.

**DILARANG disentuh:** migration yang sudah di `main`; kontrak `Interfaces/*GatewayInterface.php`; logika Tukin
(`Libraries/Presensi/Tukin/*`) — H-10 hanya mengisi tabel sumbernya; `Config/Database.php`; berkas legacy rahasia (§Cara pakai 6).

---

## 4. Glosarium

| Istilah | Arti |
|---|---|
| Klien layanan | Sistem lain yang memanggil v2 (MuDig, Simanta, Pusdatin, E-Talenta) — pengganti `simapi_auth`, `sal_user`, konstanta `MUDIG_*` |
| SIMAPI | API data pegawai untuk klien eksternal (`/simapi/v1/*`) |
| `services/*` | Endpoint antar-sistem legacy (`s_stat`, `s_dm`, `s_esign`) |
| SSO BKN | Token Keycloak SIASN/e-Kinerja (akun layanan instansi) |
| Pairing | Kecocokan baris v2 dengan id SIASN (`id_pns_siasn`, `id_rw_siasn`) |
| Job terjadwal | `php spark <command>` dipanggil crontab server (ADR-037) |

---

## 5. Keputusan

### 5.1 Keputusan yang SUDAH berlaku (diwarisi, tidak dibuka ulang)

| ID | Isi | Sumber |
|---|---|---|
| K5C-10 / GATE-F6 | Fase 6 terkunci sampai UP-1 sign-off | PRD 5C §K5C-10 |
| ADR-015 / SRS-FR-009 | Swap mock → real **tanpa** mengubah kontrak interface; regresi C-05 (e-sign cuti) & C-06 (notifikasi) tetap hijau | PRD Fase 4 :740, :1182 |
| ADR-013 / SRS-FR-007 | Push lewat Queue driver database (bukan sinkron, bukan Redis) | `Config/Queue.php:14` |
| SRS-FR-034 / J2.07 | Seluruh token SIMAPI legacy **tidak diimpor**; klien wajib re-handshake | FSD-FN-H-05 |
| K1 (S1) | Saat DP2.4 cron/simapi legacy dimatikan; endpoint v2 **wajib live sejak DP2.4** | `PRD_20261001.md` K1, J3.04 |
| D9 Fase 4 | `calculate_lc` = `POST cuti/hitung-lama` (sudah ada) | PRD Fase 4 :147, :188 |
| F5-12 | `record_geolocation` tidak di-port; koordinat bagian request presensi, `user_geo` tidak diimpor | PRD 5A :329 |
| D18 / DQ-25 | `esign_hist` + `firebase_token` sudah dibuat; deduplikasi token di aplikasi Fase 6; `auth_token` dibuang | DBV-022 §2.19/2.20 |
| H 27-10 | Gap aktivasi status TB/CLTN dikerjakan di **Fase 6** sebagai command harian idempoten + audit | PRD Fase 4 §14.6 #2 |
| CR-086 | Sesi DB UTC | `App\Database\MySQLi\Connection` |
| Q-09 (Fase 4) | Di mock, passphrase diketik admin di dialog; **mekanisme real = Fase 6** → Q6-06 | PRD Fase 4 :1295 |

### 5.2 Keputusan Fase 6 (LOCKED — Horii 01-11-2026; ✔ = dijawab eksplisit, ◻ = default diterima)

| ID | Keputusan | Label | Status |
|---|---|---|---|
| F6-01 | Sebelum GATE-F6 terbuka hanya M0 + persiapan akses (§0.1). GATE-F6 terbuka 02-11-2026 | — | ✔ |
| F6-02 | **Penjadwalan = `php spark` command + crontab sistem di runbook** (pola `cuti:saldo-tahunan`, `README-deploy.md`), bukan endpoint HTTP basic-auth dan bukan "python tiap 2 detik". Endpoint `hr/services/cron/*` **tidak di-port**; *jadwal & isi job* ikut legacy. ADR-037 | [V2] mekanisme, [K] isi | ◻ |
| F6-03 | **Auth antar-sistem satu pola** (ADR-036): tabel klien layanan (rahasia ber-hash, status, jenis klien, hak per grup endpoint, allowlist IP opsional), filter `klienLayanan` (Basic) untuk `services/*` dan `simapiToken` untuk SIMAPI. **Semua** endpoint wajib auth — termasuk yang publik di legacy (`A_dm/jabatan`, `S_stat` selain `rekomendasi_kp`, `S_dm p_*`). Klien diberi kredensial baru saat re-handshake | [V2] | ✔ Q6-01 = A |
| F6-04 | **Kontrak SIMAPI ikut legacy 1:1** [K]: path `/simapi/v1/<controller>/<method>/<nip>` (di luar `api/v1`), amplop `{"status":"01"|"02","msg","data"}`, HTTP 200, nama field & nilai teks persis (termasuk typo "Nomenlaktur", "paramater"), aturan `type` 1/2. Perbaikan hanya: auth wajib (F6-03), `ORDER BY` deterministik pada riwayat yang tak berurut. Bug `detil` `unit_es_3/4` (`AE:302-317`) **tidak** diperbaiki — ikut legacy (Q6-36, CR-104) | [K] + [V2] terbatas | ✔ Q6-02 = A |
| F6-05 | **Token SIMAPI**: opak 30 karakter, masa aktif 1 jam, header `Authorization: <skema> <token>` [K]; dibuat dari `random_bytes` dan disimpan SHA-256 [V2 keamanan dasar]. Karena hanya hash yang disimpan, setiap handshake **menerbitkan token baru**; token lama tetap sah sampai kedaluwarsa (klien tidak terdampak) | [K]/[V2] | ◻ |
| F6-06 | **`change_history`**: tabel `simapi_ch` diisi **aplikasi** (event sesudah commit pada tabel yang sama dengan trigger legacy) — trigger DB tidak dibawa karena tabel v2 berbeda; format `field_name`/`field_value` pipe, `LIMIT 100`, ack-on-read lewat `simapi_auth_ch` [K]. `simapi_update`/`_hist` **tidak dibuat** (tanpa pembaca) | [K]+[V2] | ◻ |
| F6-07 | **`A_sinkron::ambil_seluruh_pengguna`** (ekspor hash password + email untuk E-Talenta) **tidak di-port**; tercatat di Trello Catatan Teknis (kartu "Catatan Fase 6 — ambil_seluruh_pengguna tidak di-port") untuk ditangani bersama J3.03. `ambil_riwayat_mutasi` di-port [K] dengan auth | [V2] | ✔ Q6-03 = A |
| F6-08 | Kredensial klien lama (`simapi_auth`, `sal_user`, `MUDIG_*`) **tidak diimpor**; klien baru dibuat lewat command `integrasi:klien` (buat/rotasi/nonaktif, rahasia ditampilkan sekali) | [V2] | ◻ |
| F6-09 | **`services/s_stat/*` (H-08)** = API tarik untuk MuDig, **aturan deteksi & kolom keluaran ikut legacy persis** [K] (`LE:2416-2720` KP, `LE:1324-1376`+`FH:2400-2418` pensiun, `LE:4095-4220` KGB), termasuk KGB "24–36 bulan → hari ini" dan pensiun hanya PNS. Penyesuaian teknis: urutan KGB memakai `tmtsk_kgb` (kolom `tgl_pensiun` tidak ada, `S_stat.php:1007`), cek berkas arsip lewat storage v2 (bukan HTTP fetch). URL kolom `arsip_*`/`file_*` mengarah ke endpoint unduh khusus klien layanan `services/s_stat/berkas/…` (Q6-38, CR-105). **Tidak** menulis tabel/notifikasi baru | [K] | ✔ Q6-04 = A |
| F6-10 | **`services/s_dm/*`** di-port [K] dengan auth, **kecuali `p_web_config`** (keluarannya rusak oleh bug key `config_nama` di `S_dm.php:279` dan membocorkan konfigurasi) | [K]/[V2] | ◻ |
| F6-11 | **FCM HTTP v1** (service account, OAuth2 JWT via `firebase/php-jwt` yang sudah terpasang, token akses di-cache). FCM Legacy API (server key) sudah dihentikan Google sehingga protokol **wajib** berganti; perilaku (siapa menerima apa, kapan) tetap ikut legacy | [V2] teknis wajib | ◻ |
| F6-12 | **Notifikasi presensi ikut legacy**: saat presensi masuk/pulang dan saat LKH dibuat, token pegawai dimasukkan/dikeluarkan dari topik `pegawai_blm_masuk`, `pegawai_blm_pulang`, `pegawai_blm_lkh` [K `L:1834-1878`, `L:1976-2044`, `L_lkh.php:430-445`] lewat job antrean. Manajemen topik memakai endpoint Instance ID `batchAdd/batchRemove` dengan token OAuth service account (cara Firebase Admin SDK). **Pengiriman pesan ke topik tetap di luar SIMPEG** seperti legacy (Firebase console/penjadwal luar) — syaratnya proyek Firebase v2 = proyek legacy (PN-3) | [K] | ✔ Q6-11 = ikut legacy |
| F6-13 | **Kanal push ikut legacy**: (1) **Android** — token di `firebase_token` (`platform`=2), pesan dari **semua** produsen `andr_notif_queue` legacy (CR-099, Q6-28): cuti `L_cuti.php:1106/1240/2540/3147`, support `L_support.php:1080/1290/1415`, **LKH** `L_lkh.php:401` (diajukan → atasan "Monitoring LKH"), `:637` (diubah), `:1138` (diproses → pegawai), **Konket** `L_konket.php:1972` (hasil proses → pegawai dan peserta dinas gabungan); teks & bentuk `data` ikut legacy; v2: `NotifikasiService` Fase 4 (cuti) + pemicu LKH/Konket + Halo Simpeg Fase 8. **TB/IB/PMK hanya web** (legacy tanpa push Android). Popup "Informasi" `Cron.php:953` (`queue_popup_notif`) = Fase 7 F-04 (hook saja, Q6-29); (2) **Web/desktop** — pengganti `register_fb_token` + `fb_token`: endpoint `POST api/v1/integrasi/fcm-token` dipanggil SPA saat halaman dimuat setelah login [K `views/template/header.php:59`], token `platform`=1, perpanjang 1 minggu [K `L:4224-4289`]; penerima push riwayat/layanan ikut `fcmQueue_rwy`/`fcmQueue_layanan` [K `FH:588-915`] (admin, pegawai, atasan untuk LKH). Penulis token Android (backend app mobile di luar repo) = pertanyaan cutover (DEPLOY-001.1) | [K] | ✔ Q6-12 = ikut legacy |
| F6-14 | **SIASN ikut legacy penuh** (H-03): klien auth BKN (client credentials + SSO; token dari konfigurasi + cache — **tanpa token literal**, Q6-08); job berulang yang **aktif** di legacy (Lampiran A.5): pairing `id_pns_siasn` + salin data utama ke `pegawai` (`nip_lama`, `npwp`, `no_hp`, `keterangan`="Sync SIASN") [K `SI:380-507`]; tarik langsung ke tabel utama: golongan→`riwayat_kp`, pendidikan, PMK, SKP ≥2022 (rating 1↔3, **hapus fisik baris berpasangan yang tidak lagi dikembalikan BKN**), AK→`riwayat_ak_siasn`, referensi unor [K `SS/*`]; **dorong otomatis** riwayat dengan kriteria legacy per tabel: jabatan `siasn_flag=0` atau `updated_at > siasn_lu`; diklat, sertifikasi, kursus/seminar, SKP 2022 hanya `id_rw_siasn IS NULL` dan `siasn_flag=0` (keputusan Horii 02-11-2026, Q2 B0 Jalur B; tanpa kolom `siasn_lu` baru) + antrean unggah dokumen `siasn_upload_arsip` [K]. Hanya satu implementasi per tabel tujuan (duplikat legacy dipilih yang generasi 2). **Pengaman wajib** (bukan perubahan perilaku): hapus fisik SKP hanya untuk NIP yang panggilan API-nya **berhasil** (legacy ikut menghapus saat API gagal, `Rw_skp.php:219`); salah variabel `Rw_ak.php:65` diperbaiki; `debug()` tidak dibawa | [K] | ✔ Q6-07 = ikut legacy penuh |
| F6-15 | **H-10 e-Kinerja BKN masuk Fase 6** (Tukin bergantung). Ikut legacy: periode **hanya insert periode baru** [K `EB:9`]; SKP periodik insert baru dan update bila `hasil_akhir` berubah, kunci logis `id_pns`+`periode_id`+`skp_id` [K `EB:205`]; pemilihan periode tanggal > 19 → bulan berikutnya; periode manual dilewati. Aturan Tukin tetap [K] (potongan 0/16/32%, default 32% bila tak ada baris). Tambahan v2: UNIQUE kunci logis (Q6-10) + peringatan di log bila periode berjalan belum punya data | [K] | ✔ Q6-09 = A |
| F6-16 | **`services/s_esign` untuk MuDig** di-port: auth klien layanan, berkas dikirim sebagai **unggahan multipart** (bukan path server) [V2], `esign_hist` **wajib** dicatat [V2], respons berisi PDF ber-tanda tangan (base64) [V2]; parameter lain & amplop [K] | [V2] | ✔ Q6-05 = A |
| F6-17 | **BSrE ikut legacy**: NIK penanda tangan dari `pegawai.nik` PyB; `nik` & `passphrase` dikirim di query string seperti legacy (`Esign.php:166-188`, `bsre_esign.php:55-74`) — **disensor** dari semua log; kode `status_code=='1111'` = bersertifikat. Pengaman: respons dianggap sukses hanya bila berupa PDF (`%PDF-`) — legacy menulis JSON non-error sebagai berkas PDF (`bsre_esign.php:102-126`) | [K] + pengaman | ◻ |
| F6-18 | **Passphrase e-sign real diketik penanda tangan (PyB) sendiri** di halaman tugas persetujuannya — sama dengan alur legacy `Cuti::process_esign` (`Cuti.php:688-705`, `L_cuti.php:3232-3484`). Dialog admin di mock Fase 4 diganti/diselaraskan (deviasi dari PRD Fase 4 Q-09 dicatat). Rincian alur (CR-097, §7.2): Q6-19 dua langkah, Q6-20 pembatas passphrase, Q6-21 passphrase hanya Setujui/Penangguhan, Q6-22 QR TTE ikut legacy, Q6-23 QR kaki verifikasi publik. **Pengecualian Q6-40 (CR-107):** persetujuan "atas nama" PyB oleh admin boleh disertai TTE dengan passphrase PyB yang diketik admin (ikut legacy) | [K] | ✔ Q6-06 = A; Q6-19..Q6-23 (03-11) |
| F6-19 | **H-09 ditutup sebagai pemetaan** (tanpa endpoint baru): `calculate_lc` → `POST cuti/hitung-lama`; `record_geolocation` → F5-12; `register_fb_token` → H-04; seluruh AJAX dropdown/kalkulator `Local.php` sudah diserap modul Fase 2–5 (Lampiran A.4) | — | ◻ |
| F6-20 | **H-11 status harian** `php spark layanan:status-harian [--tanggal=] [--dry-run]`, jadwal 00:30 WIB: TB (`riwayat_tb` status 1) mencakup hari ini → `id_jenis_status=2`; CLTN (`riwayat_cuti` `id_jenis_cuti=6` **status 1**; status 4 tidak — CR-098 Q6-25, ikut legacy) → 3; tidak ada lagi rentang aktif dan status masih 2/3 → 1 **hanya bila dijelaskan riwayat yang sudah berakhir** (Q6-26). Bentrok TB+CLTN → Q6-24; `--tanggal` → Q6-27 (rincian §7.9). Perpanjangan = baris riwayat baru yang bersambung. Tidak menyentuh status selain 1/2/3. Lewat model beraudit. Perbandingan per tanggal inklusif (PRD Fase 4 v1.4) | [V2] (keputusan Horii 27-10; legacy tanpa cron) | ◻; Q6-24..Q6-27 (03-11) |
| F6-21 | **Tidak di-port** (kode mati/nonaktif atau tidak relevan di v2): SAPK lama, Siasn generasi 1 backfill, email ultah, popup notif, `fix_regen_*`, `Ekinerja.php` (Q6-14), skema `siasn_simpeg`/`sapk_simpeg` (tabel yang dibutuhkan H-03 dibuat ulang ramping di DBV-027). `sync_nama_pengguna` **di-port [K]** bila v2 menyimpan nama tampilan di `pengguna` (diverifikasi M0 A) | — | ◻ |
| F6-22 | Server Arsip remote (J3.06, F-10) **ditunda**; v2 tetap storage lokal | — | ◻ |
| F6-23 | **Exit criteria uji real**: Fase 6 boleh di-sign-off dengan mock/FakeTransport; **uji endpoint real** (e-sign cuti, SIASN/e-Kinerja, FCM) menjadi syarat **sebelum deploy** — dilacak kartu **MT-010** (list Manual Testing) dan **DEPLOY-001.1** (list Deployment) | — | ✔ Q6-13 = A |
| F6-24 | Log integrasi tunggal `integrasi_log` (arah masuk/keluar, layanan, operasi, status, durasi, kode, pesan teredaksi, `ref_id`) + command `integrasi:status`. Retensi 180 hari (Q6-16) | [V2] | ◻ |
| F6-25 | **Auto-approval LKH ikut legacy** (H-12): command `lkh:auto-approval` tanggal 5 [K `C:14-17`]: LKH bulan lalu yang belum disetujui → `status=1`, `auto_approval=1`, dicatat lewat audit (padanan `aa_lkh`). **PDF ikut v2: dibuat saat diminta, tidak disimpan** (CR-093, keputusan Horii 03-11-2026 atas A0 v2 Jalur A; MAKE-013; legacy menyimpan `file_lckh` — deviasi [V2]); tanpa tabel `aa_lkh` baru. Perilaku efektif legacy: hanya status **0** yang diproses (`C:147`; status 3/revisi tertahan di antrean legacy) [K]. Bergantung modul LKH v2 (diverifikasi M0 A; bila belum ada → eskalasi) | [K] | ✔ Q6-15 = ikut legacy |
| F6-26 | **Token SSO BKN produksi** (cara memperoleh, pemilik akun layanan, TOTP) dicatat sebagai **pertanyaan** di Trello Catatan Teknis (label Menunggu Jawaban); v2 tidak memakai token literal | — | ✔ Q6-08 |

---

## 6. Matriks endpoint & job

### 6.1 Endpoint masuk kompatibilitas legacy (Jalur A, F6-03/F6-04)

| Path v2 (sama dengan legacy) | Method | Auth | Task |
|---|---|---|---|
| `simapi/v1/a_auth/token` | GET/POST [K] | Basic klien layanan (jenis SIMAPI) + throttle | H-05 |
| `simapi/v1/a_employee/{method}/{nip}` (25 method, Lampiran A.1) | GET/POST [K] | `simapiToken` | H-06 |
| `simapi/v1/a_employee/change_history` | GET/POST | `simapiToken` + type 2 | H-06 (F6-06) |
| `simapi/v1/a_employee/foto/{nip}` | GET | `simapiToken` | H-06 [V2] (Q6-39) |
| `simapi/v1/a_dm/{group_jabatan|sub_group_jabatan|jabatan|struktur_jabatan}` | GET/POST | `simapiToken` (+ type 2 kecuali `jabatan` [K]) | H-07 |
| `simapi/v1/a_sinkron/ambil_riwayat_mutasi` | GET/POST | `simapiToken` | H-06 (F6-07) |
| `services/s_stat/{rekomendasi_kp|rekomendasi_kp_uns|rekomendasi_pensiun|rekomendasi_kgb|list_tb|list_ib|list_karpeg|list_kariskarsu}` | GET | `klienLayanan` (hak `stat`) | H-08 |
| `services/s_stat/berkas/{jenis}/{nip}/{id}` dan `services/s_stat/berkas/{jenis}/{nip}/{id}/{kolom}` | GET | `klienLayanan` (hak `stat`) | H-08 [V2] (Q6-38) |
| `services/s_dm/{list_unit|list_satker|p_list_unit|p_list_unit_wo_kep|p_get_unit|p_list_satker|p_get_satker}` | GET | `klienLayanan` (hak `dm`) | H-07 |
| `services/s_esign/{check_user|sign_pdf}` | GET / POST multipart | `klienLayanan` (hak `esign`) | H-02 (F6-16) |

Nginx wajib meneruskan `/simapi/` dan `/services/` ke backend v2 sejak DP2.4 (catat di `README-deploy.md`). Route CI4 di
berkas sendiri (`RoutesSimapi.php`, `RoutesServices.php`) di luar grup `api/v1`, **tanpa** filter `jwt`/`cors` SPA.

### 6.2 Endpoint v2 baru (amplop v2)

| Path | Method | Auth/role | Task |
|---|---|---|---|
| `api/v1/integrasi/fcm-token` | POST (daftar/perbarui), DELETE (lepas) | jwt, semua role | H-04 |
| `api/v1/integrasi/bkn/tarik` | POST `{jenis: periode|skp_periodik, tahun, bulan}` | jwt, role 1 | H-10 |
| `api/v1/integrasi/log` | GET (filter layanan/status/tanggal, paginasi) | jwt, role 1 | F6-24 |

FE (Jalur B): halaman **Admin → Integrasi** (role 1): log integrasi dan tombol tarik ulang e-Kinerja per periode. Aksi baris
lewat menu ⋮ (AGENTS.md §1). SIASN ikut legacy: **tanpa** UI admin sinkron/dorong (legacy tidak punya, F6-14).

### 6.3 Amplop respons kompatibilitas

| Grup | Sukses | Gagal | HTTP |
|---|---|---|---|
| SIMAPI | `{"status":"01","msg":"…","data":…}` | `{"status":"02","msg":"Authentication failed"|"Token is not valid"|"Invalid paramater"|"NIP not valid"|"Data … tidak tersedia","data":[]}` | 200 [K] — `data` gagal **`[]`** seperti legacy (`A_auth.php:21-42`, `A_employee.php:22-45`), termasuk gagal auth/throttle (CR-106) |
| `services/s_stat`, `s_dm` | `{"status":1,"msg","data"}` (**int**) [K] (`S_stat.php:24…`, `S_dm.php:24…`) | `{"status":2,…}` (int) | 200 [K]; auth gagal 401 [V2] |
| `services/s_esign` | `{"status":"1","msg","data"}` (**string**) [K] (`S_esign.php:12,57,122`; koreksi CR-106) | `{"status":"2",…}` (string) | 200 [K]; auth gagal 401 [V2] (`ResponsAuthKlien`, int) |

### 6.4 Job terjadwal (crontab runbook, F6-02)

| Command | Jadwal usulan (WIB) | Task |
|---|---|---|
| `layanan:status-harian` | 00:30 setiap hari | H-11 |
| `bkn:tarik-periode` | 01:00 setiap hari | H-10 |
| `bkn:tarik-skp-periodik [--tahun --bulan]` | 02:00 setiap hari (periode berjalan + sebelumnya) | H-10 |
| `siasn:tarik [--jenis=]` | jadwal legacy: data utama harian, golongan/SKP tiap 6 jam (batas usang 6 jam/1 hari [K `SS/*`]); jadwal pasti dicocokkan dengan crontab legacy (PN-5) | H-03 |
| `siasn:dorong [--jenis=]` + `siasn:unggah-arsip` | berkala (ikut crontab legacy, PN-5) | H-03 dorong otomatis |
| `lkh:auto-approval` | tanggal 5, 00:30 (ulang 01:00/01:30/02:00, idempoten) [K `C:14-17`] | H-12 |
| `integrasi:bersihkan-log` | 04:00 Minggu | F6-24 |
| `cuti:saldo-tahunan` (sudah ada) | 00:30 1 Januari + ulang berkala | (Fase 4, dicatat di runbook) |
| `queue:work push` (sudah ada) | setiap menit — termasuk job keanggotaan topik presensi (F6-12) | H-04 |

---

## 7. Spesifikasi per task

### 7.0 Fondasi

**7.0.1 Auth antar-sistem (Jalur A, MAKE-058, ADR-036)** — tabel klien layanan (DBV-026): `id`, `nama`, `username`
(UNIQUE), `rahasia_hash`, `jenis` (1 SIMAPI lama/Pusdatin, 2 SIMAPI baru/Simanta [K `simapi_auth.type`], 3 layanan/MuDig),
`hak` (set: `simapi`, `stat`, `dm`, `esign`, `sinkron`), `ip_allowlist` (NULL = semua), `status` 1/2/10, audit kolom.
Filter `klienLayanan:<hak>` (HTTP Basic → verifikasi hash → cek status, hak, IP → throttle per klien). Filter `simapiToken`
(header `Authorization` elemen kedua [K `AE:30-47`] → SHA-256 → `simapi_token` status 1 & belum kedaluwarsa). Gagal → amplop
grup (§6.3). Command `integrasi:klien buat|rotasi|nonaktif|daftar`. **AC-F.1** rahasia tidak pernah tersimpan/terlog
plaintext; **AC-F.2** klien status 2/10 ditolak; **AC-F.3** IP di luar allowlist ditolak; **AC-F.4** throttle aktif;
**AC-F.5** token kedaluwarsa/revoked → "Token is not valid".

**7.0.2 Klien HTTP keluar & log (Jalur B, MAKE-063)** — `KlienHttp` (BP-3), `FakeTransport` untuk test, `IntegrasiLog`
(F6-24, tabel DBV-027), penyedia token BKN (`TokenBkn`: client credentials API gateway + SSO; cache via `CacheService`
sampai `expires_in − 60 dtk`; refresh bila 401 sekali). **AC-K.1** tanpa nilai rahasia di log (uji redaksi); **AC-K.2** GET
di-retry maks 3, POST tidak; **AC-K.3** timeout tercatat sebagai kegagalan; **AC-K.4** driver real tanpa config → exception
saat dibuat.

**Bentuk auth BKN (Q6-17 = A, CR-092).** Diekstrak dari `FH:2513-2955` tanpa nilai; nama konstanta legacy hanya rujukan
(isinya di `constants.php`, tidak dibuka). Semua permintaan token: POST `application/x-www-form-urlencoded`.

| Jalur legacy | Header ke endpoint token | Body | Field respons dibaca | Cache legacy |
|---|---|---|---|---|
| Token API gateway `getTokenApi_siasn` | `Authorization: Basic base64(user:pass)` (`SIASN_API_USER/PASS`) | `grant_type=client_credentials` | `access_token`, `expires_in`, `token_type`, `scope` | `siasn_simpeg.api_token` (`expired_at > NOW()`; `expired_at` tidak dihitung di PHP) |
| Token SSO `getTokenSSO_siasn` | `Authorization: Basic` (kredensial gateway yang sama) | `grant_type=password`, `client_id`, `username`, `password` (`SIASN_SSO_*`) | `access_token`, `expires_in`, `refresh_token`, `refresh_expires_in`, `token_type`, `not_before_policy`, `session_state`, `scope` | `siasn_simpeg.api_ssobkn` |
| SSO varian e-Kinerja `getTokenSSO_siasn_2` | sama | sama + `totp` (**nilai tetap**, tanpa algoritma TOTP) dengan `EKIN_API_*`/`EKIN_SSO_*` | sama | sama |

Pemakaian token: **SIASN** (`getApi_siasn`, `postApi_siasn`, `*Arsip`) mengirim **dua header** `Authorization: Bearer <token
gateway>` + `Auth: bearer <token SSO>` + `accept: application/json` (POST `Content-Type: application/json`, unggah arsip
multipart); **e-Kinerja** — cron aktif `EB::cron_periode_skp`/`cron_skp_periodik` memanggil `getApi_bkn_ekinerja` dengan satu
header `Authorization: Bearer` memakai **token literal hard-code `FH:2919`** (temuan keamanan legacy; nilai tidak dibaca);
versi lama `getApi_bkn` (`*_20240614`, `_spe`, `_test`) memakai token SSO varian e-Kinerja.

Desain `TokenBkn` v2 (tanpa token literal, Q6-08): strategi per layanan lewat `Config\Integrations` — **(i)** token statis
dari `.env` (`bkn.ekinerjaToken`, kosong bawaan; padanan perilaku legacy aktif e-Kinerja) dan **(ii)** alur SSO password grant
(+ Basic gateway, `totp` opsional dari konfigurasi; kosong = tidak dikirim) dengan cache `CacheService` sampai
`expires_in − 60 dtk` dan refresh sekali saat 401. e-Kinerja: (i) bila terisi, selain itu (ii). SIASN: wajib token gateway +
token SSO di dua header. Semua kunci `.env` kosong di `.env.example`; nilai token/kredensial tidak pernah masuk log (AC-K.1).

**Kontrak log bersama Jalur A & B (CR-091, usulan Jalur B disepakati Horii 02-11-2026).** Kedua jalur menulis `integrasi_log` lewat **satu antarmuka yang sama**;
Jalur A **tidak** membuat antarmuka/penulis log sendiri:

- `App\Interfaces\Integrasi\IntegrasiLogInterface` — satu metode `catat(CatatanIntegrasi $catatan): void`. Implementasi wajib
  menyimpan pesan yang **sudah teredaksi** (BP-5) dan **tidak boleh** menggagalkan operasi bisnis bila penulisan log gagal
  (BP-7: kegagalan log cukup dicatat ke log berkas).
- `App\Libraries\Integrasi\Log\CatatanIntegrasi` — objek nilai satu baris `integrasi_log` (DBV-027): konstanta
  `ARAH_KELUAR = 1` (Jalur B), `ARAH_MASUK = 2` (Jalur A), `SUKSES = 1`, `GAGAL = 2`, `PESAN_MAKS = 1000`; konstruktor
  `(int $arah, string $layanan, string $operasi, int $status, ?int $httpStatus = null, ?string $kode = null,
  ?string $pesan = null, ?int $durasiMs = null, int $percobaan = 1, ?string $refId = null, ?int $idKlien = null)`.
  `idKlien` diisi Jalur A (klien layanan, tanpa FK); `pesan` diredaksi pemanggil lalu dipotong penulis log ke
  `PESAN_MAKS`. URL, query, header, dan body **tidak** dicatat (path BKN/BSrE/SIMAPI bisa memuat NIP/NIK).
- Implementasi `App\Libraries\Integrasi\Log\IntegrasiLog` dibuat Jalur B (MAKE-063) dan didaftarkan sebagai service.
  Bila Jalur A butuh pencatatan sebelum MAKE-063 masuk `main`, A memakai implementasi no-op dari antarmuka yang sama
  (berkas antarmuka + objek nilai boleh ikut commit A **persis** sesuai kontrak di atas; yang masuk `main` lebih dulu
  dipakai, yang lain menyesuaikan tanpa mengubah tanda tangan). Perubahan kontrak = CR baru, disepakati kedua jalur lewat reviewer.

**7.0.3 Penjadwalan (Jalur A, ADR-037)** — command + crontab `/etc/cron.d` dengan `flock` (pola `README-deploy.md:137-161`),
log ke `writable/logs`, kode keluar ≠ 0 bila gagal. Tabel jadwal §6.4 masuk `README-deploy.md`.

### 7.1 H-01 — Skema

**DBV-026 (A)**: `klien_layanan` (7.0.1), `simapi_token` (`token_hash` CHAR(64) PK, `id_klien`, `status`, `expired_at`,
`created_at`), `simapi_jd` (master jenis data change history [K]), `simapi_auth_jd` (filter jenis per klien [K]), `simapi_ch`
(`id_simapi_ch` BIGINT, `id_jd`, `id_rwy`, `nip`, `action` 'INSERT / UPDATE'|'DELETE', `action_at`, `field_name`,
`field_value` TEXT [K]), `simapi_auth_ch` (`id_klien`, `id_simapi_ch` — penanda sudah dikirim [K]). Indeks untuk
`id_simapi_ch > ?` per klien. Collation `utf8mb4_unicode_ci`, label [K]/[V2]/[I] per kolom dari DDL legacy.

**DBV-027 (B)**: `integrasi_log` (F6-24); ALTER `firebase_token` (+`platform` 1 web/2 android, +`token_hash` CHAR(64)
UNIQUE untuk deduplikasi — DQ-25); ALTER `riwayat_skp_periodik` UNIQUE (`id_pns`,`periode_id`,`skp_id`) **setelah pra-cek
duplikat** di data impor (pola runbook UP-1); tabel pendukung SIASN ikut legacy (F6-14) yang **belum** ada di v2:
antrean unggah dokumen `siasn_upload_arsip` [K `SS/Rw_jabatan::set_queue_arsip`, `SS/Arsip`], referensi `REF_UNOR` dan
referensi id jabatan fungsional/umum/jenis jabatan yang dipakai dorong jabatan [K `SI:3804`] — kolom diturunkan dari kode
([I], DDL `siasn_simpeg` tidak tersedia; isi referensi diimpor saat cutover atau ditarik `Ref_unor::sync`). Dorong memakai
kolom `siasn_flag`/`siasn_lu`/`id_rw_siasn`/`siasn_error` yang **sudah ada** di `riwayat_*` (tidak diubah). `esign_hist`
tidak diubah (`ip_address` sudah VARCHAR(45), `status_code` sudah ada).

**AC-01.1** dokumen `backend/docs/db-review/DBV-026-*.md` & `DBV-027-*.md` di-approve DBV; **AC-01.2** `firebase_token`
tetap kosong saat cutover (tidak diimpor, FSD-FN-H-01); **AC-01.3** migration naik/turun bersih di DB scratch.

### 7.2 H-02 — BSrE (Jalur B, MAKE-065)

[K `libraries/Esign.php`, `bsre_esign/bsre_esign.php`; konsumen `L_cuti.php:3232-3484`]. `BsreEsignAdapter`:
- `checkUser(nip)`: NIK dari `pegawai.nik` → `GET /api/user/status/{nik}` → `has_certificate = (status_code == '1111')` [K
  `Esign.php:16-45`]. Pesan gateway diteruskan.
- `signPdf(pdf, nip, passphrase, options)`: multipart `file` + opsi tampilan (`tampilan`, `image`, `width`, `height`,
  `tag_koordinat` default `##`, `reason`, `location`) [K `S_esign.php:74-84`]; `nik` & `passphrase` di query string seperti
  legacy, disensor dari log (F6-17); gambar TTD = QR per tanda tangan (Q6-22 di bawah). Sukses hanya bila respons PDF valid
  (F6-17). Kode/pesan gateway → `status_code`/`message`.
- **TLS ke gateway BSrE (Q6-18 = B, ikut legacy, CR-097).** Legacy mematikan verifikasi peer & host
  (`bsre_esign.php:92-93`, B-10). v2: `esign.tlsVerify` bawaan **true** (config + `.env.example`); nilai `false` hanya disetel
  di `.env` server sebagai "gateway internal" (BP-3) dan **khusus e-sign** — BKN tetap wajib true. Saat `false`:
  - verifikasi peer & host mati (sama dengan legacy);
  - `log_message('warning')` sekali per proses;
  - `integrasi:status` dan `integrasi:uji-koneksi esign` menampilkan "TLS BSrE: TIDAK DIVERIFIKASI".

  Opsi `esign.caBundle` (path CA di luar repo/webroot) disiapkan agar **upgrade ke TLS ketat cukup lewat `.env`**:
  1. infra mengecek sertifikat gateway (penerbit, nama host, masa berlaku, HTTPS);
  2. `tlsVerify=true` (+ `caBundle` bila CA internal);
  3. `uji-koneksi esign`;
  4. satu tanda tangan uji.

  Langkah ini ditulis di README-deploy.

  **Gap diterima:** MITM di jalur jaringan bisa menyadap client secret, NIK, passphrase, dan PDF, atau mengembalikan PDF palsu
  (cek `%PDF-` tidak membuktikan keaslian). Keamanan bergantung pada jalur internal/VPN. Opsi ini juga berpotensi menjadi temuan
  audit. Rincian opsi A/B dan gap: Trello Catatan Teknis "Keputusan TLS BSrE".
- **Alur e-sign cuti (keputusan Horii 03-11-2026, CR-097; rincian opsi & gap: Trello Catatan Teknis "Keputusan e-sign cuti
  F6-18").**
  - **Q6-19 = B, dua langkah.** Dialog persetujuan PyB berisi passphrase. FE memanggil persetujuan (`process`), lalu
    `POST cuti/{id}/esign`. E-sign **hanya oleh PyB pemilik tugas** (cek kepemilikan, bukan role); admin role 1/3 tidak lagi
    menerbitkan TTE.
    - Tanda tangan gagal → persetujuan **tetap tersimpan**, surat tanpa TTE, PyB dapat mengulang dari tugasnya.
    - Ini deviasi dari legacy `L_cuti:3232-3484` (satu langkah; sign gagal → persetujuan tidak disimpan). Opsi A (satu langkah)
      tidak dipilih karena risiko regresi C-04.
  - **Q6-20 = ya, pembatas percobaan passphrase [V2]:** 5 percobaan / 15 menit per PyB per cuti (pola LockoutService). Legacy
    tanpa pembatas; BSrE mengunci sertifikat bila salah berulang.
  - **Q6-21 = P-1, passphrase hanya untuk Setujui (1) / Penangguhan (4); Tolak tanpa passphrase.** Ikut tampilan legacy
    (`approval_cuti.php:316`). Validasi server legacy (`L_cuti:3667-3674`) mewajibkan passphrase untuk semua status, sehingga
    PyB bersertifikat tidak bisa menolak (bug, diperbaiki). Surat hanya dibuat untuk status 1/4 (`L_cuti:3264`).
  - **Q6-22 = Q-1, QR gambar TTE ikut legacy:** QR berisi URL **langsung ke PDF bertanda tangan**, terbuka **tanpa login**
    (legacy `L_cuti:3364-3389`: URL arsip publik `arsip/{NIP}/cuti/co_{NIP}_{tgl}_{waktu}_{acak 4}_signed.pdf`). Parameter QR
    dan TTE ikut legacy: logo tengah, 300px, `tag_koordinat ##`, 50x50, visible, `reason`, `location`.
    - Bentuk v2, perilaku tetap sama:
      - satu route publik tanpa `jwt` yang hanya mengalirkan PDF surat cuti bertanda tangan;
      - URL memakai **token acak**, bukan path ber-NIP;
      - throttle publik, 404 seragam, `no-store` + `noindex`, `inline`;
      - log tanpa token/IP;
      - token nonaktif bila surat dicabut.
    - **Gap diterima:**
      - siapa pun yang memegang QR/URL dapat mengunduh surat utuh (nama, NIP, jabatan, alasan & alamat cuti, data PyB);
      - URL bocor berarti akses permanen sampai surat dicabut;
      - perlu justifikasi pelindungan data pribadi/audit;
      - QR bukan bukti keaslian (bukti = tanda tangan digital di PDF);
      - QR surat legacy lama tetap menunjuk server arsip legacy.
    - Opsi lain yang tidak dipilih:
      - Q-2 verifikasi publik;
      - Q-3 URL ber-login;
      - Q-4 tanpa QR;
      - Q-5 QR ke validator resmi.
  - **Q6-23 = Q-2, QR kaki surat diganti verifikasi publik [V2].** QR kaki v2 saat ini (`SuratCutiService`, teks
    `SIMPEG/CUTI/{id}/{nip}`, memuat NIP, tidak bisa dipakai verifikasi) diganti URL halaman verifikasi publik **pola slip
    D-14** (ADR-032):
    - token acak 64 heks; DB menyimpan hash + salinan terenkripsi;
    - throttle, 404 seragam;
    - data minimal: jenis surat, nama penandatangan, tanggal tanda tangan, status "ditandatangani elektronik"/"belum";
      **tanpa NIP dan tanpa unduh PDF**.
    - Token yang sama dipakai URL PDF Q6-22. Surat tanpa TTE tetap mendapat QR kaki berstatus "belum".
  - Token surat = perubahan skema → **DBV-030** (`[CR][DBV]`, PR, review DB Validator).
- Kontrak `EsignGatewayInterface` **tidak berubah**. Alur penanda tangan (F6-18): passphrase diketik **PyB sendiri** di
  halaman tugas persetujuannya (legacy `Cuti::process_esign`); perubahan `EsignCutiService`/FE dari dialog admin mock Fase 4
  dicatat sebagai deviasi terhadap PRD Fase 4 Q-09 dan diuji ulang (C-05).
- `services/s_esign` (F6-16): `check_user?nik=` → `{status 1|2,msg,data}`; `sign_pdf` multipart → `esign_hist` wajib.
**AC-02.1** regresi C-05 (test Fase 4 e-sign cuti) hijau dengan driver mock dan dengan adapter real + FakeTransport;
**AC-02.2** gateway mati/timeout → `esign_hist` status 2 + `status_code` + pesan, cuti tidak berubah final; **AC-02.3**
passphrase/NIK tidak ada di log mana pun; **AC-02.4** uji real (reviewer, jaringan internal): 1 sukses + 1 gagal (MTC-037) — di MT-010, sebelum deploy (F6-23).
**AC-02.5** (CR-097):
- `esign.tlsVerify=false` diterima untuk e-sign, tetapi `false` untuk BKN ditolak fail-fast;
- saat `false` muncul peringatan log + baris "TLS BSrE: TIDAK DIVERIFIKASI" di `integrasi:status`;
- `esign.caBundle` tak terbaca → galat konfigurasi.

**AC-02.6** (CR-097):
- e-sign oleh selain PyB pemilik tugas → 403;
- tanda tangan gagal → persetujuan tetap tersimpan dan PyB bisa mengulang;
- percobaan ke-6 dalam 15 menit → ditolak pembatas;
- Tolak tidak meminta passphrase.

**AC-02.7** (CR-097) — route PDF publik Q6-22:
- token sah → PDF bertanda tangan;
- token salah/nonaktif/surat tanpa TTE → 404 seragam;
- throttle aktif; header `no-store` + `noindex`;
- tidak ada route publik untuk berkas lain.

**AC-02.8** (CR-097) — halaman verifikasi Q6-23:
- menampilkan data minimal tanpa NIP dan tanpa unduh PDF;
- token salah → 404 seragam;
- QR kaki surat tidak lagi memuat NIP.

### 7.3 H-03 — SIASN (Jalur B, MAKE-067) — ikut legacy penuh (F6-14)

[K generasi 2 `SS/*` + job berulang `SI` yang aktif; auth `FH:2513-2721`]. Implementasi `SiasnGatewayInterface`
(`getPegawai`, `getRiwayat`, `syncPegawai`) **tanpa ubah kontrak**; kebutuhan tambahan (unggah dokumen, simpan riwayat,
referensi) = interface baru `SiasnDorongInterface` / `SiasnReferensiInterface`.

| Job v2 | Padanan legacy [K] | Tulis |
|---|---|---|
| `siasn:tarik --jenis=data-utama` | `SS/Data_utama::sync_dsp` + `::sync_siasn` + `SI::sync_data_utama` 380 + `SI::sync_pns_id` 455 | `pegawai.id_pns_siasn/siasn_flag/siasn_lu`, `nip_lama`, `npwp`, `no_hp`, `keterangan`="Sync SIASN" (`SI:428-438`) |
| `--jenis=golongan` | `SS/Rw_gol::get_siasn` | `riwayat_kp` (pairing `id_rw_siasn` atau `gol_ruang`, lepas pasangan = `id_rw_siasn` NULL) + catatan duplikat (`double_riwayat_kp`) |
| `--jenis=pendidikan` | `SS/Rw_pendidikan::get_siasn` | `riwayat_pendidikan` (PNS + `id_jenis_pegawai` 6) |
| `--jenis=pmk` | `SS/Rw_pmk::get_siasn` | `riwayat_pmk` |
| `--jenis=skp` | `SS/Rw_skp::get_siasn` (`SI::sync_rw_skp23` duplikat, tidak dibawa) | `riwayat_skp` ≥2022, rating 1↔3, **hapus fisik** baris berpasangan yang tak lagi dikembalikan (hanya bila API NIP itu sukses — pengaman F6-14) |
| `--jenis=ak` | `SS/Rw_ak::sync_siasn` | `riwayat_ak_siasn` (salah variabel `:65` diperbaiki) |
| `--jenis=ref-unor` | `SS/Ref_unor::sync` | referensi `REF_UNOR` (DBV-027) |
| `siasn:dorong --jenis=jabatan` | `SS/Rw_jabatan::upload_siasn` 105 (= `SI::upload_rw_jabatan` 3804) | `riwayat_mutasi_jabatan` `siasn_flag=0` **atau** `siasn_lu < updated_at` → `/jabatan/unorjabatan/save` |
| `--jenis=diklat|sertifikasi` | `SS/Rw_pelatihan::upload_rw_diklat` 164, `::upload_rw_sertifikasi` 326 | `riwayat_diklat` `id_rw_siasn IS NULL` dan `siasn_flag=0` (tanpa dorong ulang, CR-090) |
| `--jenis=kursus` | `SI::upload_rw_kursus` 4848 | `riwayat_seminar` `id_rw_siasn IS NULL` dan `siasn_flag=0` (tanpa dorong ulang, CR-090) |
| `--jenis=skp22` | `SI::upload_rw_skp22` 7704 (= `SS/Rw_skp::upload_rw_skp22`) | `riwayat_skp` 2022 `id_rw_siasn IS NULL` dan `siasn_flag=0` (tanpa dorong ulang, CR-090) |
| `siasn:unggah-arsip` | `SS/Rw_jabatan::set_queue_arsip` 569, `SS/Rw_pelatihan::set_queue_arsip_sertifikasi` 406, `SS/Arsip::upload_siasn` | `siasn_upload_arsip` → `/upload-dok-rw` |

Aturan umum: penulisan lewat model beraudit (`updated_by`/`approved_by` = akun sistem integrasi, padanan
`ID_USR_DEVSIMPEG`); respons gagal BKN → `siasn_flag=2` + `siasn_error` (pesan teredaksi, BP-5); batch per NIP berlanjut
(offset tersimpan); data usang ditentukan dari `siasn_lu` (6 jam / 1 hari [K]). Yang **tidak** dibawa: generasi 1 backfill,
`Rw_tj` (hanya staging tanpa pembaca), data keluarga/CLTN/DP3/hukdis/masa kerja/pemberhentian/pindah instansi/PNS-unor/PWK
(backfill sekali jalan — hasilnya ikut impor cutover), `debug()`.
**AC-03.1** kegagalan SIASN dicatat dan tidak menggagalkan operasi utama; **AC-03.2** tarik idempoten (ulang = tanpa
duplikat); **AC-03.3** hapus fisik SKP tidak terjadi untuk NIP yang panggilannya gagal; **AC-03.4** token SSO dari
konfigurasi + cache, **tidak ada token literal**; **AC-03.5** riwayat yang diedit (`updated_at > siasn_lu`) terdorong ulang **hanya untuk tabel yang memakai `siasn_lu` di legacy** (jabatan); diklat, sertifikasi, kursus/seminar, dan SKP 2022 hanya terdorong saat belum pernah terkirim (`id_rw_siasn IS NULL`, `siasn_flag=0`), tanpa ALTER `siasn_lu` [K `SS/Rw_pelatihan`, `SI:4858`, `SI:7714`; keputusan Horii 02-11-2026, Q2 B0 Jalur B];
**AC-03.6** uji real (MTC-038) di MT-010 bila PN-2 tersedia.

**Aturan B6 (CR-100, diputus reviewer CR dan disetujui Horii 04-11-2026 saat koding/review MAKE-067; rincian di `Libraries/Siasn/README.md`).**
Prinsip: ikut legacy; deviasi hanya untuk bug legacy yang merusak data atau berisiko duplikat di BKN.

- **Q6-30 — pengaman aksi destruktif `siasn:tarik`** (perluasan F6-14). Lepas pasangan (golongan/pendidikan/PMK) dan hapus fisik SKP
  HANYA untuk NIP yang API-nya sukses dengan data sah. Tidak destruktif bila:
  - status ≠ sukses (HTTP/timeout/koneksi/token/`code≠1`/JSON tidak sah);
  - `code=1` dengan `data` kosong (INFO `data_kosong`);
  - ada baris yang ditolak validasi atau elemen bukan objek (INFO `ditolak_tanpa_destruktif`);
  - `data` bukan daftar (dianggap `respons` gagal).

  Command menolak berjalan bila driver ≠ `bkn`. Hapus fisik SKP hanya bila batch punya sisip/ubah (ikut legacy `Rw_skp:201`).
- **Q6-31 — perbaikan bug legacy `siasn:tarik`:**
  - PMK dipasangkan lewat `tgl_sk`; cocok ganda tidak dipasangkan/disisipkan (INFO `pairing_ganda`);
  - `siasn_lu` = waktu tarik (legacy mengisi string);
  - `masaKerjaTahun` dibaca (legacy salah ketik);
  - lepas pasangan PMK tidak bergantung sisip;
  - `Rw_ak:65` memakai baris AK yang benar + penjaga `isset`;
  - hapus AK tidak dibawa (legacy mengomentarinya);
  - referensi tak terpetakan → bawaan legacy + INFO;
  - `du_lu` = tanggal bisnis WIB, stempel `_lu` lain UTC;
  - daftar status disinkronkan setiap tarik;
  - flag macet dipulihkan (`du = 2` usang setelah 6 jam);
  - `GET_LOCK` per jenis;
  - `updated_by`/`approved_by` = akun sistem;
  - tanpa `audit_logs` per baris; ringkasan jumlah per batch di `integrasi_log`.
- **Q6-32 — anti-duplikat `siasn:dorong`:**
  - POST tidak di-retry (401 diulang sekali lewat token baru);
  - hasil ditulis per baris segera sesudah POST, dengan tulis ulang sekali;
  - bila tetap gagal sesudah POST sukses → baris TIDAK dipilih lagi (`hasil_tidak_tersimpan`, log critical, `refId` tabel:pk);
  - galat token / 401 kedua menghentikan batch tanpa menandai baris;
  - jabatan sukses tanpa id mempertahankan `id_rw_siasn` lama;
  - UPDATE bersyarat `updated_at` snapshot (edit selama batch tetap terdorong);
  - timeout/koneksi → flag 2 + INFO `status_tidak_diketahui` (operator memeriksa duplikat di BKN);
  - penanda proses jabatan flag 3 tanpa menggeser `updated_at`.
- **Q6-33 — bentuk POST SIASN** (ekstraksi bentuk `postApi_siasn`/`postApi_siasnArsip` `FH:2796-2881`, keputusan Horii 04-11-2026 opsi A, pola Q6-17; nilai tidak pernah dibaca):
  - body JSON;
  - header `Auth: bearer` + `Authorization: Bearer` + `Content-Type: application/json` + `accept: application/json`;
  - sukses = `success` truthy terbatas (`true`/`"true"`/`1`/`"1"`);
  - unggah: multipart `id_riwayat`, `id_ref_dokumen`, berkas di field `file` (PDF).

  Deviasi: v2 tidak mengikuti redirect (legacy `FOLLOWLOCATION`), 3xx = gagal.
- **Q6-34 — konfigurasi & data dorong:**
  - ID instansi/satker/unor bawaan dari `.env` (`integrations.bkn.siasnInstansiId`/`siasnSatkerId`/`siasnUnorBawaan`, kosong di repo; dorong jabatan gagal-cepat bila kosong);
  - diklat ber-`keterangan` NULL ikut didorong (bug semantik NULL legacy);
  - tanggal wajib kosong → tidak dikirim, flag 2 "Data tidak lengkap";
  - sukses tanpa id → flag 1 + INFO;
  - `siasn_error` disensor (tanpa NIP/payload, dipotong per byte);
  - " **Upload To SIASN**" + `updated_by` sistem (tidak ditambah berulang; `keterangan` dipotong per byte);
  - satker NULL ikut terbuang (ikut legacy);
  - `riwayat_skp.updated_at` diisi eksplisit.
- **Q6-35 — `siasn:unggah-arsip`:**
  - antre hanya PDF (`file_size` legacy KB vs v2 byte tidak dipakai); ukuran ≤ 1 MB dicek dari isi saat unggah, dengan `%PDF` di 1024 byte pertama;
  - `--limit` bawaan 1, `--langkah=semua|antre|unggah`;
  - kode 36 = "SK Jabatan JFT" (ikut legacy);
  - antre sertifikasi hanya PNS;
  - berkas dibaca lewat storage v2 (path legacy MAKE-069);
  - sesudah POST baris tidak kembali ke status 0; gagal tulis → status 3 + critical;
  - hanya `NotFoundException` = berkas hilang (status 2);
  - `ref-unor` tanpa hapus, pemetaan lokal tidak disentuh.

**AC-03.7** (CR-100) baris ditolak / data kosong / data bukan daftar / driver mock → tanpa hapus/lepas; **AC-03.8** POST
sukses yang gagal tercatat tidak di-POST ulang (dorong dan unggah arsip); **AC-03.9** galat token menghentikan batch tanpa
menandai baris; **AC-03.10** bentuk POST/multipart sesuai Q6-33 (test transport).

### 7.4 H-04 — FCM (Jalur B, MAKE-066) — perilaku ikut legacy (F6-11..F6-13)

- `FcmV1Adapter` implementasi `PushNotifGatewayInterface` (F6-11): `send`/`sendMulticast` (loop per token), `invalid_tokens`
  dari error `UNREGISTERED`/`INVALID_ARGUMENT`. Job `push-notif` yang ada (`Jobs/PushNotifLayananJob.php`, hapus token
  invalid :66-68) **dipakai apa adanya**.
- **Web/desktop** (F6-13): `POST api/v1/integrasi/fcm-token` (jwt) dipanggil SPA setiap halaman dimuat setelah login dan
  izin notifikasi diberikan [K `header.php:59`]; upsert `firebase_token` per `token_hash`, `platform`=1, `updated_at`
  diperbarui (perpanjang 1 minggu [K `L:4224-4289`]). `DELETE` saat logout.
- **Android** (F6-13): token `platform`=2 di `firebase_token`; pengiriman untuk SEMUA produsen legacy `andr_notif_queue`
  (cuti, support, LKH, Konket; CR-099 Q6-28) dengan teks & bentuk `data` ikut legacy; TB/IB/PMK hanya web; popup "Informasi"
  = Fase 7 (Q6-29). Penulis token Android = pertanyaan cutover (DEPLOY-001.1).
- **Push riwayat/layanan** (F6-13): pelanggan event `riwayatNotifikasi` (`RiwayatEngine.php:54-59`) dan notifikasi layanan →
  job `push-notif` ke penerima [K `FH:588-915`]: admin (`getTokenAdmin`), pegawai (`getTokenPeg`), atasan untuk LKH; judul,
  isi, ikon, tautan ikut legacy.
- **Topik presensi** (F6-12): `PresensiOnlineService` (masuk/pulang) dan pembuatan LKH mengantre job `fcm-topik`
  (`{nip, tambah[], lepas[]}`) **sesudah commit** [K `L:1834-1878`, `L:1976-2044`, `L_lkh.php:430-445`]; job memanggil
  `iid/v1:batchAdd|batchRemove` dengan token OAuth service account untuk semua token Android pegawai. Pengirim pesan topik
  di luar SIMPEG [K].
- FE: SDK Firebase web (versi tetap), `frontend/public/firebase-messaging-sw.js` (klik notifikasi membuka `data.link` [K
  `html/firebase-messaging-sw.js`]), permintaan izin notifikasi; konfigurasi web Firebase lewat env Vite (**bukan** disalin
  dari `header.php` legacy).
**AC-04.1** regresi C-06 (notifikasi cuti/TB/IB/PMK) hijau; **AC-04.2** token invalid dihapus, tidak di-retry selamanya;
**AC-04.3** push & topik lewat queue, bukan sinkron, dan kegagalannya tidak menggagalkan presensi; **AC-04.4** penerima push
riwayat sama dengan tabel penerima legacy (test per jenis riwayat); **AC-04.5** token web terduplikasi tidak membuat baris
baru.

### 7.5 H-05/H-06/H-07 — SIMAPI (Jalur A, MAKE-059/060)

- **H-05** `a_auth/token`: Basic → klien jenis 1/2 status 1 → token baru (F6-05) → `{"status":"01","msg":"…","data":{"token":…}}`
  (bentuk `data` ikut `A_auth.php:76-111`: **`data.token` saja**, tanpa `expired_at` — Q6-02, DBV-026 §4; koreksi CR-106).
- **H-06** 25 method `a_employee` + `change_history` — daftar method, sumber tabel, dan field per method **persis
  Lampiran A.1** (rujukan baris `AE`). Sumber v2: tabel snapshot `pegawai_*` dan `riwayat_*` status 1 [K]. Pemetaan nilai
  teks (jenis kelamin, status pernikahan, status "Aktif"/"Tidak Aktif", id 99/9999/98/1185 = "lain") [K]. NIP tidak ada →
  "NIP not valid" [K, termasuk status 10]. `foto` = URL berkas v2 (endpoint yang sudah ada).
- **change_history** (MAKE-060): penulis `simapi_ch` = pelanggan event sesudah commit untuk tabel yang sama dengan trigger
  legacy (`pegawai`, `pengguna`, `detail_anak`, 13 `riwayat_*` — Lampiran A.1); `field_value` hanya kolom yang dikirim legacy.
  Baris `pengguna` akun sistem integrasi (`sistem.integrasi`, tanpa NIP, [V2] DBV-027) **tidak** dicatat; perubahan data pegawai
  yang dilakukan akun sistem tetap dicatat (CR-093, keputusan Horii 03-11-2026 atas A0 v2 Jalur A).
- **H-07** `a_dm/*` [K `A_dm.php`] + `s_dm/*` (F6-10).
**AC-05.1** token kedaluwarsa → status 02 "Token is not valid" (MTC-038); **AC-06.1** test kontrak per method: nama field,
urutan, nilai teks sama dengan fixture yang diturunkan dari kode legacy; **AC-06.2** request tanpa token → 02;
**AC-06.3** `change_history` ack-on-read: panggilan kedua tidak mengulang baris; filter `simapi_auth_jd` berlaku;
**AC-07.1** `a_dm/jabatan` kini butuh token (F6-03).

### 7.6 H-08 — `services/s_stat` (Jalur A, MAKE-061)

Aturan [K] (Lampiran A.2) dihitung oleh kelas murni baru `Libraries/Integrasi/Stat/{RekomendasiKp,RekomendasiKgb,RekomendasiPensiun}`
(`now` diinjeksi). Perbedaan dengan kalkulasi v2 yang ada (`Kepegawaian/Kalkulasi/KenaikanPangkat.php`, `KenaikanGajiBerkala.php`,
`Pensiun.php`) **dicatat di laporan**, tidak disamakan diam-diam. **AC-08.1** unit test deteksi periode: KP bulan TMT ≤4 →
1 April, 5–10 → 1 Oktober, >10 → 1 April tahun depan; siklus `%4`; percepatan struktural; KGB tiga cabang (<24, 24–36,
>36 bulan); pensiun BUP `umur_pensiun` → 60 JPT → 58; **AC-08.2** keluaran kolom = legacy; **AC-08.3** tanpa auth → 401
(MTC-039); **AC-08.4** `rekomendasi_kp_uns` dengan `id_satker`/`id_unit` → `data: []` ikut legacy (Q6-37, CR-104); **AC-08.5** URL `arsip_*`/`file_*` mengarah ke `services/s_stat/berkas/…` di balik `klienLayanan:stat`; tanpa auth → 401; jenis/kolom di luar daftar putih, bukan milik NIP, status/tahun di luar keluaran s_stat, atau berkas belum ada di storage v2 → 404 seragam; path internal tidak pernah keluar (Q6-38, CR-105).

### 7.7 H-09 — pemetaan (Jalur A)

Tabel Lampiran A.4 dipindah ke `backend/docs/progress/06-Integrasi.md` + komentar penutup di QASMTASK-097. **AC-09.1**
setiap method `Local.php` punya padanan atau alasan "tidak di-port".

### 7.8 H-10 — e-Kinerja BKN (Jalur B, MAKE-064) — ikut legacy (F6-15)

[K `EB:9` `cron_periode_skp`, `EB:205` `cron_skp_periodik`; auth `FH:2882-2921`].
- `bkn:tarik-periode`: `GET /api_kinerja/referensi/periode` → **insert periode baru saja** ke `bkn_periode_ekinper` (id,
  nama, tahun, bulan, periode_awal/akhir, batas_pengisian, jenis_periode, status) [K].
- `bkn:tarik-skp-periodik [--tahun --bulan]`: pemilihan periode [K `EB:213-222`: tanggal > 19 → bulan berikutnya]; periode
  manual (`SKPManual*`) dilewati [K `EB:231-234`]; `GET /api_kinerja/laporan/penilaian/{tahun}/{periode_id}` (satu instansi
  sekaligus) → insert baris baru, update bila `hasil_akhir` berubah, kunci logis `id_pns`+`periode_id`+`skp_id` [K].
- Tombol admin `POST api/v1/integrasi/bkn/tarik` (antre job, bukan sinkron) — padanan pemicu URL cron legacy.
- Peringatan (F6-15): `integrasi:status` dan log menandai periode Tukin berjalan yang belum punya baris SKP periodik.
**AC-10.1** idempoten (ulang = tanpa duplikat, UNIQUE Q6-10); **AC-10.2** pegawai tanpa `id_pns_siasn` dicatat "tidak
terpasang"; **AC-10.3** test `PeriodeTukin`/potongan SKP Fase 5 tetap hijau (logika Tukin tidak diubah); **AC-10.4** uji
real di MT-010 bila PN-2 tersedia.

### 7.9 H-11 — status TB/CLTN harian (Jalur A, MAKE-062)

F6-20. Sumber [K]: TB → 2 saat setujui `L_tb.php:1170-1178`, → 1 saat hapus `:745-758`; CLTN → 3 `L_cuti.php:2421-2433`,
→ 1 `:1367-1377`. v2 memakai `StatusTugasBelajarInterface`/`BebasPresensiTb` untuk rentang dan `EfekTbTrait.php:14-22`
sebagai acuan efek. **AC-11.1** TB disetujui sebelum tanggal mulai → aktif tepat pada tanggal mulai; **AC-11.2** setelah
tanggal akhir → kembali 1; **AC-11.3** perpanjangan bersambung tidak menyebabkan status berkedip; **AC-11.4** status
selain 1/2/3 tidak disentuh; **AC-11.5** dijalankan dua kali = hasil sama; **AC-11.6** setiap perubahan teraudit.

**Aturan tambahan (CR-098, diputus reviewer CR dan disetujui Horii 03-11-2026 atas pertanyaan A2a Jalur A).** Fakta legacy:
- TB/CLTN hanya diubah saat persetujuan/hapus (event, tanpa cron);
- CLTN → 3 **hanya untuk status 1** (`L_cuti.php:2423-2433`, `:3032-3041`, `:3494-3502`);
- hapus mengembalikan → 1 hanya bila status masih kode jenisnya (`L_tb.php:745-758`, `L_cuti.php:1369-1377`);
- tidak ada validasi bentrok TB vs CLTN (persetujuan terakhir yang menang);
- tidak ada pemulihan sesudah tanggal akhir.

Aturan:
- **Q6-24 — bentrok TB dan CLTN aktif bersamaan.**
  - Status saat ini 2 atau 3 dan rentang jenis itu masih aktif → tidak diubah (tidak berkedip).
  - Selain itu pilih rentang dengan `tgl_mulai` paling akhir; seri → TB (2).
  - Jumlah bentrok dilaporkan sebagai INFO.
- **Q6-25 — status 4 (Ditangguhkan):** ikut legacy, hanya CLTN **status 1** yang menjadikan 3. Penangguhan legacy hanya untuk cuti tahunan (`approval_cuti.php:203`).
- **Q6-26 — pemulihan status usang (termasuk run pertama).**
  - Status 2/3 → 1 hanya bila dijelaskan riwayat yang sudah berakhir (TB status 1 untuk 2; CLTN status 1 untuk 3; `tgl_akhir` < tanggal proses) dan tidak ada rentang aktif.
  - Pegawai berstatus 2/3 **tanpa riwayat pendukung** (isian manual/impor) tidak disentuh; dilaporkan sebagai INFO jumlah untuk Biro SDM.
  - Runbook: run pertama wajib `--dry-run`, jumlahnya direview, baru run nyata.
- **Q6-27 — `--tanggal`:** tanggal masa depan ditolak (`EXIT_USER_INPUT`); tanggal lampau hanya bersama `--dry-run`. Run nyata selalu hari ini (WIB); job stateless, jadi run yang terlewat cukup dijalankan ulang hari ini.

**AC-11.7** bentrok TB+CLTN mengikuti Q6-24 (kedua cabang diuji); **AC-11.8** CLTN status 4 tidak mengubah status;
**AC-11.9** 2/3 tanpa riwayat pendukung tidak disentuh dan terhitung INFO; **AC-11.10** `--tanggal` masa depan ditolak,
`--tanggal` lampau tanpa `--dry-run` ditolak.

### 7.10 H-12 — Auto-approval LKH (Jalur A, MAKE-068) — ikut legacy (F6-25)

[K `C:18` `queue_aa_lkh`, `C:87` `process_queue_aa_lkh`, jadwal `C:14-17`]. `php spark lkh:auto-approval [--bulan=] [--dry-run]`:
LKH bulan lalu berstatus **0** yang belum disetujui atasan → `status=1`, `auto_approval=1`, semua lewat model beraudit; catatan
= kolom `riwayat_lckh.auto_approval` + jejak audit (padanan `aa_lkh`, **tanpa tabel baru**). **PDF tidak dibangkitkan/disimpan oleh
job** — PDF LKH v2 dibuat saat diminta (MAKE-013) (CR-093, keputusan Horii 03-11-2026 atas A0 v2 Jalur A; deviasi [V2] dari legacy `file_lckh`). Antrean legacy (`queue_aa_lkh`, batch 5 per 2 detik) diganti
pemrosesan batch dalam command yang idempoten (F6-02). Status 3 (revisi) **tidak** diproses [K efektif `C:147`]. **AC-12.1**
hanya LKH bulan lalu status 0; **AC-12.2** ulang = tanpa efek ganda; **AC-12.3** job tidak membangkitkan/menyimpan PDF; PDF LKH auto-approval tersedia lewat endpoint PDF yang ada (CR-093, keputusan Horii 03-11-2026 atas A0 v2 Jalur A).
Bila modul LKH belum ada di v2 (M0 A) → eskalasi, H-12 ditunda.


---

## 8. Aturan lintas modul

- Tukin (Fase 5) **membaca** `bkn_periode_ekinper` & `riwayat_skp_periodik`; H-10 hanya mengisi. Perubahan rumus = di luar
  Fase 6.
- Notifikasi Fase 4 (`NotifikasiService`, `inbox`) tidak diubah; H-04 hanya mengganti driver push + menambah pelanggan event.
- Riwayat Modul B: H-03 menulis lewat model beraudit/engine riwayat yang ada (bukan query mentah), `updated_by` = akun
  sistem integrasi (dibuat di DBV-027 sebagai data seed atau konfigurasi — [I], diputus DBV).
- `pegawai.id_jenis_status` hanya diubah H-11 dan alur persetujuan TB/CLTN yang sudah ada.

## 9. Ketergantungan

| Dari | Ke | Catatan |
|---|---|---|
| UP-1 sign-off | Semua koding Fase 6 | GATE-F6 |
| DBV-026 | MAKE-058 → MAKE-059/060/061 | A |
| DBV-027 | MAKE-063 → MAKE-064/065/066/067 | B |
| MAKE-058 (filter) | `services/s_esign` di MAKE-065 | Titik temu A→B |
| PN-1..PN-4 | Uji real & go-live (bukan koding) | Koding memakai FakeTransport |
| Fase 7 F-08 | Lonceng/inbox | Di luar Fase 6 |
| Cutover | Nginx `/simapi/`, `/services/`; klien re-handshake; crontab v2 aktif, crontab legacy mati | `README-deploy.md` |

## 10. Urutan kerja (ringkas; rincian di `6_1.md` / `6_2.md`)

| M | Qoder B (`6_2.md`) | Qoder A (`6_1.md`) |
|---|---|---|
| M0 | Laporan pemahaman ⛔ reviewer | Laporan pemahaman ⛔ reviewer |
| M1 | DBV-026 skema klien & SIMAPI (PR [DBV]) | DBV-027 skema integrasi keluar (PR [DBV]) |
| M2 | MAKE-062 H-11 status harian + ADR-037, lalu MAKE-068 H-12 auto-approval LKH (tanpa skema baru — boleh paralel dengan review DBV; CR-093) | MAKE-063 klien HTTP + log + token BKN |
| M3 | MAKE-058 auth antar-sistem + ADR-036 | MAKE-064 H-10 e-Kinerja BKN |
| M4 | MAKE-059 SIMAPI H-05/H-06/H-07 + `s_dm` | MAKE-065 H-02 BSrE + `s_esign` |
| M5 | MAKE-060 change_history | MAKE-066 H-04 FCM (BE + FE) |
| M6 | MAKE-061 H-08 `s_stat` | MAKE-067 H-03 SIASN |
| M7 | Penutup A: H-09 pemetaan, runbook cron & Nginx, progres | Penutup B: progres, `integrasi:uji-koneksi` |
| M8 | Reviewer: QA Lapis 1 Fase 6 (mock), laporan akhir → ⛔ sign-off Horii. Uji real → MT-010/DEPLOY-001.1 (sebelum deploy) | |

Setiap M: branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor → ⛔ merge oleh reviewer CR
(izin Horii per paket).

## 11. Definition of Done & exit criteria

**Fase 6 DONE** bila: H-01..H-12 DONE (AC terpenuhi, di `main`); semua integrasi lolos test dengan mock/FakeTransport;
regresi C-05/C-06 + Tukin Fase 5 hijau; QA Lapis 1 Fase 6 lolos; runbook cron + Nginx + daftar re-handshake klien tercatat;
laporan akhir `docs/fase6/Laporan_Akhir_Fase6.md`; sign-off Horii. Trello DEV-007 + QASMTASK-089..097 + QASMTASK-120..122 (H-10/H-11/H-12)
disinkron.

**Uji endpoint real** (exit criteria `06-Integrasi.md`: minimal e-sign cuti di jaringan internal; ditambah SIASN/e-Kinerja dan
FCM) **tidak menahan sign-off Fase 6** (F6-23, Q6-13 = A) tetapi **wajib lolos sebelum deploy**: dilacak MT-010 (Manual
Testing) dan DEPLOY-001.1 (Deployment).

Format laporan milestone (blok kode): Status / Branch-SHA-CI / Berkas / Test (jumlah) / Berkas bersama / Deviasi / Blocker.

## 12. Register keputusan (v1.0 — dijawab Horii 01-11-2026)

| ID | Pertanyaan | Keputusan Horii | Dicatat di |
|---|---|---|---|
| Q6-01 | Semua endpoint antar-sistem wajib auth (termasuk yang publik di legacy)? | **A — Ya**, klien diberi kredensial baru saat re-handshake | F6-03 |
| Q6-02 | Kontrak SIMAPI 1:1 legacy atau versi baru? | **A — 1:1 legacy** | F6-04 |
| Q6-03 | `ambil_seluruh_pengguna` (hash password E-Talenta) | **A — tidak di-port**; dicatat di Trello Catatan Teknis | F6-07 |
| Q6-04 | Aturan rekomendasi KP/KGB/pensiun | **A — ikut legacy persis** | F6-09 |
| Q6-05 | `services/s_esign` untuk MuDig | **A — dilayani, berkas diunggah** | F6-16 |
| Q6-06 | Siapa mengetik passphrase e-sign real | **A — penanda tangan (PyB) sendiri** (= alur legacy) | F6-18 |
| Q6-07 | Lingkup SIASN | **Ikut legacy penuh** | F6-14 |
| Q6-08 | Token SSO BKN produksi & pemilik akun | **Dicatat sebagai pertanyaan** di Trello Catatan Teknis (Menunggu Jawaban) | F6-26, PN-2 |
| Q6-09 | e-Kinerja BKN sebagai H-10 Fase 6 | **A — Ya**, default potongan 32% ikut legacy + peringatan | F6-15 |
| Q6-10 | UNIQUE `riwayat_skp_periodik` setelah pra-cek duplikat | Default diterima | F6-15, DBV-027 |
| Q6-11 | Notifikasi presensi | **Ikut legacy** (keanggotaan topik; pengirim pesan di luar SIMPEG) | F6-12 |
| Q6-12 | Kanal push / app Android | **Ikut legacy** (web + Android) | F6-13 |
| Q6-13 | Akses integrasi belum ada saat koding selesai | **A — sign-off dengan mock**; uji real sebelum deploy, kartu baru di Manual Testing (MT-010) & Deployment (DEPLOY-001.1) | F6-23 |
| Q6-14 | `Ekinerja.php` (API masuk) | Default diterima — tidak di-port kecuali PIC menyatakan masih dipakai (PN-4) | F6-21 |
| Q6-15 | Auto-approval LKH tanggal 5 | **Ikut legacy** | F6-25, H-12 |
| Q6-16 | Retensi `integrasi_log` 180 hari | Default diterima | F6-24 |
| Q6-17 | **(02-11-2026, B2 Jalur B)** Sumber bentuk auth BKN untuk `TokenBkn` — helper token legacy `FH:2513-2921` ada di daftar terlarang dibuka (berisi kredensial literal). Pilihan: **A** — reviewer CR mengekstrak **bentuk saja** lewat skrip redaksi (literal disamarkan kecuali nama field/header/path; keluaran ditolak otomatis bila memuat host/IP/string mirip token), hasil diteruskan ke pelaksana; **B** — tunggu dokumentasi resmi API BKN saat akses PN-2 tersedia (aman tetapi menahan TokenBkn/B2, B3 tarik, B6) | **A** (keputusan Horii 02-11-2026; ekstraksi dijalankan 02-11, nilai tidak pernah dibaca/ditampilkan). Hasil & desain di §7.0.2 "Bentuk auth BKN" (CR-092) | §7.0.2, F6-26 |
| Q6-18 | **(03-11-2026, B4a Jalur B)** TLS ke gateway BSrE — legacy mematikan verifikasi peer & host (`bsre_esign.php:92-93`). Pilihan:<br>**A** — wajib TLS ketat seperti BKN (+ `esign.caBundle` untuk CA internal);<br>**B** — ikut legacy, `esign.tlsVerify=false` boleh khusus e-sign di `.env` server | **B — ikut legacy** (keputusan Horii 03-11-2026).<br>Bawaan tetap `true`; peringatan log + `integrasi:status`; upgrade ke A lewat `esign.caBundle`/`tlsVerify` tanpa ubah kode.<br>Gap: MITM (client secret, NIK, passphrase, PDF), PDF palsu, audit. Trello Catatan Teknis "Keputusan TLS BSrE" (CR-097) | §7.2, BP-3, B-10 |
| Q6-19 | **(03-11-2026, B4a)** Alur persetujuan + tanda tangan PyB. Pilihan:<br>**A** — satu langkah seperti legacy (sign gagal → persetujuan tidak disimpan);<br>**B** — dua langkah di halaman yang sama | **B** (keputusan Horii 03-11-2026).<br>E-sign hanya oleh PyB pemilik tugas (kecuali persetujuan "atas nama", Q6-40); sign gagal → persetujuan tetap, surat tanpa TTE, bisa diulang. Deviasi dari legacy dicatat; regresi C-04/C-05 (CR-097) | §7.2, F6-18 |
| Q6-20 | **(03-11-2026, B4a)** Pembatas percobaan passphrase (legacy tidak punya) | **Ya [V2]** — 5 percobaan / 15 menit per PyB per cuti, pola LockoutService (CR-097) | §7.2, AC-02.6 |
| Q6-21 | **(03-11-2026, B4a)** Passphrase saat PyB menolak — tampilan legacy hanya meminta untuk Setujui/Penangguhan, server legacy mewajibkan untuk semua status (PyB bersertifikat tidak bisa menolak). Pilihan:<br>**P-1** ikut tampilan;<br>**P-2** ikut server;<br>**P-3** ikut keduanya | **P-1** — passphrase hanya untuk Setujui (1)/Penangguhan (4); Tolak tanpa passphrase. Memperbaiki bug legacy (CR-097) | §7.2, AC-02.6 |
| Q6-22 | **(03-11-2026, B4a)** Isi QR gambar TTE. Pilihan:<br>**Q-1** ikut legacy (URL langsung ke PDF bertanda tangan, tanpa login);<br>**Q-2** halaman verifikasi publik data minimal;<br>**Q-3** URL ber-login;<br>**Q-4** tanpa QR;<br>**Q-5** QR ke validator resmi | **Q-1 — ikut legacy** (keputusan Horii 03-11-2026).<br>v2: route publik khusus PDF surat TTE, token acak (bukan path ber-NIP), throttle, 404 seragam, `no-store`/`noindex`, token nonaktif saat dicabut.<br>Gap diterima: surat utuh (data pribadi) bisa diunduh pemegang QR/URL, URL bocor = akses sampai dicabut, QR bukan bukti keaslian. Trello Catatan Teknis "Keputusan e-sign cuti F6-18" (CR-097) | §7.2, AC-02.7, DBV-030 |
| Q6-23 | **(03-11-2026, B4a)** QR kaki surat cuti v2 (teks `SIMPEG/CUTI/{id}/{nip}`, memuat NIP) | **Q-2 [V2]** — URL halaman verifikasi publik pola slip D-14 (ADR-032): data minimal tanpa NIP/PDF; token sama dengan Q6-22 (CR-097) | §7.2, AC-02.8, DBV-030 |
| Q6-24 | **(03-11-2026, A2a Jalur A)** Bentrok TB dan CLTN aktif bersamaan (legacy: persetujuan terakhir menang, tanpa validasi) | Status 2/3 yang rentangnya masih aktif dipertahankan; selain itu `tgl_mulai` terbaru, seri → TB; jumlah bentrok = INFO (CR-098) | §7.9, AC-11.7 |
| Q6-25 | **(03-11-2026, A2a)** CLTN status 4 (Ditangguhkan) — F6-20 v1.0 menulis "status 1/4" | **Ikut legacy: hanya status 1** → 3 (`L_cuti:2423`, `:3032`, `:3494`); penangguhan legacy hanya cuti tahunan (CR-098) | F6-20, §7.9, AC-11.8 |
| Q6-26 | **(03-11-2026, A2a)** Pemulihan status 2/3 usang pada run pertama | → 1 hanya bila dijelaskan riwayat yang sudah berakhir; tanpa riwayat pendukung → tidak disentuh + INFO; run pertama wajib `--dry-run` (CR-098) | F6-20, §7.9, AC-11.9 |
| Q6-27 | **(03-11-2026, A2a)** `--tanggal` masa depan / lampau | Masa depan ditolak; lampau hanya bersama `--dry-run`; run nyata = hari ini WIB (CR-098) | §7.9, AC-11.10 |
| Q6-28 | **(04-11-2026, B5 Jalur B)** Cakupan kanal Android — F6-13 v1.0 hanya menyebut cuti/support, padahal legacy `andr_notif_queue` juga diisi LKH (`L_lkh.php:401/637/1138`) dan Konket (`L_konket.php:1972`) | **Ikut legacy penuh**: cuti, support, LKH, Konket ke Android dengan teks & bentuk `data` legacy; TB/IB/PMK hanya web (legacy tanpa push Android). Ditemukan review kode MAKE-066 (CR-099) | F6-13, §7.4 |
| Q6-29 | **(04-11-2026, B5)** Popup "Informasi" `Cron.php:953` (`queue_popup_notif`) ke Android | Milik Popup Fase 7 (F-04): B5 hanya menyediakan titik sambung `AntrePushPesan`, tidak mengirim (CR-099) | F6-13, Fase 7 F-04 |
| Q6-30 | **(04-11-2026, B6 Jalur B)** Cakupan pengaman aksi destruktif `siasn:tarik` | Hanya NIP sukses dengan data sah; data kosong/baris ditolak/data bukan daftar/driver mock → tanpa hapus/lepas; hapus SKP hanya bila batch ada sisip/ubah (CR-100) | §7.3, AC-03.7 |
| Q6-31 | **(04-11-2026, B6)** Bug legacy tarik (PMK pairing mati, `siasn_lu` string, salah ketik masa kerja, `Rw_ak:65`, dll.) | Diperbaiki; rincian §7.3 (CR-100) | §7.3 |
| Q6-32 | **(04-11-2026, B6)** Risiko duplikat di BKN saat dorong | POST tanpa retry, tulis per baris + tulis ulang sekali, hasil tidak tersimpan tidak dipilih lagi, token galat hentikan batch, id lama dipertahankan, INFO `status_tidak_diketahui` (CR-100) | §7.3, AC-03.8, AC-03.9 |
| Q6-33 | **(04-11-2026, B6)** Bentuk POST SIASN (helper `FH:2796-2881` terlarang). Pilihan:<br>**A** — ekstraksi bentuk (pola Q6-17);<br>**B** — asumsi + konfirmasi uji real;<br>**C** — tunggu dokumen BKN | **A** (keputusan Horii 04-11-2026). JSON + dua header token + `accept`, sukses truthy terbatas, multipart field `file`; v2 tanpa redirect (CR-100) | §7.3, AC-03.10 |
| Q6-34 | **(04-11-2026, B6)** Konfigurasi instansi & data dorong | `.env` untuk ID instansi/satker/unor; perbaikan NULL/tanggal kosong; ikut legacy selebihnya (CR-100) | §7.3 |
| Q6-35 | **(04-11-2026, B6)** Unggah arsip SIASN | Antre PDF, ukuran dari isi, tanpa unggah ulang sesudah POST, ref-unor tanpa hapus (CR-100) | §7.3 |
| Q6-36 | **(04-11-2026, A4 Jalur A)** Bug legacy B-9 SIMAPI `a_employee/detil`: `unit_es_3`/`unit_es_4` diturunkan dari nama atasan `atasan_es_3/_4(_koord)` yang tidak ikut di-SELECT (`AE:278` hanya `id_atasan_es_*`), sehingga Es IV → `unit_es_3` = "" dan pelaksana/JF → keduanya "" (Es III benar). Pilihan:<br>**A** — ikut legacy apa adanya;<br>**B** — perbaiki [V2] + beri tahu klien | **A** (keputusan Horii 04-11-2026). Kontrak klien eksternal tetap: nilai "" dipertahankan dan dikunci test kontrak A4 (MAKE-059), dicatat sebagai **bug legacy yang sengaja tidak diubah**. **Gap:** data unit atasan di SIMAPI tidak lengkap untuk Es IV & non-struktural. **Opsi upgrade v2 (belum dipakai):** ikut SELECT nama `atasan_es_3/_4` (versi `_koord` didahulukan) → `fixUnitName` → `unit_es_3` terisi untuk Es IV & pelaksana/JF, `unit_es_4` untuk pelaksana/JF; nama field & urutan tetap, hanya isi "" yang terisi; bila kelak dipakai wajib pemberitahuan ke klien SIMAPI + daftar re-handshake (PN-4) | F6-04, Lampiran B-9 |
| Q6-37 | **(05-11-2026, A6 Jalur A)** Filter `id_satker`/`id_unit` pada `services/s_stat/rekomendasi_kp_uns` (endpoint MuDig, `S_stat:433`). Fakta legacy: filter dibaca dari query `$_GET` (:456-457), bukan dari identitas pemanggil; auth dikomentari (:446-447). Tanpa filter → `get_all_kp('2')` format kolom MuDig (`LE:2416-2418`, :2714). Dengan filter → `get_kp_satker`/`get_kp_unit` (`LE:2056`, `LE:2236`) yang mengembalikan format **SIMPEG** tanpa `tmt_kp_new`, sehingga cek `:485` melewati semua baris → respons `{"status":1,"msg":"","data":[]}` **selalu kosong**. Varian satker/unit `rekomendasi_pensiun`/`rekomendasi_kgb` hanya kode dikomentari (:52-54, :68-70; tidak di-port). Pilihan:<br>**A** — ikut legacy persis;<br>**B** — filter diabaikan (selalu semua pegawai);<br>**C** — filter diperbaiki [V2] | **A** (keputusan Horii 05-11-2026). Route & keluaran ikut legacy: tanpa filter = semua pegawai format MuDig; `id_satker` atau `id_unit` tidak kosong → `data: []` dengan `status` 1 & `msg` "" (bug legacy direplikasi, dikunci test kontrak MAKE-061, komentar `[K] bug legacy Q6-37`). Auth klien layanan tetap wajib (F6-03; legacy publik). Opsi B **ditolak**: permintaan satu satker akan menerima seluruh pegawai beserta URL arsip (lebih banyak data dari yang diminta). **Gap:** MuDig tidak bisa menarik rekomendasi KP per satker/unit (perilaku legacy sejak filter ditambahkan). **Opsi upgrade v2 C (belum dipakai):** ambil daftar format MuDig `get_all_kp('2')` lalu saring kolom `id_satker`/`id_unit` (keduanya ada di format MuDig) → subset benar dengan kolom & urutan sama; bila kelak dipakai: isi respons berfilter berubah dari kosong menjadi subset, wajib pemberitahuan ke PIC MuDig (PN-4) + daftar re-handshake, test kontrak diganti, putusan baru Horii | F6-09, §7.6, Lampiran A.2 |
| Q6-38 | **(05-11-2026, A6 Jalur A)** URL arsip `services/s_stat` untuk klien layanan (MuDig). Legacy mengeluarkan kolom `arsip_*` (`arsip_cpns`, `arsip_pns`, `arsip_last_kp`, `arsip_skp` per tahun, `arsip_last_jab`, `arsip_last_pak`, `arsip_last_kgb`) dan `file_*` (`list_tb/ib/karpeg/kariskarsu`, `r.*`) sebagai URL berkas **terbuka tanpa login** hasil `pathToSrc()` (`FH:314`). Di v2 lampiran hanya dapat diunduh lewat endpoint pengguna `pegawai/{nip}/lampiran/{id}/unduh` (JWT), sedangkan klien layanan masuk dengan Basic auth klien (`klienLayanan:stat`, MAKE-058) sehingga tidak bisa memakainya; berkas kolom (`file_pak`, `file_*`) belum punya endpoint unduh untuk klien. Pilihan:<br>**A** — endpoint unduh khusus klien layanan;<br>**B** — URL endpoint pengguna (MuDig tidak bisa unduh, hanya penanda ada/tidak);<br>**C** — URL dikosongkan | **A** (keputusan Horii 05-11-2026). Endpoint `GET services/s_stat/berkas/{jenis}/{nip}/{id}` untuk lampiran riwayat (`jenis` kp/skp/jabatan/kgb = kode v2 11/32/9/10, `id` = `id_attachment`; `skp` hanya `riwayat_skp` status 1 tahun Y-1/Y-2 yang dikeluarkan s_stat) dan `GET services/s_stat/berkas/{jenis}/{nip}/{id}/{kolom}` untuk berkas kolom (`jenis` pak/tb/ib/karpeg/kariskarsu, `id` = PK baris, `kolom` daftar putih per jenis sesuai migration v2; tb/ib status 0/1/3, karpeg/kariskarsu 0/1), di balik `klienLayanan:stat` (401 pola F6-03). Berkas wajib milik NIP di path dan berasal dari baris yang dapat muncul di keluaran s_stat; selain itu 404 seragam (tidak membedakan "tidak ada" vs "bukan milik"); tanpa pembatasan per populasi rekomendasi. Dibaca lewat storage v2 (pola `KolomBerkas`/PMK/`file_lckh`), path tidak pernah keluar, path impor legacy yang belum ada di storage → 404 dan kolom di respons s_stat kosong. Respons stream, mime dari isi, nama `s_stat_{jenis}_{id}.{ext}`, `attachment`, `nosniff`, `no-store`; URL dibangun satu titik (`PenyajiStat::urlBerkas()`) absolut dari baseURL; log jumlah/kode tanpa NIP; throttle klien F6-03 berlaku. Opsi B/C tidak dipilih: fungsi unduh legacy hilang. **Gap:** (1) legacy publik tanpa auth, v2 MuDig WAJIB Basic auth saat unduh dan bentuk URL berubah → masuk daftar re-handshake klien (PN-4) + runbook; (2) berkas yang belum dimigrasikan ke storage v2 tidak dapat diunduh; (3) klien hak `stat` dapat mengunduh dokumen kepegawaian pegawai yang muncul di keluaran (setara legacy, kini terautentikasi) → hak `stat` hanya untuk MuDig. **Opsi lanjutan (belum dipakai):** URL bertanda tangan berumur pendek bila MuDig tidak bisa mengirim header auth saat mengunduh — butuh putusan baru Horii. Catatan Trello `sqfe8bKT` | F6-09, §7.6, AC-08.5, F6-03 |
| Q6-39 | **(05-11-2026, A4 Jalur A)** URL `foto` di keluaran SIMAPI `a_employee` untuk klien SIMAPI (Pusdatin/Simanta/E-Talenta). Legacy memberi URL berkas foto **terbuka tanpa login** (`pathToSrc()`); v2 hanya punya endpoint pengguna `api/v1/pegawai/{nip}/foto` (JWT) yang tidak dapat dibuka klien bertoken SIMAPI. Pilihan:<br>**A** — endpoint foto khusus klien SIMAPI;<br>**B** — URL endpoint pengguna (klien tidak bisa membuka);<br>**C** — kosongkan | **A** (keputusan Horii 05-11-2026, pola sama Q6-38). `GET simapi/v1/a_employee/foto/{nip}` di balik `simapiToken`; tanpa/salah token → amplop SIMAPI `"02"` "Token is not valid" `data:[]` HTTP 200. Sumber foto sama dengan endpoint pengguna (storage v2, path tidak pernah keluar); NIP tidak dikenal, tanpa foto, atau berkas belum di storage → 404 seragam; stream mime dari isi, `nosniff`, `no-store`, `inline` nama generik; log kode hasil tanpa NIP; URL dibangun satu titik (`PenyajiPegawai::urlFoto()`), kosong bila foto tidak ada. **Gap:** klien wajib mengirim token saat mengambil foto dan bentuk URL berubah → daftar re-handshake (PN-4); foto yang belum dimigrasikan tidak tersedia. Opsi B/C tidak dipilih (fungsi foto legacy hilang) | F6-04, §7.5, Q6-38 |
| Q6-40 | **(05-11-2026, ISSUE-055)** Cuti yang disetujui admin **"atas nama" PyB** tidak bisa ber-TTE: sejak F6-18/Q6-19 TTE hanya oleh penanda tangan pemilik tugas, sehingga surat terbit tanpa TTE dan tidak ada jalur menandatanganinya kemudian. Legacy: siapa pun yang memproses persetujuan (termasuk admin) boleh mencentang `allow_esign` dan mengetik passphrase (`L_cuti.php:3668-3673`, `Cuti.php:796`); bila passphrase tidak diketahui, surat terbit tanpa TTE (`allow_esign = 2`). Pilihan:<br>**a** — PyB menandatangani belakangan dari daftar "Surat cuti menunggu TTE" (menu ⋮ "Tanda tangani", pemilik tugas saja, Q6-20 berlaku);<br>**b** — notifikasi ke PyB bahwa ada surat menunggu TTE;<br>**c** — biarkan tanpa TTE, dicatat sebagai keterbatasan;<br>**d** — ikut legacy: admin yang menyetujui atas nama boleh mengetik passphrase PyB | **d** (keputusan Horii 05-11-2026), dikerjakan **di Fase 6** (Handling Case, sesudah B4b). Bentuk: pada persetujuan "atas nama" PyB oleh admin yang memang berhak menyetujui atas nama, dialog persetujuan menampilkan pilihan TTE + kolom passphrase seperti alur PyB; TTE tetap memakai identitas/sertifikat **PyB** (NIK PyB), bukan admin. Pengaman wajib: passphrase tidak pernah disimpan/di-log (`#[\SensitiveParameter]`, redaksi); pembatas percobaan Q6-20 berlaku (per PyB per cuti, termasuk percobaan oleh admin); audit mencatat pelaku = admin dan "atas nama" PyB; `esign_hist` mencatat penanda tangan PyB. Tanpa passphrase → surat terbit tanpa TTE seperti legacy. Q6-19 dua langkah tetap berlaku (sign gagal → persetujuan tetap, bisa diulang). **Rekomendasi reviewer yang tidak dipilih: a + b** (PyB menerima notifikasi lalu menandatangani sendiri; F6-18 utuh, passphrase tidak pernah diketahui admin). Dicatat sebagai opsi upgrade v2. **Risiko diterima:** passphrase sertifikat PyB diketahui admin (sama dengan legacy), TTE dibuat bukan oleh pemilik sertifikat secara langsung; mitigasi lewat audit pelaku + Q6-20. Trello ISSUE-055 `wX6Jctay` | F6-18, Q6-19, Q6-20, §7.2 |

Prinsip umum Horii 01-11-2026: **"intinya ikut legacy"** — berlaku juga untuk hal yang tidak tercantum di atas. Bila coder
menemukan perilaku legacy yang belum diputus, default = ikut legacy [K]; deviasi hanya untuk alasan teknis wajib/keamanan
dasar dan **wajib** dicatat di laporan milestone + `backend/docs/progress/06-Integrasi.md`.

---

## Lampiran A — Kesesuaian legacy → v2

### A.1 SIMAPI (`controllers/simapi/v1/`)

| Legacy | Perilaku [K] | v2 |
|---|---|---|
| `A_auth::token` `A_auth.php:31-111` | Basic → `simapi_auth` (password **plaintext**), token 30 char `mt_rand`, 1 jam, satu baris per username (token sama dikembalikan) | H-05, F6-05 (hash, CSPRNG, token baru per handshake) |
| Validasi token `AE:30-47` | `Authorization` di-`explode(" ")[1]`, `status=1 AND expired_at>NOW()`; cek IP dikomentari | `simapiToken` + allowlist opsional |
| `type` 1/2 `AE:48-50` | `change_history` & `A_dm` (kecuali `jabatan`) hanya type 2; selain itu `status "01"` data kosong | [K] |
| `A_employee` method | `change_history`(20), `data_umum`(122), `detil`(222), `alamat_rumah`(367), `alamat_kantor`(451), `keluarga`(541), `riwayat_keluarga`(627), `pendidikan_terakhir`(721), `riwayat_pendidikan`(804), `pelatihan_struktural_terakhir`(891), `riwayat_pelatihan`(979), `skp_terakhir`(1070), `riwayat_skp`(1151), `riwayat_kursus_seminar`(1235), `riwayat_organisasi`(1314), `pengangkatan_cpns`(1390), `pengangkatan_pns`(1475), `kp_terakhir`(1560), `riwayat_kp`(1645), `jabatan_terakhir`(1734), `riwayat_jabatan`(1821), `kgb_terakhir`(1914), `riwayat_kgb`(1987), `tj_terakhir`(2064), `riwayat_tj`(2141), `riwayat_hukdis`(2223) | H-06; field per method diturunkan dari baris tersebut (fixture kontrak di M4 A). Tidak ada `hukdis_terakhir` [K] |
| `simapi_ch` | Diisi **trigger DB** AFTER INS/UPD/DEL pada `pegawai`, `pengguna`, `detail_anak`, `riwayat_` alamat, diklat, hukdis, keluarga, kgb, kp, mutasi_jabatan, organisasi, pendidikan, seminar, skp, tanda_jasa; format pipe | F6-06 (event aplikasi) |
| `simapi_update`/`_hist` | Ditulis trigger, **tanpa pembaca** | Tidak dibuat |
| `A_dm` | `group_jabatan`(20), `sub_group_jabatan`(77), `jabatan`(158, **tanpa auth**), `struktur_jabatan`(231) | H-07 |
| `A_sinkron` | `ambil_seluruh_pengguna`(9): username + **hash password** + email UserLevel 2; `ambil_riwayat_mutasi`(59): seluruh `riwayat_mutasi_jabatan` tanpa filter | F6-07 |

### A.2 `services/` (`controllers/services/`)

| Legacy | Perilaku [K] | v2 |
|---|---|---|
| `S_stat` auth | `sal_user` plaintext hanya untuk `rekomendasi_kp`(21); lainnya **publik** | F6-03 |
| `rekomendasi_kp`(21), `rekomendasi_kp_uns`(433) | `get_all_kp` `LE:2416-2720`: PNS aktif; reguler `(tahun ini − tahun TMT) % 4 == 0` (jenis_kp 2 → −1 tahun, :2561-2567); plafon per pendidikan :2446-2594; periode bulan TMT ≤4 → 1 Apr, 5–10 → 1 Okt, >10 → 1 Apr tahun depan (:2599-2608); percepatan struktural (E.IV<III/c, E.III<IV/a, E.II/E.I<IV/c; TMT struktural paling awal +1 tahun, :2614-2658). Filter tahun berjalan; arsip di-fetch HTTP | H-08 [K], arsip dicek lokal [V2] |
| `rekomendasi_pensiun`(737) | `LE:1324-1376` + `FH:2400-2418`: PNS aktif; BUP = `jabatan.umur_pensiun` > 0, selain itu 60 (grup 1 sub 1/2, JPT) atau 58; TMT = lahir + BUP + 1 bulan tanggal 01 | H-08 [K] |
| `rekomendasi_kgb`(936) | `LE:4095-4220`: PNS + PPPK aktif; last = maks(`pegawai_kgb.tmtsk`, `pegawai_cpns.tmtsk`) (PPPK: `pmj.tmtsk`); >36 bln → last+4 th lalu +2 sampai ≥ hari ini; 24–36 → hari ini; <24 → last+2 th; tahun ini & depan; urut kolom tak ada (:1007) | H-08 [K], urutan diperbaiki [V2] |
| `list_tb`(1268), `list_ib`(1310), `list_karpeg`(1352), `list_kariskarsu`(1398) | Publik, status 0/1/3 (TB/IB), 0/1 (karpeg) | H-08 + auth |
| `S_dm` | `list_unit`(21)/`list_satker`(54) `sal_user`; `p_*` publik; `p_web_config`(266) dump seluruh `web_config` (bug key) | F6-10 |
| `S_esign` | Basic `MUDIG_*`; `check_user` GET `?nik`; `sign_pdf` POST path berkas server; `esign_hist` opsional | F6-16 |

### A.3 `Cron.php` (pemicu HTTP, basic `CRON_*` atau tanpa proteksi)

| Legacy | v2 |
|---|---|
| `queue_aa_lkh` C:18, `process_queue_aa_lkh` C:87 (auto-approval LKH tgl 5) | H-12 (F6-25) |
| `email_ultah_*` C:444/592 (exit), `email_ultah_send_20220206` C:730 (pengirim `email_pool`) | Tidak / sudah queue email Fase 1 |
| `queue_popup_notif` C:886 (exit) | Tidak |
| `process_queue_andr_notif` C:987, `process_queue_andr_topic` C:1071 (tanpa auth), `process_queue_fcm_desktop` C:1138 | H-04 (`queue:work push`, F6-11/12) |
| `sapk_*` C:1223-2240 | Tidak (F6-21) |
| `calculate_yearly_sc` C:2349, `_2` C:2534 | Sudah Fase 4 |
| `fix_regen_lkh` C:2665, `fix_regen_cuti` C:2772, `sync_nama_pengguna` C:2818 | Tidak (F6-21) |
| Status TB/CLTN | **Tidak ada di legacy** → H-11 |

### A.4 `Local.php` (AJAX sesi login)

| Kelompok | Method | v2 |
|---|---|---|
| Referensi dropdown | `list_kab_kota`…`list_faq_subtop` (L:9-4494) | Master Data Fase 2 / endpoint opsi modul |
| Kepegawaian | `list_all_atasan*`, `list_arsip_rwy_data`, `connect_arsip`, `delete_arsip`, `update_foto_pegawai`, `get_list_anak`, `calculate_mker_ak`, `calc_mker_pmk_req` | Fase 3/4 (RBAC v2; GET yang mengubah data tidak dibawa) |
| Layanan | `calculate_lc` L:2147, `calculate_lk` L:2211, `info_cuti_ad*`, `calculate_ltb` L:3185, `detail_tb_ad` L:3220 | Fase 4 (`cuti/hitung-lama`, form TB/IB/konket) |
| Presensi | `po_in` L:1737, `po_out` L:1884, `preview_po` L:2050, `get_list_user_lokasi_presensi` L:4604 | Fase 5 (`presensi/masuk|pulang`); antre topik FCM di po_in/out → F6-12 |
| `record_geolocation` L:4071 | Insert `user_geo` dari GET | F5-12 (tidak di-port) |
| Pengumuman/FAQ | `get_list_fd_users`, `get_list_popup_users`, `get_list_banner_users`, `rate_faq` | Fase 7/8 |
| `register_fb_token` L:4224 | `fb_token` web push, +1 minggu | H-04 (F6-13) |
| Kode mati | `*_YYYYMMDD`, `*_bkp`, `validate_cp` L:229, `list_unit_es_3/4` | Tidak |

### A.5 SIASN & e-Kinerja

| Legacy | Status legacy | v2 |
|---|---|---|
| Auth `FH:2513-2572` (client credentials, cache `siasn_simpeg.api_token`), SSO `FH:2574-2721` (token literal / password+TOTP), e-Kinerja `FH:2916-2919` (token literal) | Token literal kedaluwarsa | `TokenBkn` (7.0.2) |
| `SI` generasi 1 (62 method) | ±44 backfill sekali jalan, ±11 mati, ±7 berulang (`sync_pns_id` 455, `sync_data_utama` 380, `sync_rw_skp23` 7840, 4× `upload_*` "LIVE") | Pairing + F6-14 |
| `SS/` generasi 2 (11 kelas) | `Data_utama`, `Ref_unor`, `Rw_gol`, `Rw_pendidikan`, `Rw_pmk`, `Rw_skp`, `Rw_ak`, `Rw_tj`, `Rw_jabatan`, `Rw_pelatihan`, `Arsip` | F6-14 (ikut legacy penuh) |
| `EB:9` periode, `EB:205` SKP periodik | Aktif, **kritis Tukin** | H-10 |
| `Ekinerja.php` | API masuk Basic `EKINERJA_*` | Q6-14 |
| UI SIASN legacy | `rwy/Ak.php:85,159,471` (AK SIASN), `master/C_jabatan.php:2144-2175` (JFT pairing) | Sudah Fase 3 (`AkSiasn`) / di luar Fase 6 |

## Lampiran B — Anomali legacy & perlakuan v2

| No | Anomali | Perlakuan |
|---|---|---|
| B-1 | Kredensial klien plaintext (`simapi_auth`, `sal_user`), konstanta `MUDIG_*`/`CRON_*` | Hash + klien baru (F6-03/F6-08) |
| B-2 | Token `mt_rand`, satu token dibagi per username | F6-05 |
| B-3 | Banyak endpoint tanpa auth (`A_dm/jabatan`, `S_stat`, `S_dm p_*`, `process_queue_andr_topic`, `Siasn`, `Ekin_bkn`, `fix_regen_*`) | Semua via command/auth (F6-02/F6-03) |
| B-4 | Ekspor hash password (`A_sinkron`) | F6-07 |
| B-5 | `p_web_config` membocorkan konfigurasi | F6-10 |
| B-6 | SQL string + `addslashes` | BP-9 |
| B-7 | Kontrak respons tidak seragam, HTTP selalu 200 | Dipertahankan hanya untuk endpoint kompatibilitas (F6-04) |
| B-8 | `change_history` ack-on-read (data hilang bila klien gagal) | [K] (F6-06) — dicatat sebagai risiko klien |
| B-9 | `detil` `unit_es_3/4` kosong untuk Es IV & pelaksana/JF (`AE:278` hanya memilih `id_atasan_es_*`, `AE:302-317` membaca nama `atasan_es_*`); riwayat tanpa ORDER BY | `unit_es_3/4`: **ikut legacy, tidak diperbaiki** (Q6-36, CR-104); ORDER BY: diperbaiki deterministik (F6-04) |
| B-10 | E-sign: passphrase/NIK di query string, TLS off, JSON non-error = sukses, `continue` di `switch` | F6-17, BP-3; TLS off **ikut legacy khusus e-sign** (Q6-18 = B, CR-097), QR TTE ikut legacy (Q6-22) |
| B-11 | FCM Legacy API/IID (dihentikan Google), hapus-lalu-kirim, token invalid tidak dibersihkan | F6-11, job `push-notif` |
| B-12 | Token SSO/e-Kinerja literal di kode, tanpa refresh | 7.0.2 |
| B-13 | SIASN: hapus fisik SKP saat API gagal (`Rw_skp.php:219`), salah variabel `Rw_ak.php:65`, salah salin tabel, dua jalur menulis tabel sama, data SIASN menimpa `npwp`/`keterangan` | F6-14 |
| B-14 | `riwayat_skp_periodik` tanpa unique key; periode tidak di-update | DBV-027, F6-15 |
| B-15 | Default potongan Tukin 32% bila sinkron e-Kinerja gagal | [K] + peringatan (F6-15) |
| B-16 | TB/CLTN tanpa aktivasi/pengakhiran otomatis | H-11 |
| B-17 | `debug()` mencetak data pribadi ke respons; NIP uji di-hardcode | Tidak dibawa |

## Lampiran C — Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| v0.1 | 01-11-2026 | Draf awal dari riset kode legacy (`0cf7e67`) & v2 (`a320f919`); usulan F6-01..F6-24; Q6-01..Q6-16; pembagian `6_1.md`/`6_2.md` (`ee6ce5a6`) |
| v1.0 | 01-11-2026 | Keputusan Horii Q6-01..Q6-16 ("intinya ikut legacy"): SIASN ikut legacy penuh (F6-14, antrean dorong admin dihapus, tambah `siasn_upload_arsip` + referensi), notifikasi presensi & kanal push ikut legacy (F6-12/F6-13, pengingat terjadwal dihapus), e-Kinerja insert/update ikut legacy (F6-15), BSrE query string ikut legacy (F6-17), passphrase oleh PyB (F6-18), H-12 auto-approval LKH (F6-25), F6-26 pertanyaan token SSO, F6-23 uji real di MT-010/DEPLOY-001.1 |
| v1.0a | 01-11-2026 | Penugasan Horii: Jalur A (`6_2.md`) → Qoder B, Jalur B (`6_1.md`) → Qoder A (sesi reviewer CR) |
| v1.0b | 02-11-2026 | CR-090 (keputusan Horii 02-11-2026, Q2 B0 Jalur B): AC-03.5 dan kriteria dorong otomatis SIASN ikut legacy per tabel — dorong ulang `updated_at > siasn_lu` hanya untuk jabatan; diklat, sertifikasi, kursus/seminar, SKP 2022 hanya `id_rw_siasn IS NULL` + `siasn_flag=0`, tanpa kolom `siasn_lu` baru di DBV-027 (F6-14, tabel `siasn:dorong`, AC-03.5) |
| v1.0c | 02-11-2026 | CR-091, usulan Jalur B disepakati Horii 02-11-2026: kontrak log bersama Jalur A & B di §7.0.2 — `IntegrasiLogInterface::catat(CatatanIntegrasi)` + objek nilai `CatatanIntegrasi` (arah 1 keluar/2 masuk); Jalur A tidak membuat antarmuka log sendiri |
| v1.0d | 03-11-2026 | CR-092: Q6-17 (keputusan Horii 02-11-2026 = A, ekstraksi bentuk auth BKN tanpa nilai; pilihan B = tunggu dokumentasi BKN/PN-2) + §7.0.2 "Bentuk auth BKN" (token gateway, SSO, varian e-Kinerja, dua header SIASN, token literal e-Kinerja legacy) + desain `TokenBkn` (token statis `.env` / SSO + cache, tanpa literal) |
| v1.0e | 03-11-2026 | CR-093, keputusan Horii 03-11-2026 atas A0 v2 Jalur A: PDF auto-approval LKH ikut v2 (on-demand, tidak disimpan; AC-12.3 disesuaikan), catatan = kolom `auto_approval` + audit tanpa tabel `aa_lkh`; H-12 dipisah ke MAKE-068 (A2b); akun sistem integrasi dikecualikan dari `simapi_ch` |
| v1.0f | 03-11-2026 | CR-097, keputusan Horii 03-11-2026 atas B4a Jalur B (MAKE-065):<br>- Q6-18 TLS BSrE ikut legacy (B) + jalur upgrade `esign.caBundle`;<br>- Q6-19 alur dua langkah (B);<br>- Q6-20 pembatas passphrase 5/15 menit [V2];<br>- Q6-21 passphrase hanya Setujui/Penangguhan (P-1);<br>- Q6-22 QR TTE ikut legacy (Q-1, URL PDF tanpa login, token acak);<br>- Q6-23 QR kaki surat verifikasi publik (Q-2, pola D-14).<br><br>§7.2, BP-3, B-10, AC-02.5..AC-02.8; skema token → DBV-030. Rincian opsi & gap di Trello Catatan Teknis |
| v1.0g | 03-11-2026 | CR-098, keputusan atas pertanyaan A2a Jalur A (MAKE-062, H-11; diputus reviewer CR, disetujui Horii 03-11-2026, balasan di Trello DEV-007):<br>- F6-20 "CLTN status 1/4" dikoreksi menjadi status 1 (Q6-25, ikut legacy);<br>- Q6-24 bentrok TB+CLTN;<br>- Q6-26 pemulihan status usang hanya bila dijelaskan riwayat berakhir;<br>- Q6-27 `--tanggal`.<br><br>§7.9 + AC-11.7..AC-11.10, `6_2.md` A2a |
| v1.0h | 04-11-2026 | CR-099, keputusan atas review kode B5 MAKE-066 (disetujui Horii 04-11-2026): F6-13 kanal Android ikut legacy penuh — cuti, support, **LKH, Konket** (Q6-28); TB/IB/PMK hanya web; popup "Informasi" = Fase 7 (Q6-29). §5.2 F6-13, §7.4, register §12, `6_1.md` B5 |
| v1.0i | 04-11-2026 | CR-100, aturan B6 MAKE-067 SIASN (putusan reviewer CR saat koding/review, disetujui Horii 04-11-2026; Q6-33 = keputusan Horii opsi A): Q6-30 pengaman destruktif tarik, Q6-31 bug legacy tarik, Q6-32 anti-duplikat dorong, Q6-33 bentuk POST hasil ekstraksi, Q6-34 konfigurasi & data dorong, Q6-35 unggah arsip; §7.3 + AC-03.7..AC-03.10 |
| v1.0j | 05-11-2026 | CR-104, keputusan Horii 04-11-2026 atas analisis A4 Jalur A: Q6-36 bug legacy B-9 `detil` `unit_es_3/4` **ikut legacy** (tidak diperbaiki; gap + opsi upgrade v2 dicatat), F6-04 & Lampiran B-9 disesuaikan (ORDER BY deterministik tetap); keputusan Horii 05-11-2026: Q6-37 filter `id_satker`/`id_unit` `rekomendasi_kp_uns` **ikut legacy** (selalu `data: []`; opsi B ditolak, opsi upgrade C dicatat) + AC-08.4. `6_1.md`/`6_2.md` §4: DB scratch per agen `simpeg_v2_t_<paket>_<agen>`, `CREATE DATABASE` tanpa `IF NOT EXISTS`, contoh nama DB dihapus (insiden DB scratch bersama 04-11) |
| v1.0k | 05-11-2026 | CR-105, keputusan Horii 05-11-2026 atas desain A6 Jalur A: Q6-38 URL `arsip_*`/`file_*` `services/s_stat` untuk klien layanan (MuDig) = **opsi A** endpoint unduh `services/s_stat/berkas/…` di balik `klienLayanan:stat` (pilihan B/C, gap re-handshake/berkas belum migrasi/cakupan hak, opsi lanjutan URL bertanda tangan dicatat); tabel route §6, F6-09, AC-08.5 disesuaikan |
| v1.0l | 05-11-2026 | CR-106, keputusan Horii 05-11-2026 ("sesuai legacy") atas temuan review A4/B4b: §6.3 amplop dipisah — `s_stat`/`s_dm` status **int**, `s_esign` status **string** `"1"`/`"2"` (`S_esign.php:12,57,122`); `data` gagal SIMAPI **`[]`** (bukan `{}`), termasuk gagal auth/throttle; §7.5 H-05 `data.token` saja (tanpa `expired_at`, Q6-02); Q6-39 URL `foto` SIMAPI = **opsi A** endpoint `simapi/v1/a_employee/foto/{nip}` di balik `simapiToken` (pilihan, gap dicatat); tabel route §6 ditambah |
| v1.0m | 05-11-2026 | CR-107, keputusan Horii 05-11-2026 atas ISSUE-055: Q6-40 TTE pada persetujuan cuti "atas nama" PyB = **opsi d** ikut legacy (admin boleh mengetik passphrase PyB; TTE atas identitas PyB; pengaman audit pelaku + Q6-20 + tanpa simpan/log passphrase), dikerjakan di Fase 6; rekomendasi reviewer a + b dicatat sebagai opsi upgrade; F6-18 & Q6-19 diberi pengecualian |
