# PRD Fase 6 — Integrasi & API Eksternal (Modul H, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase6/PRD_Fase6_Integrasi.md` — **acuan utama** coder/agen AI Fase 6 (Trello DEV-007, QASMTASK-089..097). Pembagian kerja paralel: [`6_1.md`](6_1.md) (Qoder B — klien integrasi keluar) dan [`6_2.md`](6_2.md) (Qoder A — API masuk & job terjadwal) |
| Versi | **v0.1 draf — 01-11-2026** (usulan keputusan F6-01..F6-24 menunggu putusan Horii; butir ⛔ di §12 wajib dijawab sebelum milestone yang disebut) |
| Status | **DRAF.** Berlaku sebagai v1.0 setelah keputusan ⛔ diputus Horii dan dokumen masuk `main`. Koding Fase 6 terkunci **GATE-F6** (§0) |
| Basis kode | `origin/main` = **`a320f919`** (rujukan `path:baris` repo dari commit ini) |
| Acuan legacy | Salinan kerja legacy CI3 **HEAD `0cf7e67`**. Rujukan baris bercatatan AS-09 (belum dicocokkan checksum produksi, Q5A-14). Rujukan relatif ke `application/`: `C` = `controllers/hr/services/Cron.php`, `L` = `controllers/hr/services/Local.php`, `SI` = `controllers/hr/services/Siasn.php`, `SS/` = `controllers/hr/services/siasn_sync/`, `EB` = `controllers/hr/services/Ekin_bkn.php`, `AE` = `controllers/simapi/v1/A_employee.php`, `FH` = `helpers/function_helper.php`, `LE` = `libraries/hr/L_employee.php` |
| Scope | **Fase 6** = H-01..H-09 (`Bahan Baku SIMPEG/06-Integrasi.md`) + **H-10 e-Kinerja BKN** (baru, Tukin bergantung padanya) + **H-11 status TB/CLTN harian** (warisan Fase 4 §14.6 #2, keputusan Horii 27-10) + fondasi auth antar-sistem dan penjadwalan (§7.0) |
| Pelaksana | **Qoder A** (`6_2.md`) dan **Qoder B** (`6_1.md`) paralel; **Reviewer** = reviewer CR (review, gate, merge, QA, uji real); **Horii** = keputusan & sign-off; **DBV** = review skema |
| Key | Qoder A: **DBV-026**, **MAKE-058..062**; Qoder B: **DBV-027**, **MAKE-063..067**. Perbaikan review/QA: CR-088 dst. ADR baru: **ADR-036** (auth antar-sistem), **ADR-037** (penjadwalan job) |
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
  Trello `GATE-F6`). Status 01-11: UP1-04 berjalan di DBV (PR #34).
- **Boleh sebelum gerbang terbuka** (◻ F6-01): membaca PRD, M0 (laporan pemahaman tanpa commit kode), menyiapkan permintaan
  akses non-teknis (§0.2), dan menjawab pertanyaan §12.

### 0.2 Prasyarat non-teknis (blocker H-02/H-03/H-04/H-10 real, SRS-NFR-041)

| ID | Kebutuhan | Pemilik | Menahan |
|---|---|---|---|
| PN-1 | Akses jaringan + akun klien sandbox/produksi **BSrE** (gateway e-sign) untuk server v2 dev | Horii/TL → infra | H-02 real, exit criteria |
| PN-2 | Akun layanan **SIASN** (API gateway + SSO) dan **e-Kinerja BKN** milik instansi; cara memperoleh token SSO produksi saat ini (Q6-08) | Horii/TL → Biro SDM/BKN | H-03, H-10 real |
| PN-3 | **Proyek Firebase** + service account (FCM HTTP v1) untuk v2; status aplikasi Android legacy (Q6-12) | Horii/TL | H-04 real |
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
| H-03 | Adapter real SIASN | Klien auth BKN + pairing `id_pns_siasn` + tarik/dorong sesuai lingkup F6-14 | 091 | B |
| H-04 | Adapter real FCM | FCM **HTTP v1** + registrasi token + push riwayat + pengingat presensi (F6-11..F6-13) | 092 | B |
| H-05 | SIMAPI autentikasi | `simapi/v1/a_auth/token`, token opak ber-hash, 1 jam | 093 | A |
| H-06 | SIMAPI data pegawai | 26 endpoint `a_employee/*` kontrak legacy (F6-04), termasuk `change_history` (F6-06) | 094 | A |
| H-07 | SIMAPI data mart | `a_dm/*` (4 endpoint) + `services/s_dm` (F6-10) | 095 | A |
| H-08 | Rekomendasi KP/KGB/pensiun | `services/s_stat/*` (pull MuDig) aturan [K] (F6-09) | 096 | A |
| H-09 | Service lokal | **Ditutup sebagai pemetaan** (F6-19): `calculate_lc` = Fase 4, `record_geolocation` = F5-12, `register_fb_token` = H-04 | 097 | A (dokumen) |
| **H-10** | **e-Kinerja BKN** [baru] | Tarik `bkn_periode_ekinper` + `riwayat_skp_periodik` terjadwal + tombol tarik ulang admin. **Tukin bergantung** (potongan SKP, jendela periode) | (kartu baru, F6-15) | B |
| **H-11** | **Status TB/CLTN harian** [warisan] | Command harian idempoten aktivasi/pengakhiran `pegawai.id_jenis_status` (F6-20) | (QASMTASK-096/097 catatan) | A |
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
| Auto-approval LKH tanggal 5 (`C:18`, `C:87`) | **Q6-15** | Bergantung status modul LKH v2 |
| Aplikasi mobile / API untuk app Android | Q6-12 | Fase 6 tidak membangun API mobile |
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
| BP-3 | Satu klien HTTP keluar bersama (`Libraries/Integrasi/Http/KlienHttp`, di atas `CURLRequest` CI4): timeout koneksi 5 dtk / total 30 dtk (dapat dikonfigurasi), **TLS verify aktif** (pengecualian hanya untuk gateway internal tanpa TLS, dicatat di `.env`), retry **hanya** untuk GET idempoten (maks 3, backoff + jitter). **POST bertransaksi (sign, save) tidak di-retry otomatis** | Legacy mematikan verify (B-14) dan tanpa retry |
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
| **A — API masuk & job terjadwal** | Qoder A (`6_2.md`) | `Controllers/Simapi/*`, `Controllers/Services/*`, `Libraries/Integrasi/Simapi/*`, `Libraries/Integrasi/Stat/*`, `Libraries/Integrasi/Klien/*`, `Filters/KlienLayananFilter.php`, `Filters/SimapiTokenFilter.php`, `Commands/Integrasi*Klien*.php`, `Commands/LayananStatusHarian.php`, `Config/RoutesSimapi.php`, `Config/RoutesServices.php`, migration DBV-026 | H-05..H-09, H-11, ADR-036, ADR-037 |
| **B — Klien integrasi keluar** | Qoder B (`6_1.md`) | `Libraries/Integrasi/Http/*`, `Libraries/Esign/Bsre*`, `Libraries/Push/Fcm*`, `Libraries/Siasn/*` (selain mock), `Libraries/Bkn/*`, `Jobs/*Fcm*|*Siasn*|*Bkn*`, `Commands/Bkn*`, `Commands/Siasn*`, `Commands/Integrasi*UjiKoneksi*`, `Config/RoutesIntegrasi.php`, `frontend/src/features/integrasi/*`, `frontend/public/firebase-messaging-sw.js`, migration DBV-027 | H-02, H-03, H-04, H-10 |

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

### 5.2 Usulan keputusan Fase 6 (◻ default berlaku bila tidak ditolak; ⛔ wajib diputus — §12)

| ID | Usulan | Label | Status |
|---|---|---|---|
| F6-01 | Sebelum GATE-F6 terbuka hanya M0 + persiapan akses (§0.1) | — | ◻ |
| F6-02 | **Penjadwalan = `php spark` command + crontab sistem di runbook** (pola `cuti:saldo-tahunan`, `README-deploy.md`), bukan endpoint HTTP basic-auth dan bukan "python tiap 2 detik". Endpoint `hr/services/cron/*` **tidak di-port**. ADR-037 | [V2] | ◻ |
| F6-03 | **Auth antar-sistem satu pola** (ADR-036): tabel klien layanan (rahasia ber-hash, status, jenis klien, hak per grup endpoint, allowlist IP opsional), filter `klienLayanan` (Basic) untuk `services/*` dan `simapiToken` untuk SIMAPI. **Semua** endpoint wajib auth — termasuk yang publik di legacy (`A_dm/jabatan`, `S_stat` selain `rekomendasi_kp`, `S_dm p_*`). Klien diberi kredensial baru saat re-handshake | [V2] | ⛔ Q6-01 |
| F6-04 | **Kontrak SIMAPI ikut legacy 1:1** [K]: path `/simapi/v1/<controller>/<method>/<nip>` (di luar `api/v1`), amplop `{"status":"01"|"02","msg","data"}`, HTTP 200, nama field & nilai teks persis (termasuk typo "Nomenlaktur", "paramater"), aturan `type` 1/2. Perbaikan hanya: auth wajib, `ORDER BY` deterministik pada riwayat yang tak berurut, bug `detil` `unit_es_3/4` diperbaiki | [K] + [V2] terbatas | ⛔ Q6-02 |
| F6-05 | **Token SIMAPI**: opak 30+ karakter dari `random_bytes`, masa aktif 1 jam [K], disimpan SHA-256 [V2]. Karena hanya hash yang disimpan, setiap handshake **menerbitkan token baru**; token lama tetap sah sampai kedaluwarsa [V2, kompatibel]. Header `Authorization: <skema> <token>` diterima seperti legacy | [K]/[V2] | ◻ |
| F6-06 | **`change_history`**: tabel `simapi_ch` diisi **aplikasi** (event sesudah commit dari layanan riwayat/pegawai/pengguna) — **bukan trigger DB** [V2, trigger lintas schema tidak dibawa]; format `field_name`/`field_value` pipe, `LIMIT 100`, ack-on-read lewat `simapi_auth_ch` [K]. `simapi_update`/`_hist` **tidak dibuat** (tanpa pembaca, Lampiran A.1) | [K]+[V2] | ◻ |
| F6-07 | **`A_sinkron::ambil_seluruh_pengguna`** (ekspor hash password + email untuk E-Talenta) **tidak di-port**: hash v2 berbeda (rehash) dan berisiko. E-Talenta ikut keputusan J3.03 (view/SSO/API verifikasi). `ambil_riwayat_mutasi` di-port dengan auth | [V2] | ⛔ Q6-03 |
| F6-08 | Kredensial klien lama (`simapi_auth`, `sal_user`, `MUDIG_*`) **tidak diimpor**; klien baru dibuat lewat command `integrasi:klien` (buat/rotasi/nonaktif, rahasia ditampilkan sekali) | [V2] | ◻ |
| F6-09 | **`services/s_stat/*` (H-08)** = API tarik untuk MuDig, **aturan deteksi ikut legacy** [K] (`LE:2416-2720` KP, `LE:1324-1376`+`FH:2400-2418` pensiun, `LE:4095-4220` KGB) dan kolom keluaran persis. Perbaikan [V2]: urutan KGB pakai kolom yang ada (`tmtsk_kgb`), cek berkas arsip lewat storage lokal (bukan HTTP fetch per URL). **Tidak** menulis tabel/notifikasi baru | [K] | ⛔ Q6-04 |
| F6-10 | **`services/s_dm/*`** di-port dengan auth, **kecuali `p_web_config`** (membocorkan seluruh konfigurasi) | [V2] | ◻ |
| F6-11 | **FCM HTTP v1** (service account, OAuth2 JWT via `firebase/php-jwt` yang sudah terpasang, token akses di-cache). FCM Legacy API & Instance ID (topik) sudah dihentikan Google, jadi **tidak mungkin ikut legacy** | [V2] wajib | ◻ |
| F6-12 | **Pengingat presensi** (`pegawai_blm_masuk/pulang/lkh`): topik IID diganti **job terjadwal** yang mengirim multicast ke token pegawai yang belum presensi/LKH pada jam di `web_config` (default usulan 07:45 masuk, 16:45 pulang, 19:00 LKH; hari kerja saja; lewati libur/cuti/TB/DL) | [V2] | ⛔ Q6-11 |
| F6-13 | **Kanal push**: Web Push di SPA v2 (pengganti `register_fb_token` + `fb_token`) — endpoint `POST api/v1/integrasi/fcm-token` (jwt, semua role), service worker, izin notifikasi diminta lewat tombol (bukan otomatis). Token Android tetap di `firebase_token` bila app mobile masih hidup (Q6-12). Penerima push riwayat ikut legacy `fcmQueue_rwy`/`fcmQueue_layanan` (admin, pegawai, atasan untuk LKH) | [K]/[V2] | ⛔ Q6-12 |
| F6-14 | **Lingkup SIASN (H-03)** — lihat Q6-07. Usulan reviewer **opsi B**: (1) klien auth BKN (client credentials + SSO, cache, tanpa token literal); (2) pairing `pegawai.id_pns_siasn`; (3) **tarik** AK (`riwayat_ak_siasn`), SKP tahunan, golongan, pendidikan, PMK ke `riwayat_*` ikut pola generasi 2 [K] **tetapi tanpa hapus fisik** (lepas pasangan + tandai) [V2] dan `pegawai.npwp/keterangan` tidak ditimpa [V2]; (4) **dorong** jabatan/diklat/sertifikasi/kursus/dokumen lewat antrean dengan status per baris, dipicu admin (bukan otomatis setiap edit) | [K]/[V2] | ⛔ Q6-07 |
| F6-15 | **H-10 e-Kinerja BKN masuk Fase 6** (tidak ada di `06-Integrasi.md`, tetapi Tukin bergantung): tarik periode (**upsert**, bukan insert saja [V2]) + SKP periodik per periode (upsert per kunci `id_pns`+`periode_id`+`skp_id`, unique key baru [V2]). Aturan Tukin tetap [K] (potongan 0/16/32%, default 32% bila tak ada baris); v2 menambah **peringatan** di log/command bila periode aktif belum punya data SKP | [K]/[V2] | ⛔ Q6-09 |
| F6-16 | **`services/s_esign` untuk MuDig** di-port: auth klien layanan, berkas dikirim sebagai **unggahan multipart** (bukan path server) [V2], `esign_hist` **wajib** dicatat [V2], respons berisi PDF ber-tanda tangan (base64) [V2] | [V2] | ⛔ Q6-05 |
| F6-17 | **BSrE**: NIK penanda tangan dari `pegawai.nik` [K]; passphrase dikirim sesuai spesifikasi gateway (body bila didukung; legacy di query string — `Esign.php:166-188`); respons sukses hanya bila berupa PDF valid (`%PDF-`) [V2, legacy menerima JSON tanpa key error]; kode BSrE disimpan di `esign_hist.status_code` | [K]/[V2] | ◻ |
| F6-18 | **Siapa mengetik passphrase** (Q-09 Fase 4) — lihat Q6-06 | — | ⛔ Q6-06 |
| F6-19 | **H-09 ditutup sebagai pemetaan** (tanpa endpoint baru): `calculate_lc` → `POST cuti/hitung-lama`; `record_geolocation` → F5-12; `register_fb_token` → H-04; seluruh AJAX dropdown/kalkulator `Local.php` sudah diserap modul Fase 2–5 (Lampiran A.4) | — | ◻ |
| F6-20 | **H-11 status harian** `php spark layanan:status-harian [--tanggal=] [--dry-run]`, jadwal 00:30 WIB: TB (`riwayat_tb` status 1) mencakup hari ini → `id_jenis_status=2`; CLTN (`riwayat_cuti` `id_jenis_cuti=6` status 1/4) → 3; tidak ada lagi rentang aktif dan status masih 2/3 → 1. Perpanjangan = baris riwayat baru yang bersambung (otomatis tertangani). Tidak menyentuh status selain 1/2/3. Lewat model beraudit (`updated_by` = akun sistem). Perbandingan per tanggal inklusif (sama dengan PRD Fase 4 v1.4) | [V2] (legacy tanpa cron) | ◻ |
| F6-21 | **Tidak di-port**: SAPK lama, Siasn generasi 1 (backfill), email ultah, popup notif, `fix_regen_*`, `sync_nama_pengguna` (v2 membaca nama dari `pegawai`), `Ekinerja.php` (API masuk e-Kinerja pihak lain — Q6-14), skema `siasn_simpeg`/`sapk_simpeg` (yang dibutuhkan H-03 dibuat ulang ramping di DBV-027) | — | ◻ (Q6-14) |
| F6-22 | Server Arsip remote (J3.06, F-10) **ditunda**; v2 tetap storage lokal | — | ◻ |
| F6-23 | **Exit criteria uji real**: minimal e-sign cuti lolos di jaringan internal (06-Integrasi). Bila PN-1..PN-3 belum tersedia saat semua task selesai → lihat Q6-13 | — | ⛔ Q6-13 |
| F6-24 | Log integrasi tunggal `integrasi_log` (arah masuk/keluar, layanan, operasi, status, durasi, kode, pesan teredaksi, `ref_id`) + command `integrasi:status` (ringkasan 24 jam). Retensi 180 hari (command pembersih terjadwal) | [V2] | ◻ |

---

## 6. Matriks endpoint & job

### 6.1 Endpoint masuk kompatibilitas legacy (Jalur A, F6-03/F6-04)

| Path v2 (sama dengan legacy) | Method | Auth | Task |
|---|---|---|---|
| `simapi/v1/a_auth/token` | GET/POST [K] | Basic klien layanan (jenis SIMAPI) + throttle | H-05 |
| `simapi/v1/a_employee/{method}/{nip}` (25 method, Lampiran A.1) | GET/POST [K] | `simapiToken` | H-06 |
| `simapi/v1/a_employee/change_history` | GET/POST | `simapiToken` + type 2 | H-06 (F6-06) |
| `simapi/v1/a_dm/{group_jabatan|sub_group_jabatan|jabatan|struktur_jabatan}` | GET/POST | `simapiToken` (+ type 2 kecuali `jabatan` [K]) | H-07 |
| `simapi/v1/a_sinkron/ambil_riwayat_mutasi` | GET/POST | `simapiToken` | H-06 (F6-07) |
| `services/s_stat/{rekomendasi_kp|rekomendasi_kp_uns|rekomendasi_pensiun|rekomendasi_kgb|list_tb|list_ib|list_karpeg|list_kariskarsu}` | GET | `klienLayanan` (hak `stat`) | H-08 |
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
| `api/v1/integrasi/siasn/dorong` | POST `{modul, id_rwy[]}` | jwt, role 1 | H-03 (bila F6-14 opsi B) |

FE (Jalur B): halaman **Admin → Integrasi** (role 1): log integrasi, tombol tarik ulang e-Kinerja per periode, antrean dorong
SIASN (bila opsi B). Aksi baris lewat menu ⋮ (AGENTS.md §1).

### 6.3 Amplop respons kompatibilitas

| Grup | Sukses | Gagal | HTTP |
|---|---|---|---|
| SIMAPI | `{"status":"01","msg":"…","data":…}` | `{"status":"02","msg":"Authentication failed"|"Token is not valid"|"Invalid paramater"|"NIP not valid"|"Data … tidak tersedia","data":{}}` | 200 [K] |
| `services/s_stat`, `s_dm`, `s_esign` | `{"status":1,"msg","data"}` (int) [K] | `{"status":2,…}` | 200 [K]; auth gagal 401 [V2] |

### 6.4 Job terjadwal (crontab runbook, F6-02)

| Command | Jadwal usulan (WIB) | Task |
|---|---|---|
| `layanan:status-harian` | 00:30 setiap hari | H-11 |
| `bkn:tarik-periode` | 01:00 setiap hari | H-10 |
| `bkn:tarik-skp-periodik [--tahun --bulan]` | 02:00 setiap hari (periode berjalan + sebelumnya) | H-10 |
| `siasn:tarik [--jenis=]` | 03:00 setiap hari (batch, berlanjut) | H-03 |
| `queue:work siasn` | setiap menit (`flock`) | H-03 dorong |
| `push:pengingat-presensi --jenis=masuk|pulang|lkh` | sesuai F6-12 | H-04 |
| `integrasi:bersihkan-log` | 04:00 Minggu | F6-24 |
| `cuti:saldo-tahunan` (sudah ada) | 00:30 1 Januari + ulang berkala | (Fase 4, dicatat di runbook) |
| `queue:work push` (sudah ada) | setiap menit | H-04 |

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
duplikat** di data impor (pola runbook UP-1); antrean dorong SIASN `siasn_antrean` (bila F6-14 opsi B: `modul`, `id_rwy`,
`status` 0 menunggu/1 terkirim/2 gagal, `percobaan`, `pesan`, `id_rw_siasn`, waktu). Kolom `siasn_*` di `riwayat_*` sudah
ada (tidak diubah). `esign_hist` tidak diubah (`ip_address` sudah VARCHAR(45), `status_code` sudah ada).

**AC-01.1** dokumen `backend/docs/db-review/DBV-026-*.md` & `DBV-027-*.md` di-approve DBV; **AC-01.2** `firebase_token`
tetap kosong saat cutover (tidak diimpor, FSD-FN-H-01); **AC-01.3** migration naik/turun bersih di DB scratch.

### 7.2 H-02 — BSrE (Jalur B, MAKE-065)

[K `libraries/Esign.php`, `bsre_esign/bsre_esign.php`; konsumen `L_cuti.php:3232-3484`]. `BsreEsignAdapter`:
- `checkUser(nip)`: NIK dari `pegawai.nik` → `GET /api/user/status/{nik}` → `has_certificate = (status_code == '1111')` [K
  `Esign.php:16-45`]. Pesan gateway diteruskan.
- `signPdf(pdf, nip, passphrase, options)`: multipart `file` + opsi tampilan (`tampilan`, `image`, `width`, `height`,
  `tag_koordinat` default `##`, `reason`, `location`) [K `S_esign.php:74-84`]; gambar TTD dari storage v2 (bukan path
  legacy). Sukses hanya bila respons PDF valid (F6-17). Kode/pesan gateway → `status_code`/`message`.
- Kontrak `EsignGatewayInterface` **tidak berubah**; `EsignCutiService` tidak diubah kecuali pemetaan pesan.
- `services/s_esign` (F6-16): `check_user?nik=` → `{status 1|2,msg,data}`; `sign_pdf` multipart → `esign_hist` wajib.
**AC-02.1** regresi C-05 (test Fase 4 e-sign cuti) hijau dengan driver mock dan dengan adapter real + FakeTransport;
**AC-02.2** gateway mati/timeout → `esign_hist` status 2 + `status_code` + pesan, cuti tidak berubah final; **AC-02.3**
passphrase/NIK tidak ada di log mana pun; **AC-02.4** uji real (reviewer, jaringan internal): 1 sukses + 1 gagal (MTC-037).

### 7.3 H-03 — SIASN (Jalur B, MAKE-067) — bergantung Q6-07

[K generasi 2 `SS/*`; auth `FH:2513-2721`]. Implementasi `SiasnGatewayInterface` (`getPegawai`, `getRiwayat`,
`syncPegawai`) **tanpa ubah kontrak**; kebutuhan tambahan (unggah dokumen, simpan riwayat) = interface baru
`SiasnDorongInterface`. Usulan (opsi B):
- Pairing `pegawai.id_pns_siasn` dari `/pns/data-utama/{nip}` [K `SI:455-507`].
- Tarik (command batch berlanjut, tandai `siasn_lu`): AK → `riwayat_ak_siasn` [K `SS/Rw_ak`, perbaiki salah variabel
  `Rw_ak.php:65`], SKP tahunan ≥2022 [K `SS/Rw_skp`, rating dibalik 1↔3 dicek ke referensi BKN], golongan → `riwayat_kp`
  [K `SS/Rw_gol`], pendidikan [K `SS/Rw_pendidikan`], PMK [K `SS/Rw_pmk`]. Baris yang tak lagi dikembalikan BKN → lepas
  pasangan + `siasn_flag=2` + `siasn_error`, **tidak dihapus** [V2, legacy `Rw_skp.php:219` hapus fisik].
- Dorong (opsi B): admin memilih baris → `siasn_antrean` → job `queue siasn` (unggah dokumen lalu `…/save`) [K
  `SS/Rw_jabatan::upload_siasn`, `SS/Rw_pelatihan`, `SS/Arsip`], hasil `id_rw_siasn`/`siasn_flag`/`siasn_error`.
**AC-03.1** kegagalan SIASN dicatat dan tidak menggagalkan operasi utama; **AC-03.2** tarik idempoten (ulang = tanpa
duplikat); **AC-03.3** tidak ada hapus fisik riwayat; **AC-03.4** token SSO dari konfigurasi + cache, **tidak ada token
literal**; **AC-03.5** uji real (MTC-038) bila PN-2 tersedia.

### 7.4 H-04 — FCM (Jalur B, MAKE-066)

- `FcmV1Adapter` implementasi `PushNotifGatewayInterface` (F6-11): `send`/`sendMulticast` (loop per token, HTTP v1 tidak
  punya multicast native), `invalid_tokens` dari error `UNREGISTERED`/`INVALID_ARGUMENT`. Job `push-notif` yang ada
  (`Jobs/PushNotifLayananJob.php`, hapus token invalid :66-68) **dipakai apa adanya**.
- Registrasi token (F6-13): `POST/DELETE api/v1/integrasi/fcm-token` → upsert `firebase_token` per `token_hash`
  (deduplikasi DQ-25), batas 10 token per NIP (terlama dibuang).
- Push riwayat Modul B: pelanggan event `riwayatNotifikasi` (`RiwayatEngine.php:54-59`) → antre `push-notif` ke penerima
  ikut legacy [K `FH:588-760` `fcmQueue_rwy`]: pegawai pemilik + admin (role 1/3 sesuai cakupan) + atasan untuk LKH.
- Pengingat presensi (F6-12): `push:pengingat-presensi`.
- FE: SDK Firebase web (versi tetap), `firebase-messaging-sw.js` di `frontend/public`, tombol "Aktifkan notifikasi" di menu
  profil; konfigurasi web Firebase lewat env Vite (**bukan** disalin dari `header.php` legacy).
**AC-04.1** regresi C-06 (notifikasi cuti/TB/IB/PMK) hijau; **AC-04.2** token invalid dihapus, tidak di-retry selamanya;
**AC-04.3** push lewat queue, bukan sinkron; **AC-04.4** pengingat tidak dikirim di hari libur / ke pegawai cuti-TB-DL.

### 7.5 H-05/H-06/H-07 — SIMAPI (Jalur A, MAKE-059/060)

- **H-05** `a_auth/token`: Basic → klien jenis 1/2 status 1 → token baru (F6-05) → `{"status":"01","msg":"…","data":{"token":…,"expired_at":…}}`
  (bentuk `data` ikut `A_auth.php:76-111`).
- **H-06** 25 method `a_employee` + `change_history` — daftar method, sumber tabel, dan field per method **persis
  Lampiran A.1** (rujukan baris `AE`). Sumber v2: tabel snapshot `pegawai_*` dan `riwayat_*` status 1 [K]. Pemetaan nilai
  teks (jenis kelamin, status pernikahan, status "Aktif"/"Tidak Aktif", id 99/9999/98/1185 = "lain") [K]. NIP tidak ada →
  "NIP not valid" [K, termasuk status 10]. `foto` = URL berkas v2 (endpoint yang sudah ada).
- **change_history** (MAKE-060): penulis `simapi_ch` = pelanggan event sesudah commit untuk tabel yang sama dengan trigger
  legacy (`pegawai`, `pengguna`, `detail_anak`, 13 `riwayat_*` — Lampiran A.1); `field_value` hanya kolom yang dikirim legacy.
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
(MTC-039).

### 7.7 H-09 — pemetaan (Jalur A)

Tabel Lampiran A.4 dipindah ke `backend/docs/progress/06-Integrasi.md` + komentar penutup di QASMTASK-097. **AC-09.1**
setiap method `Local.php` punya padanan atau alasan "tidak di-port".

### 7.8 H-10 — e-Kinerja BKN (Jalur B, MAKE-064)

[K `EB:9` `cron_periode_skp`, `EB:205` `cron_skp_periodik`; auth `FH:2882-2921`].
- `bkn:tarik-periode`: `GET /api_kinerja/referensi/periode` → upsert `bkn_periode_ekinper` (id, nama, tahun, bulan,
  periode_awal/akhir, batas_pengisian, jenis_periode, status) [V2 upsert].
- `bkn:tarik-skp-periodik`: pemilihan periode [K `EB:213-222`: tanggal > 19 → bulan berikutnya]; periode manual (mis. nama
  berawalan `SKPManual`) dilewati [K `EB:231-234`]; `GET /api_kinerja/laporan/penilaian/{tahun}/{periode_id}` → upsert
  `riwayat_skp_periodik` per kunci; perubahan **semua** kolom dibandingkan [V2, legacy hanya `hasil_akhir`].
- Tombol admin `POST api/v1/integrasi/bkn/tarik` (antre job, bukan sinkron).
- Peringatan (F6-15): `integrasi:status` dan log menandai periode Tukin berjalan yang belum punya baris SKP periodik.
**AC-10.1** upsert idempoten; **AC-10.2** pegawai tanpa `id_pns_siasn` dicatat "tidak terpasang"; **AC-10.3** test
`PeriodeTukin`/potongan SKP Fase 5 tetap hijau (tidak ada perubahan logika Tukin); **AC-10.4** uji real bila PN-2.

### 7.9 H-11 — status TB/CLTN harian (Jalur A, MAKE-062)

F6-20. Sumber [K]: TB → 2 saat setujui `L_tb.php:1170-1178`, → 1 saat hapus `:745-758`; CLTN → 3 `L_cuti.php:2421-2433`,
→ 1 `:1367-1377`. v2 memakai `StatusTugasBelajarInterface`/`BebasPresensiTb` untuk rentang dan `EfekTbTrait.php:14-22`
sebagai acuan efek. **AC-11.1** TB disetujui sebelum tanggal mulai → aktif tepat pada tanggal mulai; **AC-11.2** setelah
tanggal akhir → kembali 1; **AC-11.3** perpanjangan bersambung tidak menyebabkan status berkedip; **AC-11.4** status
selain 1/2/3 tidak disentuh; **AC-11.5** dijalankan dua kali = hasil sama; **AC-11.6** setiap perubahan teraudit.

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

| M | Qoder A (`6_2.md`) | Qoder B (`6_1.md`) |
|---|---|---|
| M0 | Laporan pemahaman ⛔ reviewer | Laporan pemahaman ⛔ reviewer |
| M1 | DBV-026 skema klien & SIMAPI (PR [DBV]) | DBV-027 skema integrasi keluar (PR [DBV]) |
| M2 | MAKE-062 H-11 status harian + ADR-037 (tanpa skema baru — boleh paralel dengan review DBV) | MAKE-063 klien HTTP + log + token BKN |
| M3 | MAKE-058 auth antar-sistem + ADR-036 | MAKE-064 H-10 e-Kinerja BKN |
| M4 | MAKE-059 SIMAPI H-05/H-06/H-07 + `s_dm` | MAKE-065 H-02 BSrE + `s_esign` |
| M5 | MAKE-060 change_history | MAKE-066 H-04 FCM (BE + FE) |
| M6 | MAKE-061 H-08 `s_stat` | MAKE-067 H-03 SIASN |
| M7 | Penutup A: H-09 pemetaan, runbook cron & Nginx, progres | Penutup B: progres, `integrasi:uji-koneksi` |
| M8 | Reviewer: QA Lapis 1 Fase 6, uji real (PN), laporan akhir → ⛔ sign-off Horii | |

Setiap M: branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor → ⛔ merge oleh reviewer CR
(izin Horii per paket).

## 11. Definition of Done & exit criteria

**Fase 6 DONE** bila: H-01..H-11 DONE (AC terpenuhi, di `main`); semua integrasi lolos test dengan mock/FakeTransport;
regresi C-05/C-06 + Tukin Fase 5 hijau; **minimal satu konsumen (e-sign cuti) lolos uji endpoint real di jaringan internal**
(06-Integrasi; lihat F6-23/Q6-13); QA Lapis 1 Fase 6 lolos; runbook cron + Nginx + daftar re-handshake klien tercatat;
laporan akhir `docs/fase6/Laporan_Akhir_Fase6.md`; sign-off Horii. Trello DEV-007 + QASMTASK-089..097 (+ kartu H-10/H-11)
disinkron.

Format laporan milestone (blok kode): Status / Branch-SHA-CI / Berkas / Test (jumlah) / Berkas bersama / Deviasi / Blocker.

## 12. Register pertanyaan untuk Horii (v0.1)

| ID | Pertanyaan | Usulan reviewer | Batas |
|---|---|---|---|
| Q6-01 ⛔ | Semua endpoint antar-sistem wajib auth (termasuk yang publik di legacy) — klien MuDig/Simanta harus menyesuaikan saat re-handshake? | **Ya** (F6-03) | Sebelum M3 A |
| Q6-02 ⛔ | Kontrak SIMAPI dipertahankan 1:1 (amplop, HTTP 200, typo) atau versi baru + masa transisi? | **1:1** (F6-04) — klien cukup re-handshake | Sebelum M4 A |
| Q6-03 ⛔ | `ambil_seluruh_pengguna` (hash password E-Talenta) tidak di-port? Bergantung J3.03 | **Tidak di-port** (F6-07) | Sebelum M4 A |
| Q6-04 ⛔ | Rekomendasi KP/KGB/pensiun `s_stat` ikut aturan legacy persis (termasuk KGB "24–36 bulan → hari ini", PPPK tidak dihitung pensiun)? | **Ikut legacy** (konsumen MuDig); koreksi regulasi = keputusan Biro SDM terpisah | Sebelum M6 A |
| Q6-05 ⛔ | `services/s_esign` untuk MuDig tetap dilayani v2 (berkas diunggah, bukan path server)? | **Ya** (F6-16) | Sebelum M4 B |
| Q6-06 ⛔ | E-sign real: passphrase diketik siapa? (a) admin di dialog seperti mock Fase 4; (b) PyB/penanda tangan sendiri lewat halaman tugasnya | **(b)** — passphrase milik pemegang sertifikat; (a) hanya bila PyB tidak punya akses | Sebelum M4 B |
| Q6-07 ⛔ | Lingkup SIASN: (A) ikut legacy gen-2 (tarik langsung + dorong otomatis tiap edit, termasuk hapus fisik SKP); (B) tarik langsung tanpa hapus fisik + dorong lewat antrean dipicu admin; (C) tarik saja, dorong ditunda | **(B)** | Sebelum M1 B (skema antrean) |
| Q6-08 ⛔ | Token SSO BKN produksi saat ini diperoleh bagaimana (ditempel manual / akun ber-TOTP)? Siapa pemilik akun layanan? | Akun layanan instansi, rahasia di `.env`; v2 tidak memakai token literal | Sebelum M3 B (real) |
| Q6-09 ⛔ | e-Kinerja BKN masuk Fase 6 (H-10, kartu Trello baru)? Default 32% saat data kosong tetap ikut legacy + peringatan? | **Ya + ya** (F6-15) | Sebelum M1 B |
| Q6-10 ◻ | `riwayat_skp_periodik` diberi UNIQUE setelah pra-cek duplikat impor | Ya | M1 B |
| Q6-11 ⛔ | Pengingat presensi push (masuk/pulang/LKH) tetap ada? Jam kirim? | **Ya**, 07:45 / 16:45 / 19:00 WIB hari kerja, dapat diubah di `web_config` | Sebelum M5 B |
| Q6-12 ⛔ | Aplikasi Android legacy masih dipakai? (penulis `firebase_token` ada di luar repo) Bila ya, v2 Fase 6 hanya Web Push dan app mobile dicatat sebagai risiko cutover | **Web Push saja di Fase 6**; app mobile = keputusan terpisah | Sebelum M5 B |
| Q6-13 ⛔ | Bila akses BSrE/SIASN/FCM belum ada saat koding selesai: sign-off Fase 6 dengan mock + uji real dipindah ke gladi cutover, atau Fase 6 menunggu? | **Sign-off dengan mock**, uji real jadi syarat gladi cutover (dicatat di DEPLOY-001) | Sebelum M8 |
| Q6-14 ◻ | `Ekinerja.php` (API masuk atasan/bawahan untuk aplikasi e-Kinerja pihak lain, berkas 2021) tidak di-port kecuali PIC menyatakan masih dipakai | Tidak di-port | PN-4 |
| Q6-15 ⛔ | Auto-approval LKH tanggal 5 (`C:18`, `C:87`) di-port sebagai job terjadwal? (bergantung modul LKH v2 — diverifikasi di M0 A) | **Port [K]** bila LKH v2 ada; aturan status 3 (revisi) ikut legacy | Sebelum M2 A |
| Q6-16 ◻ | Retensi `integrasi_log` 180 hari | Ya | M1 B |

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
| `queue_aa_lkh` C:18, `process_queue_aa_lkh` C:87 (auto-approval LKH tgl 5) | Q6-15 |
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
| `SS/` generasi 2 (11 kelas) | `Data_utama`, `Ref_unor`, `Rw_gol`, `Rw_pendidikan`, `Rw_pmk`, `Rw_skp`, `Rw_ak`, `Rw_tj`, `Rw_jabatan`, `Rw_pelatihan`, `Arsip` | F6-14 opsi B |
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
| B-9 | `detil` `unit_es_3/4` selalu kosong (`AE:302-317`); riwayat tanpa ORDER BY | Diperbaiki (F6-04) |
| B-10 | E-sign: passphrase/NIK di query string, TLS off, JSON non-error = sukses, `continue` di `switch` | F6-17, BP-3 |
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
| v0.1 | 01-11-2026 | Draf awal dari riset kode legacy (`0cf7e67`) & v2 (`a320f919`); usulan F6-01..F6-24; Q6-01..Q6-16; pembagian `6_1.md`/`6_2.md` |
