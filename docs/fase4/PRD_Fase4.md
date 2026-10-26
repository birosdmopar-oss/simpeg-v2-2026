# PRD Fase 4 — Modul C Layanan & Approval (SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `PRD_Fase4.md` — acuan utama coder/agen AI Fase 4 (kartu Trello DEV-005, prompt eksekusi 1/2 & 2/2) |
| Versi | **v1.1 — 26-10-2026** (revisi atas v1.0 18-10-2026; daftar perubahan di §14) |
| Status | **BERLAKU** — disetujui user/reviewer CR 18-10-2026: keputusan D1–D19 dan **seluruh saran default §12 (butir ⛔ dan ◻) diterima**, termasuk konfirmasi Q-03 (nilai turunan dihitung server) dan Q-13 (pemanggil `process` = NIP approver tahap aktif). Perubahan berikutnya hanya lewat revisi versi oleh reviewer CR. **26-10-2026:** Horii menyetujui 13 keputusan riset take over (§14) — seluruh butir ◻ §12 = **disetujui default 26-10** dengan penyesuaian Q-08/Q-10/Q-21/Q-23; **tahap 2b** (logika murni cuti sebelum DBV-022) diizinkan (§6.1.4, §9) |
| Basis kode | v1.0: `origin/main` = **`2b712ed`** (rujukan `path:baris` repo diambil dari `0a79e38`/`2b712ed`). v1.1: `origin/main` = **`4d4ae92`** — berkas baru yang dirujuk v1.1: `Libraries/Pdf/KopDokumen.php` (MAKE-037), `tests/_support/Libraries/PemindaiKemurnian.php`, `tests/feature/KontrakMatriksRoleTest.php` (MAKE-038: setiap route `api/v1` baru wajib punya baris kontrak role di commit yang sama) |
| Sumber | Keputusan user D1–D19 (18-10-2026), Trello DEV-005/QASMTASK-064..075/QAUI-004/QAFUNC-005, `04-Layanan.md`, Matriks Role × Endpoint v2 Modul C, kode & DDL legacy (D1 `simpeg01_struktur_lengkap_20261001.sql`), repo v2. Daftar lengkap di Lampiran A |
| Key | Skema: **DBV-022** (DBV-021 dicadangkan ISSUE-045). Pekerjaan baru: **MAKE-022** dst. (tahap 2b = bagian awal MAKE-022). Perbaikan review/QA: CR-### berikutnya |
| Pelaksana | **Take over 26-10-2026** (permintaan coder Wahyu): Fase 4 dikerjakan tim reviewer CR. Basis = branch `fase4/dbv-022-skema-layanan` @`706f73b` (dokumen DBV-022 + skrip audit, belum ada draf migration/PR). Pembagian sesi: PRD v1.1, M0–M1 DBV-022, dan tahap 2b oleh sesi coder (Worker Fable); review/merge/PR oleh reviewer CR (Qoder-A-Fadhlan); keputusan produk oleh Horii |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh dokumen ini sebelum menulis satu baris kode.** Laporan M0 (§9) membuktikan pemahaman.
2. Bila dokumen ini bertentangan dengan sumber lain (Trello, `04-Layanan.md`, Matriks, TS/FSD/SRS, mockup), **PRD ini + keputusan user D1–D19 menang**. Di antara keduanya, keputusan user (§4) menang atas teks PRD lain.
3. Bila ada hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG menebak.** Catat di laporan serah dan di `backend/docs/progress/04-Layanan.md`.
4. Butir bertanda **⛔** = titik tunggu: jangan lanjut sebelum pihak yang disebut menyetujui. Butir **◻ default** di §12 = default PRD berlaku bila reviewer CR tidak menolaknya saat review laporan M0; butir **⛔ Q** di §12 wajib dijawab sebelum milestone yang disebut.
5. Label: **[K]** = ikut legacy apa adanya (rujukan berkas:baris), **[V2]** = deviasi v2 yang disengaja (alasan dicatat), **[I]** = inferensi dari kode bila DDL/kode tidak cukup.
6. Rujukan legacy relatif ke `1_legacy_simpeg.kemenparekraf.go.id/application/`: `C/Cuti.php` = `controllers/hr/rwy/Cuti.php`, `L/L_cuti.php` atau `L:` = `libraries/hr/rwy/L_cuti.php`, `FH` = `helpers/function_helper.php`, `MYC` = `core/MY_Controller.php`, `D1:` = baris DDL D1. Rujukan repo relatif ke root repo `Rewrite SIMPEG`.

---

## 1. Ringkasan scope

### 1.1 Di dalam scope Fase 4 (C-01..C-12)

| Task | Judul | Inti |
|---|---|---|
| C-01 | Migration layanan + pemisahan inbox vs disiplin_ticket | Satu unit review DBV (DBV-022) untuk semua tabel D18; ⛔ DBV approve sebelum migration & sebelum coding notifikasi |
| C-02 | Pengajuan cuti | CRUD cuti (pegawai 2/7, input admin 1), lampiran, ketua tim (CC), opsi form |
| C-03 | Kalkulasi kuota N/N-1/N-2 + kalkulator `calculate_lc` | `KuotaCutiService` satu algoritma (D8), lama cuti hari kerja (D9), saldo `pegawai_sisa_cuti` |
| C-04 | Approval berjenjang cuti | `CutiApprovalService` state machine 0→3→1 / 0→1 / →2 / →4 (D1, D2, D3, D7), efek final (D4), tunda pengingat (skip, D2), pa_layanan (PyB) |
| C-05 | Penerbitan surat cuti e-sign (mock) | PDF TCPDF → `EsignGatewayInterface` mock → `document_attachment` + `esign_hist` di luar transaksi (D12, D13) |
| C-06 | Notifikasi approval | `NotifikasiService` → `inbox`/`inbox_msg` v2 + push mock FCM via Queue (D10, D11); regression tidak ke `disiplin_ticket*` |
| C-07 | Tugas Belajar | CRUD + approval PIC jenis 3 (D14) + perpanjangan (`riwayat_tb_perpan`) + `bebasPresensiPenuh()` (D15) |
| C-08 | Izin Belajar | CRUD + approval PIC jenis 4 (D14) + surat IB |
| C-09 | PMK | Pengajuan `riwayat_pmk_req` + proses PIC jenis 5 (D16) + riwayat `riwayat_pmk` (lihat/arsip) |
| C-10 | Dashboard status layanan + PIC | `GET layanan/status` gabungan cuti/TB/IB/PMK + CRUD `petugas_layanan` (D17) |
| C-11 | Frontend `features/layanan` | Halaman per modul, pill badge, stepper, ApprovalDialog, kalkulator kuota real-time, menu per role, `ACTIVE_PHASE = 4` di akhir |
| C-12 | Unit test & verifikasi | `tests/Layanan/` kuota boundary 24, state machine + skip, efek final hanya final, single end node, notifikasi tidak ke ticketing, 403 per role, `check.sh`/CI hijau |

### 1.2 DI LUAR scope (DILARANG dikerjakan atau dilaporkan sebagai bagian Fase 4)

| Di luar scope | Milik | Catatan untuk Fase 4 |
|---|---|---|
| Presensi & tukin (D-06 "TB penuh bebas presensi", potongan cuti) | Fase 5 | Fase 4 hanya **menyediakan** `bebasPresensiPenuh(nip, tanggal)` dan data `riwayat_cuti.status = 1` (§8). DILARANG mengubah `Libraries/Presensi/**` |
| Adapter real BSrE (H-01/H-02), FCM real & registrasi token `register_fb_token` (H-04), SIMAPI/SIASN | Fase 6 | Fase 4 hanya memakai **mock** (F0-15, F0-17). DILARANG memanggil layanan eksternal apa pun |
| Pusat Notifikasi & Inbox UI (F-08: `hr/notification/index, need_approval, ah`), sortir impor inbox (F-02) | Fase 7 | Fase 4 menulis `inbox`/`inbox_msg` saja; **tidak** membuat endpoint/UI pusat notifikasi |
| Halo Simpeg UI & logika tiket (E-xx), `support_officer`/`officer_category` | Fase 8 | Fase 4 hanya mendesain **skema** `disiplin_ticket`/`disiplin_ticket_msg` (+ `question_category`) di C-01 |
| Manual Testing **MT-00x / MTC-0xx** (MTC-026/027/028/029/037/044) | Fase tersendiri (MT) | DILARANG dimasukkan ke task list, prasyarat, status, atau ringkasan Fase 4. MTC hanya referensi |
| Survei kepuasan layanan (`rated`, `skl`), KARPEG/KARISKARSU memakai `petugas_layanan` | F-11 / CR terpisah | Kolom `rated` [K] disimpan apa adanya; tidak ada logika survei. Perpindahan PIC Karpeg/Kariskarsu (Fase 3, `Definisi/Karpeg.php:22`) = CR terpisah, bukan Fase 4 |
| `regenerate_sc` (admin) & cron `fix_regen_cuti` | — | Tidak ada di Matriks/DoD → tidak dikerjakan (lihat §12 Q-17) |
| Ekspor tabel Status Layanan (tombol Export mockup) | — | Tidak ada DoD → tidak dibuat |
| Riwayat Organisasi (disebut SOP Fase 4) | Fase 3 B-17 (DONE) | K-19 — tidak disentuh |

---

## 2. Aturan proyek WAJIB

Sumber: `AGENTS.md` (`origin/main`), `CLAUDE.md` → `@AGENTS.md`, `docs/fase3/README.md`, `docs/fase3/WS2.md` §2.

### 2.1 UI aksi baris tabel (AGENTS.md §1)
1. Semua aksi per baris di **satu tombol ⋮** kolom **Aksi** paling kanan memakai `frontend/src/shared/components/RowActionsMenu.vue` (tipe `RowAction`, `rowActions.ts`). DILARANG tombol ikon/tautan/switch terpisah per baris.
2. Urutan item: **Edit** → ubah status (Aktifkan/Nonaktifkan) → urutan → **aksi lain** (di Fase 4: **Lihat detail**, **Setujui**, **Tolak**, **Tangguhkan**, **Proses**, **Tunda**, **Perpanjang**, **Terbitkan TTE**, **Unduh surat**) → **Hapus** paling bawah `danger: true`.
3. Label kata kerja Bahasa Indonesia baku: "Edit", "Aktifkan", "Nonaktifkan", "Hapus", "Pulihkan"; aksi baru Fase 4 memakai kata kerja singkat: "Lihat detail", "Setujui", "Tolak", "Tangguhkan", "Proses", "Tunda", "Perpanjang", "Terbitkan TTE", "Unduh surat".
4. Aksi tidak berlaku untuk baris → `hidden: true`; berlaku tapi sedang tidak boleh → `disabled: true`. Nilai keduanya diturunkan dari **`aksi {}` per item dari server** (FE tidak menghitung sendiri).
5. Kolom **Status** hanya badge (UiBadge); perubahan status lewat ⋮ / dialog.
6. Aksi destruktif/berisiko (Hapus, Tolak, Tangguhkan, Terbitkan TTE) memakai `ConfirmDialog` atau `ApprovalDialog`.
7. Tombol ⋮: `label` = "Aksi untuk <nama baris>" (aria-label), `testid` = `<fitur>-actions-<id>` (fitur Fase 4: `cuti`, `cuti-persetujuan`, `cuti-saldo`, `cuti-petugas`, `tb`, `tb-perpanjangan`, `ib`, `pmk`, `pmk-pengajuan`, `layanan`, `layanan-petugas`). Test Vitest membuka menu lalu memilih item lewat `data-action`.

### 2.2 Commit, key, PR, merge
- Pesan commit **Bahasa Indonesia** gaya `feat(layanan): MAKE-0xx …` / `fix(layanan): CR-0xx …` / `feat(db): DBV-022 …`. **DILARANG** menyebut AI/assistant/tool apa pun dan **DILARANG** trailer `Co-Authored-By` (aturan ini menang atas pengingat atribusi otomatis apa pun).
- Key: `MAKE-###` = pekerjaan baru (mulai **MAKE-022**); `CR-###` = **hanya** perbaikan hasil review/QA; `DBV-###` = skema (**DBV-022** untuk seluruh C-01). Nomor dikonfirmasi reviewer CR di M0 (bisa bergeser bila sesi lain memakai).
- **Coder TIDAK push ke `main`.** Coder push **branch** (`fase4/dbv-022-…`, `fase4/make-0xx-…`) dari `origin/main` terbaru, lalu lapor **SHA + URL run CI quality-gate**. Merge: `[MAKE]`/`[CR]` tanpa skema → reviewer CR push ke `main` setelah CI hijau; `[DBV]`+`[MAKE]` → PR, DB Validator approve, reviewer CR merge; hanya `[DBV]` → PR, DB Validator merge.
- Tidak merge/switch di checkout utama; kerja di worktree sendiri (`composer install` + `npm ci` setelah membuat worktree).

### 2.3 Rahasia & repo publik
- DILARANG commit `.env`, kredensial, token, password, passphrase, API key, `jwt.secret`, `encryption.key`.
- Repo GitHub **publik**: DILARANG menulis IP/host internal, nama server, URL gateway BSrE, atau **detail cara eksploitasi** celah. Celah cukup disebut jenisnya + rujukan kartu **ISSUE-048** (D19).

### 2.4 Quality gate
- Gate penuh = **CI `quality-gate`** (`.github/workflows/quality-gate.yml`: PHPStan L5 + CS-Fixer, frontend `npm run check`, PHPUnit ter-shard di **MySQL 8.0** dan **MariaDB 10.4**). Lapor hanya SHA yang CI-nya **hijau**.
- Lokal cukup **gate cepat**: `cd backend && composer analyse && composer cs-check`; `cd frontend && npm run check` bila FE berubah; PHPUnit terarah (`vendor/bin/phpunit --no-coverage tests/Layanan/...`). `./check.sh` dijalankan detached bila perlu (AGENTS.md §3), satu gate penuh per mesin.
- File test baru di `backend/tests/**` otomatis masuk shard CI (`tests/_support/ci/shard.php`).

### 2.5 Test database
- Test ber-DB **wajib** `extends Tests\Support\DatabaseTestCase` (`backend/tests/_support/DatabaseTestCase.php:40`); seed lewat `$seed`.
- `#[Group('db-isolasi-penuh')]` untuk DDL (`down()/up()` migration, CREATE/ALTER/DROP/TRUNCATE, trigger, AUTO_INCREMENT), koneksi DB kedua, **worker queue**, FULLTEXT. Gagal "memutus bingkai transaksi uji" → tambah tanda, DILARANG melonggarkan assertion. Atribut PHPUnit → pakai `#[DataProvider]`.
- DB scratch bernama eksplisit `simpeg_v2_t_<nama>` di instance MySQL test **port 3307** (`tools/mysql-test/`). DILARANG menyentuh MySQL Laragon 3306 / DB dev `simpeg_v2`. DILARANG menyalin `.env` dev ke worktree.
- Akun yang dibuat lewat `AccountProvisioner` punya `must_change_password = 1` (MAKE-020) → di fixture set `0`, kalau tidak endpoint menjawab 403 `password_change_required` (contoh `tests/Auth/WajibGantiPasswordTest.php:159`).

### 2.6 Skema
- Skema ikut D1 + label [K]/[V2]/[I]; collation tabel `utf8mb4_unicode_ci` (collation per kolom tidak ditulis); FK **ON DELETE RESTRICT ON UPDATE RESTRICT** dengan **nama FK legacy** (V1, D1 CASCADE/SET NULL diganti); kolom NIP ber-FK VARCHAR(30) (V4); trigger legacy **tidak** dibawa (logika pindah ke service, V3); CHECK domain status [V2]; AUTO_INCREMENT awal/ROW_FORMAT tidak ditulis; kolom audit diisi aplikasi (UTC, `id_pengguna`).
- Status: master **1/2/10**; status riwayat/layanan **ikut produksi** (cuti 0/1/2/3/4 D1; TB/IB 0/1/2/3; PMK req 0/1/2/3).
- Migration yang sudah ada di `main` **DILARANG diedit**; koreksi via migration baru + dokumen DBV. FK yang datanya bisa yatim → `app/Database/MigrationsDitahan/` dengan pra-cek yatim **fail-closed** (pola DBV-019/DBV-020).
- Setiap kolom NIP baru (`nip`, `nip_*`, `*_nip`, dan kolom berisi NIP seperti `riwayat_cuti.ketua_tim`) **wajib** diklasifikasi di `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php` dan registry `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`; cakupan koreksi NIP (`NipCascadeService`) diperluas ke tabel Fase 4.
- Dokumen review: `backend/docs/db-review/DBV-022-layanan-fase4-schema.md` + test skema `information_schema` (kolom, tipe, NULL, default, PK, index, FK + aksi, collation, COMMENT, error 1452/1451/1062) + siklus `down()`/`up()`.

### 2.7 Kontrak beku = aditif
- Kontrak API/komponen Fase 0–3 yang sudah di `main` (README kontrak `Controllers/Api/Kepegawaian/README.md`, `AtasanLangsung::untuk()`, `RiwayatEngine`, `ApprovalDialog`, `approvalActions.ts`, `StatusBadge`, `statusRiwayat.ts`, `PegawaiScope`, `AttachmentService`) **hanya boleh ditambah (aditif)**, DILARANG diubah perilakunya. Butuh varian → buat komponen/fungsi baru di Modul C (mis. `cutiStatus.ts`), bukan mengubah yang lama.

---

## 3. Glosarium & label

| Istilah | Arti di Fase 4 |
|---|---|
| **[K]** | Ikut legacy apa adanya (kode prod / DDL D1), dengan rujukan berkas:baris |
| **[V2]** | Deviasi v2 yang disengaja; alasan + keputusan dicatat |
| **[I]** | Inferensi dari kode bila DDL/kode tidak eksplisit; wajib disebut di dokumen DBV/laporan |
| **AL** | Atasan Langsung pemohon cuti; ditentukan otomatis port `getAL_es2` (FH:1700-~1945) → `riwayat_cuti.nip_atasan_langsung` |
| **PyB** | Pejabat Yang Berwenang memberi cuti; dipilih pemohon dari daftar `pa_layanan` (`id_jenis_pa = 1`, `L:12-279`) → `riwayat_cuti.nip_yang_menyetujui` |
| **PIC** | Petugas layanan = baris `petugas_layanan` `status` aktif per `id_jenis_layanan` (3 TB, 4 IB, 5 PMK); approver TB/IB/PMK (D14, D16). Ditunjuk role 1 |
| **Petugas Approval Cuti** | Aturan pemetaan pemohon → PyB di `pa_layanan` (bukan orang per baris), CRUD role 1/3 |
| **Langkah tunggal** | Pengajuan cuti yang AL-nya kosong **atau** `nip_atasan_langsung == nip_yang_menyetujui`: satu keputusan langsung final (D2, `L:2645-2651`, `L:3770-3799`) |
| **N / N-1 / N-2** | Saldo cuti tahunan tahun acuan (`pegawai_sisa_cuti.sisa_cuti`), sisa bawaan 1 tahun lalu (`sisa_cuti_n1`), 2 tahun lalu (`sisa_cuti_n2`) |
| **Utang cuti** | Porsi cuti tahunan yang jatuh di tahun berikut dan sudah dipakai (`utang_cuti`, maks 12) [K] D7 |
| **Ditangguhkan** | Status cuti 4 (hanya Tahunan): saldo dipotong dan dicatat di `sisa_cuti_ditangguhkan` untuk dibawa [K] D7 |
| **Efek final** ("snapshot" di DoD) | Efek yang hanya terjadi saat transisi ke status final setuju: potong saldo, CLTN → `pegawai.id_jenis_status`, PDF surat (cuti, D4); `pegawai.id_jenis_status` TB (D15); surat IB. Tidak ada tabel `pegawai_cuti` |
| **Final approval** | Cuti: transisi ke **1** (Disetujui) atau **4** (Ditangguhkan). TB/IB: ke 1. PMK: ke 1 (Selesai) |
| **Single end node** | Setiap alur yang dimulai **pasti** bisa berakhir di status final (cuti 1/2/4; TB/IB 1/2; PMK 1/2) tanpa status mati; status final tidak punya transisi keluar (kecuali hapus) |
| **Tunda pengingat** (`skip_al`/`skip_pyb`) | Penanda "sembunyikan pengingat persetujuan sampai login berikutnya" (D2). **Bukan** melewati tahap |
| **Lingkup** | `PegawaiScopeInterface` (`bolehLihat`/`bolehUbah`/`bolehProses`/`terapkanKeQuery*`) — wajib untuk role 3 (dan role 4/5 di dashboard) |
| **Notifikasi** | Baris `inbox`/`inbox_msg` v2 (satu arah) + job push mock FCM di Queue. **Bukan** `disiplin_ticket*` |
| **Ticketing** | `disiplin_ticket`/`disiplin_ticket_msg` (dua arah, status open/closed) = rumah tiket Halo Simpeg (Fase 8) |

---

## 4. Keputusan user D1–D19 & pemetaan DoD

### 4.1 Pola umum keputusan
Hak **LIHAT** ikut Matriks v2; alur **TULIS/PROSES** ikut legacy [K]; deviasi disengaja ditandai [V2]. Requirement belum jelas → eskalasi ke reviewer CR.

### 4.2 Tabel keputusan

| ID | Keputusan (ringkas, mengikat) | Dipakai di |
|---|---|---|
| D1 | Kode status cuti legacy [K]: **0** Pengajuan, **3** Disetujui AL (menunggu PyB), **1** Disetujui final, **2** Ditolak, **4** Ditangguhkan (hanya Tahunan). DoD dinilai semantik (§4.3). Fase 5 membaca status 1 | C-01, C-02, C-04, C-10, C-11, C-12 |
| D2 | `skip_al`/`skip_pyb` [K] = tunda pengingat persetujuan sampai login berikutnya. Tahap AL terlewat otomatis bila AL = PyB (NIP sama) atau AL kosong — **itulah arti resmi "skip" di DoD** | C-04, C-11, C-12 |
| D3 | Approver [K]: AL dari `getAL_es2` (es4→es1, Plh/Plt; v2 `AtasanLangsung`), PyB dari `pa_layanan` jenis_pa 1, pegawai eselon I → Menteri (`web_config`). Approver ditentukan **NIP**, bukan role | C-02, C-04 |
| D4 | "Snapshot" = efek final legacy: potong `pegawai_sisa_cuti`, CLTN → `pegawai.id_jenis_status = 3`, surat PDF. Hanya saat transisi ke final (1/4), **tidak pada 3**. Tidak ada `pegawai_cuti` | C-03, C-04, C-05, C-12 |
| D5 | Hak cuti: LIHAT role 1,2,3,6,7 (3 dalam lingkup); TULIS/PROSES [K]: ajukan role **2 & 7** (role 6 TIDAK bisa), approver = NIP AL/PyB, input admin role **1** (status 0 atau 1). Role 3 lihat saja | C-02, C-04, C-11 |
| D6 | Hapus cuti = **hapus fisik** [K] + `audit_logs`; refund saldo diperbaiki [V2] (tidak boleh negatif). Pegawai hanya boleh hapus status ∉ {1,3,4} | C-02, C-03 |
| D7 | Ditangguhkan (4) & `utang_cuti` ikut legacy [K] dengan aturan kuota D8 | C-03, C-04 |
| D8 | Kuota [V2]: N = 12 hari kerja, N-1 maks 6, N-2 maks 6, total maks 24; **SATU** algoritma potong N-2 → N-1 → N di `KuotaCutiService`; saldo awal diimpor dari `pegawai_sisa_cuti`. Unit test boundary 24 & >24 | C-03, C-12 |
| D9 | `calculate_lc` dikerjakan di Fase 4: lama = hari kerja − `hari_libur` status 1 (cuti bersama tidak memotong kuota [K]); dipakai C-02/C-11 real-time | C-02, C-03, C-11 |
| D10 | `inbox`/`inbox_msg` v2 = desain BARU notifikasi satu arah (tanpa open/closed); `disiplin_ticket(_msg)` = ticketing dua arah (rumah tiket Halo Simpeg legacy `inbox*`, impor Fase 8). Direview DBV sekaligus di C-01. Regression: notifikasi tidak pernah ke `disiplin_ticket` | C-01, C-06, C-12 |
| D11 | Penerima: pengaju (setiap perubahan status; tolak + alasan), approver berikutnya ("perlu persetujuan"), tembusan ketua tim [K] (`riwayat_cuti_notif_kt`, `ketua_tim` dipisah `***`); push mock FCM lewat Queue. Berlaku juga TB/IB/PMK (PIC menerima pengajuan baru, pengaju menerima keputusan) | C-06, C-07..C-09 |
| D12 | E-sign oleh Admin role 1/3 (lingkup) setelah final; MOCK adapter F0-15; passphrase tidak pernah di URL | C-05 |
| D13 | Surat di `document_attachment` (kode `jenis_rwy` baru) + `esign_hist`; setiap percobaan e-sign sukses/gagal dicatat **di luar transaksi** | C-01, C-05 |
| D14 | Approver TB/IB = PIC (`petugas_layanan` jenis 3/4) [K], satu langkah, berujung Disetujui/Ditolak; bug "pengajuan di luar unit kepegawaian tidak muncul di antrean PIC" diperbaiki [V2] | C-07, C-08 |
| D15 | `status_penuh` TB dihitung: `bebasPresensiPenuh(nip, tanggal)` = TB disetujui & tanggal dalam rentang TB atau perpanjangan; untuk Fase 5 D-06. Perpanjangan = baris terpisah terhubung TB induk. TB disetujui mengubah `pegawai.id_jenis_status` bila hari ini dalam rentang [K] | C-07, C-12 |
| D16 | PMK [K]: approval PIC jenis 5 → Disetujui/Ditolak (single end node), **tanpa** efek ke masa kerja, **tanpa** salin ke `riwayat_pmk`. PMK hanya PNS (role 2 / jenis_pegawai 1) | C-09 |
| D17 | Dashboard Status Layanan [V2]: pegawai melihat semua pengajuannya (cuti/TB/IB/PMK) + stepper + nama PIC; admin 1/3 memantau dalam lingkup; role 4/5 lihat; PIC ditunjuk Super Admin (`petugas_layanan` CRUD). Mockup `LayananStatusPage` (`67fa767`) = acuan visual saja | C-10, C-11 |
| D18 | Satu unit DBV C-01: `riwayat_cuti`, `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`, `inbox`, `inbox_msg`, `disiplin_ticket`, `disiplin_ticket_msg`, `pegawai_sisa_cuti`, `jenis_cuti`, `pa_layanan`, `jenis_pa`, `jenis_layanan`, `petugas_layanan`, `riwayat_cuti_notif_kt`, `question_category`, `esign_hist`, `firebase_token`, + kode `jenis_rwy` baru. Tanpa migration sebelum DBV setuju; migration di `main` tidak diedit | C-01 |
| D19 | Celah keamanan legacy **DIBIARKAN** seperti legacy, dicatat kartu WARNING **ISSUE-048** (A-01/E3, A-03, A-04, E4, P-1/P-4, E1/E2/E7/E8/E13). TETAP BERLAKU: JWT, `Role::filter` per route, PegawaiScope, 403/404; state machine ketat + single end node (menutup A-02), satu algoritma kuota (A-11), refund benar (A-08), `esign_hist` tidak silent-fail (E5/E6). Coder TIDAK menambah penjaga lain dan TIDAK memperluas celah | Semua; §7.9 |

### 4.3 Pemetaan DoD Trello → arti semantik (acuan penilaian QA)

DoD Trello C-04/QAFUNC-005 ditulis dengan kode lain. QA **WAJIB** menilai memakai kolom "Arti v2":

| Teks DoD Trello | Arti v2 (kode yang diuji) | Dasar |
|---|---|---|
| "Pengajuan (0)" / "Diajukan" | `status = 0` (`status_al = 0`, `status_pyb = 0`) | D1 |
| "Atasan Langsung (1)" / "approval atasan langsung" | `status = 3` (AL setuju, menunggu PyB), `status_al = 1` | D1 |
| "Pejabat Yang Berwenang (2)" / "final/PyB" | `status = 1` (Disetujui final) — dan `status = 4` (Ditangguhkan, final, hanya Tahunan) | D1, D7 |
| "penolakan → 3" / "Ditolak" | `status = 2` + `reason_note` (+ `reason_note_al`/`reason_note_pyb`) | D1 |
| "skip_al/skip_pyb benar melewati step" | (a) tahap AL **terlewat otomatis** bila AL kosong atau NIP AL = NIP PyB → status 0 langsung ke final; (b) `skip_al`/`skip_pyb` = tunda pengingat, **tidak** mengubah status | D2 |
| "snapshot sync HANYA status 2, tidak status 1" | Efek final (§6.4.5) **hanya** pada transisi ke 1/4; **tidak** pada transisi ke 3 maupun 2 | D4 |
| "Selesai/Diarsipkan" | Status final: cuti 1/2/4, TB/IB 1/2, PMK 1/2. Tidak ada status arsip baru | §6, K-08 |
| "status_penuh=1 ter-flag" (TB) | `bebasPresensiPenuh(nip, tanggal) === true` dan field turunan `status_penuh = 1` di respons TB | D15 |
| "TB/IB/PMK status pending" | `status = 0` | [K] D1:6585, :4936, :6246 |
| "notifikasi ke inbox/inbox_msg" | baris `inbox`/`inbox_msg` v2 (§6.6) | D10 |

---

## 5. Matriks role × endpoint Modul C (v2)

Prefix semua path `api/v1/`; filter `jwt` di level grup; `Role::filter(...)` per route = kolom "Route"; aturan lebih rinci (kolom "Tulis/Proses") ditegakkan di **service** dengan 403/404 sesuai §7.10. Route didaftarkan di berkas baru `backend/app/Config/RoutesLayanan.php` (ditambahkan ke `Config\Routing::$routeFiles`, pola `RoutesPegawai.php`). Role: 1 Super Admin, 2 Pegawai PNS, 3 Admin Satker, 4 Admin View Eselon I, 5 Menteri, 6 PTT, 7 PPPK, 8 Pimpinan. Role di luar kolom "Route" → **403**.

### 5.1 Cuti (C-02..C-05)

| Method & path v2 | Legacy | Route | Lihat | Tulis / Proses (service) | Catatan |
|---|---|---|---|---|---|
| `GET cuti?mode=saya\|persetujuan\|admin&status=&id_jenis_cuti=&tahun=&search=&id_unit=&id_satker=&page=&per_page=` | `cuti/index`, `list_ad`, `L_notification.php:176-208` | 1,2,3,6,7 | `saya`: milik NIP pemanggil; `persetujuan`: NIP pemanggil = AL pada status 0 (dua langkah) atau = PyB pada status 3 / status 0 langkah tunggal; `admin`: role 1 semua, role 3 `terapkanKeQuery` lingkup, data `YEAR(tgl_mulai) >= tahun−2` [K] `L:424-467` | — | `mode=admin` untuk role 2/6/7 → 403. Bawaan: role 1/3 `admin`, lainnya `saya` |
| `GET cuti/opsi?nip=` | `form()` `L:509-516`, `getListPYB`, `getAL_es2` | 1,2,3,6,7 | — | — | `{jenis_cuti[] (sudah disaring setJenisCuti), atasan_langsung {nip,nama,jabatan}\|null, pyb[] {nip,nama,jabatan}, langkah_tunggal, saldo {...}, tgl_akhir_maks, ketua_tim[] (pegawai aktif), can_create}`; `nip` hanya role 1 (input admin) |
| `POST cuti/hitung-lama` | `services/local/calculate_lc` (`Local.php:2147-2209`) | semua login (jwt saja) | — | — | Body `{id_jenis_cuti, tgl_mulai, tgl_akhir?, durasi?, nip?, id_riwayat_cuti?}` → `{lama_cuti, satuan_lama_cuti, tgl_akhir, hari_libur[], kuota}` (§6.3). `nip` hanya role 1 |
| `POST cuti` | `add`, `add_ad` | 1,2,3,6,7 | — | role 2, 7: untuk diri sendiri, status dipaksa 0; role 1: `nip` pegawai + `status` 0\|1; role 3, 6 → 403 | Multipart (berkas `file_bukti`) → 201 |
| `GET cuti/{id}` | `detail` (C:378-494) | 1,2,3,6,7 | pemilik, AL, PyB, ketua tim (CC), role 1, role 3 dalam lingkup; lainnya **404** | — | + `aksi {ubah, hapus, proses, tunda, esign, unduh_surat}`, `tahap[]`, `lampiran[]`, `surat[]` |
| `PUT cuti/{id}` (multipart: `POST` + `_method=PUT`) | `edit`, `edit_ad` | 1,2,3,6,7 | — | pemilik (2/7): status 0 dan `tgl_mulai >= hari ini` (C:313-327); role 1: status 0 (C:107-110) | Lain → 403 (terlihat) / 404 (tak terlihat); status salah → 422 `errors.status` |
| `DELETE cuti/{id}` | `delete` (C:496-569) | 1,2,3,6,7 | — | pemilik (2/7): status ∈ {0,2}; role 1: semua status; role 3: 403 (D5, A-15) | Hapus fisik + audit + refund + kembalikan CLTN + hapus berkas (§6.2.6) |
| `POST cuti/{id}/process` | `process`, `process_esign`, `process_cuti` | 1,2,3,6,7 | — | NIP pemanggil = AL (status 0, dua langkah) / PyB (status 3, atau status 0 langkah tunggal) [K] C:584-591 + state machine | `{aksi: setujui\|tolak\|tangguhkan, reason_note}` (§6.4) |
| `POST cuti/{id}/tunda` | `skip_cuti` (C:922-1004) | 1,2,3,6,7 | — | AL (status 0) / PyB (status 3 atau langkah tunggal), sebelum `tgl_mulai` 12:00 | Set `skip_al`/`skip_pyb = 1` (§6.4.7) |
| `GET cuti/pengingat` | popup MYC:327-457 | 1,2,3,6,7 | daftar `persetujuan` milik pemanggil yang belum ditunda | — | Untuk dialog pengingat setelah login (§6.4.7) |
| `GET cuti/{id}/lampiran` | `file_bukti` | 1,2,3,6,7 | yang boleh melihat detail | — | Unduh lampiran (`attachment`, `nosniff`, `no-store`); path arsip legacy → 404 |
| `GET cuti/{id}/surat` | `file_surat_cuti` | 1,2,3,6,7 | yang boleh melihat detail; hanya status 1/4 | — | Unduh surat terakhir (bertanda tangan bila ada, kalau tidak versi belum TTE) |
| `POST cuti/{id}/esign` | `process_esign/{id}` (Matriks role 1,3) | **1, 3** | — | role 1; role 3 `bolehProses` lingkup; status ∈ {1,4} | Body `{passphrase}` (JSON body, **tidak pernah di URL**) (§6.5) |
| `GET cuti/saldo?tahun=&search=&id_unit=&id_satker=` | `sc` (C:1174-1204) | 1, 3 | role 1 semua; role 3 lingkup | — | Role 4/5 legacy boleh, Matriks tidak → 403 (D5) |
| `POST cuti/saldo`, `PUT cuti/saldo/{id}`, `DELETE cuti/saldo/{id}` | `add_sc/edit_sc/delete_sc` | 1, 3 | — | role 1; role 3 lingkup | Hapus fisik [K] + audit (§6.3.6) |
| `GET cuti/petugas-approval` | `list_off` (C:1009-1033) | 1, 3 | role 1 semua; role 3 `id_unit_pemohon` = unit pemanggil [K] `L:4058-4081` | — | |
| `POST cuti/petugas-approval`, `PUT …/{id}`, `DELETE …/{id}` | `add_off/edit_off/delete_off` | 1, 3 | — | role 1; role 3 unit sendiri (edit/hapus [K]); `edit` hanya baris status 1 [K] C:1103 | Hapus fisik [K] + audit (§6.4.8) |

### 5.2 Tugas Belajar (C-07)

| Method & path v2 | Legacy | Route | Lihat | Tulis / Proses | Catatan |
|---|---|---|---|---|---|
| `GET tb?mode=saya\|pic\|admin&status=&search=&page=&per_page=` | `tb/index`, `list_ad`, `L_notification.php:213-228` | **1,2,3,6** | `saya` milik sendiri; `pic` = semua pengajuan status 0 bila pemanggil PIC TB aktif (tanpa batas unit, D14 [V2]); `admin` role 1 semua / role 3 lingkup | — | **Role 7 → 403** (DoD 070) |
| `GET tb/opsi?nip=` | `form` L_tb.php:66-72 | 1,2,3,6 | — | — | jenjang (`id_jenjang_pendidikan > 3`), default `up`, snapshot pegawai, `can_create` |
| `POST tb` | `add` / `add_ad` | 1,2,3,6 | — | role 2, 6: diri sendiri, status 0; role 3: `nip` dalam lingkup, status 0; role 1: `nip` PNS, status 0\|1 | Multipart 4 berkas (§6.7) → 201 |
| `GET tb/{id}` | `detail` Tb.php:326-473 | 1,2,3,6 | pemilik, PIC TB aktif, role 1, role 3 lingkup; lainnya 404 | — | + `status_penuh`, `perpanjangan[]`, `aksi {}`, `tahap[]` |
| `PUT tb/{id}` | `edit`, `edit_ad` | 1,2,3,6 | — | pemilik: status 0 & `tgl_mulai > hari ini` [K Tb.php:174-243]; role 3: status 0 + lingkup; role 1: semua status kecuali 2 [K list_ad.php:181-187] | |
| `DELETE tb/{id}` | `delete` Tb.php:475-547 | 1,2,3,6 | — | pemilik: status 0; role 3: status 0 + lingkup; role 1: semua | Hapus fisik [K] + audit + hapus perpanjangan anak eksplisit + kembalikan `id_jenis_status` (§6.7) |
| `POST tb/{id}/process` | `process` Tb.php:549-612 | 1,2,3,6 | — | PIC TB aktif; status 0 | `{aksi: setujui\|tolak, reason_note, no_sk, tgl_sk, ds_tb, no_ds_tb, tgl_ds_tb?, perihal_ds_tb?}` + berkas `file_surat_tb` |
| `POST tb/{id}/perpanjangan` | `extend` Tb.php:614-674 | 1,2,3,6 | — | **role 1 saja** [K]; role lain 403 | `tgl_mulai_perpan`, `tgl_akhir_perpan`, berkas `file_sperpan` |
| `PUT tb/perpanjangan/{id}`, `DELETE tb/perpanjangan/{id}` | `edit_extend`, `delete_extend` | 1,2,3,6 | — | role 1 saja | `PUT` hanya dua tanggal [K] |
| `GET tb/{id}/lampiran/{id_attachment}`, `GET tb/{id}/surat` | berkas, `detail?format=pdf` | 1,2,3,6 | yang boleh melihat detail | — | Surat TB dirender on-the-fly dari templat `print/surat_tb` [K] |

### 5.3 Izin Belajar (C-08)

| Method & path v2 | Legacy | Route | Lihat | Tulis / Proses | Catatan |
|---|---|---|---|---|---|
| `GET ib?mode=saya\|pic\|admin…` | `ib/index`, `list_ad`, `L_notification.php:230-245` | **1,2,3,7** | sama pola TB (PIC IB jenis 4) | — | **Role 6 → 403** (DoD 071) |
| `GET ib/opsi?nip=` | `form` | 1,2,3,7 | — | — | |
| `POST ib` | `add`, `add_ad` | 1,2,3,7 | — | role 2, 7: diri sendiri; role 1, 3: `nip` (3 lingkup); status **selalu 0** [K Ib.php:128-171] | 3 berkas wajib semua role [K L_ib.php:705] |
| `GET ib/{id}` | `detail` | 1,2,3,7 | pemilik, PIC IB, role 1, role 3 lingkup | — | |
| `PUT ib/{id}`, `DELETE ib/{id}` | `edit`, `edit_ad`, `delete` | 1,2,3,7 | — | aturan sama TB (tanpa ubah status oleh admin) | Hapus fisik [K] + audit |
| `POST ib/{id}/process` | `process` Ib.php:548-613 | 1,2,3,7 | — | PIC IB aktif; status 0 | Setujui → Surat IB PDF otomatis (§6.8) |
| `GET ib/{id}/lampiran/{id_attachment}`, `GET ib/{id}/surat` | | 1,2,3,7 | yang boleh melihat | — | |

### 5.4 PMK (C-09)

| Method & path v2 | Legacy | Route | Lihat | Tulis / Proses | Catatan |
|---|---|---|---|---|---|
| `GET pmk?nip=` (riwayat PMK final) | `pmk/index` Pmk.php:11-79 | **1,2,3** | 2: diri sendiri; 3: lingkup; 1: semua | — | Role selain 1,2,3 → 403 (DoD 072) |
| `PUT pmk/{id}` (tambah arsip SK), `DELETE pmk/{id}`, `DELETE pmk/{id}/arsip/{id_attachment}` | `edit`, `delete`, `delete_da` | 1,2,3 | — | role 1 saja; arsip minimal tersisa 1 [K Pmk.php:178-183] | Arsip di `document_attachment` kode 42 (sudah ada) |
| `GET pmk/pengajuan?mode=saya\|pic\|admin&status=…` | `req`, `req_ad`, `list_pic` | 1,2,3 | `saya` (role 2); `pic` (PIC jenis 5, semua tanpa batas unit [K P-19]); `admin` role 1 semua, role 3 lingkup lihat saja | — | |
| `GET pmk/opsi?nip=`, `POST pmk/hitung-mker` | `peg_selected_pmk_req`, `calc_mker_pmk_req` (Local.php:4537-4603) | 1,2,3 | — | — | `tmt_cpns`, `tgl_awal_maks`; mker = `diff` y/m (hari diabaikan) [K] |
| `POST pmk/pengajuan` | `add_req`, `add_req_ad` | 1,2,3 | — | role 2 (PNS) diri sendiri; role 1 `nip` PNS; role 3 → 403 | status 0; 4 berkas (§6.9) |
| `GET pmk/pengajuan/{id}` | `detail_req` | 1,2,3 | pemilik, PIC PMK, role 1, role 3 lingkup | — | [V2] status 0 boleh dibuka (§12 Q-16) |
| `PUT pmk/pengajuan/{id}`, `DELETE pmk/pengajuan/{id}` | `edit_req(_ad)`, `delete_req(_ad)` | 1,2,3 | — | role 2 pemilik (lingkup platform); role 1; hapus ditolak bila status 1 [K Pmk.php:357, :693] | Hapus fisik [K] + audit |
| `POST pmk/pengajuan/{id}/process` | `process_req`, `edit_req_pic` | 1,2,3 | — | PIC PMK aktif; status 0 atau 3 | `{aksi: proses\|setujui\|tolak, reason_note}` (§6.9) |

### 5.5 Dashboard & PIC (C-10)

| Method & path v2 | Legacy | Route | Lihat | Tulis | Catatan |
|---|---|---|---|---|---|
| `GET layanan/status?jenis=&status=&id_unit=&id_satker=&search=&page=&per_page=` | `layanan/index` (halaman kosong legacy) | **1–7** | role 2/6/7: semua pengajuan sendiri dari modul yang boleh dilihat role itu; role 1: semua; role 3: lingkup; role 4/5: `terapkanKeQuery` lingkup lihat PegawaiScope | — | [V2] D17; role 8 → 403 |
| `GET layanan/jenis` | `jenis_layanan` | 1–7 | semua | — | Master `jenis_layanan` aktif |
| `GET layanan/petugas?id_jenis_layanan=` | `layanan/list_off` | 1–7 | semua (nama/NIP/jabatan PIC aktif) | — | LIHAT ikut Matriks |
| `POST layanan/petugas`, `PUT layanan/petugas/{id}`, `DELETE layanan/petugas/{id}` | `add_off/edit_off/delete_off` (Layanan.php:103-222) | 1–7 | — | **role 1 saja** [K]; lain 403 | Kandidat: PNS aktif satker kepegawaian [K L_layanan.php:38-46] |

### 5.6 Endpoint terkait yang TIDAK dibuat di Fase 4

| Endpoint | Milik | Alasan |
|---|---|---|
| `hr/notification/index, need_approval, ah` | F-08 Fase 7 | Fase 4 hanya menulis `inbox*`; antrean approval Fase 4 = `GET cuti?mode=persetujuan`, `tb/ib/pmk …?mode=pic` |
| `hr/services/local/register_fb_token` | H-04 Fase 6 | Tabel `firebase_token` dibuat (D18), endpoint tidak |
| `services/s_esign/check_user`, `sign_pdf` (Internal Service Auth, Mudig) | H-02 Fase 6 | Fase 4 memanggil `EsignGatewayInterface` mock langsung |
| `hr/support/*` | Fase 8 | Skema saja |

---

## 6. Spesifikasi per task

Format setiap subbagian: tujuan · dependency · QASMTASK · file boundary · kontrak · aturan bisnis · state machine · efek · notifikasi · acceptance criteria (AC) · test wajib · DILARANG. ID AC (`AC-xx.n`) dipakai di Lampiran C (keterlacakan) dan laporan QA.

### 6.1 C-01 — Migration layanan + pemisahan inbox vs disiplin_ticket (DBV-022)

**Tujuan.** Menyiapkan seluruh skema Modul C dalam **satu unit review DBV** (D18), termasuk pemisahan `inbox`/`inbox_msg` (notifikasi satu arah, desain baru) dan `disiplin_ticket`/`disiplin_ticket_msg` (ticketing dua arah) **sekaligus**, sebelum coding apa pun yang bergantung skema.

**Dependency.** Fase 3 DONE (sign-off 15-10-2026). **QASMTASK-064.** Actor: DB Validator (review), coder (dokumen + migration setelah disetujui).

**File boundary.**
- Dokumen: `backend/docs/db-review/DBV-022-layanan-fase4-schema.md`; kueri audit baca-saja `backend/docs/db-review/sql/DBV-022-*.sql`.
- Migration baru: `backend/app/Database/Migrations/2026-10-xx-xxxxxx_Create*.php` (satu berkas per kelompok tabel; nama mengikuti pola tanggal repo); FK ditahan: `backend/app/Database/MigrationsDitahan/Add*Fase4*.php`.
- Seed uji: `backend/app/Database/Seeds/Layanan*Seeder.php` (master uji `jenis_cuti`, `jenis_layanan`, `jenis_pa`, `question_category`).
- Registry NIP: `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php` (ubah baris fase 4/6 yang ada; tambah jenis untuk `ketua_tim`), `backend/app/Libraries/Kepegawaian/NipCascadeService.php` (cakupan tabel Fase 4; konstanta `NipCascadeService::FASE` naik ke 4 — test cascade memakai `sampaiFase(NipCascadeService::FASE)`, bukan angka tetap, agar Fase 5A dapat menaikkannya ke 5 tanpa konflik; lihat `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §3.4), `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php`.
- Test skema: `backend/tests/database/Layanan/SkemaLayananTest.php`, `SkemaInboxTicketTest.php`, `MigrasiLayananSiklusTest.php` (`#[Group('db-isolasi-penuh')]`).
- DILARANG menyentuh migration lama di `main` (mis. `2026-09-30-130000_CreateJenisRwy.php`, `2026-09-30-130900_CreateDocumentAttachment.php`).

#### 6.1.1 Daftar tabel (D18) — ringkasan kolom D1 dan usulan

Kolom lengkap ada di DDL D1 (baris disebut) dan disalin ke dokumen DBV-022. Ringkasan di bawah = yang **wajib** ditangani dokumen.

| # | Tabel | D1 | Kolom inti [K] | Usulan v2 untuk diputuskan DBV |
|---|---|---|---|---|
| 1 | `riwayat_cuti` | 4396-4463 | PK `id_riwayat_cuti` INT; `nip` VARCHAR(30) NOT NULL; snapshot pemohon `nama, id_sub_group_jabatan, pangkat, gol_ruang, jabatan, satker, mker_th, mker_bl`; `id_jenis_cuti` NOT NULL, `nama_jenis_cuti` NOT NULL; `tgl_mulai, tgl_akhir` DATE NOT NULL; `lama_cuti` INT NOT NULL; `satuan_lama_cuti` DEFAULT 'Hari'; `no_telp_saat_cuti, alamat_saat_cuti, file_bukti`; snapshot saldo `sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, utang_cuti`; `keterangan`; AL `nip_atasan_langsung` VARCHAR(50), `nama_/jabatan_atasan_langsung, id_jabatan_al, status_al` (0/1/2/4), `reason_note_al`; PyB `nip_yang_menyetujui` VARCHAR(50), `nama_/jabatan_yang_menyetujui, id_jabatan_pyb, status_pyb` (0/1/2/3/4), `reason_note_pyb`; `file_sc_unsign, file_sc_signed_bef, file_surat_cuti`; `keterangan_petugas_approval`; `ketua_tim` TEXT; `status` INT DEFAULT 0 (0/1/2/3/4); `status_regen`; `created_at, updated_at, updated_by, approved_by, reason_note, rated, skip_al, skip_pyb, show_notif, notif_date, show_atasan, show_pejabat, show_history_atasan`; index `id_jenis_cuti_idx, nip_idx, jabatan_atasan_langsung, nip_atasan_langsung, nip_yang_menyetujui, jabatan_yang_menyetujui, nip_status_rated_notif_date`; FK `fk_nip_rwycuti_to_pegawai`, `id_jenis_cuti` | FK RESTRICT (V1). [V2] `nip_atasan_langsung`/`nip_yang_menyetujui` → VARCHAR(30) + FK nullable ke `pegawai.nip` (nama usulan `fk_nip_atasan_langsung_rwycuti_to_pegawai`, `fk_nip_yang_menyetujui_rwycuti_to_pegawai`) — **usul ditahan** di `MigrationsDitahan/` dengan pra-cek yatim fail-closed sampai audit produksi = 0. CHECK [V2] `status` IN (0,1,2,3,4) (tanpa 10: hapus fisik D6), `status_al` IN (0,1,2,4) NULL boleh, `status_pyb` IN (0,1,2,3,4) NULL boleh, `skip_*` IN (0,1), `status_regen` IN (0,1), `rated` IN (0,1,2). Trigger `riwayat_cuti_beDel` (arsip `d_riwayat_cuti`) → `audit_logs` (D6); `riwayat_cuti_beUpd` (show_notif) → service (§6.6). Kolom `file_*` [K] dipertahankan untuk data impor; berkas v2 di `document_attachment` (D13). Index approval opsional `(nip_atasan_langsung, status)`, `(nip_yang_menyetujui, status)` |
| 2 | `riwayat_cuti_notif_kt` | 4699-4709 | PK `(id_riwayat_cuti, nip_kt)`; `status` 0 Belum Dibaca/1 Sudah/2 Delete; `created_at, updated_at`; FK `fk_id_riwayat_cuti_rcnkt_1`, `fk_nip_kt_rcnkt_2` | [K] dibuat (D11 "ikut legacy"); FK RESTRICT; CHECK `status` IN (0,1,2). Tutup status `BelumDiputuskan` di registry (`TabelAnakNip.php:83`) |
| 3 | `jenis_cuti` | 1634-1643 | `id_jenis_cuti` AI, `nama_jenis_cuti` VARCHAR(105), `order`, `status`, audit; **PK komposit** `(id_jenis_cuti, nama_jenis_cuti)` | id **wajib** = legacy 1..6 (Tukin v2 meng-hard-code `StatusHarian::JENIS_CUTI`). Usulan [V2] PK tunggal `id_jenis_cuti` + UNIQUE `nama_jenis_cuti`, CHECK `status` 1/2/10; seed literal 6 baris di migration (pola `jenis_rwy`) |
| 4 | `pegawai_sisa_cuti` | 3576-3592 | PK `id_pegawai_sisa_cuti`; `nip, tahun` YEAR; `sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, sisa_cuti_ditangguhkan, utang_cuti` TINYINT; `digunakan` 0/1; audit; index `nip_tahun` (bukan UNIQUE); FK `fk_nip_psc_to_pegawai` | FK RESTRICT; usulan [V2] UNIQUE `(nip, tahun)` bila audit duplikat = 0; CHECK `digunakan` IN (0,1), nilai saldo ≥ 0 bila audit mengizinkan. Cadangan `pegawai_sisa_cuti_2024…2026` tidak diimpor |
| 5 | `riwayat_tb` | 6544-6604 | PK `id_riwayat_tb`; `nip`; `id_jenjang_pendidikan` TINYINT; snapshot `nama, gol_ruang, pangkat, jabatan, unit, satker, pendidikan_dasar_singkat`; `tgl_mulai, tgl_akhir` NOT NULL; `tgl_mulai_perpan, tgl_akhir_perpan`; `lama_tahun` DECIMAL(10,2); `jenjang_pendidikan_singkat, institusi_pendidikan, jurusan_pendidikan, dalam_negeri, lokasi_pendidikan, lembaga_yang_mendanai` NOT NULL; `up, tembusan`; `file_sp, file_1, file_2, file_3` NOT NULL; `keterangan`; `status_admin_upt, rs_admin_upt, status_admin_deputi, sp_admin_deputi, rs_admin_deputi`; `ds_tb, no_ds_tb, tgl_ds_tb, perihal_ds_tb, no_sk, tgl_sk, file_surat_tb`; `status` 0/1/2/3; audit, `approved_by, reason_note, rated, show_notif, notif_date, show_ua_upt/deputi/biro, show_pl`; FK `fk_id_jenjang_pendidikan_twytb_to_jenjp`, `fk_nip_rwytb_to_pegawai` | FK RESTRICT; `id_jenjang_pendidikan` → INT (V5, PK v2 INT). CHECK `status` IN (0,1,2,3), `status_admin_*` IN (0,1,2) NULL boleh. **Tanpa kolom `status_penuh`** (D15 dihitung). `file_*` NOT NULL [K] vs berkas v2 di `document_attachment` → DBV putuskan (usul [V2] NULL-able, nilai impor tetap). Trigger `beUpd` → service. `status_admin_*`/`show_ua_*` disimpan [K], tidak dipakai alur v2 (D14) |
| 6 | `riwayat_tb_perpan` | 6662-6673 | PK `id_riwayat_tb_perpan`; `id_riwayat_tb` NOT NULL; `tgl_mulai_perpan, tgl_akhir_perpan` NOT NULL; `file_sperpan` NOT NULL; `created_at, created_by`; FK `fk_id_riwayat_tb_tbperpan_to_tb` CASCADE | FK RESTRICT (hapus TB → service hapus perpanjangan eksplisit). Tanpa kolom status (D15; §12 Q-12). Trigger `afIns/afUpd/afDel` (MAX tanggal → induk) → service dalam transaksi yang sama |
| 7 | `riwayat_ib` | 4898-4955 | sama pola `riwayat_tb` tanpa `file_3`, tanpa `*_perpan`; `ds_ib, no_ds_ib, tgl_ds_ib, perihal_ds_ib, file_surat_ib`; `nama` NOT NULL; `updated_at` tanpa ON UPDATE [K] | FK RESTRICT; `id_jenjang_pendidikan` → INT; CHECK `status` IN (0,1,2,3) |
| 8 | `riwayat_pmk` | 6202-6227 | PK BIGINT; `nip`; `id_pns_siasn, no_sk` NOT NULL, `tgl_sk` NOT NULL, `no_bkn, tgl_bkn, instansi, tgl_awal, tgl_akhir, mker_th, mker_bl, dinilai, id_rw_siasn, siasn_flag, siasn_error, siasn_lu`; `status` 1 Ok/2 Fail (status sinkron, bukan approval); audit; FK `fk_rw_pmk_01` | FK RESTRICT; CHECK `status` IN (1,2). Arsip SK di `document_attachment` kode 42 (sudah ada) |
| 9 | `riwayat_pmk_req` | 6232-6259 | PK BIGINT; `nip`; `jenis_pmk` DEFAULT 'Swasta'; `instansi, tgl_awal, tgl_akhir` NOT NULL; `mker_th, mker_bl`; `file_kontrak, file_paklaring, file_surat_phk, file_slip_gaji`; `id_rw_siasn`; `status` **DEFAULT 1** (0 Pengajuan/1 Selesai/2 Ditolak/3 Proses); `reason_note` TINYTEXT; `show_notif, notif_date, created_*, updated_*, approved_at, approved_by`; FK `fk_rriwayat_pmk_req_01` | FK RESTRICT; CHECK `status` IN (0,1,2,3); default 1 [K] dipertahankan atau [V2] 0 (P-16) — DBV putuskan, aplikasi **selalu** mengisi eksplisit. Berkas tetap di kolom [K] (pola `KolomBerkas`) |
| 10 | `disiplin_ticket` | (sumber `inbox` [K] 1369-1393) | rename [K] `inbox`: `id_inbox` PK, `id_question_category`, `subject`, `sender, sender_name, nip` VARCHAR(18), `jabatan, satker, receiver` DEFAULT 0, `receiver_name, allow_reply` 0/1, **`status` 0 Open / 1 Closed**, `is_read, is_read_off` 1/2, `notify_pic` 0/1/2, `notify_at, created_at, updated_at, closed_by`; FK `fk_id_question_category_i_qc` | Skema **final** untuk Fase 8. Collation `0900_ai_ci` → `unicode_ci`; FK RESTRICT ke `question_category`; `nip` → VARCHAR(30) (+ FK ditahan atau `NON_FK` registry — DBV); CHECK domain; `sender/receiver/closed_by` = id pengguna (FK atau tanpa FK, `0` = semua petugas — DBV); hapus lunak 10 [V2] atau [K] (N11) — DBV. Nama PK/kolom `id_inbox` dipertahankan atau rename — DBV |
| 11 | `disiplin_ticket_msg` | (sumber `inbox_msg` [K] 1398-1414) | rename [K] `inbox_msg`: `id_inbox_msg` PK, `id_inbox`, `msg_sender, msg_sender_name, msg_receiver` DEFAULT 0, `msg_receiver_name, msg` TEXT, `attachments` TEXT, `show_notif, is_read, created_at`; FK `fk_id_inbox_im_qc` CASCADE | FK RESTRICT; `id_inbox` NULL → NOT NULL [V2] (audit NULL); trigger `inbox_msg_afIns` → service Fase 8 (dicatat, tidak dibuat di Fase 4) |
| 12 | `inbox` (v2) | **baru [V2]** | — | Notifikasi **satu arah**. WAJIB: tanpa kolom `status` open/closed, tanpa `allow_reply`, tanpa `closed_by`, tanpa balasan/thread. Usulan: `id_inbox` PK, `nip_penerima` VARCHAR(30) (FK pegawai) dan/atau `id_pengguna_penerima`, `modul` (`cuti`/`tb`/`ib`/`pmk` …), `id_jenis_layanan` NULL, `id_rwy` (id baris sumber, tanpa FK — polimorfik), `judul`, `url` (rute FE), `is_read` 0/1, `read_at`, `created_at`; index `(nip_penerima, is_read, created_at)` |
| 13 | `inbox_msg` (v2) | **baru [V2]** | — | Usulan: satu baris per kejadian/perubahan status di bawah satu `inbox` (tanpa balasan): `id_inbox_msg` PK, `id_inbox` FK RESTRICT, `tipe` (1 diajukan, 2 keputusan, 3 perubahan, 4 tembusan), `status_sumber` (status baris sumber saat kejadian), `isi`, `reason_note` NULL, `push_status` (0 antre/1 terkirim/2 gagal/3 tanpa token), `created_at`. DBV boleh menyederhanakan, **asal dua tabel tetap ada dan tetap satu arah** (DoD 064) |
| 14 | `question_category` | 4008-4017 | `id_question_category`, `question_category` VARCHAR(250), `status` 0 Inactive/1 Active, `created_at, updated_at` | Dibuat di C-01 (dirujuk FK `disiplin_ticket`); master → CHECK 1/2/10 dengan remap impor 0→2, atau [K] 0/1 — DBV. Collation → `unicode_ci` |
| 15 | `jenis_layanan` | 1705-1714 | `id_jenis_layanan`, `jenis_layanan`, `table`, `module_name`, `url_process`, `url_pic`, `status` 1/2 | id [K] 1 KARPEG, 2 KARISKARSU, 3 Tugas Belajar, 4 Izin Belajar, 5 Peninjauan Masa Kerja (dari kode legacy, data tidak ada di dump — [I]); `url_*` berisi URL CI3 → diisi path v2 atau dibiarkan [K] — DBV. Seed uji 5 baris |
| 16 | `petugas_layanan` | 3952-3962 | `id_petugas_layanan`, `id_jenis_layanan` (FK SET NULL), `nip` VARCHAR(50) tanpa FK, `status` 0 Inactive/1 Active (kode juga memakai `!= '10'`), `created_at, updated_at`; index `(id_jenis_layanan, nip, status)` | FK RESTRICT; `nip` → VARCHAR(30) + FK (ditahan) atau `NON_FK`; domain `status` [K] 0/1 atau master 1/2/10 dengan remap — DBV; UNIQUE `(id_jenis_layanan, nip)` bila audit = 0 (legacy menolak duplikat di aplikasi, `L_layanan.php:192-242`) |
| 17 | `pa_layanan` | 2429-2471 | `id_pa_layanan`, `id_jenis_pa, id_jenis_pegawai, id_group_jabatan_pemohon, id_sub_group_jabatan_pemohon, gol_ruang_pemohon, id_unit_pemohon, id_group_jabatan_petugas, id_sub_group_jabatan_petugas, id_unit_petugas, id_satker_petugas, id_jabatan, id_jabatan_koord, nip` (JF), `status` 1/2, audit; 11 FK SET NULL ke master G-02 | FK RESTRICT (semua master sudah ada di `main`, tipe kolom = PK master v2); collation `0900_ai_ci` → `unicode_ci`; `nip` VARCHAR(50) → registry; hapus fisik [K] (`L:4357`) vs lunak — DBV |
| 18 | `jenis_pa` | 1733-1741 | `id_jenis_pa` TINYINT, `jenis_pa`, `order`, `status` 1/2, `created_at`, **`udpated_at`** (typo [K]) | Seed 1 = Cuti; typo kolom dipertahankan [K] atau diperbaiki [V2] — DBV |
| 19 | `esign_hist` | 891-907 | PK BIGINT; `nip` NULL (FK SET NULL); `nik` NOT NULL; `nama_lengkap` NOT NULL; `modul` DEFAULT 'Cuti Online'; `id_rw`; `file_pdf_to_sign` NOT NULL; `status` VARCHAR(256) '1' Berhasil/'2' Gagal; `ip_address` VARCHAR(20); `user_agent, keterangan, created_at` | **Dimajukan dari H-01** (registry `TabelAnakNip.php:52` pindah ke fase 4). FK RESTRICT nullable; append-only (tanpa `updated_*`). Putuskan: [V2] kolom `status_code` VARCHAR(10) (kontrak QASMTASK-090) dan peta `message` gateway → `keterangan` [K] (docblock `EsignGatewayInterface.php:13` menyebut `pesan_gateway` yang tidak ada di D1 → perbaiki docblock, aditif); `ip_address` → VARCHAR(45) [V2]?; CHECK `status` IN ('1','2'); index `(modul, id_rw)`; kebijakan PII `nik` |
| 20 | `firebase_token` | 1156-1163 | `nip` NOT NULL, `fcm_token` TEXT, `auth_token` TEXT, `created_at, updated_at` TIMESTAMP; **tanpa PK**; collation `0900_ai_ci` | **Dimajukan** (D18), tabel kosong ([M] Tier 6 tidak dimigrasi). [V2] PK surrogate; `nip` FK RESTRICT; `auth_token` dibawa atau dibuang — DBV. `fb_token` (desktop, per `id_user`) **tidak** dibuat kecuali DBV memutuskan |
| 21 | Kode `jenis_rwy` baru | (seed `2026-09-30-130000_CreateJenisRwy.php:63-85`, 0..42) | — | Migration **baru** (bukan edit) menambah kode, usulan: 43 `cuti` (lampiran pengajuan cuti → `riwayat_cuti`), 44 `surat_cuti` (surat cuti belum TTE & ber-TTE → `riwayat_cuti`), 45 `tb` (lampiran TB), 46 `surat_tb` (SK TB), 47 `tb_perpan` (berkas perpanjangan → `riwayat_tb_perpan`), 48 `ib` (lampiran IB), 49 `surat_ib`. Nomor final & pembeda "belum TTE / ber-TTE" (kolom `tag`?) = DBV. Kode `skl.jenis_rwy` (1 Cuti, 4 TB, 5 IB) **tidak** dicampur (`CreateJenisRwy.php:31-32`) |

**Tabel yang TIDAK dibuat:** `d_riwayat_cuti` (arsip trigger), semua cadangan bertanggal (`riwayat_cuti_2020…`, `riwayat_tb_20201204`, `riwayat_ib_20201213`, `pa_layanan_20211122`, `petugas_approval_20211122`, `inbox_msg_20251027`, `pegawai_sisa_cuti_20xx`), `layanan`/`layanan_pegawai`/`riwayat_layanan` (registry: `BelumDiputuskan`/`TidakDiimpor`), antrean push legacy (`fb_pn_queue`, `andr_notif_queue`, `andr_topic_queue`, `firebase_message_logs`), `fb_token`, `email_notification`, `support_officer`/`officer_category`/`help_desk_contact` (Fase 8).

#### 6.1.2 Isi wajib dokumen `DBV-022-layanan-fase4-schema.md`
Mengikuti pola `B-01-B-02-pegawai-riwayat-schema.md`, `DBV-019`, `DBV-020`:
1. **Header**: unit DBV-022, cakupan = 21 butir tabel §6.1.1, status "Menunggu review DB Validator", penulis, tanggal; **tidak boleh** ditulis sebagai "review DB Validator" bila belum direview (cek atribusi; pra-review internal ditulis "pra-review internal sisi CR").
2. **Konvensi** yang dipakai (K1, G2, V1–V5, status, K3, MigrationsDitahan) dengan rujukan.
3. **Per tabel**: DDL D1 apa adanya (baris), DDL v2 usulan, tabel deviasi `[K]/[V2]/[I]` per kolom/index/FK/CHECK + alasan, perlakuan trigger legacy, risiko impor.
4. **Pemisahan inbox vs ticketing** (bagian tersendiri): temuan T1 (legacy `inbox` = 100% tiket Halo Simpeg, `L_support.php:997,1006,1169,1341`), rancangan `inbox` v2 (satu arah) vs `disiplin_ticket` (rename [K]), daftar kolom yang **dilarang** ada di `inbox` v2, rencana impor: semua `inbox`/`inbox_msg` legacy → `disiplin_ticket(_msg)` (Fase 8), `inbox` v2 dimulai kosong atau diisi dari `riwayat_cuti_notif_kt`/`show_notif` (keputusan DBV/F-02, dicatat).
5. **Registry NIP**: setiap kolom NIP baru + jenisnya (`FkV2`, `NonFk`, `NULLABLE`, jenis baru untuk `ketua_tim` TEXT dipisah `***`), cakupan `NipCascadeService`.
6. **Bagian impor** (J1.07): urutan impor (`jenis_cuti`, `jenis_layanan`, `jenis_pa`, `question_category` → `petugas_layanan`, `pa_layanan` → `pegawai_sisa_cuti` → `riwayat_cuti` → `riwayat_cuti_notif_kt` → `riwayat_tb` → `riwayat_tb_perpan` → `riwayat_ib` → `riwayat_pmk_req`, `riwayat_pmk` → `esign_hist` (utuh, bukti legal) → `disiplin_ticket` → `disiplin_ticket_msg` → `inbox` v2), counter AUTO_INCREMENT disalin, normalisasi nilai di luar domain. **Lampiran lama (26-10, Q-21):** path legacy di kolom `riwayat_cuti.file_bukti/file_sc_unsign/file_sc_signed_bef/file_surat_cuti`, `riwayat_tb.file_sp/file_1..3/file_surat_tb`, `riwayat_tb_perpan.file_sperpan`, `riwayat_ib.file_sp/file_1/file_2/file_surat_ib` dipetakan ke baris `document_attachment` (kode `jenis_rwy` baru) pada runbook cutover agar riwayat lama tetap bisa diunduh; kolom `file_*` legacy diperlakukan sesuai DQ-20. Dicatat juga di dokumen Mapping (Q-19).
7. **Kueri audit baca-saja** (WAJIB_0 kecuali disebut) di `backend/docs/db-review/sql/`: (1) yatim/panjang/`''` `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`; (2) distribusi `status`, `status_al`, `status_pyb`, `rated`, `skip_*`, `status_regen`; (3) duplikat `pegawai_sisa_cuti (nip, tahun)`, `tahun = 0`; (4) distribusi status TB/IB/PMK req, `jenis_pmk`; (5) `petugas_layanan` nip yatim/>30, status di luar {0,1,10}, `id_jenis_layanan` NULL, duplikat; (6) `pa_layanan` id master yatim, nip yatim; (7) `inbox` `id_question_category` NULL, nip yatim, sender/receiver/closed_by tanpa pengguna, `inbox_msg.id_inbox` NULL/yatim, tabrakan UNIQUE setelah collation; (8) `esign_hist.status` di luar ('1','2'), nip yatim; (9) elemen `ketua_tim` yang bukan NIP; (10) `riwayat_cuti_notif_kt` distribusi & yatim; **(11)** `pegawai_sisa_cuti` nilai negatif (`utang_cuti < 0 OR sisa_cuti_ditangguhkan < 0 OR sisa_cuti* < 0` — akibat bug refund legacy `L:1318, 1325`) dan di luar batas D8 (PERIKSA); **(12)** `LENGTH(reason_note*) > 255` di `riwayat_cuti`, `riwayat_tb`, `riwayat_ib` (PERIKSA; kolom [K] tidak dikecilkan, lihat DQ-22). Digabung ke runbook impor DBV-011.
8. **Pertanyaan untuk DBV** (§6.1.3) beserta usulan coder.
9. **Rencana test skema**: `information_schema` per tabel (kolom, tipe, NULL, default, PK, index, FK + aksi, collation, COMMENT), error 1452/1451/1062, CHECK, siklus `down()`/`up()`, migration ditahan menolak jalan bila ada yatim, `inbox` tidak punya kolom `status`/`allow_reply`/`closed_by`.

#### 6.1.3 Pertanyaan DBV yang WAJIB dijawab di DBV-022 (dari riset skema)
| # | Pertanyaan | Status keputusan |
|---|---|---|
| DQ-1 | Domain `riwayat_cuti.status` | **Terjawab D1** (0/1/2/3/4 [K], tanpa 10 karena D6 hapus fisik) |
| DQ-2 | Arti `skip_al`/`skip_pyb` | **Terjawab D2** (kolom [K], arti tunda pengingat) |
| DQ-3 | FK `nip_atasan_langsung`/`nip_yang_menyetujui`: di CREATE atau ditahan; perlakuan yatim (NULL-kan + simpan nama/jabatan, atau impor pegawai lama) | DBV |
| DQ-4 | `inbox`/`inbox_msg` v2: kolom penerima (`nip` vs `id_pengguna`), perlu dua tabel atau tidak (DoD mewajibkan keduanya), kolom `id_inbox` di `disiplin_ticket_msg` dipertahankan/rename | Arah **D10**; detail DBV |
| DQ-5 | `question_category` dibuat di C-01 | **Terjawab D18** (dibuat); domain status — DBV |
| DQ-6 | FK ke `pengguna` untuk `disiplin_ticket.sender/receiver/closed_by`, `_msg.msg_sender/msg_receiver` (0 = semua petugas) | DBV |
| DQ-7 | `esign_hist` di C-01; tipe `status`; kolom `status_code` [V2]; `ip_address` 45 | Masuk C-01 **D18**; tipe/kolom DBV |
| DQ-8 | Cakupan tabel pendukung (`pegawai_sisa_cuti`, `jenis_cuti`, `pa_layanan`, `jenis_pa`, `jenis_layanan`, `petugas_layanan`) | **Terjawab D18** |
| DQ-9 | `jenis_cuti` PK komposit → tunggal + UNIQUE; seed literal + UI master atau tanpa UI | DBV (Fase 4 **tidak** membuat UI master `jenis_cuti`) |
| DQ-10 | `pegawai_sisa_cuti` UNIQUE `(nip, tahun)`, CHECK ≥ 0 | DBV |
| DQ-11 | `status_penuh` kolom vs turunan; status approval perpanjangan | **Terjawab D15** (turunan, tanpa kolom; perpanjangan tanpa status — §12 Q-12 konfirmasi) |
| DQ-12 | `petugas_layanan.status` domain & FK `nip` | DBV |
| DQ-13 | Surat cuti di `document_attachment` vs kolom | **Terjawab D13** (`document_attachment` + `jenis_rwy` baru); nomor kode DBV |
| DQ-14 | `riwayat_cuti_notif_kt` dibuat atau tidak | **Terjawab D11/D18** (dibuat [K]) |
| DQ-15 | `ketua_tim` masuk `TabelAnakNip` jenis baru | **Wajib** (aturan registry); bentuk jenis — DBV |
| DQ-16 | Index approval tambahan | DBV |
| DQ-17 | `id_sub_group_jabatan`, `id_jabatan_al`, `id_jabatan_pyb` tanpa FK | DBV (usul tanpa FK, historis) |
| DQ-18 | CHECK status TB/IB (0..3) dan `riwayat_pmk_req` default 1 | DBV |
| DQ-19 | Revisi dokumen Mapping (tambah `pegawai_sisa_cuti`, `jenis_cuti`, `pa_layanan`, `jenis_pa`; koreksi Prinsip #4) | Di luar coder → §12 Q-19 |
| DQ-20 | Kolom `file_*` NOT NULL [K] di `riwayat_tb`/`riwayat_ib` sementara berkas v2 di `document_attachment` | DBV |
| DQ-21 | Kolom `tag`/pembeda versi surat (belum TTE vs ber-TTE) di `document_attachment` atau dua kode `jenis_rwy` | DBV |
| DQ-22 | (26-10, Q-23) Tipe kolom `reason_note*` tetap [K] (`text`/`mediumtext`; `riwayat_pmk_req` `tinytext`) — **tidak dikecilkan** karena data impor bisa > 255; batas 255 byte hanya validasi input v2 | Usulan tetap [K]; DBV konfirmasi |
| DQ-23 | (26-10, Q-05) Audit `pegawai_sisa_cuti` bernilai negatif / di luar batas D8 sebelum CHECK ≥ 0 dipasang (DQ-10) | DBV memutuskan: normalisasi saat impor atau CHECK ditahan |

#### 6.1.4 ⛔ TITIK TUNGGU C-01
- ⛔ **Sebelum DBV menyetujui DBV-022: DILARANG** menulis migration yang dijalankan, model, service, controller, atau test yang bergantung pada tabel Modul C — **khususnya coding notifikasi (C-06)**. Yang boleh: dokumen DBV-022, kueri audit, draf migration **di branch DBV** untuk dibaca DBV.
- ⛔ Migration **DILARANG dijalankan** di DB Dev/Production sebelum disetujui; hanya di DB scratch uji (`simpeg_v2_t_dbv022*`, port 3307).
- Alur: dokumen + draf migration → PR `[DBV]`(+`[MAKE]` bila ada kode) → DB Validator review/approve → reviewer CR merge. Revisi setelah review = commit baru di PR yang sama.
- **Tahap 2b (26-10-2026, DIIZINKAN):** logika **murni** cuti boleh dikerjakan sebelum DBV-022 disetujui karena tidak bergantung pada tabel Modul C (input → output; tidak memakai Model, `db_connect`, `Database::connect`, `Services`). Batas berkas: `backend/app/Config/Cuti.php`; `backend/app/Libraries/Layanan/Cuti/{StatusCuti, SaldoCuti (value object), HitungLamaCuti (termasuk hari kerja sendiri + test paritas terhadap `Presensi/Tukin/HariKerja`), PorsiTahunCuti, ValidasiTanggalCuti, KuotaCuti (validasi/potong/refund atas `SaldoCuti`), TransisiCuti, EfekFinalCuti (predikat CLTN saja), PengingatCuti (batas tunda)}.php`; test `backend/tests/unit/Layanan/Cuti/*` termasuk `KuotaBoundaryTest` (§6.3.8 + §6.3.10) dan `CutiMurniTest` (`PemindaiKemurnian` pada folder itu); README `Libraries/Layanan`. **DILARANG** di tahap 2b: Model, controller, route, migration, `KuotaCutiService` berbasis DB, penentuan AL/PyB, notifikasi. Key: bagian awal **MAKE-022**, branch `fase4/make-022-cuti` (§9 M2b).

#### 6.1.5 Acceptance criteria C-01 (QASMTASK-064)
- **AC-01.1** Migration membuat `riwayat_cuti`, `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req` (+ semua tabel D18) sesuai DBV-022 yang disetujui; test `information_schema` lulus di MySQL 8.0 & MariaDB 10.4 (CI).
- **AC-01.2** `inbox`/`inbox_msg` v2 **tidak** memiliki kolom `status`, `allow_reply`, `closed_by`, `is_read_off`, `notify_pic` (test skema eksplisit menegaskan ketiadaan kolom).
- **AC-01.3** `disiplin_ticket`/`disiplin_ticket_msg` tabel terpisah dengan `status` open/closed (CHECK 0/1 [+10 bila DBV]) dan dinyatakan "skema final Fase 8" di DBV-022.
- **AC-01.4** FK `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui` → `pegawai.nip` tersedia (aktif atau ditahan dengan pra-cek fail-closed sesuai DBV); kueri audit yatim tersedia; test 1452 untuk NIP yatim (bila FK aktif) atau test migration ditahan menolak jalan saat ada yatim.
- **AC-01.5** Bukti review: PR berisi persetujuan DB Validator atas **kedua** skema (inbox & ticketing) **sebelum** commit pertama C-06; tanggal persetujuan dicatat di `backend/docs/progress/04-Layanan.md`.
- **AC-01.6** Pemisahan terverifikasi sejak awal: test skema `SkemaInboxTicketTest` ada di PR C-01 (bukan ditambahkan belakangan).
- **AC-01.7** `NipReferenceRegistryTest` hijau dengan semua kolom NIP baru terklasifikasi (termasuk `ketua_tim`, `nip_kt`, `esign_hist.nip`, `petugas_layanan.nip`, `pa_layanan.nip`, `inbox.nip_penerima`, `disiplin_ticket.nip`, `firebase_token.nip`); `NipCascadeService` mencakup tabel Fase 4.
- **AC-01.8** Siklus `down()`/`up()` setiap migration lulus (`db-isolasi-penuh`); migration lama di `main` tidak berubah (`git diff origin/main -- backend/app/Database/Migrations/` hanya berisi berkas baru).

**DILARANG (C-01):** mengedit migration lama; membuat tabel di luar D18 tanpa persetujuan; menaruh notifikasi di `disiplin_ticket*` atau tiket di `inbox`; menulis "disetujui DB Validator" tanpa persetujuan nyata; menjalankan migration di DB dev.

### 6.2 C-02 — Pengajuan cuti

**Tujuan.** CRUD pengajuan cuti online sesuai legacy (`C/Cuti.php`, `L/L_cuti.php`) dengan hak D5; submit menghasilkan status 0.

**Dependency.** C-01 (DBV-022 merged), C-03 (`KuotaCutiService` dipakai validasi; dikerjakan dalam milestone yang sama M3). **QASMTASK-065.**

**File boundary.**
- Backend: `backend/app/Controllers/Api/Layanan/CutiController.php`, `backend/app/Libraries/Layanan/Cuti/{CutiService.php, AturanJenisCuti.php, AtasanLangsungCuti.php, PejabatBerwenangCuti.php, OpsiCuti.php}`, `backend/app/Models/Layanan/{RiwayatCutiModel.php, RiwayatCutiNotifKtModel.php, JenisCutiModel.php}`, `backend/app/Config/RoutesLayanan.php`, `backend/app/Config/Routing.php` (tambah satu entri `$routeFiles`), `backend/app/Config/Services.php` (aditif), `backend/app/Config/Cuti.php` (konstanta).
- Dokumentasi endpoint: `backend/app/Controllers/Api/Layanan/README.md` (WAJIB: method, path, payload, respons, role, kode galat per endpoint — DoD 065-3).
- Test: `backend/tests/Layanan/Cuti/{CutiCrudTest.php, CutiValidasiTest.php, CutiRoleTest.php, RouteLayananKontrakTest.php}`.

#### 6.2.1 Siapa boleh apa (D5, legacy §1.1)
| Aksi | Role 1 | Role 2 / 7 | Role 3 | Role 6 | Role 4/5/8 |
|---|---|---|---|---|---|
| Lihat daftar | `admin` semua | `saya` | `admin` lingkup | `saya` (umumnya kosong) | 403 |
| Ajukan (`POST`) | input admin: `nip` + `status` 0\|1 | untuk diri sendiri, status 0 | 403 | 403 | 403 |
| Ubah | status 0 | pemilik, status 0, `tgl_mulai >= hari ini` | 403 | 403 | 403 |
| Hapus | semua status | pemilik, status ∈ {0,2} | 403 | 403 | 403 |
| Proses | sebagai NIP AL/PyB | sebagai NIP AL/PyB | sebagai NIP AL/PyB | sebagai NIP AL/PyB | 403 (route) |

#### 6.2.2 Jenis cuti yang boleh dipilih (`setJenisCuti` `L:281-391`, `form()` `L:509-511`) [K]
| id | Jenis | Siapa boleh | Lama | Aturan tanggal/durasi (server) |
|---|---|---|---|---|
| 1 | Tahunan | PNS (`id_jenis_pegawai = 1`) bukan CPNS (`id_jenis_kp ∉ {'',1}`) & `mker_th ≥ 1` (`L:313-321`); PPPK bila ≥ 1 tahun sejak `riwayat_mutasi_jabatan` pertama (`L:296-311`); hilang bila ada Cuti Besar disetujui berakhir sebelum tgl mulai di tahun yang sama (`L:325-331`, `L:1572-1576`) | hari kerja | `tgl_mulai >= hari ini` (`L:1540-1548`; pengecualian akun uji `USR_SADEV/USR_SPC` **tidak** dibawa); kuota (§6.3) |
| 2 | Besar | PNS `mker_th ≥ 5` (`L:337-349`, `L:1551-1558`); bukan PPPK | hari kalender inklusif | `durasi` ∈ {1,2,3} bulan; `tgl_akhir = tgl_mulai + n bulan − 1 hari` (`FH:960-966`) |
| 3 | Sakit | semua yang boleh mengajukan | hari kerja | boleh tanggal lampau (server tidak membatasi, `L:1543`); batas mundur di klien [K klien `form.php:437-457`] |
| 4 | Melahirkan | perempuan saja (`L:356-361`) | hari kalender inklusif | tanpa batas lain di server [K] |
| 5 | Karena Alasan Penting | bukan PPPK (`L:364-370`) | hari kerja | maks 1 bulan kalender (`L:1666-1675`); boleh lampau seperti Sakit |
| 6 | CLTN | PNS `mker_th ≥ 5` (`L:373-386`), **hanya input admin role 1** (`L:510`) | hari kalender inklusif | maks 3 tahun (`L:1561-1571`) |

Daftar opsi (`GET cuti/opsi`) **sudah disaring server** per pemohon; `POST` dengan jenis di luar daftar → 422 `errors.id_jenis_cuti`.

#### 6.2.3 Kontrak `POST cuti` / `PUT cuti/{id}` (multipart)
| Field | Wajib | Validasi server & pesan 422 (`errors.<field>`) | Sumber |
|---|---|---|---|
| `nip` | role 1 saja | pegawai aktif ada; role 2/7 diabaikan (dipaksa NIP sesi) | C:263-266 |
| `status` | role 1 saja | ∈ {0,1}; role 2/7 dipaksa 0 | `form_ad.php:151-154` |
| `id_jenis_cuti` | ya | "Silahkan pilih jenis cuti." / "Jenis cuti tidak tersedia untuk pegawai ini." | `L:1440`, `setJenisCuti` |
| `tgl_mulai` | ya | `YYYY-MM-DD`; "Tanggal mulai cuti wajib diisi."; jenis 1/2/4/6: "Tanggal mulai cuti tidak boleh sebelum hari ini." (jenis 3/5 boleh mundur [K `L:1547-1554`]); **tahun acuan (26-10, keputusan #6)**: `tgl_mulai` < 01-01 tahun acuan (§6.3.3) → "Tanggal cuti tidak boleh pada tahun sebelum tahun pengajuan." [V2; legacy meloloskan tanpa potong `L:1591-1610`] | `L:1540-1554` |
| `tgl_akhir` | ya (kecuali jenis 2: dihitung dari `durasi`) | `>= tgl_mulai` "Tanggal akhir tidak boleh sebelum tanggal mulai."; `<= 31-12 tahun acuan + 1` "Tanggal akhir cuti maksimal 31-12-{tahun+1}." [V2 di server; legacy hanya klien C:248-255, 295 — keputusan #6: porsi hanya tahun acuan & tahun berikutnya] — **hanya jenis 1** (catatan v1.1 26-10, disetujui Horii: batas ini berasal dari kuota dua tahun; CLTN sah sampai 3 tahun dan jenis 2–6 tidak memotong kuota, jadi tidak dikenai batas ini); jenis 5: `<= tgl_mulai + 1 bulan` (§6.3.2); jenis 6: `<= tgl_mulai + 3 tahun` (§6.3.2) | `L:1540-1543`, C:248-255 |
| `durasi` | jenis 2 | ∈ {1,2,3} "Silahkan pilih durasi cuti besar." | `form.php:130-137` |
| `lama_cuti` | — | **tidak diterima dari klien**; diisi server = `KuotaCutiService::hitungLama()` (§6.3.2); hasil 0 → `errors.tgl_mulai` "Lama cuti tidak boleh 0 hari." | `L:1504-1517`; §12 Q-03 |
| `no_telp_saat_cuti` | ya | ≤ 50; "No. telepon selama cuti wajib diisi." | `L:1440-1467` |
| `alamat_saat_cuti` | ya | "Alamat selama cuti wajib diisi." | idem |
| `keterangan` | ya | ≥ 10 karakter "Keterangan minimal 10 karakter." | `L:1466` |
| `nip_atasan_langsung` | ya kecuali eselon I (`id_sub_group_jabatan = 1`) | "Silahkan pilih pejabat atasan langsung."; NIP pegawai yang ada (integritas FK) [K D19]; validasi lain tidak ditambahkan (ISSUE-048) | `L:1469-1489`, `L:1746-1756` |
| `nip_yang_menyetujui` | ya kecuali eselon I | "Silahkan pilih pejabat yang berwenang menyetujui cuti."; NIP pegawai yang ada (integritas FK) [K D19]; validasi lain tidak ditambahkan (ISSUE-048) | `L:1762-1772` |
| `ketua_tim[]` | tidak | array NIP pegawai aktif (`pegawai.status = 1`), unik; disimpan `implode('***')` + baris `riwayat_cuti_notif_kt` | `L:1785-1800`, `L:516` |
| `file_bukti` | tidak (server) | 5 MB, jpg/jpeg/png/gif/pdf via `ValidasiBerkas` (isi ≙ ekstensi, tanpa ekstensi ditolak, CR-054); disimpan `AttachmentService` kode `jenis_rwy` cuti (§6.1.1 #21). FE mewajibkan untuk jenis 3 [K klien `form.php:354-362`] | `L:901-915` |
| Tumpang tindih | — | Sakit/Alasan Penting: cek terhadap jenis 3/5 status {0,1,**3**}; jenis lain: terhadap semua jenis status {0,1,**3**,4} → `errors.tgl_mulai` "Tanggal cuti beririsan dengan pengajuan cuti lain." Status 3 **ikut dicek** [V2, keputusan #5 26-10; legacy `L:1527-1536` mengabaikan 3 walau 3 ikut dihitung "menunggu" kuota]. Baris yang sedang diubah dikecualikan | `L:1527-1536` |
| Kuota (jenis 1) | — | §6.3.4 → `errors.lama_cuti` | `L:1572-1661` |

**Semua galat dikumpulkan** (bukan hanya yang terakhir — A-09 [V2], kontrak 422 per kolom platform).

Kolom diisi server saat simpan [K `sp()` `L:1688-2040`]: snapshot pemohon (`nama` bergelar, `id_sub_group_jabatan`, `pangkat`, `gol_ruang`, `jabatan`, `satker`, `mker_th`, `mker_bl`) **dari data server** (bukan POST), `nama_jenis_cuti`, `lama_cuti`, `satuan_lama_cuti = 'Hari'`, snapshot AL/PyB (`nama_*`, `jabatan_*`, `id_jabatan_al`, `id_jabatan_pyb`) dari NIP yang dikirim; eselon I: AL & PyB = nama/jabatan Menteri dari konfigurasi (`web_config` → konfigurasi v2 setara; sumber v2 ditetapkan di M0, §12 Q-06), NIP NULL; snapshot saldo `sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, utang_cuti` dari `pegawai_sisa_cuti` tahun acuan (`L:1795-1808`); `status`, `status_al = 0`, `status_pyb = 0`, `updated_by`, `created_at` (UTC).

**Edit** [K]: field sama; `nip`, `id_jenis_cuti` boleh diubah selama status 0; `riwayat_cuti_notif_kt` disinkronkan (hapus yang dibuang, tambah yang baru — `L:1161-1169`); berkas `file_bukti` **boleh diganti** (§12 Q-15 default [V2]); notifikasi "Perubahan Cuti Online" ke approver tahap aktif (§6.6).

#### 6.2.4 Respons
- `POST` → 201 `data` = item cuti; `PUT` → 200 item; `GET cuti` → `{items, total, page, per_page, mode, can_create}`; item = kolom DDL `riwayat_cuti` (tanpa path berkas) + `label_status`, `tahap[]` (§6.11.4), `ada_lampiran`, `ada_surat`, `aksi {ubah, hapus, proses, tunda, esign, unduh_surat}`.
- `GET cuti/{id}` → item + `ketua_tim_detail[] {nip, nama}`, `lampiran[]`, `surat[] {id_attachment, jenis (belum_tte|tte), display_name, created_at}`, `riwayat_approval` (teks `keterangan_petugas_approval`, `reason_note_al`, `reason_note_pyb`).
- Daftar `persetujuan` diurut `tgl_mulai ASC`; `saya` `tgl_mulai DESC` (`L:393-422`); `admin` `tgl_mulai DESC`.

#### 6.2.5 Ketua tim (tembusan) [K]
`ketua_tim` opsional multi-pilih pegawai aktif (`views/hr/employee/rwy/cuti/form.php:198-210`); ketua tim **boleh melihat** detail (C:408-438) dan menerima notifikasi tembusan (D11) — tidak punya aksi. Baris `riwayat_cuti_notif_kt.status`: 0 saat dibuat, 1 saat KT membuka detail (`L:798-801`).

#### 6.2.6 Hapus (D6)
Dalam satu transaksi: (1) kunci `pegawai` pemohon `FOR UPDATE`, baca ulang baris cuti terkunci; (2) cek hak (§6.2.1); (3) bila `id_jenis_cuti = 1` dan status ∈ {1,4} → **refund** `KuotaCutiService::kembalikan()` (§6.3.5); (4) bila CLTN (6) status 1 sedang berjalan dan `pegawai.id_jenis_status = 3` → kembalikan ke 1 [K `L:1365-1376`] dengan audit; (5) hapus anak eksplisit (FK RESTRICT): `riwayat_cuti_notif_kt`, baris `document_attachment` lampiran/surat (via `AttachmentService`), baris `inbox`/`inbox_msg` yang merujuk cuti itu **tidak** dihapus (riwayat notifikasi; `id_rwy` tanpa FK); (6) **hapus fisik** `riwayat_cuti`; (7) `audit_logs` event `delete` dengan `before` lengkap (pengganti `d_riwayat_cuti`); (8) commit; berkas fisik dibuang sesudah commit (kompensasi `AttachmentService`). Respons `{deleted: true, soft_delete: false, id}`.

#### 6.2.7 AC C-02 (QASMTASK-065)
- **AC-02.1** CRUD: pegawai role 2 dan 7 dapat `POST`/`GET`/`PUT`/`DELETE` cuti sendiri sesuai §6.2.1; role 1 dapat input admin status 0 dan 1; role 3 dan 6 mendapat 403 pada `POST`/`PUT`/`DELETE`; role 4/5/8 403 di semua endpoint cuti (kecuali `hitung-lama`).
- **AC-02.2** `POST cuti` oleh role 2/7 menghasilkan baris `status = 0`, `status_al = 0`, `status_pyb = 0`, snapshot terisi server, `lama_cuti` = hasil server meski klien mengirim nilai lain.
- **AC-02.3** Setiap validasi §6.2.3 punya test 422 dengan `errors.<field>` dan pesannya; banyak galat sekaligus dikembalikan bersama.
- **AC-02.4** Ubah oleh pemilik ditolak (422 `errors.status`) bila status ≠ 0 dan (422 `errors.tgl_mulai`) bila `tgl_mulai < hari ini`; hapus oleh pemilik ditolak (403) untuk status 1/3/4.
- **AC-02.5** Hapus = baris hilang dari `riwayat_cuti`, `audit_logs` berisi `before`, saldo dikembalikan tanpa nilai negatif, CLTN dikembalikan.
- **AC-02.6** Dokumentasi endpoint lengkap di `Controllers/Api/Layanan/README.md` (DoD 065-3).
- **AC-02.7** `GET cuti/{id}` milik orang lain yang tidak terlihat → 404; ketua tim CC dapat melihat (200) tetapi `aksi` semua false.

**DILARANG (C-02):** menerima `lama_cuti`/snapshot pemohon dari klien; membuka pengajuan untuk role 6 atau role 3; soft delete status 10 untuk cuti (D6 hapus fisik); memakai `StatusRiwayat`/`StatusBadge` Fase 3 untuk status cuti (arti kode berbeda).

---

### 6.3 C-03 — Kalkulasi kuota N/N-1/N-2 & kalkulator lama cuti

**Tujuan.** Satu sumber kebenaran untuk lama cuti (D9) dan saldo cuti tahunan (D8) dipakai C-02 (validasi), C-04 (potong saat final), D6 (refund), C-11 (kalkulator real-time).

**Dependency.** C-01 (`pegawai_sisa_cuti`), Master Hari Libur (Fase 2). **QASMTASK-066**, C-12.

**File boundary.** `backend/app/Libraries/Layanan/Cuti/KuotaCutiService.php` (WAJIB nama ini, DoD 066), `backend/app/Libraries/Layanan/Cuti/HitungLamaCuti.php` (boleh digabung ke service), `backend/app/Config/Cuti.php`, `backend/app/Models/Layanan/PegawaiSisaCutiModel.php`, endpoint di `CutiController::hitungLama` dan `CutiSaldoController.php`; test `backend/tests/Layanan/Kuota/{KuotaCutiServiceTest.php, HitungLamaCutiTest.php, KuotaBoundaryTest.php, SaldoCutiCrudTest.php}`.

#### 6.3.1 Konstanta (`Config\Cuti`, tanpa override `.env`, pola `Config\AngkaKredit`)
`KUOTA_N = 12`, `MAKS_N1 = 6`, `MAKS_N2 = 6`, `MAKS_TOTAL = 24`, `MAKS_UTANG = 12`, `JENIS_HARI_KERJA = [1, 3, 5]`, `JENIS_HARI_KALENDER = [2, 4, 6]`, `DURASI_BESAR = [1, 2, 3]`, `MAKS_BULAN_ALASAN_PENTING = 1`, `MAKS_TAHUN_CLTN = 3`, `JAM_BATAS_TUNDA = '12:00'`.

#### 6.3.2 Lama cuti (D9, `Local.php:2147-2209`, `FH:2175-2215`)
- Jenis 1/3/5: hitung **hari kerja** inklusif `tgl_mulai..tgl_akhir` = hari Senin–Jumat yang **bukan** tanggal di `HariLiburService::tanggalLibur(from, to)` (hanya hari libur `status = 1`; cuti bersama yang tercatat sebagai hari libur ikut dikurangi → **tidak memotong kuota** [K]). Daftar libur dari `HariLiburService::tanggalLibur()` (`Libraries/MasterData/HariLiburService.php:209-230`, baca-saja). **Hari kerja dihitung sendiri** di `HitungLamaCuti` (Sabtu/Minggu = `date('N') >= 6` [K `FH:997-999`], libur = himpunan tanggal) dengan **test paritas** terhadap `Libraries/Presensi/Tukin/HariKerja::hariKerja()` (keputusan #13 26-10: `Libraries/Layanan/Cuti` lolos pemindai kemurnian dan tidak mengikat Fase 5). DILARANG mengubah `HariKerja`/`HariLiburService`. Libur legacy = semua baris `hari_libur` tanpa filter jenis (`FH:2181-2195`) → cuti bersama ikut dikurangi [K].
- Jenis 2: `tgl_akhir` = **tanggal yang sama pada bulan `tgl_mulai + durasi` (dipotong ke hari terakhir bulan itu bila tidak ada) dikurangi 1 hari** [K `addMonth` `FH:960-966`; keputusan #2 26-10]. Contoh: 15-01 + 1 bulan → **14-02**; 31-01-2025 + 1 → **27-02-2025** (28-02 dipotong, −1); 31-01-2024 + 1 → **28-02-2024** (kabisat: 29-02 − 1); 31-03 + 1 → 29-04; 15-01 + 3 → 14-04. **Bukan** PHP `+1 month` (31-01 → 03-03). Lama = hari kalender inklusif. Respons mengembalikan `tgl_akhir` yang **benar** (tanpa maju 1 hari — perbaikan anomali LK-01, §12 Q-15).
- Jenis 4, 6: hari kalender inklusif (nilai yang selama ini tersimpan dari kalkulator klien legacy; anomali server `diff()->days` eksklusif LK-02 tidak dibawa).
- `satuan_lama_cuti = 'Hari'`.
- Batas durasi jenis 5 (alasan penting): `tgl_akhir ≤ tgl_mulai` **+ 1 bulan PHP** (`DateTime::modify('+1 month')`, inklusif) [K `L:1666-1674`; keputusan #4 26-10]. Contoh: 15-01 → batas 15-02; 31-01-2025 → **03-03-2025**; 31-01-2024 → **02-03-2024**; 30-04 → 30-05. Jenis 6 (CLTN): `tgl_akhir ≤ tgl_mulai + 3 tahun` inklusif (TA = TM + 3 tahun persis masih boleh) [K `L:1567-1575`]; 29-02 + 3 tahun → 01-03 (PHP).

#### 6.3.3 Tahun acuan & saldo
- Baris saldo = `pegawai_sisa_cuti (nip, tahun)`; tahun acuan = **tahun pengajuan** (`created_at`, zona waktu aplikasi) [K `L:1579`, `L:3829-3831`] — validasi saat ajukan dan potong saat final memakai baris tahun yang sama (§12 Q-04 konfirmasi).
- Porsi cuti yang jatuh **di tahun acuan** = `lcTahunIni`; porsi di tahun berikut = `lcTahunDepan` (pecah pada 31-12 / 01-01 memakai hitungan hari kerja §6.3.2). **Hanya dua tahun** yang dikenal: tanggal sebelum 01-01 tahun acuan atau sesudah 31-12 tahun berikutnya → 422 (§6.2.3; keputusan #6 26-10, legacy tidak menangani `L:1591-1610`).
- Tahun acuan **refund** (§6.3.5) = tahun `created_at` baris yang dihapus, bukan tahun berjalan saat menghapus [V2; legacy `L:1282` memakai `date('Y')` sehingga hapus cuti tahun lalu salah sasaran].
- `saldoTahunIni = sisa_cuti + sisa_cuti_n1 + sisa_cuti_n2` (≤ 24 karena batas D8); `saldoTahunDepan = MAKS_UTANG − utang_cuti`.
- Saldo tidak ada → 422 `errors.lama_cuti` "Saldo cuti anda tidak terdaftar didalam sistem. Silahkan hubungi admin SIMPEG untuk menambahkan saldo cuti tahunan anda." (`L:1655-1658`).

#### 6.3.4 Validasi saat ajukan/ubah (jenis 1) [K `L:1584-1653`]
`menungguTahunIni`/`menungguTahunDepan` = jumlah porsi cuti Tahunan milik NIP yang sama berstatus {0,3} (kecuali baris yang sedang diubah). Tolak bila `lcTahunIni + menungguTahunIni > saldoTahunIni` **atau** `lcTahunDepan + menungguTahunDepan > saldoTahunDepan` → 422 `errors.lama_cuti` "Sisa Cuti anda tidak mencukupi. Sisa cuti {Y}: {a} Hari. Cuti sedang dalam proses {Y}: {b} Hari. Cuti yang diajukan {Y}: {c} Hari. Sisa utang cuti {Y+1}: {d}. Utang cuti dalam proses {Y+1}: {e}. Utang cuti yang diajukan {Y+1}: {f}." (`L:1648`). Porsi menunggu dihitung ulang dari tanggal (bukan `lama_cuti` tersimpan) dan memakai variabel tahun yang benar (perbaikan A-10). Validasi yang sama **diulang saat keputusan final** (`vp_process_esign`, `L:3646-3752`) di dalam transaksi terkunci.

#### 6.3.5 Potong & refund — SATU algoritma (D8, A-11)
- **Potong** (hanya jenis 1, saat transisi ke 1 atau 4, di dalam transaksi persetujuan, baris saldo dikunci `FOR UPDATE`):
  1. `lcTahunIni` dipotong berurutan dari `sisa_cuti_n2` → `sisa_cuti_n1` → `sisa_cuti` (N-2 → N-1 → N), **tanpa normalisasi ulang** (LK-05 tidak dibawa).
  2. Status 1 dan `lcTahunDepan > 0` → `utang_cuti += lcTahunDepan` (≤ 12).
  3. Status 4 → `sisa_cuti_ditangguhkan += lcTahunIni` (porsi tahun depan tidak menjadi utang) [K `L:3891-3893`].
  4. Hasil tidak boleh negatif; bila kurang (seharusnya tidak terjadi karena §6.3.4 diulang) → 422, transaksi dibatalkan (perbaikan A-07: tidak ada `return` tanpa rollback).
- **Refund** (D6, hapus cuti status 1/4 jenis 1) = kebalikan tepat: (1) `lcTahunIni` dikembalikan berurutan ke `sisa_cuti` → `sisa_cuti_n1` → `sisa_cuti_n2` dengan batas 12 / 6 / 6 (sisa yang melebihi batas dibuang, total tidak pernah > 24); (2) status 1: `utang_cuti = max(0, utang_cuti − lcTahunDepan)`; (3) status 4: `sisa_cuti_ditangguhkan = max(0, … − lcTahunIni)`. **Tidak ada nilai negatif** (perbaikan A-08). Rumus refund = keputusan PRD [V2] atas D6 → §12 Q-05 konfirmasi.
- Seluruh perubahan saldo dicatat `audit_logs` (`update`, before/after).

#### 6.3.6 Kelola saldo (`cuti/saldo`, legacy `sc/add_sc/edit_sc/delete_sc` C:1174-1374, `L:4590-4993`)
- Daftar per `tahun` (bawaan tahun berjalan; `tahun > tahun ini` → 422), role 3 lingkup; kolom NIP, nama, tahun, `sisa_cuti`, `sisa_cuti_n1`, `sisa_cuti_n2`, `sisa_cuti_ditangguhkan`, `utang_cuti`, jabatan, satker.
- Tambah: target `1` semua pegawai (dalam lingkup pemanggil — perbaikan anomali cek duplikat lintas lingkup) / `2` pilih pegawai (jenis pegawai 1/6); `tahun`, `sisa_cuti`, `sisa_cuti_n1`, `sisa_cuti_n2`, `utang_cuti` wajib, `sisa_cuti_ditangguhkan` opsional; tolak bila pegawai sudah punya baris tahun itu (`errors.nip`). [V2 D8] batas `sisa_cuti ≤ 12`, `n1 ≤ 6`, `n2 ≤ 6`, `utang ≤ 12`, semua ≥ 0 → 422 per kolom.
- Ubah: kolom saldo saja; hapus: fisik + audit [K].
- **Pembentukan saldo tahun baru** (cron legacy `calculate_yearly_sc(_2)`) → §12 **⛔ Q-02** (tidak dikerjakan sebelum dijawab).

#### 6.3.7 Kalkulator real-time `POST cuti/hitung-lama`
Respons `{lama_cuti, satuan_lama_cuti, tgl_akhir, hari_libur: [{tanggal, keterangan}], kuota: null | {tahun, sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, saldo_tahun_ini, menunggu_tahun_ini, diajukan_tahun_ini, saldo_tahun_depan, menunggu_tahun_depan, diajukan_tahun_depan, cukup: bool, pesan}}`. `kuota` hanya untuk jenis 1 dan untuk NIP pemanggil (role 1: `nip` yang sedang diinput). `id_riwayat_cuti` (edit) dikecualikan dari menunggu. Tanpa efek tulis. Galat input → 422 per kolom seperti §6.2.3.

#### 6.3.8 Tabel kasus uji wajib (KuotaBoundaryTest — dataProvider)
Saldo awal `(N, N-1, N-2, utang, ditangguhkan)`; semua cuti Tahunan di tahun acuan kecuali disebut.

| # | Saldo awal | Menunggu | Diajukan | Hasil validasi | Saldo setelah final (status) |
|---|---|---|---|---|---|
| K1 | (12,6,6,0,0) | 0 | 24 | **lolos (tepat 24)** | (0,0,0,0,0) (1) |
| K2 | (12,6,6,0,0) | 0 | 25 | **422 (>24)** | — |
| K3 | (12,6,6,0,0) | 0 | 5 | lolos | (12,6,1,0,0) (1) |
| K4 | (12,6,6,0,0) | 0 | 8 | lolos | (12,4,0,0,0) (1) |
| K5 | (12,6,6,0,0) | 0 | 15 | lolos | (9,0,0,0,0) (1) |
| K6 | (12,0,0,0,0) | 0 | 12 | lolos | (0,0,0,0,0) (1) |
| K7 | (12,0,0,0,0) | 0 | 13 | 422 | — |
| K8 | (10,3,0,0,0) | 5 | 8 | lolos (13 = 13) | — |
| K9 | (10,3,0,0,0) | 5 | 9 | 422 (14 > 13) | — |
| K10 | (12,6,6,0,0) | 20 | 4 | lolos (24) | — |
| K11 | (12,6,6,0,0) | 20 | 5 | 422 (25) | — |
| K12 | (12,6,6,0,0) | 0 | 4 | lolos | (12,6,2,0,4) (**4** Ditangguhkan) |
| K13 | (2,0,0,0,0) | 0 | 2 thn ini + 3 thn depan (lintas 31-12) | lolos | (0,0,0,3,0) (1) |
| K14 | (12,6,6,10,0) | 0 | 3 thn depan | 422 (3 > 12−10) | — |
| K15 | (12,6,6,10,0) | 0 | 2 thn depan | lolos | (12,6,6,12,0) (1) |
| K16 | refund K3 | — | hapus status 1 | — | kembali (12,6,6,0,0) |
| K17 | refund K12 | — | hapus status 4 | — | kembali (12,6,6,0,0) |
| K18 | refund saat saldo (12,6,5,0,0) dan lama 3 | — | hapus | — | (12,6,6,0,0) — kelebihan 2 dibuang, total ≤ 24 |
| K19 | refund utang saat `utang_cuti = 0` | — | hapus | — | `utang_cuti = 0` (tidak negatif) |
| K20 | saldo tidak terdaftar | — | 1 | 422 "Saldo cuti anda tidak terdaftar…" | — |
| K21 | jenis 3/5/2/4/6 | — | berapa pun | tidak memeriksa/memotong kuota | saldo tetap |
| K22 | 3 hari kerja dengan 1 hari libur status 1 di tengah | — | — | `lama_cuti = 2` | — |
| K23 | hari libur status 2/10 di rentang | — | — | tidak dikurangi | — |
| K24 | rentang Jumat–Senin | — | — | `lama_cuti = 2` (akhir pekan tidak dihitung) | — |

#### 6.3.9 AC C-03 (QASMTASK-066)
- **AC-03.1** N = 12: pegawai tanpa sisa bawaan dapat mengajukan tepat 12 hari kerja dan ditolak pada 13 (K6/K7).
- **AC-03.2** N-1 ≤ 6 dan N-2 ≤ 6: input saldo > 6 untuk N-1/N-2 ditolak 422; potong mengikuti N-2 → N-1 → N (K3–K5).
- **AC-03.3** Total maks 24: K1 lolos, K2 ditolak; kombinasi dengan pengajuan menunggu (K8–K11).
- **AC-03.4** Unit test berbagai kombinasi (seluruh tabel §6.3.8) lulus di CI.
- **AC-03.5** Boundary tepat 24 dan > 24 diuji eksplisit (K1, K2, K10, K11).
- **AC-03.6** Kalkulator `POST cuti/hitung-lama` memberi lama sama persis dengan yang disimpan `POST cuti` untuk input sama (satu fungsi).

**DILARANG (C-03):** lebih dari satu implementasi potong/refund; menghitung kuota di FE (ADR-025/026); normalisasi ulang saldo saat potong; menulis saldo di luar transaksi terkunci; mengubah `HariKerja`/`HariLiburService`.

#### 6.3.10 Kasus uji tambahan wajib (26-10-2026, dari verifikasi kode legacy; masuk `KuotaBoundaryTest`/`HitungLamaCutiTest`)
| # | Kasus | Dasar |
|---|---|---|
| T1 | `tgl_akhir` Besar: 31-01-2025 → 27-02; 31-01-2024 → 28-02; 30-01 → 28-02/27-02; 31-03 → 29-04; durasi 2/3 bulan | `FH:960-966` |
| T2 | Alasan penting 31-01 → batas 03-03 (02-03 kabisat); TA = batas (boleh), batas + 1 (422) | `L:1666-1674` |
| T3 | CLTN TA = TM + 3 tahun persis (boleh), + 1 hari (422); TM 29-02 | `L:1567-1575` |
| T4 | Jenis 2/4/6 `tgl_mulai = tgl_akhir` → lama 1 (sah, Q-24; legacy server menolak `L:1509-1513`) | Q-24 |
| T5 | Jenis 1: rentang seluruhnya libur/akhir pekan → lama 0 → 422 | `L:1519` |
| T6 | Libur multi-hari yang hanya sebagian beririsan; libur jatuh di Sabtu/Minggu (tidak dobel); libur status 2/10 tidak dihitung | `FH:2181-2208` |
| T7 | Lintas tahun: 31-12 Jumat & 01-01 libur; TM 31-12; TM 01-01 tahun berikut (`lcTahunIni` 0); TA > 31-12 tahun berikut → 422 | `L:1591-1610` |
| T8 | Pengajuan Januari untuk tanggal tahun sebelumnya → 422 (keputusan #6) | §6.2.3 |
| T9 | Menunggu mencakup status 0 **dan 3**, dihitung ulang dari tanggal, termasuk baris menunggu lintas tahun (A-10 diperbaiki) | `L:1614-1644` |
| T10 | Edit: baris sendiri dikecualikan dari menunggu & tumpang tindih | `L:1616, 1527` |
| T11 | Status 4 dengan `lcTahunDepan > 0`: ditangguhkan += `lcTahunIni`, utang tetap, porsi tahun depan dibuang | `L:3892-3901` |
| T12 | Potong saat saldo kurang (balapan) → 422, saldo tidak berubah | A-07 |
| T13 | **Paritas**: untuk saldo **berbentuk ternormalisasi legacy** (N terisi dulu sampai 12, lalu N-1 sampai 6, lalu N-2 — bentuk yang selalu dihasilkan legacy) dan `lc ≤ total`, hasil N-2 → N-1 → N = normalisasi legacy e-sign (`L:3867-3886`) — jaminan data impor. Catatan v1.1 (26-10, disetujui Horii): untuk saldo di luar bentuk itu (mis. (5,6,6), lc 2 → v2 (5,6,4) vs normalisasi (12,3,0)) kedua rumus memang berbeda; bentuk tersebut tidak pernah dihasilkan legacy maupun v2, sehingga test dibatasi ke bentuk ternormalisasi | A-11 |
| T14 | Refund cuti tahun lalu memakai tahun `created_at` baris; refund `utang < lcTahunDepan` → 0 | `L:1282, 1318` |
| T15 | Input admin status 1 (T3) memakai potong yang sama; potongan ganda `sp()` legacy tidak ditiru | `L:1986-2024` |
| T16 | State machine: AL tangguhkan dari 0 → final 4 + potong [K A-05]; AL == PyB → langkah tunggal; PyB kosong → langkah tunggal | `L:3797-3818` |
| T17 | Tunda tepat `tgl_mulai 12:00:00` (boleh), 12:00:01 (422); zona Asia/Jakarta | C:971-976 |
| T18 | CLTN TM = hari ini / TA = hari ini → aktif; TM = besok → tidak | `L:3494-3504` |
| T19 | Tumpang tindih: jenis 3 vs jenis 1 status 1 (legacy tidak mengecek lintas kelompok) → tetap tidak dicek [K], terdokumentasi | `L:1527-1536` |

---

### 6.4 C-04 — Approval berjenjang cuti

**Tujuan.** State machine cuti yang ketat, tanpa jalan buntu, dengan efek final hanya di final (D1, D2, D3, D4, D7, D19).

**Dependency.** C-02, C-03, F0-05 (`SyncsToSnapshot`, `BaseSnapshotModel`). **QASMTASK-067**, C-12.

**File boundary.** `backend/app/Libraries/Layanan/Cuti/CutiApprovalService.php` (WAJIB nama ini), `backend/app/Libraries/Layanan/Cuti/{StatusCuti.php (enum/konstanta 0,1,2,3,4 + label), EfekFinalCuti.php, PengingatCuti.php, PetugasApprovalCutiService.php}`, `backend/app/Controllers/Api/Layanan/{CutiController.php (process, tunda, pengingat), CutiPetugasApprovalController.php}`, `backend/app/Models/Layanan/{PaLayananModel.php, JenisPaModel.php}`; test `backend/tests/Layanan/Approval/{CutiStateMachineTest.php, CutiSingleEndNodeTest.php, CutiEfekFinalTest.php, CutiTundaTest.php, CutiApproverTest.php, PetugasApprovalCutiTest.php}`.

#### 6.4.1 Penentuan AL & PyB (D3)
- **AL** = port `getAL_es2(nip)` (`FH:1700-~1945`): kolom atasan pertama yang terisi `pegawai_mutasi_jabatan.id_atasan_es_4 → es_3 → es_2 → es_1`; pemegang jabatan aktif (`id_jenis_status IN (1,12)`); prioritas **Plh** aktif → definitif → **Plt** (`getPLT`, `FH:1946`); pejabat `id_sub_group_jabatan = 1` → AL = Menteri (tanpa NIP). Implementasi di `AtasanLangsungCuti` (baru). `Libraries/Kepegawaian/Lkh/AtasanLangsung::untuk()` (port `getAL` untuk LKH) **dipakai ulang hanya bila terbukti setara**; perbedaannya (prioritas Plh, turun es4→es1) WAJIB dicatat di laporan M3; DILARANG mengubah perilaku `AtasanLangsung` (kontrak LKH beku).
- **PyB** = daftar `getListPYB()` (`L:12-279`): `pa_layanan.id_jenis_pa = 1`, cocok `id_unit_pemohon`, `id_group_jabatan_pemohon`, dan `gol_ruang_pemohon` (JF/JFU gol I/II: golongan saja; lainnya gol/ruang; PPPK: `gol_pppk`) atau `id_sub_group_jabatan_pemohon` (struktural); satker UPT (unit 6/7, GJ ≠ 5) wajib cocok `id_satker_petugas` (kecuali gol III/d–IV/e di unit 7). Pejabat: koordinator (`id_jabatan_koord`) → struktural (Plh, lalu definitif) → `pa_layanan.nip` (JF/JFT) → Plt. Anomali kurung kurawal ganda (`Local.php:2376, 2567`) tidak dibawa. Daftar dikembalikan `GET cuti/opsi` (`pyb[]`).
- **Langkah tunggal**: `nip_atasan_langsung` kosong/NULL **atau** sama dengan `nip_yang_menyetujui` → keputusan pertama langsung final (D2).
- Approver ditentukan **NIP** pemanggil (`AuthContext::nip`), role apa pun di route 1,2,3,6,7 (perbaikan A-14 bahwa hanya role 2 yang bisa approve).

#### 6.4.2 State machine cuti

```
                       POST cuti (role 2/7)                 POST cuti (role 1, status=1)
                               |                                         |
                               v                                         v
                    +----------------------+                  [efek final §6.4.5]
                    |  0  Pengajuan        |                             |
                    |  (Menunggu AL, atau  |                             v
                    |   Menunggu PyB bila  |                       +-----------+
                    |   langkah tunggal)   |                       | 1 Disetujui|
                    +----------------------+                       +-----------+
          dua langkah |  AL setujui          | langkah tunggal: PyB/AL setujui ---> 1 (final)
                      v                      |                  tolak      ---> 2 (final)
          +---------------------------+      |                  tangguhkan ---> 4 (final, jenis 1)
          | 3 Disetujui AL /          |      |
          |   Menunggu PyB            |      +-- dua langkah: AL tolak      ---> 2 (final)
          +---------------------------+      +-- dua langkah: AL tangguhkan ---> 4 (final, jenis 1) [K A-05]
             | PyB setujui    -> 1 (final)
             | PyB tolak      -> 2 (final)
             | PyB tangguhkan -> 4 (final, jenis 1)

  Final: 1 Disetujui · 2 Ditolak · 4 Ditangguhkan  — tidak ada transisi keluar (kecuali hapus fisik §6.2.6).
  0 dan 3 selalu punya pelaku: 0 → AL (atau PyB/AL langkah tunggal); 3 → PyB. Hapus/ubah: §6.2.1.
```

| # | Dari | Aksi | Oleh | Syarat | Ke | Efek samping |
|---|---|---|---|---|---|---|
| T1 | — | ajukan | pegawai 2/7 | validasi §6.2.3 | 0 | `status_al = 0`, `status_pyb = 0`; notif ke AL (atau PyB bila langkah tunggal), tembusan KT |
| T2 | — | input admin status 0 | role 1 | validasi | 0 | sama T1 |
| T3 | — | input admin status 1 | role 1 | validasi + kuota | **1** | `status_al = status_pyb = 1`; efek final; notif ke pemohon |
| T4 | 0 (dua langkah) | setujui | NIP = AL | — | **3** | `status_al = 1`, **`status_pyb` tetap 0** (tidak ditulis) [K — diverifikasi 26-10: `set_param_process` `L:2654-2660` dan `sp_process_esign` `L:3810-3818` tidak menyentuh `status_pyb`; COMMENT D1:4431 "3 Disetujui AL - Waiting PYB" tidak dipakai kode; keputusan #3]; `reason_note_al = NULL`; `keterangan_petugas_approval .= "- Disetujui {nama} - ({jabatan})"`; `show_atasan = 1`; **tanpa efek final**; notif pemohon ("Sedang Diproses") + PyB ("perlu persetujuan") + KT |
| T5 | 0 (dua langkah) | tolak | NIP = AL | `reason_note` wajib | **2** | `status_al = 2`, `reason_note_al`, `reason_note`; notif pemohon (+alasan) + KT |
| T6 | 0 (dua langkah) | tangguhkan | NIP = AL | jenis 1 | **4** | `status_al = 4`; efek final (status 4); notif pemohon + KT |
| T7 | 0 (langkah tunggal) | setujui/tolak/tangguhkan | NIP = PyB (atau AL bila PyB kosong) | sama T4–T6 | **1/2/4** | `status_al = status_pyb = <keputusan>`, `show_pejabat = 1`; efek final bila 1/4 |
| T8 | 3 | setujui | NIP = PyB | kuota diulang | **1** | `status_pyb = 1`, `show_pejabat = 1`; efek final; notif pemohon + KT |
| T9 | 3 | tolak | NIP = PyB | `reason_note` wajib | **2** | `status_pyb = 2`, `reason_note_pyb`, `reason_note`; notif pemohon (+alasan) + KT |
| T10 | 3 | tangguhkan | NIP = PyB | jenis 1 | **4** | `status_pyb = 4`; efek final (4); notif |
| T11 | 0 / 3 | tunda | NIP approver tahap aktif | sebelum `tgl_mulai` 12:00 | (tetap) | `skip_al = 1` (tahap AL) / `skip_pyb = 1` (tahap PyB); **status tidak berubah** |
| T12 | 0 | ubah | pemilik / role 1 | §6.2.1 | 0 | notif "Perubahan Cuti Online" ke approver tahap aktif |
| T13 | 0, 2 (pemilik) / semua (role 1) | hapus | §6.2.1 | — | (hilang) | §6.2.6 |

`keterangan_petugas_approval`: baris AL ditulis ulang (overwrite) saat T4–T6, baris PyB **ditambahkan** dengan pemisah `"\n"` (jalur `process()` `L:2672-2686`; jalur e-sign memakai `<br/>` `L:3784-3792` — tidak dibawa; keputusan #7 26-10); FE merender baris baru.

Setiap transisi lain → 422 `errors.status` "Permohonan cuti tidak dalam tahap yang dapat Anda proses." (contoh: PyB memproses status 0 dua langkah, AL memproses status 3, siapa pun memproses 1/2/4 — menutup A-02). Pemanggil bukan approver tahap aktif tetapi boleh melihat (pemilik, KT, role 1/3) → **403**; tidak boleh melihat → **404**.

Pemetaan pelaku per status (bukti **single end node**): status 0 → pelaku AL (dua langkah) atau PyB/AL (langkah tunggal), keduanya selalu ada NIP-nya karena wajib saat ajukan (kecuali eselon I → §12 ⛔ Q-06); status 3 → PyB (NIP wajib). Status 1/2/4 terminal. Tidak ada status lain yang bisa dicapai. Test `CutiSingleEndNodeTest` **menelusuri semua jalur** dari 0 (enumerasi semua urutan aksi yang sah, pola BFS) dan memastikan setiap jalur berakhir di {1,2,4} dalam ≤ 2 keputusan, dan dari setiap status non-final ada ≥ 1 aksi sah oleh pelaku yang ada.

#### 6.4.3 Kontrak `POST cuti/{id}/process`
Body JSON `{aksi: "setujui" | "tolak" | "tangguhkan", reason_note?: string}`.
| Kasus | Respons |
|---|---|
| `aksi` kosong/tidak dikenal | 422 `errors.aksi` "Silahkan setujui, tolak, atau tangguhkan permohonan cuti online pegawai." (teks E12 diperbaiki) |
| `tolak` tanpa `reason_note` | 422 `errors.reason_note` "Silahkan isi alasan penolakan jika menolak permohonan cuti online pegawai." (`L:3657-3663`) |
| `reason_note` > 255 byte | 422 `errors.reason_note` (batas ApprovalDialog) |
| `tangguhkan` untuk jenis ≠ 1 | 422 `errors.aksi` "Penangguhan hanya berlaku untuk cuti tahunan." (`views/template/modal.php:1323-1334`) |
| Kuota tidak cukup saat final | 422 `errors.lama_cuti` "Sisa cuti pegawai tahun {Y}: {n} hari, tidak mencukupi…" (`vp_process_esign`) |
| Tahap salah | 422 `errors.status` |
| Sukses | 200 item terbaru (`status`, `label_status`, `tahap[]`, `aksi`) |

Urutan pemeriksaan: route role (403) → baris ada & terlihat (404) → pemanggil approver tahap aktif (403) → validasi body (422) → transaksi: kunci `pegawai` pemohon `FOR UPDATE` + baca ulang `riwayat_cuti` terkunci → cek tahap (422) → kuota (422) → tulis → efek final → `inbox` → commit → enqueue push.

#### 6.4.4 Penanda `aksi {}` per item (penegakan server, dipakai FE)
`ubah`, `hapus` (§6.2.1); `proses` = pemanggil approver tahap aktif dan status ∈ {0,3}; `tangguhkan` = `proses` && jenis 1; `tunda` = `proses` && sekarang < `tgl_mulai` 12:00 && flag tahap belum 1; `esign` = role 1/3 lingkup && status ∈ {1,4} && belum ada surat ber-TTE; `unduh_surat` = status ∈ {1,4} && ada surat.

#### 6.4.5 Efek final (D4) — hanya pada transisi ke 1 atau 4
Diimplementasikan di `EfekFinalCuti` dan dipanggil **hanya** dari T3/T6/T7(1,4)/T8/T10 lewat `syncToActiveSnapshot()` model `RiwayatCutiModel extends BaseSnapshotModel` (pola F0-05: sync hanya di final approval, bukan Model Events, audit manual):
1. **Saldo** (jenis 1): `KuotaCutiService::potong()` (§6.3.5).
2. **CLTN** (jenis 6) status 1 dan hari ini dalam `tgl_mulai..tgl_akhir` → `pegawai.id_jenis_status = 3` [K `L:2416-2433`, `L:3494-3503`] lewat model pegawai beraudit; CLTN yang mulai di masa depan **tidak** diubah (A-12 [K], Fase 5 memutuskan aktivasi).
3. **Surat cuti PDF belum TTE** dirender (§6.5.2) dan disimpan `document_attachment` (kode surat cuti, versi belum TTE).
4. `audit_logs` manual untuk setiap tulis lewat query builder.
Transisi ke **3** dan ke **2** **tidak** memanggil apa pun dari daftar di atas (diverifikasi test per langkah: saldo, `pegawai.id_jenis_status`, `document_attachment` tidak berubah).

#### 6.4.6 Transaksi
Pola `transBegin()` → cek `transStatus()` → `transCommit()`/`transRollback()` + `resetTransStatus()` (acuan `RiwayatEngine.php:715-744`, `MasterAkJfService.php:144-151`). **DILARANG `transException(true)`** (juga jangan menyalin contoh di docblock `Lampiran/KolomBerkas.php:21`). Notifikasi `inbox` ditulis di dalam transaksi; push di-enqueue **sesudah commit**.

#### 6.4.7 Tunda pengingat (`skip_al`/`skip_pyb`, D2)
- `POST cuti/{id}/tunda`: pemanggil = approver tahap aktif; status 0 (tahap AL; langkah tunggal: tahap PyB) atau 3 (tahap PyB); sekarang < `tgl_mulai` 12:00 (C:969-974) → set `skip_al = 1` atau `skip_pyb = 1` (C:983-990). Selain itu 422 `errors.status`. **Tidak mengubah status, tidak memindah tahap.**
- `GET cuti/pengingat` = daftar `persetujuan` pemanggil dengan flag tahapnya = 0. FE menampilkan dialog "Pengingat Persetujuan Cuti" sekali setelah login bila daftar tidak kosong, dengan tombol per baris "Proses" dan "Tunda".
- Flag di-reset ke 0 untuk NIP itu saat **login berikutnya** [K MYC:363-366, 395, 419-422, 436, 444-449]. Mekanisme reset di v2 menyentuh modul Auth → **⛔ Q-07** (dijawab sebelum M3 bagian tunda).

#### 6.4.8 Petugas approval cuti (`pa_layanan`) [K C:1009-1171, `L:3958-4516`]
- Daftar: role 1 semua; role 3 `id_unit_pemohon = unit pemanggil` (`L:4058-4081`); kolom Layanan, Jenis Pemohon, Unit/GJ/SGJ/Gol Pemohon, Unit/Satker/GJ/SGJ Petugas, Nama/NIP/Jabatan Petugas (nama = pemegang jabatan saat ini, Plt bila kosong, `L:4000-4053`).
- Tambah/ubah: `id_jenis_pa`, `id_jenis_pegawai` (1/6), `id_unit_pemohon`, `id_group_jabatan_pemohon`; GJ pemohon ∈ {2,3} → `gol_ruang_pemohon` wajib, selain itu `id_sub_group_jabatan_pemohon` wajib; `id_unit_petugas`, `id_satker_petugas`, `id_group_jabatan_petugas`, `id_sub_group_jabatan_petugas`, petugas (`struk_<id_jabatan>` | `koord_<id_jabatan_koord>` | `other_<nip>` → kolom `id_jabatan`/`id_jabatan_koord`/`nip`) (`L:4401-4516`). Whitelist kolom (bukan mass-assignment `$postData`). Ubah hanya baris status 1, status dipaksa 1 (C:1103). Role 3 ubah/hapus hanya unit sendiri [K C:1090-1095, 1149-1154]; **tambah** oleh role 3: `id_unit_pemohon` wajib = unit pemanggil → 422 `errors.id_unit_pemohon` (= pembatasan dropdown legacy `L:4170-4172`; server legacy tidak mengecek `L:4208-4240, 4479` sehingga baris yatim tak terlihat/terubah oleh pembuatnya — §12 Q-08 disesuaikan 26-10, keputusan #8; bukan penjaga keamanan D19).
- Hapus: fisik [K `L:4357`] + audit (kecuali DBV memutuskan status 10).

#### 6.4.9 AC C-04 (QASMTASK-067)
- **AC-04.1** Alur dua langkah 0 → 3 → 1: setujui AL menghasilkan `status = 3` (= "Atasan Langsung" di DoD), setujui PyB menghasilkan `status = 1` (= "Pejabat Yang Berwenang/final").
- **AC-04.2** Tolak di tahap mana pun → `status = 2` + `reason_note` tersimpan + notifikasi ke pengaju memuat alasan; tolak tanpa alasan → 422.
- **AC-04.3** "Skip": (a) AL kosong atau NIP AL = NIP PyB → satu keputusan dari 0 langsung ke 1/2/4 (tahap AL terlewat); (b) `POST …/tunda` men-set `skip_al`/`skip_pyb = 1` tanpa mengubah `status`, item hilang dari `GET cuti/pengingat`, tetap ada di `GET cuti?mode=persetujuan`, dan muncul lagi setelah reset login.
- **AC-04.4** Efek final per langkah: setelah T4 (→3) saldo, `pegawai.id_jenis_status`, `document_attachment` **tidak berubah**; setelah T8 (→1) atau T10/T6 (→4) berubah sesuai §6.4.5; setelah T5/T9 (→2) tidak berubah.
- **AC-04.5** Tanpa stuck/dead-end: `CutiSingleEndNodeTest` membuktikan semua jalur berakhir di {1,2,4}; proses ulang status final → 422; PyB memproses status 0 dua langkah → 422; AL memproses status 3 → 422.
- **AC-04.6** Unit test state machine mencakup T1–T13 dan setiap transisi terlarang.
- **AC-04.7** Approver ditentukan NIP: pegawai role 7 atau 3 yang menjadi AL dapat memproses; pegawai role 2 yang bukan AL/PyB baris itu → 403 (terlihat) / 404 (tidak terlihat).
- **AC-04.8** Dua permintaan persetujuan paralel untuk pemohon yang sama tidak dapat membuat saldo negatif (test kunci `FOR UPDATE`, `db-isolasi-penuh` bila memakai koneksi kedua).

**DILARANG (C-04):** menerima `appr_as`/`allow_esign`/tahap dari klien; memutus status final; menjalankan efek final pada status 3 atau 2; membuat status baru di luar 0/1/2/3/4; mengubah `AtasanLangsung::untuk()`; menambah penjaga keamanan di luar §7.9.

---

### 6.5 C-05 — Penerbitan surat cuti (e-sign mock)

**Tujuan.** Admin (role 1/3 lingkup) menerbitkan surat cuti ber-TTE setelah final memakai **mock** `EsignGatewayInterface`; setiap percobaan tercatat di `esign_hist` (D12, D13).

**Dependency.** C-04, C-01 (`esign_hist`, `jenis_rwy` baru), F0-15 (`Libraries/Esign/MockEsignAdapter.php`, `Config/Services.php:462-474`), ADR-033 PDF. **QASMTASK-068.**

**File boundary.** `backend/app/Controllers/Api/Layanan/CutiEsignController.php` (WAJIB nama ini), `backend/app/Libraries/Layanan/Cuti/{SuratCutiService.php, EsignCutiService.php}`, `backend/app/Models/Layanan/EsignHistModel.php`, view `backend/app/Views/pdf/surat_cuti.php`; docblock `backend/app/Interfaces/EsignGatewayInterface.php` (perbaikan nama kolom, aditif, setelah DBV-022); test `backend/tests/Layanan/Esign/{SuratCutiTest.php, EsignCutiTest.php, EsignHistTest.php, EsignRoleTest.php}`.

#### 6.5.1 Aturan
- Hanya status **1 atau 4** (`L:3263`). Status lain → 422 `errors.status` "Surat cuti hanya dapat diterbitkan untuk cuti yang sudah disetujui atau ditangguhkan."
- Hanya role **1** dan **3** (`bolehProses` lingkup); role lain → 403 (DoD 068-4). Route `Role::filter(1, 3)`.
- Penanda tangan = NIP `nip_yang_menyetujui` (PyB) [K `L:3396-3405`]; langkah tunggal dengan PyB kosong → NIP AL; tanpa NIP (eselon I) → 422 `errors.nip` "Data pejabat yang berwenang menyetujui cuti tidak terdaftar didalam sistem." (C:683-710). Penanda tangan final → §12 Q-09.
- Sudah ada surat ber-TTE → 422 `errors.status` "Surat cuti sudah ditandatangani." (tidak menimpa berkas ber-TTE, perbaikan E9).
- `passphrase` **wajib** di **body JSON**; kosong → 422 `errors.passphrase` "Passphrase tanda tangan digital tidak boleh kosong." Passphrase **DILARANG** muncul di URL/query, log, `audit_logs`, `esign_hist`, respons, atau pesan galat (E1 tidak dibawa ke kontrak v2).
- **DILARANG** memanggil layanan eksternal; adapter = `service('esign')` driver `mock` (`Config\Integrations`). Swap real Fase 6 tidak boleh mengubah kontrak ini.

#### 6.5.2 PDF surat cuti
- Render lewat `App\Libraries\Pdf\Pdf::renderer()` (`PdfRendererInterface`, TCPDF, ADR-033) + `DokumenPdf` (A4, kop kementerian, kaki/disclaimer TTE) dari view `Views/pdf/surat_cuti.php` = port isi `views/hr/employee/rwy/cuti/pdf/surat_cuti.php` [K] (data pemohon, jenis, tanggal, lama, alamat & telepon selama cuti, AL, PyB, catatan pertimbangan). Satu generator untuk semua jalur (perbaikan E10). **Kop = kop kementerian (K1)** [keputusan Horii #9 26-10]: `App\Libraries\Pdf\KopDokumen::html(null, null)` (MAKE-037) **tanpa lingkup** — nama + alamat kementerian dari `web_config` (`pdf_header_nama_kementerian` ?? `nama_kementerian`, `pdf_header_alamat_kementerian`; bawaan `KopDokumen::NAMA_INSTANSI_BAWAAN` bila kosong), tanpa logo; satu kop untuk surat unduhan maupun ber-TTE. Dasar: surat resmi/TTE legacy memakai `MY_PDF_HEADER` kementerian (`L:943-975`, `L:3268-3300`); kop unit/satker `MY_PDF_HEADER_UNIT` hanya pada unduhan detail (`L:811-858`) dan **tidak dibawa** (§12 Q-10).
- QR: pola PDF LKH Fase 3 (`Pdf::qrImg()`, isi kode identitas dokumen, bukan URL berkas publik). E8 (QR = URL publik) tidak direplikasi karena v2 tidak punya URL arsip publik.
- Versi **belum TTE** dibuat otomatis saat efek final (§6.4.5 #3) dan disimpan `document_attachment` (kode surat cuti, penanda versi sesuai DBV DQ-21). Versi ber-TTE disimpan sebagai lampiran terpisah; `GET cuti/{id}/surat` mengembalikan versi ber-TTE bila ada.

#### 6.5.3 Alur `POST cuti/{id}/esign` (urutan WAJIB; menutup E5/E6 sesuai D19)
1. Route role (403) → baris ada & terlihat (404) → `bolehProses` lingkup (403) → body (422) → status 1/4 & belum ber-TTE (422).
2. Ambil PDF belum TTE (render ulang bila belum ada); `checkUser(nip)` adapter.
3. `signPdf(pdf, nip, passphrase, options)` — `options` memuat koordinat/tag (`tag_koordinat '##'`, `tampilan visible`, `reason`, `location` [K `L:3376-3407`]).
4. Gagal (`status_code !== '200'` atau `checkUser` gagal): tulis `esign_hist` `status = '2'`, `keterangan` = pesan gateway (dipotong 255), `status_code` (bila kolom disetujui DBV) — **di luar transaksi bisnis** (commit tersendiri, tidak ikut rollback); respons 422 `errors.passphrase`/`errors.nip` dengan pesan gateway; status cuti **tidak berubah**; PDF sumber tidak rusak. **Tidak silent-fail.**
5. Sukses: transaksi bisnis — kunci & baca ulang `riwayat_cuti` (`FOR UPDATE`), cek ulang status 1/4 & belum ber-TTE, simpan PDF ber-TTE ke `document_attachment` (`AttachmentService`, kompensasi berkas saat rollback), `audit_logs`; commit.
6. Setelah langkah 5 (commit **atau** rollback): tulis `esign_hist` hasil akhir — `status = '1'` bila tersimpan; bila penyimpanan gagal: `status = '2'`, `keterangan` "Dokumen ditandatangani gateway tetapi gagal disimpan: …" (perbaikan E5). Satu baris per percobaan; append-only.
7. Isi `esign_hist`: `nip` penanda tangan, `nik` dari `pegawai.nik` penanda tangan (NOT NULL; NIK kosong → langkah 4 dengan pesan "NIK pejabat belum terdaftar"), `nama_lengkap` bergelar, `modul = 'Cuti Online'`, `id_rw` = `id_riwayat_cuti`, `file_pdf_to_sign` = path/kunci berkas belum TTE, `ip_address`, `user_agent`, `created_at`.
8. Notifikasi ke pemohon "Surat Cuti Online Terbit" (§6.6) sesudah commit sukses.

#### 6.5.4 AC C-05 (QASMTASK-068)
- **AC-05.1** Sukses: PDF dirender → `MockEsignAdapter::signPdf` dipanggil → PDF ber-TTE (berisi `MOCK_TRAILER`) tersimpan di `document_attachment` dengan kode `jenis_rwy` surat cuti dan `id_entri` = id cuti.
- **AC-05.2** Adapter diambil dari `service('esign')` (driver mock); tidak ada panggilan jaringan (test memakai Fake/mock; tidak ada konfigurasi host).
- **AC-05.3** Gagal (NIP tanpa sertifikat → 404 mock, passphrase salah/kosong) → baris `esign_hist status='2'` dengan `keterangan` berisi pesan gateway, tetap ada walau transaksi bisnis gagal/rollback; respons bukan 200; status cuti tetap.
- **AC-05.4** Role 2, 4, 5, 6, 7, 8 → 403; role 3 di luar lingkup → 403; role 1 & 3 dalam lingkup → 200.
- **AC-05.5** Passphrase tidak ditemukan di log aplikasi, `audit_logs`, `esign_hist`, respons (test grep isi tabel & log uji).
- **AC-05.6** Penerbitan kedua atas cuti yang sudah ber-TTE → 422; berkas ber-TTE tidak tertimpa.

**DILARANG (C-05):** memanggil BSrE/host apa pun; menyimpan passphrase; menulis `esign_hist` di dalam transaksi yang bisa di-rollback; menimpa surat ber-TTE; membuat jalur e-sign otomatis tanpa aksi admin.

---

### 6.6 C-06 — Notifikasi approval

**Tujuan.** Setiap perubahan status layanan menghasilkan notifikasi satu arah di `inbox`/`inbox_msg` v2 + job push mock FCM di Queue; tidak pernah menyentuh `disiplin_ticket*` (D10, D11).

**Dependency.** C-01 (⛔ DBV-022 disetujui — **coding C-06 DILARANG sebelum itu**), C-04, F0-13 (`Config/Queue.php`), F0-17 (`Libraries/Push/MockFcmAdapter.php`). **QASMTASK-069.**

**File boundary.** `backend/app/Libraries/Layanan/NotifikasiService.php` (WAJIB nama ini), `backend/app/Libraries/Layanan/Notifikasi/{TemplatNotifikasi.php, PenerimaNotifikasi.php}`, `backend/app/Jobs/PushNotifLayananJob.php`, `backend/app/Config/Queue.php` (aditif: `jobHandlers['push-notif']`, antrean `push`), `backend/app/Models/Layanan/{InboxModel.php, InboxMsgModel.php, FirebaseTokenModel.php}`, `README-deploy.md` (aditif: worker `php spark queue:work push --stop-when-empty --max-time 55`); test `backend/tests/Layanan/Notifikasi/{NotifikasiServiceTest.php, NotifikasiPenerimaTest.php, NotifikasiTidakKeTicketingTest.php, PushNotifJobTest.php (db-isolasi-penuh)}`.

#### 6.6.1 API service (kontrak internal, dipakai C-04/C-05/C-07..C-09)
`NotifikasiService::kirim(string $modul, int|string $idRwy, int $tipe, array $penerima /* NIP[] */, string $judul, string $isi, ?string $reasonNote, int $statusSumber, string $url): void` — dipanggil **di dalam** transaksi pemanggil untuk menulis `inbox`/`inbox_msg`; mengembalikan daftar id untuk di-enqueue pemanggil **sesudah commit** (`enqueuePush(array $ids)`). Penerima duplikat digabung; NIP pemicu (pelaku aksi) tidak dikirimi notifikasi atas aksinya sendiri.

#### 6.6.2 Penerima & templat (D11; teks dari legacy `FH:762-915`, `L:3538-3637`, `L_notification.php:2579-2665, 3201-3245`; typo "anda anda" N7 diperbaiki)
| Modul | Kejadian | Penerima | Judul | Isi |
|---|---|---|---|---|
| Cuti | diajukan (T1/T2) | AL (dua langkah) / PyB (langkah tunggal) | "Permohonan Cuti Online Baru" | "{nama} telah mengajukan cuti ({nama_jenis_cuti}) untuk tanggal {tgl_mulai} s/d {tgl_akhir}." |
| Cuti | diajukan | ketua tim (CC) | "Tembusan Cuti Online" | "{nama} melakukan pengajuan {nama_jenis_cuti} dari tanggal {tgl_mulai} s.d {tgl_akhir}." |
| Cuti | diubah (T12) | approver tahap aktif | "Perubahan Cuti Online" | "Data: ({nama_jenis_cuti}) {tgl_mulai} s/d {tgl_akhir} oleh {nama} / {nip}" |
| Cuti | AL setuju (T4) | pemohon | "Cuti Online Sedang Diproses" | "Pengajuan cuti anda pada tanggal {tgl_mulai} s/d {tgl_akhir} disetujui atasan langsung {nama_AL} dan menunggu persetujuan {nama_PyB}." |
| Cuti | AL setuju (T4) | PyB | "Permohonan Cuti Online Baru" | seperti "diajukan" |
| Cuti | final 1/4/2 | pemohon | "Cuti Online Disetujui" / "Cuti Online Ditangguhkan" / "Cuti Online Ditolak" | "Pengajuan cuti anda pada tanggal … s/d … {disetujui/ditangguhkan/ditolak} oleh {nama approver}." + bila tolak ". Ket: {reason_note}" (`FH:847-852`) |
| Cuti | setiap perubahan status | ketua tim | "Tembusan Cuti Online" | "… {disetujui/ditolak/sedang diproses/ditangguhkan} …" (`L_notification.php:2636-2648`) |
| Cuti | surat ber-TTE terbit | pemohon | "Surat Cuti Online Terbit" | "Surat cuti tanggal … s/d … sudah ditandatangani secara elektronik." |
| TB / IB | diajukan / diubah status 0 | **semua PIC aktif** jenis 3 / 4 (tanpa syarat unit — D14 [V2]) | "Permohonan Tugas Belajar Baru" / "Perubahan Tugas Belajar" (IB: "Izin Belajar") | "Data: {jenjang} - {institusi} ({jurusan}), {tgl_mulai} s/d {tgl_akhir} oleh {nama} / {nip}" |
| TB / IB | disetujui / ditolak | pemohon | "Tugas Belajar Disetujui/Ditolak" (IB serupa) | "{jenjang} - {institusi} ({jurusan}), {tgl_mulai} s/d {tgl_akhir}" + ". Ket: {reason_note}" bila tolak (perbaikan N6) |
| PMK | diajukan | semua PIC aktif jenis 5 | "Permohonan Peninjauan Masa Kerja Baru" | "Pengajuan di {instansi} ({jenis_pmk}) mulai tanggal {tgl_awal} s/d {tgl_akhir} dengan masa kerja {mker_th} tahun {mker_bl} bulan oleh {nama} / {nip}" |
| PMK | proses / selesai / ditolak | pemohon | "Peninjauan Masa Kerja Sedang Diproses/Selesai/Ditolak" | isi PMK `L_notification.php:3241-3245` + alasan bila tolak |

Kolom kompatibilitas [K] `riwayat_*.show_notif = 1`, `notif_date = now` diisi service pada transisi yang setara trigger legacy (`riwayat_cuti_beUpd` D1:8839-8844: status lama ∈ {0,3} → baru ∈ {1,2,3,4}; TB/IB D1:10478, :9169; PMK `L_pmk.php:1781, 1938`) — **pengganti trigger** (V3); bukan sumber notifikasi v2.

#### 6.6.3 Push mock FCM
- Sesudah commit, untuk tiap `inbox_msg` baru: `service('queue')->push('push', 'push-notif', ['id_inbox_msg' => …])` (pola `QueueDummy.php:30`, `ResetPasswordEmailJob.php:24-48`, `TRIES` ≤ 5). Gagal enqueue → `log_message('error', …)` + `inbox_msg.push_status = 2`; **tidak** membatalkan transaksi bisnis yang sudah commit.
- Job: baca token `firebase_token` milik `nip_penerima` → `PushNotifGatewayInterface::sendMulticast()` (mock, `Config/Services.php:490-502`); tanpa token → `push_status = 3`, selesai tanpa error; sukses → 1; gagal → 2 + retry sampai `TRIES` lalu `queue_jobs_failed` (tidak retry selamanya, H-04). Payload: `{title, body, data: {modul, id_rwy, url}}`; DILARANG memuat NIK/passphrase.
- DILARANG memanggil FCM real/HTTP apa pun.

#### 6.6.4 AC C-06 (QASMTASK-069)
- **AC-06.1** Setiap transisi T1–T10 (cuti) serta transisi TB/IB/PMK menulis ≥ 1 baris `inbox_msg` (dan `inbox` induk) untuk penerima §6.6.2 dengan `status_sumber` = status baru.
- **AC-06.2** Tolak menghasilkan notifikasi ke pengaju yang memuat `reason_note`.
- **AC-06.3** Setiap `inbox_msg` baru menghasilkan tepat satu baris `queue_jobs` antrean `push` job `push-notif` setelah commit; tidak ada baris bila transaksi rollback; worker (test `db-isolasi-penuh`) memanggil `MockFcmAdapter` (`sent()` berisi payload).
- **AC-06.4** **Regression wajib** `NotifikasiTidakKeTicketingTest`: setelah menjalankan seluruh skenario cuti/TB/IB/PMK (ajukan, setujui, tolak, tangguhkan, proses, e-sign), `SELECT COUNT(*)` dari `disiplin_ticket` dan `disiplin_ticket_msg` = **0**; tambahan: `NotifikasiService` tidak punya dependensi ke model/tabel ticketing (assert statis daftar tabel yang ditulis).
- **AC-06.5** Ketua tim CC menerima tembusan dan baris `riwayat_cuti_notif_kt` terbentuk.

**DILARANG (C-06):** coding sebelum ⛔ DBV-022 disetujui; menulis `disiplin_ticket*`; membuat UI/endpoint pusat notifikasi (F-08); trigger DB; push sinkron di request; menghit layanan eksternal.

---

### 6.7 C-07 — Tugas Belajar

**Tujuan.** Pengajuan TB, approval PIC satu langkah, perpanjangan, dan penanda bebas presensi untuk Fase 5 (D14, D15).

**Dependency.** C-01, C-06 (untuk notifikasi; inti CRUD boleh paralel, §9). Dependency eksplisit konsumen: D-06 Fase 5. **QASMTASK-070.**

**File boundary.** `backend/app/Controllers/Api/Layanan/TbController.php` (WAJIB), `backend/app/Libraries/Layanan/Tb/{TbService.php, TbApprovalService.php, PerpanjanganTbService.php, StatusTugasBelajar.php (bebasPresensiPenuh), SuratTbService.php}`, `backend/app/Models/Layanan/{RiwayatTbModel.php, RiwayatTbPerpanModel.php}`, `backend/app/Libraries/Layanan/PetugasLayananService.php` (cek PIC, dipakai bersama C-08/C-09/C-10), view `Views/pdf/surat_tb.php`; test `backend/tests/Layanan/Tb/{TbCrudTest.php, TbApprovalTest.php, TbPerpanjanganTest.php, BebasPresensiPenuhTest.php, TbRoleTest.php, TbSingleEndNodeTest.php}`.

#### 6.7.1 Hak (Matriks 1,2,3,6; legacy `Tb.php`)
Role **7 → 403 di semua endpoint TB** (DoD 070-4); role 4/5/8 → 403. Pengajuan: 2, 6 (diri sendiri), 3 (pegawai dalam lingkup, status 0), 1 (PNS `id_jenis_pegawai = 1`, status 0\|1). Proses: **PIC TB aktif** (`petugas_layanan` jenis 3, status aktif) dengan role ∈ {1,2,3,6} — tanpa batas unit (D14 [V2], perbaikan TB-01). Perpanjangan: role 1 saja.

#### 6.7.2 Kontrak `POST tb` / `PUT tb/{id}` (multipart) [K `L_tb.php:821-1085`]
| Field | Wajib | Validasi / pesan 422 |
|---|---|---|
| `tgl_mulai`, `tgl_akhir` | ya | `tgl_akhir >= tgl_mulai` "Tanggal akhir tidak boleh sebelum tanggal mulai." (`L_tb.php:891-901`) |
| `lama_tahun` | — | **dihitung server** = `diff.y + diff.m/12` 1 desimal (`Local.php:3185-3217`) (§12 Q-03) |
| `id_jenjang_pendidikan` | ya | master `jenjang_pendidikan` status 1 dan `id > 3`; `jenjang_pendidikan_singkat` diisi server |
| `institusi_pendidikan`, `jurusan_pendidikan`, `lokasi_pendidikan`, `lembaga_yang_mendanai`, `up` | ya | "{Label} wajib diisi." (`L_tb.php:848-864`); `up` bawaan "Kepala Biro Sumber Daya Manusia dan Organisasi" |
| `dalam_negeri` | tidak divalidasi [K] | 1 Dalam Negeri / 2 Luar Negeri |
| `tembusan`, `keterangan` | tidak | kosong → NULL |
| `status` | role 1 saja | 0\|1; bila 1: `no_sk`, `tgl_sk`, `ds_tb`, `no_ds_tb` wajib (`L_tb.php:870-887`) |
| Berkas `file_sp` (Surat Pengantar), `file_1` (Ket. Kampus), `file_2` (Ket. BAN PT), `file_3` (Pernyataan Bermaterai) | wajib saat tambah; saat ubah wajib bila belum ada; **role 1 dibebaskan** (`L_tb.php:907, 942`) | 5 MB; satu whitelist `jpg, jpeg, png, gif, pdf, doc, docx` untuk tambah & ubah (perbaikan TB-05, §12 Q-15); `ValidasiBerkas` (CR-054); disimpan `document_attachment` kode TB |
| Tumpang tindih | — | NIP sama, rentang beririsan, status ∈ {0,3,4} [K `L_tb.php:928-937`] (status 1 ikut dicek = §12 Q-15) → `errors.tgl_mulai` |
| Snapshot `nama, gol_ruang, pangkat, jabatan, unit, satker, pendidikan_dasar_singkat` | — | diambil **server** saat tambah (urutan `gol_ruang`/`pangkat` benar — perbaikan TB-06) |
| Kolom [K] tak dipakai alur v2 | — | `status_admin_upt`, `status_admin_deputi`, `sp_admin_deputi`, `rs_*`, `show_ua_*`: **tidak diisi** (NULL) di v2 (D14 satu langkah); data impor dipertahankan |

Ubah [K]: pemilik status 0 & `tgl_mulai > hari ini` (hari H ditolak); role 3 status 0 + lingkup; role 1 semua status kecuali 2; `status` pegawai dipaksa 0. Data SK pada `PUT` role 1 ikut tersimpan (perbaikan TB-09, §12 Q-15).

#### 6.7.3 State machine TB (D14)
```
 POST tb (2/6, 3, 1 status 0) --> [0 Pengajuan] --PIC setujui--> [1 Disetujui]  (final)
                                       |        --PIC tolak----> [2 Ditolak]    (final, reason_note wajib)
                                       +-- ubah/hapus (§6.7.1)
 POST tb (role 1, status 1) --------------------------------------> [1 Disetujui] (final)
 Kode 3 (Diproses) [K] tidak ditulis v2; 10 tidak dipakai (hapus fisik [K]).
```
| Dari | Aksi | Oleh | Ke | Efek |
|---|---|---|---|---|
| — | ajukan | 2, 6, 3, 1 | 0 | notif ke semua PIC TB aktif |
| — | input role 1 status 1 | 1 | 1 | efek final TB (bawah) + Surat TB [K TB-07 disamakan dengan approval — §12 Q-15] |
| 0 | setujui | PIC TB | 1 | `no_sk`, `tgl_sk`, `ds_tb`, `no_ds_tb` wajib, `tgl_ds_tb`/`perihal_ds_tb` opsional; `file_surat_tb` opsional di server (PDF 5 MB) [K, wajib di FE]; `reason_note = NULL`, `show_pl = 1`, `approved_by`; efek final; notif pemohon |
| 0 | tolak | PIC TB | 2 | `reason_note` wajib (kontrak `process` platform); notif pemohon + alasan |
| 1, 2 | apa pun | — | — | 422 `errors.status` (final, menutup "process status 0/3/4" legacy) |

Efek final TB (hanya → 1) [K D15, `L_tb.php:1169-1180`]: bila **hari ini** dalam `tgl_mulai..tgl_akhir` → `pegawai.id_jenis_status = 2` (model beraudit). Hapus TB status 1 → `id_jenis_status` kembali 1 bila hari ini dalam rentang dan masih 2 (`L_tb.php:746-760`). Tidak ada cron (TB yang mulai di masa depan tidak mengubah status — [K]; konsumen Fase 5 memakai `bebasPresensiPenuh`, bukan `id_jenis_status`). Nilai master `jenis_status` 2 wajib ada (data impor); test menyiapkan seed.

#### 6.7.4 Perpanjangan (`riwayat_tb_perpan`, D15) [K `L_tb.php:1297-1537`]
- `POST tb/{id}/perpanjangan` role 1: `tgl_mulai_perpan` (bawaan = `tgl_akhir_perpan` induk atau `tgl_akhir` + 1 hari), `tgl_akhir_perpan` wajib, berkas `file_sperpan` wajib (5 MB, whitelist §6.7.2). Baris terpisah, `id_riwayat_tb` = induk. Cek `tgl_akhir_perpan >= tgl_mulai_perpan` dan induk berstatus 1 = §12 Q-15 (default [V2] dicek).
- `PUT` hanya dua tanggal; `DELETE` fisik + audit.
- Setelah tiap tambah/ubah/hapus, **dalam transaksi yang sama**: `riwayat_tb.tgl_mulai_perpan = MAX(tgl_mulai_perpan)`, `tgl_akhir_perpan = MAX(tgl_akhir_perpan)` dari semua perpanjangan induk; tak ada lagi → NULL (pengganti trigger `riwayat_tb_perpan_af*` D1:10487-10527). `riwayat_tb.tgl_akhir` tidak diubah. Tanpa notifikasi, tanpa ubah `id_jenis_status` [K].
- Hapus TB induk → hapus semua perpanjangan + berkasnya eksplisit dulu (FK RESTRICT; perbaikan TB-14).

#### 6.7.5 `bebasPresensiPenuh(nip, tanggal)` (D15) — kontrak untuk Fase 5
`App\Libraries\Layanan\Tb\StatusTugasBelajar::bebasPresensiPenuh(string $nip, string $tanggal /* YYYY-MM-DD */): bool` = ada `riwayat_tb` milik `nip` dengan `status = 1` **dan** (`tgl_mulai <= tanggal <= tgl_akhir` **atau** ada `riwayat_tb_perpan` induknya dengan `tgl_mulai_perpan <= tanggal <= tgl_akhir_perpan`) [K `L_presensi.php:972-977, 1660, 4127`; `L_user.php:118-119`]. Tambahan `rentangBebasPresensi(nip, from, to): array` (daftar tanggal) untuk efisiensi Fase 5. Didaftarkan `Config\Services::statusTugasBelajar()` + interface `App\Interfaces\Layanan\StatusTugasBelajarInterface`. Respons TB memuat `status_penuh` = `bebasPresensiPenuh(nip, hari ini) ? 1 : 0` (penanda DoD 070-3).

#### 6.7.6 Surat TB
`GET tb/{id}/surat` = render on-the-fly templat `print/surat_tb` [K Tb.php:326-473]: kop, penanda tangan = pemegang jabatan Kabiro kepegawaian (`web_config.id_jabatan_kabiro_kepegawaian`, `tmtsk` terbaru) → sumber v2 §12 Q-06. Bila `file_surat_tb` (SK unggahan PIC) ada → itu yang diunduh.

#### 6.7.7 AC C-07 (QASMTASK-070)
- **AC-07.1** Pengajuan TB oleh role 2/6 (status 0), role 3 (lingkup), role 1 (0/1) berhasil dengan berkas wajib; validasi §6.7.2 punya test 422.
- **AC-07.2** Perpanjangan = baris `riwayat_tb_perpan` terpisah dengan `id_riwayat_tb` induk; ringkasan `tgl_*_perpan` induk ter-update pada tambah/ubah/hapus.
- **AC-07.3** `bebasPresensiPenuh` true di dalam rentang TB disetujui, true di rentang perpanjangan, false di luar, false untuk TB status 0/2; respons TB `status_penuh` 1/0 sesuai hari ini.
- **AC-07.4** Role 7 → 403 di **setiap** endpoint TB (test per route).
- **AC-07.5** Single end node: semua jalur 0 → {1,2}; status final tidak bisa diproses; PIC dari unit mana pun melihat antrean `mode=pic` (perbaikan D14).
- **AC-07.6** Notifikasi: PIC menerima pengajuan baru; pemohon menerima keputusan (+alasan).

**DILARANG (C-07):** menambah kolom `status_penuh`; alur bertingkat admin UPT/deputi; mengubah `Libraries/Presensi/**`; pegawai mengajukan perpanjangan (role 1 saja, [K]).

---

### 6.8 C-08 — Izin Belajar

**Tujuan.** CRUD pengajuan IB di luar jam kerja + approval PIC IB satu langkah (D14).

**Dependency.** C-01, C-06, `PetugasLayananService` (C-07). **QASMTASK-071.**

**File boundary.** `backend/app/Controllers/Api/Layanan/IbController.php` (WAJIB), `backend/app/Libraries/Layanan/Ib/{IbService.php, IbApprovalService.php, SuratIbService.php}`, `backend/app/Models/Layanan/RiwayatIbModel.php`, view `Views/pdf/surat_ib.php`; test `backend/tests/Layanan/Ib/{IbCrudTest.php, IbApprovalTest.php, IbRoleTest.php, IbSingleEndNodeTest.php}`.

#### 6.8.1 Aturan (sama TB kecuali disebut) [K `Ib.php`, `L_ib.php`]
- Role Matriks **1,2,3,7**; **role 6 → 403 di semua endpoint IB** (DoD 071-3); 4/5/8 → 403.
- Pengajuan: 2, 7 (diri sendiri), 1 dan 3 (input admin; 3 lingkup); status **selalu 0** (admin tidak bisa langsung setuju, Ib.php:128-171). Bug legacy `add_ad` tanpa `$_FILES` (Ib.php:140) **tidak** dibawa — input admin berfungsi (IB-01, §12 Q-15).
- Field sama TB tanpa `file_3` dan tanpa perpanjangan; `nama` NOT NULL; snapshot server (`L_ib.php:799-841`). Berkas `file_sp`, `file_1`, `file_2` **wajib semua role** termasuk role 1 (`L_ib.php:705`) [K].
- Proses PIC IB (jenis 4) dari status 0: setujui → `ds_ib`, `no_ds_ib`, `no_sk`, `tgl_sk` wajib, `tgl_ds_ib`/`perihal_ds_ib` opsional, `show_pl = 1`, `approved_by`; **Surat IB PDF dibuat otomatis** (port `print/surat_ib`, penanda tangan Kabiro) dan disimpan `document_attachment` kode surat IB (`L_ib.php:896-964`) — efek final IB; tolak → 2 + `reason_note` wajib. Tanpa efek ke `pegawai.id_jenis_status`, presensi, tukin (IB-5 [K]).
- Teks salah salin legacy ("Ubah Tugas Belajar", "tugas belajar") diperbaiki (IB-05).

#### 6.8.2 State machine IB
`0 Pengajuan → (PIC setujui) 1 Disetujui | (PIC tolak) 2 Ditolak`; 1/2 final; tidak ada input "langsung disetujui".

#### 6.8.3 AC C-08 (QASMTASK-071)
- **AC-08.1** CRUD IB untuk role 2/7 (sendiri), 1/3 (admin), sesuai aturan ubah/hapus TB.
- **AC-08.2** Pengajuan baru tersimpan `status = 0` ("pending"), juga untuk input admin.
- **AC-08.3** Role 6 → 403 di setiap endpoint IB.
- **AC-08.4** Single end node 0 → {1,2}; setuju menghasilkan surat IB di `document_attachment`.

**DILARANG (C-08):** perpanjangan IB; status awal selain 0; efek ke presensi/tukin.

---

### 6.9 C-09 — PMK (Peninjauan/Penyesuaian Masa Kerja)

**Tujuan.** Pengajuan PMK oleh PNS dan persetujuan PIC PMK satu alur berujung single end node, tanpa efek masa kerja (D16).

**Dependency.** C-01, C-06, `PetugasLayananService`. **QASMTASK-072.**

**File boundary.** `backend/app/Controllers/Api/Layanan/PmkController.php` (WAJIB), `backend/app/Libraries/Layanan/Pmk/{PmkPengajuanService.php, PmkApprovalService.php, RiwayatPmkService.php}`, `backend/app/Models/Layanan/{RiwayatPmkModel.php, RiwayatPmkReqModel.php}`; test `backend/tests/Layanan/Pmk/{PmkPengajuanTest.php, PmkApprovalTest.php, PmkRoleTest.php, PmkSingleEndNodeTest.php, RiwayatPmkTest.php}`.

#### 6.9.1 Aturan [K `Pmk.php`, `L_pmk.php`]
- Role Matriks **1,2,3**; role 4,5,6,7,8 → 403 di semua endpoint PMK (DoD 072-4). PMK hanya PNS (P-17 [K]): pengajuan pegawai role 2; input admin role 1 hanya untuk `id_jenis_pegawai = 1`; role 3 lihat dalam lingkup saja.
- Field pengajuan: `jenis_pmk` (Swasta/Negeri; validasi enum = §12 Q-15), `instansi`, `tgl_awal`, `tgl_akhir` (`>= tgl_awal`, dicek bila tidak ada galat lain `L_pmk.php:801`), batas **TMT CPNS**: galat bila `TMT CPNS < tgl_awal` (`L_pmk.php:811-822`) [K; perbaikan P-3 = §12 Q-15], tumpang tindih dengan pengajuan lain NIP sama berstatus ≠ 2 (`L_pmk.php:846-855, 880-891`), `mker_th`/`mker_bl` **dari klien** (diisi FE lewat `POST pmk/hitung-mker`) [K D19/P-4], tidak boleh kosong.
- Berkas 4 kolom [K] `file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji`: wajib saat tambah; saat ubah wajib bila belum ada; **2 MB**, `jpg, jpeg, png, gif, pdf` (P-6 [K]); disimpan di kolom lewat `KolomBerkas` (pola Fase 3) — tidak di `document_attachment`. Hapus pengajuan → berkas ikut dibuang (perbaikan P-9).
- Ubah: pemilik (role 2, lingkup platform) / role 1; aturan ubah ikut legacy [K D19] (ISSUE-048); hapus ditolak bila status 1 (`Pmk.php:357, 693`) → 422 `errors.status`; hapus fisik + audit.
- Riwayat PMK final (`riwayat_pmk`) hanya dari impor/SIASN [K D16, P-2]: `GET pmk` (2 sendiri, 3 lingkup, 1 semua), `PUT pmk/{id}` role 1 = **tambah arsip SK** saja (`document_attachment` kode 42, arsip wajib, batas 2 MB diterapkan ke berkas arsip — perbaikan P-7), `DELETE pmk/{id}` role 1 fisik + audit, `DELETE pmk/{id}/arsip/{id_attachment}` role 1 dengan minimal tersisa 1 arsip.

#### 6.9.2 State machine PMK (D16)
```
 POST pmk/pengajuan (2 / 1) --> [0 Pengajuan] --PIC proses--> [3 Proses] --PIC setujui--> [1 Selesai/Disetujui] (final)
                                     |                            |
                                     |--PIC setujui-------------------------------------> [1] (final)  [K P-14 0->1]
                                     |--PIC tolak---> [2 Ditolak] (final) <---PIC tolak---+
```
| Dari | Aksi | Oleh | Ke | Efek |
|---|---|---|---|---|
| — | ajukan | 2 / 1 | 0 | notif semua PIC PMK aktif |
| 0 | proses | PIC PMK | 3 | `show_notif = 1`, `notif_date`, `approved_at`, `approved_by` [K `L_pmk.php:1774-1792`]; notif pemohon |
| 0, 3 | setujui | PIC PMK | 1 | sama + notif pemohon; **tanpa** salin ke `riwayat_pmk`, **tanpa** ubah masa kerja/KP/KGB (D16) |
| 0, 3 | tolak | PIC PMK | 2 | `reason_note` wajib (`L_pmk.php:1749-1772`); notif pemohon + alasan |
| 1, 2 | apa pun | — | — | 422 `errors.status` (status final dikunci; P-14 "1→x lewat URL" tidak dibawa karena single end node) |
Label UI: 0 "Menunggu PIC", 3 "Diproses", 1 "Selesai", 2 "Ditolak". Antrean `mode=pic` = semua pengajuan status 0 dan 3, tanpa batas unit [K P-19]; redirect/endpoint mati legacy (`req_pic`, `process_req_ad`) tidak dibawa (P-12, P-13).

#### 6.9.3 AC C-09 (QASMTASK-072)
- **AC-09.1** Pengajuan oleh role 2 (PNS) & role 1 (atas nama PNS) dan persetujuan oleh PIC PMK berjalan end-to-end.
- **AC-09.2** Pengajuan baru `status = 0` ("pending").
- **AC-09.3** Single end node: semua jalur 0 → {1,2} (lewat 3 atau langsung); 1 dan 2 tidak bisa diproses.
- **AC-09.4** Role 4, 5, 6, 7, 8 → 403 di setiap endpoint PMK; role 3 tidak bisa menulis (403).
- **AC-09.5** Persetujuan tidak menulis `riwayat_pmk` dan tidak mengubah tabel pegawai/masa kerja (test membandingkan sebelum/sesudah).

**DILARANG (C-09):** menyalin ke `riwayat_pmk`; menghitung ulang masa kerja; membuka PMK untuk PPPK/PTT.

---

### 6.10 C-10 — Dashboard status layanan & PIC

**Tujuan.** Satu tampilan monitoring semua pengajuan layanan (cuti, TB, IB, PMK) dengan tahap & PIC, plus penunjukan PIC oleh Super Admin (D17 [V2]; legacy hanya halaman "Sedang Dalam Pengembangan", `L_layanan.php:10-12`).

**Dependency.** C-02, C-07, C-08, C-09. **QASMTASK-073.**

**File boundary.** `backend/app/Controllers/Api/Layanan/LayananDashboardController.php` (WAJIB), `backend/app/Controllers/Api/Layanan/PetugasLayananController.php`, `backend/app/Libraries/Layanan/{LayananDashboardService.php, PetugasLayananService.php}`, `backend/app/Models/Layanan/{JenisLayananModel.php, PetugasLayananModel.php}`; test `backend/tests/Layanan/Dashboard/{LayananStatusTest.php, LayananStatusRoleTest.php, PetugasLayananCrudTest.php}`.

#### 6.10.1 `GET layanan/status`
- Gabungan baris dari `riwayat_cuti` (jenis "Cuti"), `riwayat_tb` ("Tugas Belajar"), `riwayat_ib` ("Izin Belajar"), `riwayat_pmk_req` ("Peninjauan Masa Kerja"); query param `jenis` (`cuti|tb|ib|pmk`), `status` (`berjalan` = non-final, `selesai` = final, atau kode per jenis), `id_unit`, `id_satker`, `search` (nama/NIP), `page`, `per_page` (1..100, bawaan 20).
- Item: `{jenis, id, nip, nama, unit, satker, tanggal_pengajuan (created_at), ringkasan (mis. "Cuti Tahunan 02-11-2026 s/d 05-11-2026"), status, label_status, nada_status, tahap: [{kode, label, state: done|current|todo, nama_petugas, waktu}], petugas: [{peran, nip, nama}], url_detail}`. `tahap` & `label_status` **dihitung server** (§6.11.4).
- `petugas`: cuti → AL & PyB (nama dari snapshot `nama_atasan_langsung`, `nama_yang_menyetujui`); TB/IB/PMK → **semua PIC aktif** `petugas_layanan` jenis 3/4/5 (nama, NIP); jenis tanpa PIC aktif → `[]` dan `catatan: "PIC belum ditunjuk"`.
- Lingkup data: role **2, 6, 7** = pengajuan **sendiri** saja, dari modul yang boleh dilihat role itu (6: cuti + TB; 7: cuti + IB; 2: semua); role **1** = semua; role **3** = `PegawaiScope::terapkanKeQuery` lingkup; role **4, 5** = monitoring lihat-saja sesuai `PegawaiScope` (`bolehLihat`) untuk role itu; role **8 → 403**. Urut `created_at DESC`.
- Item tidak punya aksi tulis; satu aksi ⋮ "Lihat detail" (`url_detail` ke halaman modul) hanya bila pemanggil boleh melihat detail modul itu (role 4/5 tidak punya halaman detail modul → item tanpa aksi).

#### 6.10.2 PIC `layanan/petugas` [K Layanan.php:77-222, `L_layanan.php`]
- `GET` (1–7): daftar `{id_petugas_layanan, id_jenis_layanan, jenis_layanan, nip, nama, jabatan, satker, unit, status}`, filter `id_jenis_layanan`; urut `status DESC, id DESC`.
- `POST`/`PUT` (role 1): `id_jenis_layanan` (jenis_layanan **aktif** — perbaikan opsi nonaktif legacy), `nip` (kandidat: pegawai `status = 1`, `id_jenis_pegawai = 1`, `pmj.id_satker = satker kepegawaian` [K `L_layanan.php:38-46`]; sumber nilai satker kepegawaian v2 → §12 Q-06), `status` (Aktif/Tidak Aktif sesuai domain DBV). Duplikat `(id_jenis_layanan, nip)` → 422 `errors.nip` "Petugas sudah terdaftar untuk layanan ini." (`L_layanan.php:192-242`). Whitelist kolom (bukan mass-assignment).
- `DELETE` (role 1): fisik [K `L_layanan.php:166`] + audit (atau status 10 bila DBV memutuskan).
- Cek "PIC aktif" dipakai C-07/C-08/C-09 lewat `PetugasLayananService::adalahPic(nip, idJenisLayanan): bool` (status aktif saja; anomali Karpeg/Kariskarsu tanpa filter status tidak disentuh di Fase 4).

#### 6.10.3 AC C-10 (QASMTASK-073)
- **AC-10.1** Role 1–7 dapat membuka `GET layanan/status`; role 2/6/7 hanya melihat pengajuannya sendiri (test: data milik NIP lain tidak muncul); role 3 hanya lingkup; role 8 → 403.
- **AC-10.2** Penunjukan PIC per jenis layanan oleh role 1 berhasil; role 2–7 mendapat 403 pada `POST/PUT/DELETE layanan/petugas`.
- **AC-10.3** PIC tampil benar: item TB/IB/PMK menampilkan PIC aktif jenis yang sesuai; PIC nonaktif tidak tampil; item cuti menampilkan AL & PyB.
- **AC-10.4** Pegawai melihat semua jenis pengajuannya (cuti/TB/IB/PMK) dengan `tahap[]` yang konsisten dengan state machine modul masing-masing.

**DILARANG (C-10):** memakai alur mockup (Review Ketua Tim, Validasi Kabid, Proses TTE, Penerbitan SK) sebagai tahap; endpoint tulis selain PIC; ekspor.

---

### 6.11 C-11 — Frontend modul layanan

**Tujuan.** Antarmuka Modul C sesuai QAUI-004, AGENTS §1, dan token redesign; mockup `LayananStatusPage` (`origin/feat/frontend-ui-redesign`, commit `67fa767`) = **acuan visual saja** (D17) — alurnya asumsi, tanpa PMK & PIC; ikuti PRD.

**Dependency.** C-02..C-10 (per halaman boleh mulai setelah backend modulnya merged, §9). **QASMTASK-074.** Figma Modul C belum ada (QAUI-004 bloker) → QA Lapis 1 memakai mockup Gambar 17 + pola komponen redesign (§12 Q-18).

**File boundary.** `frontend/src/features/layanan/**` (`views/`, `components/`, `composables/`, `services/`, `stores/`, `schemas/`, `types.ts`, `roles.ts`, `routes.layanan.ts`, `__tests__/`), `frontend/src/router/index.ts` (aditif: impor `routes.layanan.ts`), `frontend/src/shared/layouts/nav.config.ts` (entri Fase 4 + `ACTIVE_PHASE`), `frontend/src/shared/**` hanya **aditif** (komponen baru yang dipakai ≥ 2 modul). DILARANG mengubah perilaku komponen bersama yang ada.

#### 6.11.1 Halaman & route
| Route (name) | Halaman | Role (meta, UX saja) | Isi |
|---|---|---|---|
| `/layanan/cuti` (`layanan-cuti`) | `CutiPage.vue` | 1,2,3,6,7 | Tab: **Cuti Saya** (2,6,7; tombol "Ajukan Cuti" hanya bila `can_create`), **Perlu Persetujuan** (semua role route; badge jumlah), **Monitoring** (1,3). Tabel + ⋮ (`cuti-actions-<id>`) |
| `/layanan/cuti/:id` (`layanan-cuti-detail`) | `CutiDetailPage.vue` | 1,2,3,6,7 | Data, timeline/stepper tahap, riwayat approval & alasan, lampiran, surat, aksi sesuai `aksi {}` |
| dialog | `CutiFormDialog.vue` | 1,2,7 | Form 2 kolom (`formLayout.ts`): jenis (dropdown dari `opsi`), datepicker mulai/akhir (`tgl_akhir` maks dari server), durasi (jenis 2), **kalkulator lama & sisa kuota real-time** (§6.11.3), telp, alamat, keterangan, AL (readonly dari server), PyB (dropdown `pyb[]`), CC ketua tim (multi-select), unggah `file_bukti` (`UiFileField`, wajib untuk Sakit), role 1: pilih pegawai + status 0/1 |
| dialog | `CutiProsesDialog.vue` (membungkus `ApprovalDialog`) | approver | Setujui / Tolak (alasan **wajib**) / Tangguhkan (hanya jenis 1) |
| dialog | `PengingatCutiDialog.vue` | 1,2,3,6,7 | Sekali setelah login bila `GET cuti/pengingat` tidak kosong; "Proses" / "Tunda" (mekanisme §6.4.7, ⛔ Q-07) |
| dialog | `EsignDialog.vue` | 1,3 | Konfirmasi + input passphrase `type="password"` (perbaikan E7), dikirim di body; galat gateway ditampilkan |
| `/layanan/cuti/saldo` | `SaldoCutiPage.vue` | 1,3 | Tabel saldo per tahun + ⋮ Edit/Hapus, tombol Tambah |
| `/layanan/cuti/petugas-approval` | `PetugasApprovalCutiPage.vue` | 1,3 | CRUD `pa_layanan` |
| `/layanan/tugas-belajar` (+`/:id`) | `TbPage.vue`, `TbDetailPage.vue`, `TbFormDialog.vue`, `TbProsesDialog.vue`, `TbPerpanjanganDialog.vue` | **1,2,3,6** (tanpa 7) | Tab Saya / Perlu Persetujuan (PIC) / Monitoring; perpanjangan (role 1) |
| `/layanan/izin-belajar` (+`/:id`) | `IbPage.vue`, `IbDetailPage.vue`, `IbFormDialog.vue`, `IbProsesDialog.vue` | **1,2,3,7** (tanpa 6) | Pola TB tanpa perpanjangan |
| `/layanan/pmk` (+`/pengajuan/:id`) | `PmkPage.vue`, `PmkDetailPage.vue`, `PmkFormDialog.vue`, `PmkProsesDialog.vue` | **1,2,3** | Tab Riwayat PMK / Pengajuan Saya / Perlu Persetujuan (PIC) / Monitoring; proses: Proses / Setujui / Tolak |
| `/layanan/status` (`layanan-status`) | `LayananStatusPage.vue` | **1–7** | Kartu "Status Layanan", filter Unit/Satker (1,3,4,5) & Jenis & Status, pencarian, jumlah baris 10/25/50, tabel **Nama Pegawai (avatar + NIP) · Jenis Layanan · Status Layanan (UiStepper) · PIC/Petugas · Aksi ⋮** (`layanan-actions-<jenis>-<id>`), `UiPagination` |
| `/layanan/petugas` | `PetugasLayananPage.vue` | 1 | CRUD PIC per jenis layanan (`layanan-petugas-actions-<id>`) |

#### 6.11.2 Menu per role (`nav.config.ts`, grup "Layanan", `phase: 4`)
| Item | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
|---|---|---|---|---|---|---|---|---|
| Cuti | ✓ | ✓ | ✓ | – | – | ✓ | ✓ | – |
| Saldo Cuti, Petugas Approval Cuti | ✓ | – | ✓ | – | – | – | – | – |
| Tugas Belajar | ✓ | ✓ | ✓ | – | – | ✓ | **–** | – |
| Izin Belajar | ✓ | ✓ | ✓ | – | – | **–** | ✓ | – |
| PMK | ✓ | ✓ | ✓ | – | – | – | – | – |
| Status Layanan | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Petugas Layanan (PIC) | ✓ | – | – | – | – | – | – | – |
Konstanta role per menu di `features/layanan/roles.ts` (pola `features/kepegawaian/roles.ts`). Guard route = UX saja; penegakan di backend. **`ACTIVE_PHASE` dinaikkan dari 3 ke 4 hanya di milestone penutup C-11 (M7)** setelah semua halaman Fase 4 ada di `main` (pola WS-2 B-20); sebelum itu menu Fase 4 tersembunyi.

#### 6.11.3 Kalkulator real-time
Saat jenis/tanggal/durasi berubah (debounce ±300 ms) FE memanggil `POST cuti/hitung-lama` dan menampilkan `lama_cuti`, `tgl_akhir` (jenis 2), daftar hari libur dalam rentang, dan panel kuota (jenis 1: sisa N/N-1/N-2, menunggu, diajukan, sisa setelah pengajuan, pesan `cukup=false`). **FE DILARANG menghitung hari kerja/kuota sendiri** (ADR-025/026); tombol Simpan tidak dikunci oleh hasil kalkulator (validasi akhir tetap di server, 422 ditampilkan per kolom).

#### 6.11.4 Badge, stepper, warna (server menyediakan `label_status`, `nada_status`, `tahap[]`)
| Modul | Kondisi | `label_status` | Nada `UiBadge` |
|---|---|---|---|
| Cuti | status 0, dua langkah | **Menunggu Atasan** | `warning` |
| Cuti | status 3, atau status 0 langkah tunggal | **Menunggu PyB** | `info` |
| Cuti | 1 | **Disetujui** | `success` |
| Cuti | 2 | **Ditolak** | `danger` |
| Cuti | 4 | **Ditangguhkan** | `secondary` |
| TB/IB | 0 / 1 / 2 | Menunggu PIC / Disetujui / Ditolak | `warning` / `success` / `danger` |
| PMK | 0 / 3 / 1 / 2 | Menunggu PIC / Diproses / Selesai / Ditolak | `warning` / `info` / `success` / `danger` |

Stepper (`UiStepper`): Cuti dua langkah = Diajukan → Atasan Langsung → Pejabat Berwenang → Selesai; langkah tunggal = Diajukan → Pejabat Berwenang → Selesai; TB/IB = Diajukan → Verifikasi PIC → Selesai; PMK = Diajukan → Diproses PIC → Selesai. Tahap ditolak/ditangguhkan ditandai pada tahap yang memutus (label "Ditolak oleh …"). Badge satu baris + `title` teks penuh (CR-054). Warna lewat token redesign (bukan hex lama, K-23). Komponen status cuti **baru** (`features/layanan/components/StatusLayananBadge.vue`, `cutiStatus.ts`) — DILARANG memakai `StatusBadge`/`statusRiwayat.ts` (arti kode berbeda).

#### 6.11.5 Komponen dipakai ulang (wajib)
`ApprovalDialog.vue` (alasan wajib saat Tolak ≤ 255 byte, emit `{aksi, reason_note}`; varian "Tangguhkan" dibuat sebagai pembungkus di Modul C, bukan mengubah komponen), `UiStepper`, `UiBadge`, `UiFileField` (validasi mengikat tetap backend), `RowActionsMenu` + `rowActions.ts`, `ConfirmDialog`, `FormField`, `formLayout.ts`, `useStickyActionsShadow` (kolom Aksi sticky kanan + bayangan di **semua** tabel), `UiPagination`, `UiSearchInput`, `UiSelect`, `UiTabs`, `UiNotice`, `UiCard`. Form memakai VeeValidate + Zod (`schemas/`), service lewat `@/lib/axios`, store Pinia per domain.

#### 6.11.6 Data & responsif
- **Normalisasi angka**: semua kode/angka dari backend (MySQLi mengembalikan string `"1"`) dinormalkan di `services/*.service.ts` sebelum dibandingkan (`Number(...)`), pola `features/kepegawaian/services/pegawai.service.ts:38-39` (CR-049). Test Vitest **wajib** memakai payload string.
- Responsif lebar **1440 / 600 / 390 px**: tanpa scroll horizontal halaman (tabel boleh scroll di wadahnya), kolom Aksi sticky kanan, form 2 kolom → 1 kolom di ≤ 600 px, dialog muat di 390 px. WCAG 2.1 AA: label form, aria-label tombol ⋮, fokus dialog.
- Item ⋮ per tabel mengikuti urutan AGENTS §1: Edit → (status) → Lihat detail, Setujui, Tolak, Tangguhkan, Proses, Tunda, Perpanjang, Terbitkan TTE, Unduh surat → Hapus (`danger`). `hidden`/`disabled` dari `aksi {}` server.
- Galat 422 ditampilkan per kolom (`serverErrors`); 403 → pesan "Anda tidak berhak …"; 404 → "Data tidak ditemukan".

#### 6.11.7 AC C-11 (QASMTASK-074, QAUI-004)
- **AC-11.1** Timeline/pill badge per tahap dengan warna berbeda per status (tabel §6.11.4) di daftar, detail, dan Status Layanan.
- **AC-11.2** Modal approve/reject memakai `ApprovalDialog`; Tolak tanpa alasan tidak bisa dikirim (FE) dan ditolak 422 (BE).
- **AC-11.3** Form pengajuan cuti (datepicker, dropdown jenis, unggah lampiran), TB, IB, PMK tersedia dan menampilkan 422 per kolom.
- **AC-11.4** Kalkulator sisa kuota real-time memanggil `POST cuti/hitung-lama` saat isian berubah; tidak ada logika hitung di FE.
- **AC-11.5** Menu per role sesuai tabel §6.11.2 (TB tanpa role 7, IB tanpa role 6); Vitest per role.
- **AC-11.6** AGENTS §1: semua aksi baris lewat ⋮ dengan `label` "Aksi untuk …" dan `testid` `<fitur>-actions-<id>`; Vitest memilih item lewat `data-action`.
- **AC-11.7** Tampilan 1440/600/390 tanpa scroll horizontal halaman; kolom Aksi sticky.
- **AC-11.8** `ACTIVE_PHASE = 4` hanya di commit penutup M7.

**DILARANG (C-11):** menghitung kuota/hari kerja/tahap di FE; tombol ikon aksi per baris; mengubah perilaku `ApprovalDialog`/`StatusBadge`/`approvalActions.ts`; memakai data contoh/mock di halaman produksi (mock hanya di test); menaikkan `ACTIVE_PHASE` lebih awal.

---

### 6.12 C-12 — Unit test & verifikasi

**Tujuan.** Bukti otomatis seluruh business rule Fase 4, terintegrasi CI/`check.sh`.

**Dependency.** C-03, C-04 (dan semua task untuk verifikasi akhir). **QASMTASK-075.** Test berbasis Fake untuk integrasi eksternal (ADR-016).

**File boundary.** `backend/tests/Layanan/**` (unit & feature), `backend/tests/database/Layanan/**` (skema), `frontend/src/features/layanan/__tests__/**`.

#### 6.12.1 Daftar test wajib (nama berkas yang diharapkan)
| Area | Berkas | Isi minimal |
|---|---|---|
| Kuota | `tests/Layanan/Kuota/KuotaBoundaryTest.php`, `KuotaCutiServiceTest.php`, `HitungLamaCutiTest.php` | Tabel §6.3.8 K1–K24 (dataProvider), boundary 24 & 25 |
| State machine | `tests/Layanan/Approval/CutiStateMachineTest.php` | T1–T13 + semua transisi terlarang (422), langkah tunggal (AL kosong, AL = PyB), tunda tidak mengubah status |
| Single end node | `CutiSingleEndNodeTest.php`, `Tb/TbSingleEndNodeTest.php`, `Ib/IbSingleEndNodeTest.php`, `Pmk/PmkSingleEndNodeTest.php` | Enumerasi semua jalur sah dari status awal → berakhir di status final; status final tanpa transisi keluar; setiap status non-final punya pelaku |
| Efek final | `tests/Layanan/Approval/CutiEfekFinalTest.php` | Per langkah: setelah →3 dan →2 tidak ada perubahan saldo/`id_jenis_status`/surat; setelah →1/→4 ada; `syncToActiveSnapshot` dipanggil tepat sekali dan hanya di final |
| Notifikasi | `tests/Layanan/Notifikasi/NotifikasiTidakKeTicketingTest.php`, `NotifikasiPenerimaTest.php`, `PushNotifJobTest.php` | §6.6.4 |
| E-sign | `tests/Layanan/Esign/EsignHistTest.php`, `EsignCutiTest.php`, `EsignRoleTest.php` | §6.5.4 |
| Role 403 | `tests/Layanan/RoleMatriksLayananTest.php` (dataProvider route × role) | Setiap route §5 × role 1–8: 403 untuk role di luar kolom Route; khusus: TB × role 7, IB × role 6, `POST cuti` × role 6 dan 3, PMK × 4–8, esign × 2/4–8, dashboard × 8 |
| Route kontrak | `tests/Layanan/RouteLayananKontrakTest.php` | Semua path §5 terdaftar dengan filter yang benar (pola `RoutePegawaiKontrakTest.php`) |
| TB presensi | `tests/Layanan/Tb/BebasPresensiPenuhTest.php` | §6.7.7 AC-07.3 |
| Skema | `tests/database/Layanan/SkemaLayananTest.php`, `SkemaInboxTicketTest.php`, `MigrasiLayananSiklusTest.php` | §6.1.5 |
| Registry NIP | `tests/database/Kepegawaian/NipReferenceRegistryTest.php` (diperbarui) | Kolom NIP Fase 4 |
| FE | `features/layanan/__tests__/{CutiPage,CutiFormDialog,CutiProsesDialog,LayananStatusPage,TbPage,IbPage,PmkPage,navLayanan}.spec.ts` | Menu per role, ⋮ `data-action`, alasan wajib, kalkulator memanggil API, payload string |

#### 6.12.2 AC C-12 (QASMTASK-075)
- **AC-12.1** Test kuota N/N-1/N-2 termasuk boundary 24 lulus.
- **AC-12.2** Test state machine termasuk skip (langkah tunggal + tunda) lulus.
- **AC-12.3** Test efek final hanya di final step lulus.
- **AC-12.4** Semua test di atas berjalan di CI `quality-gate` (MySQL 8.0 + MariaDB 10.4) dan `./check.sh`; run hijau dilampirkan per milestone.

**DILARANG (C-12):** melonggarkan assertion agar lulus; menandai test `skip`/`incomplete` untuk DoD; test yang memanggil layanan eksternal; test ber-DB tanpa `DatabaseTestCase`.

---

## 7. Aturan lintas modul

### 7.1 Notifikasi
- Struktur: `inbox` (induk per penerima per pengajuan) + `inbox_msg` (kejadian satu arah) sesuai DBV-022; tanpa open/closed/balasan. Penulis tunggal: `NotifikasiService`. Tiket Halo Simpeg tetap `disiplin_ticket*` (Fase 8).
- Enqueue push sesudah commit; driver Queue database (F0-13, ADR-013); job `push-notif` → `PushNotifGatewayInterface` mock (F0-17). Token dari `firebase_token` (kosong sampai Fase 6).
- Regression wajib: tidak ada baris di `disiplin_ticket`/`disiplin_ticket_msg` dari alur Modul C (AC-06.4).
- Event `riwayatNotifikasi` Fase 3 (`RiwayatEngine.php:51-59`) **tidak** wajib dilanggan di Fase 4 (riwayat Modul B di luar scope); bila ingin melanggan → eskalasi (perubahan perilaku Fase 3).

### 7.2 E-sign
`EsignGatewayInterface` (`app/Interfaces/EsignGatewayInterface.php:7-34`) lewat `service('esign')`; mock F0-15; `esign_hist` ditulis di luar transaksi bisnis, satu baris per percobaan, sukses & gagal (D13). Kontrak tidak berubah saat swap real (H-02).

### 7.3 PDF
`App\Libraries\Pdf\Pdf::renderer()` (`PdfRendererInterface`, `TcpdfRenderer`, `DokumenPdf`), `Pdf::qrImg()`, `Pdf::respon()` (ADR-033). Satu generator per jenis surat. Unduhan: `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.

### 7.4 Lampiran
- `AttachmentServiceInterface::simpan(nip, idRiwayat = kode jenis_rwy, idEntri, UploadedFile, AturanLampiran)` di dalam transaksi pemanggil, kompensasi berkas saat rollback (`Interfaces/Kepegawaian/AttachmentServiceInterface.php:10-65`); untuk berkas di kolom (PMK req) `KolomBerkas` (`Lampiran/KolomBerkas.php`) **dengan pola transaksi `transBegin`** (abaikan contoh `transException(true)` di docblock-nya).
- `ValidasiBerkas` (CR-054): ukuran dari disk, MIME dari isi, **ekstensi ≙ isi**, **tanpa ekstensi ditolak**, OOXML/OLE dicek. Batas ukuran per jenis **ikut legacy**: cuti `file_bukti` 5 MB (jpg/jpeg/png/gif/pdf); TB 4 berkas + SK + perpanjangan 5 MB; IB 3 berkas 5 MB; PMK req 4 berkas **2 MB** (jpg/jpeg/png/gif/pdf); arsip SK PMK 2 MB.
- Nama simpan dibangkitkan server; nama klien hanya metadata. Path arsip legacy (impor) → unduh 404 (pola Konket).
- Otorisasi unduh = hak lihat detail baris induk; `id_attachment` bukan milik baris → 404.

### 7.5 PegawaiScope
Semua akses baris milik pegawai lain memakai `PegawaiScopeInterface` (`bolehLihat` untuk baca, `bolehUbah` untuk tulis admin, `bolehProses` untuk e-sign/proses admin, `terapkanKeQuery`/`terapkanKeQueryProses` untuk daftar). Role 3 tanpa pengecualian NIP sendiri (`PegawaiScope.php:14-57`). Bagi pemanggil ber-lingkup, NIP tidak ada vs di luar lingkup dijawab sama (403). Approver cuti (AL/PyB) dan PIC **tidak** memakai lingkup satker (ditentukan NIP / penunjukan PIC).

### 7.6 Atasan langsung
`AtasanLangsungCuti` = port `getAL_es2` (§6.4.1); `AtasanLangsung::untuk()` (LKH) tidak diubah.

### 7.7 Audit
Model Modul C **wajib** `extends BaseAuditableModel` (`Models/Layanan/README.md`); model `riwayat_*` `extends BaseSnapshotModel` (efek final lewat `syncToActiveSnapshot()` hanya di final). Approve/reject dicatat `update` (before/after); hapus fisik `delete` dengan `before`. Tulis lewat query builder (mis. saldo) → `AuditLogModel::record()` manual. Kegagalan audit mengikuti perilaku platform (tidak diubah).

### 7.8 Transaksi & konkurensi
- **DILARANG `transException(true)`**. Pola: `transBegin()` → kerja → `transStatus()` → `transCommit()`/`transRollback()` + `resetTransStatus()` (`RiwayatEngine.php:715-744`).
- Kunci `pegawai` pemohon `FOR UPDATE` lalu **baca ulang** baris layanan terkunci sebelum cek status (TOCTOU); baris `pegawai_sisa_cuti` dikunci saat potong/refund. `getCompiledSelect() . ' FOR UPDATE'` diperbolehkan (pola `PenggunaModel.php:174`); DILARANG merakit SQL dari input (jebakan named bind `:kata:`).
- Notifikasi `inbox` di dalam transaksi; push, `esign_hist`, dan pembuangan berkas sesudah commit/rollback.

### 7.9 Keamanan (D19)
- **Dibiarkan seperti legacy** (risiko diterima, kartu WARNING **ISSUE-048** "Warning (Risiko Diterima)", Trello privat): butir A-01/E3, A-03, A-04, E4, P-1/P-4, E1/E2/E7/E8/E13. Rincian celah **sengaja tidak ditulis** di repo publik — baca kartu ISSUE-048 bila perlu.
- **Tetap berlaku**: JWT; `Role::filter` per route; PegawaiScope; 403/404; state machine ketat + single end node; satu algoritma kuota; refund benar; `esign_hist` tidak silent-fail; MAKE-020 `must_change_password` (route Layanan otomatis terkunci, `Filters/JwtAuthFilter.php:14-33`); CSRF/penjaga platform yang sudah ada.
- **Konsekuensi yang dicatat** (bukan penjaga tambahan): kontrak v2 tidak memuat `appr_as`/`allow_esign`/tahap — tahap diturunkan dari status (state machine); pemanggil `process` harus NIP approver tahap aktif = penjaga legacy jalur `process` (C:584-591); PegawaiScope platform membatasi PMK ke pemilik (P-1 praktis tertutup oleh platform); `lama_cuti` & snapshot dihitung server (§12 Q-03). Semua dikonfirmasi di M0.
- Coder **DILARANG** menambah penjaga keamanan lain dan **DILARANG** memperluas celah (mis. membuka endpoint tanpa route role, menerima field yang legacy tidak terima). Temuan celah baru → laporkan ke reviewer CR (tanpa detail eksploit di repo publik).

### 7.10 Kontrak API (pola Fase 3)
- Prefix `api/v1/`; envelope sukses `{status: "success", data}`; gagal `{status: "error", message, errors?: {<field>: ["…"]}}` (ADR-001); nama field = **nama kolom DDL** snake_case; tanggal bisnis `YYYY-MM-DD`; waktu UTC.
- Daftar: `data: {items, total, page, per_page, …}` (`page` 1..1.000.000, `per_page` dijepit 1..100 bawaan 20, parameter array → 422 "Parameter daftar tidak valid.", `Libraries/ListQuery.php`). Bentuk `{items, meta}` (Plt/Plh) **tidak** dipakai.
- Hak per item `aksi {}` + hak halaman `can_create`/`can_process` = penegakan server.
- Urutan pemeriksaan: 401 → **403** role route → **403** role aksi → **404** baris tidak ada / tidak terlihat → **403** terlihat tetapi bukan pelaku / di luar lingkup tulis → **422** validasi (termasuk `errors.status` untuk transisi salah). 403 ganti password memakai `code: password_change_required`.
- Proses: `POST …/{id}/process` body `{aksi, reason_note}`; alasan wajib saat `tolak` (422); multipart ubah = `POST` + `_method=PUT`.
- Dokumentasi endpoint per task di `backend/app/Controllers/Api/Layanan/README.md` (tabel `Method & path | Role | Isi`, kode status, contoh payload) — bagian dari DoD.

---

## 8. Ketergantungan ke fase lain

| Fase | Butir | WAJIB disediakan Fase 4 | DILARANG dikerjakan sekarang |
|---|---|---|---|
| 5 | D-06 TB penuh bebas presensi; potongan cuti tukin | `StatusTugasBelajarInterface::bebasPresensiPenuh(nip, tanggal)` + `rentangBebasPresensi()` (§6.7.5); data `riwayat_cuti.status = 1` final dengan `id_jenis_cuti` id legacy 1..6 (cocok `StatusHarian::JENIS_CUTI`); status 3/4 **bukan** cuti untuk presensi [K `L_presensi.php:132,549,1003,1607-1612`] | Mengubah `Libraries/Presensi/**`, Tukin, aktivasi CLTN otomatis |
| 6 | H-01/H-02 adapter real BSrE; H-04 FCM real & token | Kontrak `EsignGatewayInterface`/`PushNotifGatewayInterface` dipakai apa adanya; `esign_hist` (+`status_code` bila disetujui DBV); `firebase_token`; regression C-05/C-06 hijau dengan mock | Adapter real, `register_fb_token`, konfigurasi host/kredensial |
| 7 | F-08 Pusat Notifikasi & Inbox (`index`, `need_approval`, `ah`) | Data `inbox`/`inbox_msg` benar per penerima (pegawai/atasan/PIC); antrean persetujuan per modul | UI/endpoint pusat notifikasi; sortir impor F-02 |
| 8 | E-xx Halo Simpeg | Skema final `disiplin_ticket`, `disiplin_ticket_msg`, `question_category` | Logika tiket, `support_officer`, UI Halo Simpeg |
| MT | MT-00x / MTC | — | Apa pun (fase tersendiri) |

---

## 9. Urutan kerja (runbook) dengan ⛔ TITIK TUNGGU

Aturan setiap milestone: (a) branch baru dari **`origin/main` terbaru** (`git fetch` dulu) bernama `fase4/<key>-<ringkas>`; (b) satu key per milestone; (c) gate cepat lokal; (d) push branch; (e) tunggu CI `quality-gate` **hijau** untuk SHA itu; (f) **lapor**: SHA, URL run CI, ringkasan berkas, test yang ditambah, deviasi [V2], blocker, pembaruan `backend/docs/progress/04-Layanan.md`; (g) ⛔ tunggu review & merge reviewer CR (atau PR DBV) sebelum milestone yang bergantung. Coder tidak push ke `main`.

| No | Milestone | Task | Key | Branch | Menunggu | Membuka jalan |
|---|---|---|---|---|---|---|
| M0 | Baca PRD + inspeksi repo + lapor pemahaman | — | (tanpa commit) | — | — | ⛔ reviewer CR menjawab §12 (butir ⛔) & mengonfirmasi key |
| M1 | Dokumen DBV-022 + kueri audit + draf migration (belum dijalankan di DB dev) | C-01 | DBV-022 | `fase4/dbv-022-skema-layanan` (PR) | M0 | ⛔ **DB Validator approve kedua skema** + reviewer CR |
| M2 | Migration + seed uji + registry NIP + test skema, sesuai DBV-022 disetujui | C-01 | DBV-022 (PR sama atau lanjutan) | idem | M1 approve | ⛔ merge PR → semua milestone kode |
| **M2b** | **Tahap 2b (26-10):** `Config\Cuti` + library murni cuti + `KuotaBoundaryTest` + pemindai kemurnian (batas berkas §6.1.4) — tanpa Model/controller/route/migration | C-03, C-04 (bagian murni) | MAKE-022 (bagian awal) | `fase4/make-022-cuti` | M0 (tidak menunggu M1/M2) | M3 (service DB membungkus library murni) |
| M3 | Cuti: CRUD, kalkulator, kuota, state machine, efek final, tunda, pa_layanan, saldo | C-02, C-03, C-04 | MAKE-022 | `fase4/make-022-cuti` | M2 merged; ⛔ Q-07 untuk bagian tunda | M4, M6, M7 (cuti) |
| M4 | E-sign mock + notifikasi + job push (cuti) | C-05, C-06 | MAKE-023 | `fase4/make-023-esign-notifikasi` | M3 merged; ⛔ DBV-022 disetujui (C-06) | M5 wiring notifikasi, M7 |
| M5 | TB (+perpanjangan, `bebasPresensiPenuh`), IB, PMK, `PetugasLayananService` | C-07, C-08, C-09 | MAKE-024 | `fase4/make-024-tb-ib-pmk` | M2 merged (inti); **M4 merged** sebelum menyambung notifikasi | M6, M7 |
| M6 | Dashboard status layanan + CRUD PIC | C-10 | MAKE-025 | `fase4/make-025-dashboard-pic` | M3 & M5 merged | M7 |
| M7 | Frontend Modul C + menu + `ACTIVE_PHASE = 4` (commit penutup) | C-11 | MAKE-026 | `fase4/make-026-frontend-layanan` | backend modul terkait merged | M8 |
| M8 | Verifikasi akhir: test C-12 lengkap, single-end-node, pemisahan skema, matriks 403, `check.sh`/CI hijau, progres & laporan akhir | C-12 | MAKE-027 | `fase4/make-027-verifikasi` | M3–M7 merged | ⛔ sign-off reviewer CR (§12 Q-14) |

**Boleh paralel** (coder berbeda, berkas tidak tumpang tindih): M3 ∥ inti M5 (setelah M2); M7 per halaman segera setelah backend halaman itu merged (cuti setelah M4; TB/IB/PMK setelah M5; Status Layanan setelah M6), `ACTIVE_PHASE` tetap di akhir. **Tidak boleh paralel**: apa pun sebelum M2 merged (kecuali dokumen **dan M2b**); C-06 sebelum DBV-022 disetujui. M2b ∥ M1 (berkas tidak tumpang tindih).

Isi laporan **M0** (wajib sebelum M1): ringkasan pemahaman per task (≤ 3 baris), daftar berkas repo yang akan dipakai ulang (dengan path), konfirmasi tidak ada migration/kode Modul C di `main` selain README, jawaban/konfirmasi yang dibutuhkan dari §12, risiko yang terlihat.

---

## 10. Definition of Done Fase 4 & exit criteria

### 10.1 Fase 4 DONE hanya bila SEMUA berikut terpenuhi
1. QASMTASK-064..075 seluruh AC §6 lulus (Lampiran C), dengan bukti test/run CI.
2. C-12 lulus dan terintegrasi CI `quality-gate` + `./check.sh` (hijau di MySQL 8.0 & MariaDB 10.4).
3. **Single end node** terverifikasi untuk cuti, TB, IB, PMK (test enumerasi jalur).
4. **Pemisahan skema** `inbox*` vs `disiplin_ticket*` terverifikasi sejak C-01 (persetujuan DBV sebelum C-06, test skema, regression notifikasi).
5. Role tak berhak = 403 untuk setiap endpoint (QAFUNC-005), termasuk TB tanpa role 7, IB tanpa role 6, ajukan cuti tanpa role 6 dan 3.
6. Kegagalan e-sign tercatat `esign_hist`; TB penuh ter-flag (`bebasPresensiPenuh`).
7. Dokumentasi endpoint lengkap di `Controllers/Api/Layanan/README.md`; progres di `backend/docs/progress/04-Layanan.md`.
8. Semua butir ⛔ §12 terjawab dan tercatat; deviasi [V2] tercatat.
9. QA Lapis 1 C-11: tetap **IN_PROGRESS** sampai lolos (Figma belum ada → acuan disepakati di §12 Q-18), walau QA fungsional lolos (konvensi `02-MasterData.md:37`).

**DILARANG menyatakan Fase 4 DONE** sebelum butir 1–6 tervalidasi (prompt 2/2).

### 10.2 Aturan IN_PROGRESS
Sesi berhenti sebelum task selesai → status task **IN_PROGRESS** + catatan progres detail di `backend/docs/progress/04-Layanan.md` dengan format seperti `03-Kepegawaian.md`: judul, rujukan PRD ini, "Terakhir diperbarui" (tanggal, branch, SHA, status CI), entry criteria, tabel `Task | Milestone | Key | Status | Ringkas`, lalu subbab per milestone berisi: yang sudah jalan, yang belum, berkas terakhir yang disentuh, test yang gagal/belum, keputusan & deviasi, blocker.

### 10.3 Format laporan akhir (dan per milestone)
```
Status: C-01 DONE|IN_PROGRESS|BLOCKED … C-12 …
Branch/SHA/CI: <branch> <sha> <url run> (hijau/merah)
File utama: <daftar path>
Test & validasi: <berkas test + hasil>, single-end-node: <bukti>, pemisahan skema: <bukti>, kuota boundary 24: <bukti>,
                 notifikasi tidak ke ticketing: <bukti>, 403 per role: <bukti>, check.sh/CI: <bukti>
Deviasi [V2] & keputusan: <daftar>
Blocker / pertanyaan terbuka: <daftar + rujukan §12>
```

---

## 11. Checklist verifikasi sebelum serah (centang per butir di laporan)

- [ ] State machine cuti: 0→3→1, 0→1/2/4 langkah tunggal (AL kosong / AL = PyB), 0/3→2 dengan alasan, →4 hanya Tahunan; transisi terlarang 422 (AC-04.*)
- [ ] "Skip" = langkah tunggal + tunda pengingat; tunda tidak mengubah status (AC-04.3)
- [ ] Efek final hanya pada →1/→4; tidak pada →3/→2; per langkah diuji (AC-04.4, AC-12.3)
- [ ] Kuota: K1 (tepat 24) lolos, K2 (>24) ditolak, seluruh K1–K24 hijau (AC-03.*)
- [ ] Satu algoritma potong N-2 → N-1 → N; refund tidak negatif (AC-03.2, AC-02.5)
- [ ] Notifikasi: `inbox`/`inbox_msg` terisi; `disiplin_ticket*` = 0 baris (AC-06.4); push ter-enqueue sesudah commit (AC-06.3)
- [ ] Single end node: cuti, TB, IB, PMK (test enumerasi) 
- [ ] 403 per endpoint × role (`RoleMatriksLayananTest`), termasuk TB × 7, IB × 6, `POST cuti` × 6 dan 3, PMK × 4–8, e-sign × selain 1/3, dashboard × 8
- [ ] E-sign: gagal tercatat `esign_hist` di luar transaksi; passphrase tidak bocor (AC-05.3, AC-05.5)
- [ ] `bebasPresensiPenuh` benar untuk rentang TB & perpanjangan (AC-07.3)
- [ ] Menu per role sesuai §6.11.2; `ACTIVE_PHASE = 4` hanya di penutup M7
- [ ] AGENTS §1: ⋮ `RowActionsMenu`, urutan & label baku, `hidden`/`disabled` dari server, `ConfirmDialog`/`ApprovalDialog`, aria-label "Aksi untuk …", testid `<fitur>-actions-<id>`, Vitest via `data-action`
- [ ] Normalisasi angka string MySQLi di service FE; test memakai payload string
- [ ] Responsif 1440/600/390, kolom Aksi sticky
- [ ] Test DB `extends DatabaseTestCase`; DDL/worker bertanda `db-isolasi-penuh`; DB scratch port 3307; `.env` dev tidak disalin
- [ ] Akun uji `must_change_password = 0`
- [ ] Tidak ada `transException(true)`
- [ ] Migration lama di `main` tidak diubah; registry NIP diperbarui
- [ ] Commit Bahasa Indonesia `feat|fix(scope): KEY …`, **tanpa** trailer `Co-Authored-By` & tanpa penyebutan AI
- [ ] Tidak ada rahasia/IP/host internal/detail celah di diff
- [ ] Tidak ada pemanggilan layanan eksternal; tidak ada perubahan `Libraries/Presensi/**`
- [ ] Dokumentasi endpoint & `backend/docs/progress/04-Layanan.md` diperbarui
- [ ] Tidak ada pekerjaan/laporan MT-00x/MTC dalam laporan Fase 4

---

## 12. Pertanyaan terbuka yang TERSISA

> **Status 26-10-2026:** seluruh butir **◻** = **disetujui default 26-10** oleh Horii (riset take over), dengan penyesuaian **Q-08, Q-10, Q-21, Q-23** di kolom "Saran default" (teks tercoret = versi v1.0). Butir ⛔ (Q-01, Q-02, Q-06, Q-07, Q-18, Q-20) tetap titik tunggu; temuan legacy pendukung di §14.
>
> **Status 18-10-2026:** user/reviewer CR **menyetujui semua saran default** di tabel ini (butir ⛔ dan ◻). Kolom "Saran default" = **keputusan yang berlaku**. Q-01 tetap mensyaratkan laporan jumlah pejabat AL/PyB ber-role 4/5/8 di M3; Q-02 syarat masa kerja ≥ 12 bulan diikuti seperti legacy (`Cron.php:2406-2428`); Q-20 tetap diputuskan DB Validator di DBV-022; Q-19 urusan reviewer CR. Hal baru di luar tabel ini → tetap BERHENTI dan eskalasi.

Aturan: butir **⛔** WAJIB dijawab reviewer CR (atau pihak yang disebut) **sebelum** milestone yang disebut; coder **berhenti** pada bagian itu dan mengerjakan bagian lain. Butir **◻** = default PRD berlaku bila reviewer CR tidak menolaknya saat review laporan M0; bila ditolak, ikuti jawaban reviewer. Tidak ada butir yang boleh "diputuskan sendiri" oleh coder.

| ID | Sifat | Pertanyaan | Saran default | Batas |
|---|---|---|---|---|
| Q-01 | ⛔ | Approver (AL/PyB) yang akunnya role **4/5/8** (di luar route cuti 1,2,3,6,7) atau tidak punya akun tidak bisa memproses → pengajuan berpotensi tertahan. Legacy: hanya role 2 yang bisa approve (A-14) | Ikuti Matriks: route tetap 1,2,3,6,7 (role 4/5/8 → 403); laporan M3 memuat kueri jumlah pejabat AL/PyB ber-role 4/5/8 dari data impor; bila > 0 → keputusan produk | Sebelum M3 |
| Q-02 | ⛔ | Pembentukan saldo `pegawai_sisa_cuti` tahun baru: legacy punya dua cron berbeda (`Cron.php:2349-2532` vs `:2534-2663`), mana yang aktif tidak bisa dipastikan dari kode; apakah dibuat di Fase 4 (spark command terjadwal) | Command `php spark cuti:saldo-tahunan {tahun}` mengikuti `calculate_yearly_sc_2` + batas D8: `N = 12 − utang`, pool bawaan = `min(sisa N lalu, 6) + min(sisa N-1 lalu, 6) + ditangguhkan`, pool ≤ 12 → N-1 = min(pool, 6), N-2 = sisa; untuk pegawai aktif jenis 1/6 yang belum punya baris; idempoten. Syarat masa kerja ≥ 12 bulan (`Cron.php:2406-2428`) → konfirmasi | Sebelum bagian saldo M3 |
| Q-03 | ◻ | Nilai turunan dihitung server walau legacy menerima dari klien: `lama_cuti` (A-03, D19), `lama_tahun` TB, snapshot pemohon cuti/TB. D19 menyatakan A-03 "dibiarkan", tetapi D8/D9 mensyaratkan satu fungsi hitung | Server menghitung & menyimpan; field klien diabaikan (bukan penolakan → bukan penjaga tambahan). `mker` PMK tetap dari klien (P-4, D19) | Review M0 |
| Q-04 | ◻ | Tahun acuan saldo: legacy validasi memakai tahun berjalan (`L:1579`), potong memakai tahun `created_at` (`L:3830`), form edit tahun `tgl_mulai` | Tahun `created_at` (= tahun berjalan saat ajukan) untuk validasi & potong; porsi tahun berikut → utang | Review M0 |
| Q-05 | ◻ | Rumus refund D6 (legacy menormalisasi dan bisa negatif) | §6.3.5: kembalikan ke N → N-1 → N-2 dengan batas 12/6/6, utang/ditangguhkan dikurangi tanpa negatif; status 4 juga mengembalikan saldo yang dipotong | Review M0 |
| Q-06 | ⛔ | (a) Sumber v2 untuk nilai `web_config` legacy: `nama_menteri`, `jabatan_menteri_full`, `id_jabatan_kabiro_kepegawaian`, `id_satker_kepegawaian`, `id_unit_kepegawaian`, `pdf_header_*`. (b) Pegawai **eselon I** (`id_sub_group_jabatan = 1`): AL & PyB = Menteri tanpa NIP → tidak ada yang bisa memproses di sistem (jalan buntu) | (a) `Config\Layanan` + override `.env` tervalidasi (pola `Config\Konket`), nilai diisi saat cutover; (b) pengajuan eselon I tetap dibuat (status 0) dan **difinalkan lewat input admin role 1** (ubah ke status 1 dengan efek final) — perlu keputusan produk | Sebelum M3 |
| Q-07 | ⛔ | Reset `skip_al`/`skip_pyb` "saat login berikutnya" membutuhkan kait di modul Auth (tidak ada event login di v2) | Tambah `Events::trigger('auth.login', $nip)` aditif di alur login sukses + listener Modul C yang me-reset flag NIP itu; atau FE memanggil `GET cuti/pengingat?reset=1` sekali per sesi login | Sebelum bagian tunda M3 |
| Q-08 | ◻ | Role 3 menambah aturan `pa_layanan` untuk unit pemohon lain: dropdown legacy dibatasi unit sendiri (`L:4170-4172`) tetapi server menyimpan POST apa adanya (`L:4208-4240, 4479`) | **26-10:** `id_unit_pemohon` wajib = unit pemanggil (422) — meniru pembatasan UI legacy, mencegah baris yatim; ubah/hapus tetap dibatasi unit sendiri [K]. ~~Ikut legacy tanpa penjaga tambahan~~ | Disetujui 26-10 |
| Q-09 | ◻ | Penanda tangan surat cuti & siapa yang mengetik passphrase (admin role 1/3 memicu, tetapi sertifikat milik pejabat) | Penanda tangan = NIP PyB (AL bila langkah tunggal tanpa PyB); di Fase 4 (mock) passphrase diketik admin di dialog; mekanisme real = Fase 6 | Review M0 |
| Q-10 | ◻ | Kop surat cuti: legacy punya lima titik generator dan tiga kop (E10): kementerian `MY_PDF_HEADER` (`L:943-975, 3268-3300`), remote `GENERATE_SC_URL` (`L:2100-2122, 2586-2606`), unit/satker `MY_PDF_HEADER_UNIT` di unduhan detail (`L:811-858`) | **26-10 (Horii):** kop **kementerian (K1)** via `KopDokumen::html(null, null)` tanpa lingkup/logo, satu kop untuk unduhan & TTE (§6.5.2); kop unit/satker tidak dipakai | Disetujui 26-10 |
| Q-11 | ◻ | Aturan legacy yang dibiarkan: tidak ada kedaluwarsa pengajuan (E14), TB dan IB boleh beririsan (IB-09), CLTN masa depan tidak mengubah status pegawai (A-12) | Ikut legacy [K]; "tanpa stuck" = setiap status non-final selalu punya pelaku | Review M0 |
| Q-12 | ◻ | Perpanjangan TB: D15 "pengajuan terpisah terhubung induk" — perlu status approval? | Tanpa status, langsung berlaku, hanya role 1 [K] (`Tb.php:614-674`) | Review M0 |
| Q-13 | ◻ | Konsekuensi D19 yang otomatis menutup sebagian celah: tahap dari status (bukan `appr_as`), pemanggil `process` harus NIP approver tahap aktif (penjaga jalur `process` legacy C:584-591), PegawaiScope membatasi PMK ke pemilik | Diterima sebagai aturan platform/state machine (bukan penjaga baru); ISSUE-048 tetap mencatat celah legacy | Review M0 |
| Q-14 | ◻ | Sign-off Fase 4 (K-12: PRD §7 wajib, IDX/SOP tidak) | Sign-off eksplisit reviewer CR setelah M8 (seperti Fase 3) | Sebelum menyatakan DONE |
| Q-15 | ◻ | Perbaikan bug **non-keamanan** [V2] yang diusulkan PRD: LK-01 `tgl_akhir` cuti Besar maju 1 hari; A-06 lampiran cuti bisa diganti saat ubah; A-09/TB-03 semua galat ditampilkan; A-10 variabel tahun menunggu; TB-04/IB-04 tumpang tindih ikut status 1; TB-05/IB-04 satu whitelist ekstensi; TB-06 snapshot server & urutan gol/pangkat; TB-07 input admin "langsung disetujui" memakai efek yang sama dengan approval; TB-09 data SK tersimpan saat ubah admin; TB-13 perpanjangan: `akhir ≥ mulai` & induk status 1; TB-14/P-9 berkas anak ikut dihapus; IB-01 input admin IB berfungsi; IB-05/P-18/E12 teks salah salin; P-3 `tgl_akhir < TMT CPNS`; P-5 enum `jenis_pmk`; P-7 batas 2 MB arsip; P-10 tautan paklaring; N6/N7 isi push; E9 surat ber-TTE tidak ditimpa | Semua diadopsi [V2]; butir yang ditolak reviewer kembali ke [K] | Review M0 |
| Q-16 | ◻ | Detail pengajuan PMK status 0 tidak bisa dibuka di legacy (P-11) | Boleh dibuka [V2] (D17: pegawai melihat semua pengajuannya) | Review M0 |
| Q-17 | ◻ | `regenerate_sc` (role 1) & cron `fix_regen_cuti` | Tidak dikerjakan di Fase 4 (tidak ada di Matriks/DoD); surat belum TTE dibuat otomatis saat final | Review M0 |
| Q-18 | ⛔ | Acuan QA Lapis 1 C-11 tanpa Figma (QAUI-004 bloker, RTM Conflict #2) | Mockup Gambar 17 + `LayananStatusPage` `67fa767` + pola komponen redesign Fase 3; reviewer/QA menyepakati checklist sebelum QA | Sebelum QA Lapis 1 M7 |
| Q-19 | — | Revisi dokumen Mapping Migrasi (tambah `pegawai_sisa_cuti`, `jenis_cuti`, `pa_layanan`, `jenis_pa`; koreksi Prinsip #4 "inbox dipakai notifikasi") | Di luar coder — reviewer CR/Horii | — |
| Q-20 | ⛔ (DBV) | DQ-3, DQ-4 (detail), DQ-6, DQ-7 (tipe/kolom), DQ-9, DQ-10, DQ-12, DQ-16, DQ-17, DQ-18, DQ-20, DQ-21, nomor kode `jenis_rwy` | Usulan di §6.1.1/§6.1.3; diputuskan DB Validator di DBV-022 | M1 |
| Q-21 | ◻ | Penyimpanan lampiran: cuti/TB/IB/surat → `document_attachment` (kode baru D13/D18); PMK req → kolom [K] (`KolomBerkas`). Legacy: cuti/TB/IB memakai kolom `file_*` + arsip remote, `document_attachment` tidak dipakai (0 referensi) | Seperti tertulis **+ (26-10)** lampiran lama dipetakan ke `document_attachment` pada runbook cutover (§6.1.2 #6) dan DQ-20 dijawab searah; dicatat di Mapping (Q-19) | Disetujui 26-10 |
| Q-22 | ◻ | Templat teks notifikasi (D11 tidak menetapkan isi) | Tabel §6.6.2 (dari teks legacy, typo diperbaiki) | Review M0 |
| Q-23 | ◻ | Batas `reason_note` untuk semua modul (kolom legacy: `riwayat_cuti.reason_note` text, `_al/_pyb` mediumtext, TB/IB mediumtext, `riwayat_pmk_req` tinytext; textarea tanpa `maxlength`) | ≤ 255 byte sebagai **validasi input baru**; **(26-10)** tipe kolom [K] **tidak dikecilkan** di DBV-022 (DQ-22) karena data impor bisa > 255 (audit §6.1.2 #12) | Disetujui 26-10 |
| Q-24 | ◻ | Lama cuti jenis 2/4/6 = hari kalender **inklusif** (nilai kalkulator klien legacy) vs server legacy eksklusif (LK-02) | Inklusif | Review M0 |

---

## 13. Lampiran

### Lampiran A — Rujukan sumber

| Kode | Berkas | Bagian |
|---|---|---|
| Riset reviewer | Berkas kerja riset reviewer CR (keputusan D1–D19, kontrak Trello, spesifikasi dokumen, perilaku legacy, DDL, kondisi repo) | **Tidak dipublikasikan.** Semua isi yang mengikat sudah disalin ke PRD ini; bila butuh rincian tambahan → minta ke reviewer CR |
| Legacy utama | `controllers/hr/rwy/{Cuti,Tb,Ib,Pmk,Layanan}.php`; `libraries/hr/rwy/{L_cuti,L_tb,L_ib,L_pmk,L_layanan}.php`; `libraries/hr/L_notification.php`; `libraries/hr/L_support.php`; `controllers/hr/services/{Local,Cron}.php`; `helpers/function_helper.php` (`getAL_es2` 1700-1945, `calcLCTahunan` 2175-2215, `fcmQueue_*` 588-915); `core/MY_Controller.php` (popup 327-457, `userAuth` 655-662); `libraries/bsre_esign/*`, `libraries/Esign.php`, `libraries/PdfCreator.php` | |
| DDL D1 | `simpeg01_struktur_lengkap_20261001.sql` | `riwayat_cuti` 4396-4463; trigger 8830-8844; `riwayat_cuti_notif_kt` 4699-4709; `jenis_cuti` 1634-1643; `pegawai_sisa_cuti` 3576-3592; `riwayat_tb` 6544-6604 (+trigger 10478-10483); `riwayat_tb_perpan` 6662-6673 (+trigger 10487-10527); `riwayat_ib` 4898-4955 (+9169-9174); `riwayat_pmk` 6202-6227; `riwayat_pmk_req` 6232-6259; `inbox` 1369-1393; `inbox_msg` 1398-1414 (+7730-7739); `esign_hist` 891-907; `jenis_layanan` 1705-1714; `petugas_layanan` 3952-3962; `pa_layanan` 2429-2471; `jenis_pa` 1733-1741; `question_category` 4008-4017; `firebase_token` 1156-1163; `document_attachment` 498-520 |
| Repo v2 | `AGENTS.md`; `backend/app/Interfaces/{EsignGatewayInterface,PushNotifGatewayInterface,PdfRendererInterface,SyncsToSnapshot}.php`; `Libraries/Esign/MockEsignAdapter.php`; `Libraries/Push/MockFcmAdapter.php`; `Config/{Queue,Services,Routing,RoutesPegawai,Konket,AngkaKredit,Integrations}.php`; `Libraries/Kepegawaian/{Riwayat/RiwayatEngine,Lampiran/*,Scope/PegawaiScope,Lkh/AtasanLangsung,Nip/TabelAnakNip}.php`; `Libraries/MasterData/HariLiburService.php`; `Libraries/Presensi/Tukin/HariKerja.php`; `Libraries/Pdf/*`; `Models/{BaseAuditableModel,BaseSnapshotModel,AuditLogModel}.php`; `Controllers/Api/Kepegawaian/README.md`; `frontend/src/shared/{components/{RowActionsMenu,ApprovalDialog,ConfirmDialog,FormField}.vue, ui/{UiStepper,UiBadge,UiFileField}.vue, composables/useStickyActionsShadow.ts, layouts/nav.config.ts}`; `tests/_support/DatabaseTestCase.php`; `tools/mysql-test/README.md`; `docs/fase3/*`; `backend/docs/progress/03-Kepegawaian.md`; mockup `origin/feat/frontend-ui-redesign` `67fa767:frontend/src/features/layanan/*` |

### Lampiran B — Anomali legacy & perlakuan

Legenda perlakuan: **[K]** dibiarkan seperti legacy · **[V2]** diperbaiki · **W-048** dibiarkan + kartu WARNING ISSUE-048 (D19) · **D-x** diputuskan keputusan user · **Q-x** konfirmasi §12. (Kode anomali kuota legacy `K-01..K-07` di `legacy.md` §2.3 ditulis **LK-01..LK-07** agar tidak bentrok dengan konflik dokumen K-xx.)

| ID | Temuan legacy (ringkas) | Rujukan | Perlakuan Fase 4 |
|---|---|---|---|
| D-STATUS | Status 0/3/1/2/4 vs Trello 0/1/2/3 | D1:4438; `L:2655-2667` | D1 [K] + pemetaan §4.3 |
| D-SKIP | skip = tunda popup | C:922-1004; MYC:327-457 | D2 [K] |
| D-SNAP | Efek final di 1/4 | `L:2079-2433`, `L:3255-3503` | D4 [K] |
| A-01, A-03, A-04, P-1, P-4, E1–E4, E7, E8, E13 | Butir keamanan legacy (otorisasi/integritas kiriman approval & PMK, transport e-sign) — rincian sengaja **tidak** ditulis di repo publik | ISSUE-048 (Trello, privat) | **W-048** dibiarkan seperti legacy (D19); kontrak v2 di §5–§7 tidak memuat field/jalur yang membawa celah itu (tahap dari status, satu endpoint `process`, passphrase di body, QR kode dokumen) |
| A-02 | Tanpa guard urutan tahap | C:584-591 | [V2] state machine ketat (D19 tetap) |
| A-05 | AL tangguhkan → final 4 tanpa PyB | `L:2664-2667, 3802` | [K] D7 |
| A-06 | Lampiran sakit wajib hanya di klien; edit tak bisa ganti | `form.php:354-362`; C:356 | wajib di FE [K]; ganti saat edit [V2] Q-15 |
| A-07 | `return` tengah transaksi tanpa rollback | `L:2129, 2181-2183` | [V2] |
| A-08 | Refund ke array kosong → negatif; hapus fisik | `L:1277-1352` | hapus fisik D6 [K]; refund [V2] D6 |
| A-09 | Pesan validasi tertimpa | `L:1478-1488` dll. | [V2] semua galat (kontrak 422) |
| A-10 | Variabel tahun menunggu salah | `L:1637` | [V2] Q-15 |
| A-11 | Tiga algoritma potong saldo | `L:1916-1952, 2196-2240, 3864-3884` | [V2] D8 satu algoritma |
| A-12 | CLTN hanya bila sudah berjalan saat diputus | `L:2416-2433` | [K] Q-11 |
| A-13 | Cuti bersama tidak memotong kuota | `FH:2175-2215` | [K] D9 (libur status 1) |
| A-14 | Hanya role 2 bisa approve | MYC:331; C:573 | [V2] D3 approver = NIP (route 1,2,3,6,7); Q-01 |
| A-15 | Role 3 di delete mati | C:498, 526-548 | [K] D5 (role 3 lihat saja) |
| LK-01/02 | Off-by-one lama/tgl akhir Besar/Melahirkan/CLTN | `Local.php:2187-2201`; `L:1509-1513` | [V2] Q-15/Q-24 |
| LK-08 | `addMonth` memotong ke akhir bulan lalu −1 hari (31-01 → 27-02) | `FH:960-966` | [K] §6.3.2 (rumus ditegaskan 26-10) |
| LK-09 | `process()`: status 4 hanya bulan 12, tanpa N-2, membaca kolom `sisa_cuti_ditangguhkan` yang tidak ada di `riwayat_cuti`; `utang_cuti` ditimpa | `L:2275-2411` | tidak dibawa (PRD ikut jalur e-sign) |
| LK-10 | Refund memakai tahun berjalan & `lcNext` dari `yearTM-01-01` | `L:1282, 1297-1326` | [V2] §6.3.3/§6.3.5 |
| LK-03 | Dua cron saldo berbeda | `Cron.php:2349-2663` | D8 + Q-02 |
| LK-04 | Tahun acuan berbeda-beda | `L:1579, 3830` | Q-04 |
| LK-05 | Normalisasi ulang saat potong | `L:3864-3884` | [V2] D8 |
| LK-06 | `utang_cuti` & ditangguhkan | Cron, `L:3711-3718, 3891-3893` | [K] D7 |
| LK-07 | Cuti bersama | — | [K] D9 |
| TB-01 / IB-02 | Antrean PIC macet untuk pegawai di luar unit kepegawaian | `L_tb.php:1040-1051`; `L_notification.php:222, 239` | [V2] D14 |
| TB-02 | Status 3/4/10 tak pernah ditulis; hapus fisik | `L_tb.php:730-819` | [K] (hapus fisik + audit) |
| TB-03 | Galat tertimpa | `L_tb.php:832-886` | [V2] |
| TB-04 / IB-04 | Tumpang tindih abaikan status 1 | `L_tb.php:931, 976` | [V2] Q-15 |
| TB-05 | Ekstensi tak konsisten | `L_tb.php:252 vs 532` | [V2] Q-15 |
| TB-06 | Snapshot gol/pangkat tertukar, dari POST | `form.php:46-47` | [V2] Q-03/Q-15 |
| TB-07 | Input admin langsung setuju tanpa efek | `L_tb.php:375-490` | [V2] Q-15 |
| TB-08 | `id_jenis_status` hanya bila hari ini dalam rentang; tanpa cron | `L_tb.php:1169-1180` | [K] D15 + `bebasPresensiPenuh` |
| TB-09 / IB-08 | Edit admin tidak menyimpan SK | `L_tb.php:1028-1071` | [V2] Q-15 |
| TB-10 / IB-06 | Proses tanpa cek unit; SK wajib hanya di JS | Tb.php:549-612 | [K] (PIC lintas unit = D14; SK opsional server) |
| TB-11 / IB-06 | Hak akses role 7/8 janggal | Tb.php:328, 477 | Matriks (D5 pola) |
| TB-12 | Tombol hapus vs controller | list.php:125 | [V2] `aksi {}` dari server |
| TB-13 | Perpanjangan tanpa cek; trigger DB | `L_tb.php:1349-1393`; D1:10487-10527 | [V2] Q-15; trigger → service |
| TB-14 | Berkas perpanjangan tertinggal | FK CASCADE | [V2] |
| TB-15 | Potongan TB 25% beda antarlaporan | `L_presensi.php` | Fase 5 (di luar scope) |
| TB-16 / N6 | Push TB tanpa isi | `function_helper.php:587-700` | [V2] §6.6.2 |
| TB-17 | `lama_tahun` y + m/12 | `Local.php:3185-3217` | [K] rumus, dihitung server (Q-03) |
| TB-18 | LEFT JOIN ganda | `L_employee.php:4603` | di luar scope |
| IB-01 | `add_ad` tanpa `$_FILES` | Ib.php:140 | [V2] Q-15 |
| IB-03 | Lampiran wajib juga role 1 | `L_ib.php:705` | [K] |
| IB-05 | Teks salah salin | Ib.php:228; `L_ib.php:1032` | [V2] |
| IB-07 | Surat IB otomatis vs SK TB unggah | — | [K] masing-masing |
| IB-09 | TB vs IB beririsan | — | [K] Q-11 |
| P-2 | Pengajuan tidak disalin ke `riwayat_pmk` | grep `riwayat_pmk` | [K] D16 |
| P-3 | Cek TMT CPNS hanya `tgl_awal` | `L_pmk.php:811-822` | [V2] Q-15 |
| P-5 | `jenis_pmk` tanpa enum | — | [V2] Q-15 |
| P-6 | Batas FE 5 MB vs server 2 MB | `req/form.php:126` | [K] 2 MB |
| P-7 | Cek ukuran salah kolom | `L_pmk.php:60` | [V2] |
| P-8 | Rollback hapus berkas salah | `L_pmk.php:686, 1415` | [V2] (`KolomBerkas`) |
| P-9 | Hapus req tidak hapus berkas | `L_pmk.php:716-751` | [V2] |
| P-10 | Tautan paklaring salah | `req/detail.php:109` | [V2] |
| P-11 | Detail status 0 tak bisa dibuka | Pmk.php:408-420 | [V2] Q-16 |
| P-12 / P-13 | Endpoint/redirect mati, kode mati Diklat | Pmk.php:713-795, 841 | tidak dibawa |
| P-14 | PIC ubah status final; 0→1 langsung | `L_pmk.php:1848-1904` | 0→1 [K]; final dikunci [V2] (single end node) |
| P-15 | Badge notifikasi tak hitung PMK | `L_notification.php:2719-2741` | [V2] (notifikasi inbox) |
| P-16 | Default status 1 | D1:6246 | DBV (aplikasi isi eksplisit) |
| P-17 | Hanya PNS | — | [K] D16 |
| P-18 | Teks salah salin | Pmk.php:115-124, 503 | [V2] |
| P-19 | Daftar PIC tanpa batas unit | `L_pmk.php:1794-1834` | [K] |
| M-1 | Menu Status Layanan SA → 404 | `super_admin.php:136-138` | [V2] D17 halaman baru |
| M-2 | PPPK tak pernah tampil Butuh Approval | `pppk.php` | [V2] approver = NIP (D3) |
| M-3 | Role 4/5 diizinkan endpoint cuti tanpa menu | — | Matriks: 4/5 hanya Status Layanan (D5/D17) |
| M-4 | SA tanpa menu Notifikasi | — | F-08 (Fase 7) |
| M-5 | Label menu PIC mentah | — | [V2] label baku |
| N1 | `inbox` legacy = tiket semua | grep `inbox` | D10 |
| N2 | Notifikasi via `show_notif` + trigger | D1:8839, 9169, 10478 | [V2] inbox eksplisit; `show_notif` diisi kompatibilitas |
| N3 | `show_notif = 4` tak pernah ditulis | `L_notification.php:3104` | impor (Fase 7) |
| N4 / N5 | Role `Notification::delete`/`need_approval` | Notification.php:39, 120 | Fase 7 |
| N7 | Typo "anda anda" | `L_cuti.php:2482, 3090, 3560` | [V2] |
| N8 | Antrean FCM dihapus walau gagal | Cron.php:1160-1210 | [V2] Queue + retry terbatas |
| N9–N13 | `closed_by`, mass-assignment, hard delete tiket, collation, receiver 0 | `L_support.php` | Fase 8 / DBV-022 (skema) |
| E5 / E6 | `esign_hist` hilang saat rollback; sign sebelum transaksi | `L:3438-3481` | [V2] D13 (§6.5.3) |
| E9 | Regenerate menimpa berkas ber-TTE | C:898-920 | [V2] (Q-17: regenerate tidak dibuat) |
| E10 | Tiga generator PDF | `L:3282, 2606, 838` | [V2] satu generator (Q-10) |
| E11 | Log `mylog` tertimpa | `L:3470-3476` | [V2] `audit_logs` |
| E12 | Pesan salah modul | `L:3658` | [V2] |
| E14 | Cek expired dikomentari | C:594-606 | [K] Q-11 |
| E15 | `esign_hist.status` varchar, NIK polos | D1:892-899 | DBV-022 |
| E16 | Popup tidak pernah e-sign | MYC:335-346 | v2: e-sign selalu aksi admin (D12) |
| E17 | Hanya role 2 approve | C:573 | = A-14 |
| T1–T10 | Temuan skema (inbox = tiket, domain status, skip, status penuh, tanpa snapshot `pegawai_*`, `esign_hist` Fase 4, `jenis_rwy` baru, master pendukung, collation, registry NIP) | `skema.md` §0 | T1 D10 · T2 D1 · T3 D2 · T4 D15 · T5 D4 · T6 D18 · T7 D13 · T8 D18 · T9 DBV-022 (G2) · T10 AC-01.7 |

### Lampiran C — Matriks keterlacakan (pemeriksaan kelengkapan)

#### C.1 DoD QASMTASK → AC
| DoD | AC | | DoD | AC |
|---|---|---|---|---|
| 064-1 tabel dibuat | AC-01.1 | | 069-1 status → inbox | AC-06.1 |
| 064-2 inbox satu arah | AC-01.2 | | 069-2 reject + alasan → pengaju | AC-06.2, AC-04.2 |
| 064-3 ticketing terpisah open/closed final F8 | AC-01.3 | | 069-3 push mock via Queue | AC-06.3 |
| 064-4 FK atasan valid + orphan | AC-01.4 | | 069-4 regression tidak ke ticketing | AC-06.4 |
| 064-5 review DBV sekaligus sebelum notifikasi | AC-01.5, §6.1.4 | | 070-1 pengajuan TB | AC-07.1 |
| 064-6 pemisahan sejak awal | AC-01.6 | | 070-2 perpanjangan terpisah | AC-07.2 |
| 065-1 CRUD cuti | AC-02.1, AC-02.4, AC-02.5 | | 070-3 status_penuh ter-flag | AC-07.3 |
| 065-2 submit → 0 | AC-02.2 | | 070-4 role 7 ditolak | AC-07.4 |
| 065-3 dokumentasi endpoint | AC-02.6, §7.10 | | 071-1 CRUD IB | AC-08.1 |
| 065-4 MTC-026/027 | **di luar scope (MT)** | | 071-2 status pending | AC-08.2 |
| 066-1 N = 12 | AC-03.1 | | 071-3 role 6 ditolak | AC-08.3 |
| 066-2 N-1 ≤ 6 | AC-03.2 | | 072-1 pengajuan & persetujuan PMK | AC-09.1 |
| 066-3 N-2 ≤ 6 | AC-03.2 | | 072-2 pending | AC-09.2 |
| 066-4 total ≤ 24 | AC-03.3 | | 072-3 single end node | AC-09.3 |
| 066-5 test kombinasi | AC-03.4 | | 072-4 role selain 1,2,3 ditolak | AC-09.4 |
| 066-6 boundary 24 & >24 | AC-03.5 | | 073-1 role 1–7 lihat status sendiri | AC-10.1, AC-10.4 |
| 067-1 0 → AL → PyB | AC-04.1 | | 073-2 penunjukan PIC | AC-10.2 |
| 067-2 tolak + alasan | AC-04.2 | | 073-3 PIC tampil benar | AC-10.3 |
| 067-3 skip melewati step | AC-04.3 | | 074-1 pill badge beda warna | AC-11.1 |
| 067-4 snapshot hanya final, per step | AC-04.4 | | 074-2 modal approve/reject alasan wajib | AC-11.2 |
| 067-5 tanpa stuck | AC-04.5 | | 074-3 form pengajuan | AC-11.3 |
| 067-6 unit test state machine | AC-04.6 | | 074-4 kalkulator real-time | AC-11.4 |
| 068-1 PDF → adapter → document_attachment | AC-05.1 | | 075-1 test kuota boundary | AC-12.1 |
| 068-2 mock, swap Fase 6 | AC-05.2 | | 075-2 test state machine skip | AC-12.2 |
| 068-3 gagal di esign_hist | AC-05.3 | | 075-3 test snapshot final | AC-12.3 |
| 068-4 hanya role 1,3 | AC-05.4 | | 075-4 terintegrasi check.sh | AC-12.4 |

QAUI-004 → AC-11.1..11.7 (pill per tahap, modal alasan, form cuti datepicker/jenis/lampiran, kalkulator, form TB/IB/PMK, dashboard + PIC = AC-10.3, menu TB tanpa PPPK & IB tanpa PTT = AC-11.5; bloker Figma = Q-18). QAFUNC-005 → trace penuh (AC-04.5, AC-07.5, AC-08.4, AC-09.3), kode semantik (§4.3), skip (AC-04.3), snapshot hanya final (AC-04.4), kuota (AC-03.*), reject + notifikasi (AC-06.2), inbox bukan ticketing (AC-06.4), e-sign gagal (AC-05.3), TB penuh (AC-07.3), 403 (§11, `RoleMatriksLayananTest`), exit (§10.1). Hasil: **semua butir DoD punya AC**; satu butir (065-4 MTC) sengaja di luar scope.

#### C.2 Validasi prompt eksekusi
| Butir prompt | Tercakup di |
|---|---|
| 2/2: test/check relevan tiap task | §6.x "Test wajib", §6.12.1, §9 (CI per milestone) |
| 2/2: verifikasi state machine termasuk skip_al/skip_pyb | AC-04.1–04.6, AC-12.2, §11 |
| 2/2: snapshot hanya berubah pada final approval | AC-04.4, AC-12.3, §6.4.5 |
| 2/2: kuota termasuk boundary 24 | §6.3.8, AC-03.5, AC-12.1 |
| 2/2: notifikasi tidak pernah masuk tabel ticketing | AC-06.4, §7.1 |
| 2/2: setiap flow approval single end node | §6.4.2, AC-04.5, AC-07.5, AC-08.4, AC-09.3, §6.12.1 |
| 2/2: jalankan check.sh | §2.4, AC-12.4, §10.1 #2 |
| 2/2: final report status C-01..C-12, file utama, test/validation, blocker | §10.3 |
| 2/2: jangan nyatakan DONE sebelum seluruh task, C-12, single-end-node, pemisahan tervalidasi | §10.1 |
| 1/2: Fase 3 DONE dulu | §6.1 dependency, §9 M0 |
| 1/2: inspect repo & reuse | §2.7, §6.11.5, §7, laporan M0 |
| 1/2: ikuti dependency | §9 |
| 1/2: C-01 DBV review sebelum migration dijalankan | §6.1.4 |
| 1/2: inbox/inbox_msg vs disiplin_ticket dipisah sejak awal | §6.1.1 #10–13, AC-01.2/01.3/01.6 |
| 1/2: state machine jelas, tidak stuck | §6.4.2, §6.7.3, §6.8.2, §6.9.2 |
| 1/2: snapshot hanya final | §6.4.5 |
| 1/2: kalkulasi & business rule unit test | §6.12 |
| 1/2: mock BSrE & FCM, jangan hit eksternal | §6.5.1, §6.6.3, §7.2 |
| 1/2: notifikasi ke inbox, ticketing tetap disiplin_ticket | §7.1 |
| 1/2: Matriks role ketat | §5, `RoleMatriksLayananTest` |
| 1/2: requirement belum jelas → eskalasi | Cara pakai #3, §12 |
| 1/2: sesi berhenti → IN_PROGRESS + progres detail | §10.2 |

#### C.3 Keputusan D1–D19 → bagian
| D | Bagian | | D | Bagian |
|---|---|---|---|---|
| D1 | §4.3, §6.1.1 #1, §6.4.2, §6.11.4 | | D11 | §6.2.5, §6.6.2, §6.7.7, §6.9.2 |
| D2 | §4.3, §6.4.1, §6.4.7, AC-04.3 | | D12 | §6.5.1, §6.5.3 |
| D3 | §6.4.1, AC-04.7, Q-01 | | D13 | §6.1.1 #21, §6.5.3, §7.2 |
| D4 | §6.4.5, AC-04.4 | | D14 | §6.7.1, §6.7.3, §6.8.1, §6.6.2 |
| D5 | §5.1, §6.2.1 | | D15 | §6.7.3–6.7.5, §8 |
| D6 | §6.2.6, §6.3.5 | | D16 | §6.9 |
| D7 | §6.3.5, §6.4.2 (T6, T10) | | D17 | §5.5, §6.10, §6.11 |
| D8 | §6.3.1–6.3.8 | | D18 | §6.1.1 |
| D9 | §6.3.2, §6.3.7, §6.11.3 | | D19 | §7.9, §6.2.3, Lampiran B, Q-03/Q-13 |
| D10 | §6.1.1 #10–13, §6.6, §7.1 | | | |

Hasil: **semua D1–D19 tercermin** di bagian task terkait.

#### C.4 Konflik K-01..K-23 (spek-dokumen §6)
| K | Penyelesaian | | K | Penyelesaian |
|---|---|---|---|---|
| K-01 tahap desain vs state machine | D17 (mockup visual saja), §6.11.4 | | K-13 penerima & isi notifikasi | D11 + §6.6.2 (isi: Q-22) |
| K-02 `esign_hist`/token di Fase 6 | D18 | | K-14 e-sign otomatis vs manual, penanda tangan | D12 (manual admin); penanda tangan Q-09 |
| K-03 daftar tabel C-01 | D18 (`absen_ijin` sudah ada Fase 3) | | K-15 passphrase | D12 + §6.5.1 |
| K-04 TS §8 vs MRX | MRX/D5 (§5) | | K-16 `jenis_rwy` | D13/D18, nomor DBV (Q-20) |
| K-05 kode status | D1; TB/IB/PMK [K] §6.1.1 | | K-17 target snapshot | D4 |
| K-06 approver | D3, D5, D14, D16; Q-01 | | K-18 ADR-012 vs raw QB | §7.7 (ikut repo, audit manual) |
| K-07 `calculate_lc` di Fase 6 | D9 | | K-19 Riwayat Organisasi | §1.2 di luar scope |
| K-08 Selesai/Diarsipkan vs status | §3, §4.3; pembatalan = hapus (D6); kedaluwarsa Q-11 | | K-20 MTC mapping | §1.2 (MT fase tersendiri) |
| K-09 jenis cuti | §6.2.2 [K] | | K-21 nama interface | §7.2 (nama repo) |
| K-10 hari kerja & kuota | D8, D9 | | K-22 role Halo Simpeg | Fase 8 (di luar scope) |
| K-11 PMK | D16 | | K-23 warna/desain | §6.11.4 token redesign |
| K-12 sign-off | Q-14 | | | |

Pertanyaan spek §7 no. 1–16: 1 → D3/Q-01; 2 → D17; 3 → D18/D12/Q-09; 4 → D18; 5 → D8/D9/Q-02/Q-04; 6 → §6.2.2; 7 → D4/D15; 8 → D6/Q-11; 9 → D11/Q-22; 10 → D1; 11 → D16; 12 → D9; 13 → D17 (§6.10.1); 14 → D13/§7.4/Q-21; 15 → Q-14; 16 → Q-18. Hasil: **semua terjawab atau masuk §12**.

#### C.5 Pertanyaan DBV `skema.md` §7 (1–19)
| No | Status | | No | Status |
|---|---|---|---|---|
| 1 domain status cuti | D1 (DQ-1) | | 11 status penuh / perpanjangan | D15 (DQ-11), Q-12 |
| 2 arti skip | D2 (DQ-2) | | 12 `petugas_layanan.status` | DBV (DQ-12, Q-20) |
| 3 FK atasan create/ditahan | DBV (DQ-3, Q-20) | | 13 surat di `document_attachment` | D13 (DQ-13) |
| 4 pemisahan inbox/ticket | D10 + DBV detail (DQ-4) | | 14 `riwayat_cuti_notif_kt` | D11/D18 (DQ-14) |
| 5 `question_category` | D18 (DQ-5) | | 15 `ketua_tim` registry | wajib, bentuk DBV (DQ-15) |
| 6 FK pengguna tiket | DBV (DQ-6, Q-20) | | 16 index approval | DBV (DQ-16) |
| 7 `esign_hist` di C-01, tipe | D18 + DBV (DQ-7) | | 17 FK id jabatan cuti | DBV (DQ-17) |
| 8 cakupan tabel pendukung | D18 (DQ-8) | | 18 CHECK TB/IB/PMK | DBV (DQ-18) |
| 9 PK `jenis_cuti` | DBV (DQ-9) | | 19 revisi Mapping | Q-19 |
| 10 UNIQUE saldo | DBV (DQ-10) | | | |

Hasil: **semua pertanyaan skema terjawab keputusan atau masuk §12 (Q-19/Q-20) sebagai tugas DBV M1.**

— Akhir dokumen —

---

## 14. Riwayat revisi & keputusan 26-10-2026

### 14.1 v1.1 (26-10-2026) — take over & riset tahap 2b
Konteks: coder Wahyu meminta Fase 4 diambil alih tim reviewer CR (26-10). Basis = branch `fase4/dbv-022-skema-layanan` @`706f73b`. Riset read-only (laporan "Riset_Fase4_Tahap2b_dan_Q12", verifikasi baris legacy 26-10) menghasilkan 13 keputusan Horii:

| # | Keputusan | Tempat di PRD |
|---|---|---|
| 1 | Tahap 2b (library murni cuti sebelum DBV-022) diizinkan; batas berkas; bagian awal MAKE-022 | §6.1.4, §9 M2b |
| 2 | Rumus `tgl_akhir` cuti Besar = legacy `addMonth` (potong akhir bulan, −1 hari) dengan contoh | §6.3.2 |
| 3 | T4 (AL setuju): `status_pyb` tetap 0 [K] (koreksi "[I]" v1.0) | §6.4.2 T4 |
| 4 | "1 bulan" alasan penting = PHP `+1 month` inklusif, dengan contoh | §6.3.2 |
| 5 | Tumpang tindih mengikutsertakan status 3 [V2] | §6.2.3 |
| 6 | Tanggal sebelum tahun acuan atau sesudah 31-12 tahun berikutnya → 422 | §6.2.3, §6.3.3 |
| 7 | Pemisah `keterangan_petugas_approval` = `\n` | §6.4.2 |
| 8 | Q-08: validasi server `id_unit_pemohon` = unit admin (role 3) | §6.4.8, §12 |
| 9 | Q-10: kop surat cuti = **kementerian (K1)** via `KopDokumen` tanpa lingkup | §6.5.2, §12 |
| 10 | Q-21: lampiran lama cuti/TB/IB → `document_attachment` di runbook cutover + DQ-20 | §6.1.2 #6, §12 |
| 11 | Q-23: 255 byte = validasi input; kolom `reason_note*` [K] tidak dikecilkan (DQ-22) + audit panjang | §6.1.2 #7, §6.1.3, §12 |
| 12 | Q-05: audit nilai negatif `pegawai_sisa_cuti` masuk DBV-022 (DQ-23) | §6.1.2 #7, §6.1.3 |
| 13 | Hari kerja cuti dihitung sendiri di `HitungLamaCuti` + test paritas terhadap `HariKerja` | §6.3.2 |
| — | Kasus uji tambahan T1–T19 | §6.3.10 |
| — | Catatan Tahap 2b (26-10, disetujui Horii): batas `tgl_akhir ≤ 31-12 tahun acuan + 1` hanya jenis 1; paritas T13 hanya saldo ternormalisasi legacy; teks pesan batas jenis 5/6 dan "tanggal tidak valid" = [V2]; berkas ke-10 `AturanCuti` (library tidak boleh membaca `Config`). Tahap 2b di `main` 3fbf08e | §6.2.3, §6.3.10, §6.1.4 |
| — | Butir ◻ lain (Q-03, Q-04, Q-09, Q-11–Q-17, Q-22, Q-24): default v1.0 dipertahankan | §12 |

### 14.2 Temuan legacy pendukung butir ⛔ (bukan keputusan)
- **Q-01:** endpoint proses `userAuth(['2'])` (C:573, 653, 777, 846); popup approval MYC:331 untuk role 2 **dan 6** (PTT melihat popup tetapi endpoint menolak).
- **Q-02:** `calculate_yearly_sc` (`Cron.php:2349-2533`) mensyaratkan masa kerja ≥ 12 bulan (2406-2428) + "dua tahun tidak cuti" (2395-2400) dan N-2 selalu 0; `calculate_yearly_sc_2` (2534-2663) tanpa syarat masa kerja — hanya `_2` yang cocok dengan D8 (N-2 ≤ 6).
- **Q-06:** eselon I → `nip_atasan_langsung = NULL`, nama/jabatan dari `web_config.nama_menteri` / `jabatan_menteri_full` (`L:1741-1745, 1763-1766`), `status_al = status`; jalan buntu juga terjadi di legacy.
- **Q-07:** `justLogin` flashdata (MYC:21-22); `skip_al`/`skip_pyb` di-reset ke 0 **setiap** login (MYC:395-398, 433-436) — "tunda" hanya berlaku sampai login berikutnya.
- **Q-20/DQ-20:** terkait keputusan #10 (lampiran lama).

### 14.3 Verifikasi nomor baris legacy (26-10)
Beberapa rujukan v1.0 bergeser; nomor aktual: `calcLCTahunan` FH:2175-2213; `addMonth` FH:960-966; `vp()` L:1424-1686 (kuota L:1586-1659, bug A-10 L:1639); potong `sp()` L:1935-2026, `process()` L:2156-2415, `sp_process_esign()` L:3824-3903 (normalisasi 3867-3886); refund `delete()` L:1257-1352; `set_param_process` L:2639-2692; `sp_process_esign` param L:3767-3822; CLTN L:2416-2427 / 3494-3504; `skip_cuti` C:922-1004 (batas 971-976); guard C:584-591, 667-674, `process_esign_api` C:775-842.

