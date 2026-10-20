# PRD Fase 5A — Skema, Slip Gaji & Presensi (Modul D Presensi & Remunerasi, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` — **acuan utama** coder/agen AI paket **5A** Fase 5 (kartu Trello DEV-006, prompt eksekusi 1/2 & 2/2 di komentar DEV-006 mewajibkan membaca dokumen ini) |
| Versi | **v1.1 — 20-10-2026** (v1.0 19-10-2026; v1.1 menambah keputusan F5-23 dan F5-24 tentang `tmtMasuk` adapter Tukin) |
| Status | **BERLAKU** — keputusan user 19-10-2026 (P-1..P-4, F5-1..F5-22, "semua saran disetujui") sudah **DIKUNCI** di §5. Perubahan berikutnya hanya lewat revisi versi oleh reviewer CR |
| Basis kode | `origin/main` = **`39b5331`** (semua rujukan `path:baris` repo diambil dari commit ini) |
| Acuan legacy | Salinan kerja legacy CI3, **HEAD `771f8b4`** (08-10-2026) — acuan perilaku Slip Gaji terbaru (F5-1). Rujukan baris legacy bercatatan AS-09 (belum dicocokkan checksum produksi, §13 Q5A-14) |
| Scope | **5A** = D-01, D-02, D-03, D-10, D-11, D-14, sisi integrasi D-04/D-05/D-07 (adapter ke `KalkulasiTukin`), FE Presensi & Slip Gaji. **Bukan** 5B/5C/Fase 4 (§1.2) |
| Dikerjakan | **PARALEL dengan Fase 4** (coder berbeda) — pengecualian urutan fase disetujui eksplisit user 19-10-2026 (P-1). Aturan anti-tabrakan §3 **wajib** |
| Key | Skema: **DBV-023** (DBV-015 lama **tidak dipakai**). Pekerjaan baru: **MAKE-030** dst. (MAKE-022..027 milik Fase 4, MAKE-028/029 cadangan). Perbaikan review/QA: CR-### berikutnya (CR-056 dst., dikonfirmasi reviewer CR) |
| Sumber | Keputusan user 19-10-2026; Trello DEV-006, QASMTASK-076..088, QAUI-005, QAFUNC-006; `Bahan Baku SIMPEG/05-Presensi.md`; Matriks Role × Endpoint v2 Modul D; Technical Spec, ADR, Mapping Migrasi, Legacy System Audit; `PRD_20261001.md` (SG-1..SG-3, J7.01–J7.07, AS-05/06/10/12/14); `Feature Slip Gaji.md`; `PRD_G-03_Lokasi_Presensi.md`; `PRD_Kalkulasi_Tukin.md`; DDL produksi D1; SQL lokal legacy Slip Gaji; kode legacy `771f8b4`; repo v2. Daftar di Lampiran A |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh dokumen ini sebelum menulis satu baris kode.** Laporan M0 (§10) membuktikan pemahaman.
2. Bila dokumen ini bertentangan dengan sumber lain (Trello, `05-Presensi.md`, Matriks, TS/FSD/SRS, README modul, mockup, PRD lama), **PRD ini + keputusan user (§5) MENANG**. Di antara keduanya, keputusan user menang atas teks PRD lain.
3. Bila ada hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG MENEBAK.** Catat di laporan serah dan di `backend/docs/progress/05-Presensi.md`. Kerjakan bagian lain yang tidak terdampak sambil menunggu.
4. **⛔** = titik tunggu: jangan lanjut sebelum pihak yang disebut menyetujui. Di §13: butir **⛔** WAJIB dijawab sebelum milestone yang disebut; butir **◻ default** = default PRD berlaku bila reviewer CR tidak menolaknya saat review laporan M0.
5. Label: **[K]** = ikut legacy apa adanya (rujukan berkas:baris), **[V2]** = deviasi v2 yang disengaja (alasan + keputusan dicatat), **[I]** = inferensi dari kode bila DDL/kode tidak cukup (wajib disebut di dokumen DBV/laporan).
6. Rujukan legacy relatif ke `application/` salinan legacy: `Local.php` = `controllers/hr/services/Local.php`, `Presensi.php` = `controllers/hr/Presensi.php`, `L_presensi.php` = `libraries/hr/L_presensi.php`, `Sl_gaji.php` = `controllers/hr/Sl_gaji.php`, `Lsl_gaji.php` = `libraries/hr/Lsl_gaji.php`, `Verify.php` = `controllers/Verify.php`, `MYC` = `core/MY_Controller.php`, `Lm_lokasi.php` = `libraries/hr/master/Lm_lokasi.php`, `SQL-L` = berkas `sql/2026_09_01_slip_gaji_migration.sql`, `sql/2026_09_02_slip_gaji_qr_history.sql`, `sql/2026_10_08_slip_gaji_uang_makan.sql` di root legacy, `D1:` = baris DDL produksi `simpeg01_struktur_lengkap_20261001.sql`. Rujukan repo relatif ke root repo.
7. Aturan dalam dokumen ini bersifat **LOCKED**: setiap "WAJIB", "DILARANG", dan AC harus dipenuhi apa adanya. Tidak ada butir yang boleh dilewati, dilonggarkan, atau "diputuskan sendiri". Deviasi hanya dengan persetujuan tertulis reviewer CR (dicatat di progres + laporan).

---

## 1. Scope 5A

### 1.1 Di dalam scope 5A

| Task | Judul | Inti 5A | QASMTASK |
|---|---|---|---|
| D-01 | Migration tabel presensi & gaji | Satu unit review **DBV-023**: `dh_online` (+ kolom koordinat/lokasi/sumber F5-12), `gaji_pegawai` versi legacy terbaru (F5-2), `gaji_pegawai_qr_history`, nilai ENUM `audit_logs.event` baru bila perlu (F5-4); registry NIP; ⛔ DBV approve & DBV-022 di `main` sebelum migration | 076 |
| D-10 | Slip Gaji — impor Excel | 3 langkah unggah → pratinjau (berkas sementara `writable/` + token + TTL) → commit (validasi ulang server dalam satu transaksi); log mismatch nama-NIP ke `audit_logs`; opsi timpa bawaan tercentang; role 1 + role 3 dalam satker (SG-2) | 085 |
| D-11 | Slip Gaji — akses pegawai & kelola admin | Gerbang tgl 4 10:00 WIB (`GerbangPeriode`), hanya slip sendiri (2/6/7), buka sekali atomik, cetak PDF (TCPDF + QR), kelola admin legacy (monitoring, detail, koreksi, buka ulang, cetak) untuk 1 + 3 dalam satker; uang makan N−1 lewat kontrak placeholder (nilai disambung 5B) | 086 |
| D-14 | Verifikasi QR publik `/verify/slip/{token}` | Halaman publik tanpa login, token hash SHA-256, throttle CI4, data AS-06, `noindex`, respons "tidak ditemukan" seragam | (kartu baru, F5-20) |
| D-02 | Presensi online + geofence | Rekam masuk/pulang role 2/6/7; geofence **divalidasi server** (haversine, `≤ radius`) untuk pegawai yang tercakup aturan lokasi; pegawai tanpa aturan bebas [K]; jendela waktu & zonasi `satker.zonasi` [K]; pulang last-wins [K] | 077 |
| D-03 | Presensi manual | Tambah/ubah/hapus `dh_online` hanya akun `pengguna.id` 4675 & 4676 (`Config\Presensi`); validasi [V2]; hapus fisik + audit [K] | 078 |
| 5A-4 | Adapter data → `KalkulasiTukin` (sisi integrasi D-04/D-05/D-07) | `KonfigurasiTukin` dari `web_config` (tarif + key jam kerja/puasa baru F5-16), hari libur, periode BKN, `DataPegawaiTukin`, presensi `dh_online` & konket `absen_ijin`; cuti/TB = antarmuka kosong fail-closed (5B) | 079, 080, 082 (bagian integrasi) |
| FE 5A | Frontend Presensi & Slip Gaji (F5-21) | Halaman presensi (masuk/pulang + status lokasi/indikator radius), slip pegawai (daftar, buka, cetak PDF), kelola & impor admin (pratinjau tabel validasi), data presensi + presensi manual, halaman verifikasi publik, menu per role + flag `rilis` | 087 (bagian 5A), QAUI-005 (bagian 5A) |

Sub-butir wajib per task ada di §7. Test 5A (bagian D-13 yang relevan: impor slip mismatch/duplikat/constraint, gerbang waktu, ubah tarif `web_config`) termasuk di 5A (§7.9).

### 1.2 DI LUAR scope 5A (DILARANG dikerjakan atau dilaporkan sebagai bagian 5A)

| Di luar scope | Milik | Catatan untuk 5A |
|---|---|---|
| D-06 precedence status khusus (cuti/TB), adapter cuti `riwayat_cuti` & `bebasPresensiPenuh` | **5B** (butuh Fase 4 M3/M5) | 5A hanya menyediakan **antarmuka kosong fail-closed** (§7.7.6). DILARANG membaca `riwayat_cuti`/`riwayat_tb` |
| D-08 Laporan Tukin (`list_lt`, `list_lt_il`, `laporan_tukin/{nip?}`), ekspor PDF/XLS besar | **5B** | 5A tidak membuat endpoint laporan tukin |
| D-09 rekap kehadiran & **nilai** uang makan (termasuk nilai uang makan di slip) | **5B** | 5A menyediakan kontrak placeholder `UangMakanSlipInterface` (§7.3.7). **Nilai uang makan di slip WAJIB tersambung sebelum go-live** (F5-1) |
| D-12 sisa (Laporan Tukin dkk., tabel densitas tinggi, color-coding TL/PSW/TK/TPM/TPP), D-13 lengkap (12 skenario regression suite), **sign-off Horii** | **5C** | 5A tidak menaikkan `ACTIVE_PHASE` |
| Modul C (cuti, TB, IB, PMK, notifikasi, e-sign) | **Fase 4** (coder lain) | DILARANG menyentuh berkas Fase 4 (§3.2) |
| Notifikasi FCM presensi (topik `pegawai_blm_masuk/pulang/lkh`), adapter real FCM/BSrE | **Fase 6** | 5A tidak mengantre push apa pun |
| Presensi di dashboard (tombol rekam di `UserDashboard`, "Kehadiran Tim", hitung presensi hari ini admin) | Fase 7 | 5A membuat halaman Presensi sendiri |
| Fitur admin Slip Gaji **di luar legacy** (mis. monitoring tambahan, buka ulang massal, koreksi uang makan) | Kartu terpisah (F5-8) | Hanya fitur admin yang ada di legacy (§7.3) |
| Selfie, ikatan perangkat, mode WFH/WFO | Tidak dibuat | Keputusan user 19-10 |
| Impor data legacy (`user_geo`, `dh_online_coordinate`, `d_dh_online`, `user_lokasi_presensi`) | Tidak diimpor | F5-12, DBV-020 butir 6 |
| Manual Testing **MT-00x / MTC-0xx** (MTC-030..036) | Fase tersendiri (MT) | DILARANG dimasukkan ke task list, prasyarat, status, atau ringkasan 5A. MTC hanya referensi |

---

## 2. Aturan proyek WAJIB

Sumber: `AGENTS.md` (`origin/main`), `CLAUDE.md` → `@AGENTS.md`, `docs/fase4/PRD_Fase4.md` §2 (disalin & disesuaikan), README modul `backend/app/Libraries/Presensi/README.md`.

### 2.1 UI aksi baris tabel (AGENTS.md §1)
1. Semua aksi per baris di **satu tombol ⋮** kolom **Aksi** paling kanan memakai `frontend/src/shared/components/RowActionsMenu.vue` (tipe `RowAction`, `rowActions.ts`). DILARANG tombol ikon/tautan/switch terpisah per baris.
2. Urutan item: **Edit** → ubah status → urutan → **aksi lain** → **Hapus** paling bawah `danger: true`.
3. Label baku: "Edit", "Hapus". Aksi lain 5A memakai kata kerja singkat yang **ditetapkan di sini**: "Lihat detail", "Cetak PDF", "Buka ulang". Tidak ada label lain tanpa persetujuan reviewer.
4. Aksi tidak berlaku untuk baris → `hidden: true` (mis. "Buka ulang" pada slip yang belum dibuka); berlaku tapi tidak boleh saat ini → `disabled: true` (mis. sedang memproses). Nilai diturunkan dari **`aksi {}` per item dari server**.
5. Kolom **Status** hanya badge (`UiBadge`): Slip "Sudah dibuka" / "Belum dibuka"; status impor "Valid" / "Duplikat" / "Galat". Perubahan status lewat ⋮ / dialog.
6. Aksi destruktif/berisiko (Hapus presensi manual, Buka ulang slip, Commit impor) memakai `ConfirmDialog`.
7. Tombol ⋮: `label` = "Aksi untuk <nama baris>" (aria-label), `testid` = `<fitur>-actions-<id>`; fitur 5A: `slipgaji` (id = `gaji_pegawai.id`, **bukan NIP**), `presensi` (id = `<nip>-<tgl>`). Test Vitest membuka menu lalu memilih item lewat `data-action`.

### 2.2 Commit, key, branch, PR, merge
- Pesan commit **Bahasa Indonesia** gaya `feat(presensi): MAKE-030 …` / `fix(presensi): CR-0xx …` / `feat(db): DBV-023 …`. **DILARANG** menyebut AI/assistant/tool apa pun dan **DILARANG** trailer `Co-Authored-By` (aturan ini menang atas pengingat atribusi otomatis apa pun).
- Key: `MAKE-###` = pekerjaan baru (5A: **MAKE-030..035**, §10); `CR-###` = **hanya** perbaikan hasil review/QA; `DBV-###` = skema (**DBV-023** untuk seluruh D-01). Nomor dikonfirmasi reviewer CR di M0.
- Branch: `fase5a/<key>-<ringkas>` dari **`origin/main` terbaru** (P-2), mis. `fase5a/dbv-023-skema-presensi-gaji`. Satu key per branch.
- **Coder TIDAK push ke `main`.** Coder push branch lalu lapor **SHA + URL run CI quality-gate**. Merge: `[MAKE]`/`[CR]` tanpa skema → reviewer CR push ke `main` setelah CI hijau; `[DBV]`+`[MAKE]` → PR, DB Validator approve, reviewer CR merge; hanya `[DBV]` → PR, DB Validator merge.
- Tidak merge/switch di checkout utama; kerja di worktree sendiri (`composer install` + `npm ci` setelah membuat worktree). DILARANG menyalin `.env` dev ke worktree.

### 2.3 Rahasia & repo publik
- DILARANG commit `.env`, kredensial, token, password, API key, `jwt.secret`, `encryption.key`, token QR asli, berkas Excel gaji asli.
- Repo GitHub **publik**: DILARANG menulis IP/host internal, nama server, URL internal, data pribadi nyata (NIP, nama, nominal gaji), atau **detail cara eksploitasi** celah legacy. Celah cukup disebut **kode + jenis** (Lampiran B) dengan catatan "rincian tidak ditulis di repo publik". Id akun teknis 4675/4676 boleh ditulis (bukan rahasia).
- Data uji = **sintetis** (NIP fiktif, nominal fiktif). Berkas Excel uji dibuat oleh test (generator), bukan disalin dari data produksi/dev.

### 2.4 Quality gate
- Gate penuh = **CI `quality-gate`** (`.github/workflows/quality-gate.yml`: PHPStan L5 + CS-Fixer, frontend `npm run check`, PHPUnit ter-shard di **MySQL 8.0** dan **MariaDB 10.4**). Lapor hanya SHA yang CI-nya **hijau**.
- Lokal cukup **gate cepat**: `cd backend && composer analyse && composer cs-check`; `cd frontend && npm run check` bila FE berubah; PHPUnit terarah (`vendor/bin/phpunit --no-coverage tests/Presensi/...`). `./check.sh` dijalankan detached bila perlu (satu gate penuh per mesin).
- File test baru di `backend/tests/**` otomatis masuk shard CI.

### 2.5 Test database
- Test ber-DB **wajib** `extends Tests\Support\DatabaseTestCase`; seed lewat `$seed`.
- `#[Group('db-isolasi-penuh')]` untuk DDL (`down()/up()`, CREATE/ALTER/DROP/TRUNCATE), koneksi DB kedua, test yang menulis berkas sementara di `writable/` bersamaan dengan transaksi DB, dan test konkurensi. DILARANG melonggarkan assertion.
- DB scratch bernama eksplisit `simpeg_v2_t_<nama>` (mis. `simpeg_v2_t_dbv023`) di instance MySQL test **port 3307**. DILARANG menyentuh MySQL 3306 / DB dev `simpeg_v2`.
- Akun uji lewat `AccountProvisioner` punya `must_change_password = 1` (MAKE-020) → di fixture **set `0`**, kalau tidak endpoint menjawab 403 `password_change_required`.
- Waktu di test **disuntik** (`Time::setTestNow()` atau argumen `$sekarang`), DILARANG bergantung jam sistem (gerbang slip, jendela presensi, zonasi).

### 2.6 Skema
- Skema ikut D1 / SQL-L + label [K]/[V2]/[I]; collation tabel `utf8mb4_unicode_ci`; FK **ON DELETE RESTRICT ON UPDATE RESTRICT** dengan **nama FK legacy** (CASCADE legacy diganti, ADR-008, K4); kolom NIP ber-FK VARCHAR(30); trigger legacy **tidak** dibawa (logika pindah ke service); CHECK domain [V2]; kolom audit diisi aplikasi (stempel UTC, `id_pengguna`).
- Status master 1/2/10 (tidak ada tabel master baru di 5A).
- Migration yang sudah ada di `main` **DILARANG diedit**; koreksi via migration baru + dokumen DBV.
- Setiap kolom NIP baru **wajib** diklasifikasi di `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php` dan `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`; cakupan koreksi NIP (`backend/app/Libraries/Kepegawaian/NipCascadeService.php`) diperluas ke `dh_online` (§3.4).
- Dokumen review: `backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md` + test skema `information_schema` + siklus `down()`/`up()`.

### 2.7 Kontrak beku = aditif
Kontrak Fase 0–4 yang ada di `main` (README kontrak `Controllers/Api/Kepegawaian/README.md`, `PegawaiScope`, `ValidasiBerkas`, `AturanLampiran`, `WebConfigService`, `WebConfigTypes`, `HariLiburService::tanggalLibur`, `HariKerja`, `Pdf`, `AuditLogModel`, `RowActionsMenu`, `ConfirmDialog`, `UiFileField`, `nav.config.ts`, `router/index.ts`) **hanya boleh ditambah (aditif)**, DILARANG diubah perilakunya. Butuh varian → fungsi/kelas baru, bukan mengubah yang lama. Library murni milik Fase 5 (`Libraries/Presensi/SlipGaji/*`, `Libraries/Presensi/Tukin/*`) hanya boleh diubah sebatas yang ditetapkan PRD ini (§2.8, §7.3.8) dengan test lama tetap hijau.

### 2.8 Kemurnian `Libraries/Presensi/SlipGaji` & `Libraries/Presensi/Tukin` (WAJIB)
- Kedua folder dijaga pemindai `tests/_support/Libraries/PemindaiKemurnian.php` lewat `tests/unit/Presensi/SlipGaji/SlipGajiMurniTest.php` dan `tests/unit/Presensi/Tukin/KalkulasiMurniTest.php` (larangan `Database`, `Model`, `Services`, `Cache`, `Config`, `Time`, `PhpOffice`, `IOFactory`, I/O berkas, jam, acak). **Kode ber-DB, ber-HTTP, ber-berkas, ber-jam, atau ber-acak DILARANG di kedua folder itu.** Pemindai DILARANG dilonggarkan.
- Tempat kode ber-DB/IO 5A (milik 5A, dibuat baru):

| Folder | Isi |
|---|---|
| `backend/app/Libraries/Presensi/Slip/` | `SlipGajiService` (pegawai), `SlipGajiAdminService`, `ImporSlipService`, `PembacaExcelSlip` (PhpSpreadsheet), `PenyimpanPratinjau` (berkas sementara + token + TTL), `PdfSlipGaji`, `TokenSlipService` (acak + hash + riwayat), `VerifikasiSlipService`, `UangMakanSlipInterface` + `UangMakanBelumTersedia` + `UangMakanSlip` (VO) |
| `backend/app/Libraries/Presensi/Kehadiran/` | `PresensiOnlineService`, `PresensiManualService`, `ResolverLokasiPresensi` (ber-DB), `ZonaSatker` (ber-DB) |
| `backend/app/Libraries/Presensi/Geo/` | `Haversine`, `PenilaianGeofence` — **murni** (tanpa DB), diuji di `tests/unit/Presensi/Geo/` dan ikut dipindai kemurnian (tambahkan pemindaian folder ini, aditif) |
| `backend/app/Libraries/Presensi/TukinData/` | `TukinDataService` (adapter), `KonfigurasiTukinDariWebConfig`, `SumberHariLibur`, `SumberPeriodeTukin`, `SumberDataPegawaiTukin`, `SumberHarianTukin`, `SumberCutiTbInterface` + `SumberCutiTbBelumTersedia` |
| `backend/app/Models/Presensi/` | `DhOnlineModel`, `GajiPegawaiModel`, `GajiPegawaiQrHistoryModel` (semua `extends BaseAuditableModel` kecuali dinyatakan lain di §8.1) |
| `backend/app/Controllers/Api/Presensi/` | `SlipGajiController`, `SlipGajiAdminController`, `SlipGajiImportController`, `VerifikasiSlipController`, `PresensiController`, `PresensiManualController`, `README.md` (dokumentasi endpoint) |

---

## 3. ATURAN PARALEL & ANTI-TABRAKAN dengan Fase 4 (F5-18, F5-19, F5-22)

Fase 4 (Modul C) dikerjakan coder lain **bersamaan**, mengikuti `docs/fase4/PRD_Fase4.md`. Aturan berikut **wajib**; pelanggaran = berhenti & lapor.

### 3.1 Berkas MILIK 5A (Fase 4 tidak menyentuh)
`backend/app/Config/RoutesPresensi.php`, `backend/app/Config/Presensi.php`, `backend/app/Controllers/Api/Presensi/**`, `backend/app/Libraries/Presensi/**`, `backend/app/Models/Presensi/**`, `backend/app/Filters/ThrottlePublikFilter.php`, `backend/app/Views/pdf/slip_gaji.php`, `backend/app/Database/Migrations/<tanggal>_*Presensi*|*Gaji*.php` (5A), `backend/tests/Presensi/**`, `backend/tests/unit/Presensi/**`, `backend/tests/database/Presensi/**`, `backend/docs/db-review/DBV-023-*.md`, `backend/docs/db-review/sql/DBV-023-*.sql`, `backend/docs/progress/05-Presensi.md`, `docs/adr/ADR-032-*.md`, `frontend/src/features/presensi/**` (termasuk `routes.presensi.ts`, `roles.ts`, `__tests__/`).

### 3.2 Berkas MILIK Fase 4 — DILARANG disentuh 5A
`backend/app/Libraries/Layanan/**`, `backend/app/Controllers/Api/Layanan/**`, `backend/app/Models/Layanan/**`, `backend/app/Config/RoutesLayanan.php`, `backend/app/Config/Cuti.php`, `backend/app/Config/Layanan.php`, `backend/app/Interfaces/StatusTugasBelajarInterface.php` (dan implementasinya), migration & seed Fase 4, `backend/docs/db-review/DBV-022-*`, `backend/docs/progress/04-Layanan.md`, `backend/tests/Layanan/**`, `backend/tests/database/Layanan/**`, `frontend/src/features/layanan/**`, `docs/fase4/**`. Butuh perubahan di sana → **berhenti & lapor reviewer CR**.

### 3.3 Berkas BERSAMA — hanya ADITIF, commit TERPISAH
Setiap perubahan berkas di tabel ini: (a) hanya menambah (entri/baris/metode baru) — DILARANG mengubah atau menghapus baris yang ada kecuali disebut; (b) di **commit tersendiri** berjudul jelas (mis. `feat(presensi): MAKE-031 daftarkan RoutesPresensi di Routing`), satu topik per commit; (c) sebelum push: `git fetch` + rebase ke `origin/main` terbaru, selesaikan konflik per hunk dengan **mempertahankan kedua sisi**.

| Berkas | Perubahan 5A yang diizinkan |
|---|---|
| `backend/app/Config/Routing.php` | Tambah satu entri `APPPATH . 'Config/RoutesPresensi.php'` di `$routeFiles` |
| `backend/app/Config/Filters.php` | Tambah alias `'throttle' => ThrottlePublikFilter::class` |
| `backend/app/Config/Services.php` | **Hindari.** Pakai pola pabrik statis/Config (`Pdf::renderer()`). Bila tidak terhindarkan → aditif + alasan di laporan |
| `backend/app/Config/WebConfig.php` | Tambah key katalog F5-16 (§7.7.2) + grup baru `jam_kerja` |
| `backend/app/Libraries/MasterData/WebConfigTypes.php` (+ `tests/unit/Libraries/WebConfigTypesTest.php`) | Tambah satu tipe `date_range_list` (§7.7.2); tipe lain tidak berubah |
| `backend/app/Libraries/Kepegawaian/Lampiran/ValidasiBerkas.php` / `.../Riwayat/AturanLampiran.php` | Tambah pabrik/aturan untuk berkas impor non-lampiran (§7.2.2) tanpa mengubah perilaku lampiran |
| `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`, `backend/app/Libraries/Kepegawaian/NipCascadeService.php`, `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php`, `backend/tests/Kepegawaian/NipCascadeTest.php`, `backend/tests/unit/Kepegawaian/Nip/TabelAnakNipTest.php` | Hanya sesuai §3.4, **setelah** DBV-022 Fase 4 ada di `main` |
| `backend/composer.json`, `backend/composer.lock` | Tambah `phpoffice/phpspreadsheet` (+ `ext-*` yang dibutuhkan). Lock diregenerasi **setelah** rebase ke `main` terbaru, di commit sendiri |
| `.github/workflows/quality-gate.yml` | Hanya bila ekstensi PHP CI kurang untuk PhpSpreadsheet: tambah ekstensi di `PHP_EXTENSIONS` |
| `tests/_support/Libraries/PemindaiKemurnian.php` atau test pemanggilnya | Tambah pemindaian folder murni baru `Libraries/Presensi/Geo` (tanpa melonggarkan larangan) |
| `frontend/src/router/index.ts` | Tambah impor `routes.presensi.ts` + spread; tambah rute publik `/verify/slip/:token` |
| `frontend/src/shared/layouts/nav.config.ts` + `__tests__/nav.config.spec.ts` | Field opsional `rilis?: boolean` di `NavItem`, `roles?` di `NavChild`, penyaringan anak per role, isi `children` grup Presensi, `rilis: true` pada grup Presensi (§7.8.2). **`ACTIVE_PHASE` DILARANG disentuh** |
| `frontend/src/shared/**` lain | Hanya komponen baru yang dipakai ≥ 2 modul; DILARANG mengubah perilaku komponen yang ada |
| `docs/adr/README.md` | Ubah satu baris ADR-032 (status → "Diterima", tautan berkas repo) |
| Migration `audit_logs` (ENUM `event`) | Migration **baru** 5A (bukan edit). Bila DBV-022 juga mengubah ENUM `event`, migration 5A memuat **gabungan** semua nilai (urutan versi setelah DBV-022) |

### 3.4 Registry NIP & `NipCascadeService::FASE` (titik tabrakan kritis)
1. ⛔ Perubahan registry/cascade 5A **baru boleh dibuat setelah DBV-022 (Fase 4 M2) ada di `main`**. Sebelum itu 5A hanya menyiapkan rancangan di dokumen DBV-023.
2. Setelah rebase ke `main` yang memuat DBV-022:
   - Bila `NipCascadeTest::testRegistryCocokDenganSkema` masih memakai literal `sampaiFase(3)` (atau angka lain), ubah **satu kali** menjadi `TabelAnakNip::sampaiFase(NipCascadeService::FASE)` (F5-19) di commit tersendiri — kecuali Fase 4 sudah melakukannya.
   - Naikkan `NipCascadeService::FASE` ke **5** di commit migration DBV-023 (karena `dh_online.nip` ber-FK ke `pegawai`). `dh_online.nip` WAJIB dikoreksi oleh cascade (FK RESTRICT); `gaji_pegawai.nip` tetap `NonFkSlipGaji` [K] (tidak ikut Ubah NIP, slip lama tetap ber-NIP lama, `TabelAnakNip.php:133-137`).
   - Bila `sampaiFase(5)` memunculkan entri registry fase 5 lain yang tabelnya belum ada di v2 → **BERHENTI & lapor** (DILARANG mengubah klasifikasi/`TERLEWAT` tanpa persetujuan reviewer CR).
   - Hitungan di `TabelAnakNipTest` (jumlah entri/FK/Ada/per fase) diperbarui oleh 5A **hanya** untuk entri yang diubah 5A; nilai Fase 4 dipertahankan.
3. `gaji_pegawai_qr_history` **tidak** punya kolom NIP → tidak masuk registry (dicatat di DBV-023).

### 3.5 Urutan merge (WAJIB)
1. Dokumen DBV-023 + kueri audit + draf migration boleh disusun **sekarang** (PR `[DBV]` doc-only).
2. ⛔ Migration DBV-023 di-rebase dan baru boleh di-merge **setelah DBV-022 masuk `main`** dan DB Validator menyetujui DBV-023. Timestamp migration 5A WAJIB **lebih baru** dari semua migration DBV-022.
3. Adapter Tukin bagian non-skema (§7.7, butir 1–4) dan logika murni geofence boleh jalan paralel sebelum skema.
4. FE 5A per halaman setelah backend halaman itu merged.
5. `ACTIVE_PHASE`: 3→4 milik Fase 4 M7; 4→5 milik penutup Fase 5 (5C). 5A **DILARANG** mengubahnya; visibilitas menu 5A lewat flag `rilis` (F5-18).

### 3.6 Menangani konflik
- Konflik teks di berkas bersama → selesaikan dengan **mempertahankan kedua sisi** (aditif), jalankan test berkas terkait (mis. `RoutePegawaiKontrakTest`, `nav.config.spec.ts`, `NipReferenceRegistryTest`).
- Konflik **makna** (dua fase mengubah perilaku sama, nomor migration/ENUM/kode bentrok, test Fase 4 gagal karena perubahan 5A atau sebaliknya) → **BERHENTI**, jangan "memperbaiki" kode Fase 4, laporkan ke reviewer CR dengan SHA kedua sisi.
- Koreksi path `NipCascadeService` di PRD Fase 4 (`docs/fase4/PRD_Fase4.md` §6.1 menulis `Libraries/Kepegawaian/Nip/NipCascadeService.php`; path benar `backend/app/Libraries/Kepegawaian/NipCascadeService.php`) = **tugas dokumen reviewer CR** yang ikut push PRD 5A (F5-22). Coder 5A **tidak** mengubah `docs/fase4/**` (revisi dokumen lain: Q5A-20).

---

## 4. Glosarium & label

| Istilah | Arti di 5A |
|---|---|
| **[K] / [V2] / [I]** | Ikut legacy / deviasi v2 disengaja / inferensi (lihat Cara pakai #5) |
| **Periode slip** | Pasangan (bulan, tahun) `gaji_pegawai`; kunci API `YYYY-MM` (`PeriodeSlip::dariTeks`) |
| **Gerbang rilis** | Periode (B, T) tersedia bagi pegawai ⇔ periode ≥ Januari 2024 **dan** sekarang WIB ≥ T-B-04 10:00:00 WIB (inklusif, per detik) — `GerbangPeriode` (G-1, K-CR027-4). WIB tetap untuk semua satker (G-5) |
| **Dibuka** | `gaji_pegawai.viewed_at IS NOT NULL`. Dibuka hanya oleh pegawai pemilik, sekali; buka ulang hanya oleh admin |
| **Token QR** | 64 heks huruf kecil dari 32 byte acak (`TokenQr`); **disimpan sebagai hash SHA-256 heks** (F5-6). "Token aktif" = token terbit terakhir sejak buka terakhir |
| **Riwayat token** | Baris `gaji_pegawai_qr_history` per penerbitan token (setiap buka), dipakai verifikasi agar PDF lama tetap terverifikasi |
| **Pratinjau impor** | Hasil validasi server atas berkas Excel yang disimpan sementara di `writable/` dengan token + TTL (F5-3) |
| **Timpa** | Opsi commit: kunci yang sudah ada di DB (status "duplikat") diperbarui. Bawaan **tercentang** (F5-5) |
| **Uang makan N−1** | Uang makan presensi bulan sebelum periode slip, dihitung saat slip dibuka lalu di-snapshot (F5-1, `Lsl_gaji.php:157-220`). Nilai = 5B; kontrak = 5A |
| **Aturan lokasi** | Baris `dm_user_lokasi_presensi` status 1 (G-03); menentukan lokasi `lokasi_presensi` mana yang berlaku untuk pegawai |
| **Tercakup aturan** | Pegawai yang punya ≥ 1 aturan lokasi berlaku pada tanggal lokal hari itu (§7.5.3) → wajib geofence |
| **Zonasi** | `satker.zonasi` (menit, 0/60/120) satker jabatan saat ini pegawai; menggeser jam & tanggal presensi (G-02) |
| **Tanggal/jam lokal** | Jam server Asia/Jakarta + zonasi satker; disimpan apa adanya di `dh_online.tgl/jam_*` (bukan UTC) |
| **Jendela presensi** | Masuk 04:00:00–12:30:59, pulang 12:31:00–23:59:59 waktu lokal [K] (F5-11) |
| **Akun khusus** | `pengguna.id` ∈ {4675 (USR_SADEV), 4676 (USR_SPC)} — satu-satunya pelaku presensi manual (F5-14) |
| **Lingkup** | `PegawaiScopeInterface` platform (dipakai lihat data presensi admin). **Slip Gaji tidak memakai PegawaiScope**: cakupan role 3 = `AksesSlip::dalamCakupan` (satker jabatan saat ini = `id_satker` akun, fail-closed) |
| **Adapter Tukin** | Kode ber-DB yang menyiapkan argumen `KalkulasiTukin::hitung()` dari data v2 tanpa mengubah library murni |
| **Rilis (menu)** | Flag aditif `rilis: true` pada item menu: tampil walau `phase > ACTIVE_PHASE` (F5-18) |

---

## 5. Keputusan (LOCKED) & pemetaan DoD

### 5.1 Pola umum (keputusan user 19-10-2026)
Hak **LIHAT** ikut Matriks v2; alur **TULIS/PROSES** ikut legacy [K]; deviasi disengaja [V2]; DoD Trello dipenuhi; keputusan final lama tetap berlaku (SG-1, SG-2, SG-3, K-1, K1–K4, DB terpisah + impor, status 1/2/10, `utf8mb4_unicode_ci`, migration di `main` tidak diedit). Hal di luar ini → eskalasi, **jangan menebak**.

### 5.2 Tabel keputusan

| ID | Keputusan (ringkas, mengikat) | Dipakai di |
|---|---|---|
| P-1 | Fase 5 dijalankan **paralel** dengan Fase 4 (coder berbeda); entry criteria "Fase 4 DONE" dikecualikan untuk 5A; 5B tetap menunggu Fase 4 M3/M5 | §1, §3, §9 |
| P-2 | Bertahap 5A → 5B → 5C; satu PRD per scope; branch `fase5a/…`, `fase5b/…`, `fase5c/…` | §2.2, §10 |
| P-3 | Key MAKE-030 dst.; skema 5A = **DBV-023** (DBV-015 tidak dipakai) | §2.2, §7.1, §10 |
| P-4 | Instruksi coder = PRD per scope + prompt 1/2 & 2/2 DEV-006 | Header |
| F5-1 | Acuan Slip Gaji = legacy **`771f8b4`** [K]: total = `bersih_gpp + bersih_tk + uang_makan` (uang makan bulan N−1, snapshot saat dibuka); baris tukin kelas jabatan **dihapus**. Inti D-11 di 5A; nilai uang makan disambung D-09 (5B), **WAJIB sebelum go-live**. README `Libraries/Presensi/README.md` diperbarui (R-1..R-4, P-8, BK-1 direvisi) | §7.3.7, §7.3.8 |
| F5-2 | `gaji_pegawai` v2 = versi legacy terbaru: 28 kolom GPP/TK + `uang_makan` (snapshot) + `uang_makan_hari` + `uang_makan_periode` + kolom status/token legacy; kolom generik lama D1 **dibuang** [V2]; semua histori dimigrasi (dugaan 0 baris), dikonfirmasi kartu DBA `SHOW CREATE TABLE` + `COUNT(*)` produksi | §7.1 |
| F5-3 | Pratinjau impor = berkas sementara di `writable/` (tidak publik) + token + TTL; commit **memvalidasi ULANG** di server dalam **satu transaksi** (tidak memercayai baris klien); **tanpa tabel baru** | §7.2 |
| F5-4 | Mismatch nama–NIP **dicatat ke `audit_logs`** (bukan silent-fail); nilai ENUM `event` baru → bagian DBV-023 | §7.1.1 (4), §7.2.3 #5 |
| F5-5 | Opsi timpa impor **bawaan TERCENTANG** (02-10, `admin_import.php:54`); J7.02 "default mati" dikoreksi | §7.2 |
| F5-6 | Token QR disimpan **HASH SHA-256** [V2] (J7.03); path `/verify/slip/{token}` dipertahankan (QR PDF lama menunjuk ke sana) | §7.1, §7.4 |
| F5-7 | Verifikasi publik: filter Throttler bawaan CI4 (**30 permintaan/menit per IP**, IP tidak disimpan); data tampil = default AS-06; konfirmasi Biro Keuangan **sebelum go-live** | §7.4 |
| F5-8 | Modul admin Slip Gaji = **fitur admin yang ada di legacy saja**; tambahan = kartu terpisah | §7.3.4 |
| F5-9 | Pelaku presensi online = legacy **role 2/6/7**; geofence **divalidasi server** untuk **semua** pegawai yang tercakup aturan lokasi [V2] | §7.5 |
| F5-10 | Pegawai tanpa aturan lokasi **tetap boleh** presensi [K]; radius **`≤`**; akurasi GPS **dicatat**, tidak dipakai menolak | §7.5 |
| F5-11 | Jendela waktu (masuk 04:00–12:30:59, pulang 12:31–23:59:59), pulang **last-wins**, jam/tanggal lokal `satker.zonasi` [K]; konstanta di `Config\Presensi` | §7.5 |
| F5-12 | Kolom koordinat (lat/lng/akurasi), id lokasi, dan `sumber` (online/manual) ditambahkan di `dh_online` [V2] lewat DBV-023; `user_geo` & `dh_online_coordinate` **tidak diimpor** | §7.1, §7.5, §7.6 |
| F5-13 | **Tidak** menolak presensi pegawai yang sedang cuti/TB/nonaktif [K] (akun nonaktif sudah ditolak di login) | §7.5 |
| F5-14 | Presensi manual hanya `pengguna.id` **4675** (USR_SADEV) & **4676** (USR_SPC), daftar di `Config\Presensi` (override `.env` tervalidasi); aksi tambah/ubah/hapus (legacy `add/{nip}` ikut); hapus = **hapus fisik** + `audit_logs` [K]; validasi [V2]: jam masuk ≤ jam pulang, tanggal ≤ hari ini, `nip` & `tgl` wajib, minimal satu jam, duplikat `(nip, tgl)` ditolak | §7.6 |
| F5-15 | Lihat data presensi (admin) ikut Matriks, **termasuk role 3** dalam lingkup satker (PegawaiScope) | §6.3, §7.6 |
| F5-16 | Key `web_config` baru untuk jam kerja normal/puasa (+ Jumat) dan rentang puasa per tahun ditambahkan ke katalog G-09 (tanpa migration); nama & format **ditetapkan di PRD ini** | §7.7.2 |
| F5-17 | Tambah `phpoffice/phpspreadsheet` (xlsx/xls; cek ekstensi PHP CI); **ADR-032** (library PDF/Excel/QR & rute publik) **diputuskan di PRD ini** | §8.4 |
| F5-18 | Flag aditif per item menu `rilis` + `roles` di submenu agar Slip Gaji/Presensi tampil sebelum `ACTIVE_PHASE` 5; 3→4 milik Fase 4 M7, 4→5 milik penutup Fase 5 | §3.5, §7.8.2 |
| F5-19 | Anti-tabrakan: berkas sendiri, berkas bersama hanya aditif di commit terpisah, migration 5A di-rebase setelah DBV-022 di `main`, test cascade NIP memakai `NipCascadeService::FASE`; kode ber-DB DILARANG di `Libraries/Presensi/SlipGaji` & `Libraries/Presensi/Tukin` | §2.8, §3 |
| F5-20 | Kartu Trello baru: QASMTASK untuk D-14; kartu DBA `COUNT(*)` `gaji_pegawai` produksi (urusan reviewer CR) | §7.4, §13 Q5A-02 |
| F5-21 | FE Presensi & Slip Gaji ikut 5A; Laporan Tukin dkk. di 5C; acuan QA Lapis 1 = PNG Figma Slip Gaji (`Figma - UI Slip Gaji.png`, `UI Preview Slip Gaji (Export PDF).png`, folder kerja proyek di luar repo — minta ke reviewer CR) + pola komponen Fase 3 | §7.8 |
| F5-22 | Koreksi path `NipCascadeService` di PRD Fase 4 (dokumen saja) ikut push PRD 5A (urusan reviewer CR) | §3.6 |
| F5-23 | (20-10-2026) `DataPegawaiTukin.tmtMasuk` ikut legacy [K] `L_employee.php` `get_all_pegawai_dh_spe` (~:6400-6600) + `L_presensi.php:12699-12716`: `gol_ruang`/`tmtsk_pangkat` = baris `riwayat_kp` status 1 dengan `tmtsk ≤ awal periode` urut `tmtsk DESC` (pegawai `created_at` setelah akhir periode → `≤ akhir periode`); PPPK ditimpa `gol_pppk`/`tmtsk` mutasi jabatan terbaru; "hari kerja pertama" = library `HariKerja` atas periode + libur; `jumlahAnak` ikut syarat legacy `tgl_perkawinan ≤ akhir periode` (:12170-12198) | §7.7.5 |
| F5-24 | (20-10-2026, deviasi **[V2]**) Cabang PPPK pada `tmtMasuk` (legacy `L_presensi.php:12707` memakai **nama** `jenis_pegawai == 'PPPK'`) mengenali PPPK lewat **`id_jenis_pegawai == 6`** memakai konstanta bersama (mis. `Jabatan::JENIS_PEGAWAI_PPPK`), sama dengan bagian lain legacy (`:12621`, `L_employee.php:6580-6584`), role akun, dan badge CR-051. Alasan: nama master dapat diubah lewat CRUD → cek nama gagal diam-diam. **Cutover WAJIB** (runbook impor): `SELECT id_jenis_pegawai, jenis_pegawai FROM jenis_pegawai` di produksi; bila id 6 bernama tepat 'PPPK' → hasil identik legacy; bila bernama lain → nilai Tukin PPPK berbeda dari legacy (cabang legacy kemungkinan tidak pernah jalan) → keputusan bisnis + bandingkan dengan slip/rekap lama sebelum dipakai. Test: id 6 masuk cabang apa pun namanya; id selain 6 tidak masuk | §7.7.5, §13 Q5A-25 |
| — | Tidak perlu keputusan sekarang: FCM presensi (Fase 6), precedence tukin (5B, K-1 ikut rekap), selfie/ikatan perangkat (**tidak**), AS-09 checksum (§13) | §1.2, §9, §13 |

Keputusan final lama yang tetap berlaku (ringkas): **SG-1** Slip Gaji = port perilaku legacy; D-11 dan `/verify/slip/{token}` wajib live sejak switch produksi (DP2.4); D-14 task baru. **SG-2** akses slip: 2/6/7 milik sendiri, 1 semua satker, 3 satker sendiri (fail-closed, 403 di luar cakupan), 4/5/8 tanpa akses. **SG-3** slip tidak dirilis di produksi legacy. **K-1** tukin ikut rekap `laporan_tukin_us_skp`. **K2** ID legacy diimpor apa adanya (id akun 4675/4676 tetap bermakna). **K4** nama kolom ikut legacy kecuali keputusan tertulis; FK RESTRICT + koreksi NIP oleh aplikasi. **AS-12** stempel UTC.

### 5.3 Pemetaan teks DoD Trello → arti v2 (acuan penilaian QA)

QA **WAJIB** menilai memakai kolom "Arti v2":

| Teks DoD / Trello | Arti v2 (yang diuji) | Dasar |
|---|---|---|
| D-01 "`dh_online` UNIQUE `(nip, tgl)` eksplisit di DB, bukan cuma app logic" | **PRIMARY KEY `(nip, tgl)`** [K D1:398] = constraint unik di level DB. Diuji: `information_schema` PK `(nip,tgl)` + INSERT ganda → 1062. Teks DoD "legacy hanya app logic" keliru (P5-12) | D1, DBV-023 |
| D-01 "keputusan volume histori `gaji_pegawai` final (Item Kritis #4) [TBD blocker]" | Diputus F5-2: **semua** baris produksi dimigrasi; **ditutup** oleh hasil kartu DBA (`SHOW CREATE TABLE` + `COUNT(*)` produksi) yang dicatat di DBV-023 §impor | F5-2, F5-20, Q5A-02 |
| D-01 "dep: Fase 4 DONE" | **Dikecualikan** (P-1); diganti syarat "DBV-022 ada di `main`" untuk migration | P-1, §3.5 |
| D-02 "actor role 1,2,3,6,7 (pegawai aktif)" | Rekam presensi online = **role 2/6/7** saja; role lain 403 | F5-9 |
| D-02 "koordinat dalam radius lokasi terdaftar → tersimpan; di luar → ditolak" | Berlaku untuk pegawai **tercakup aturan** (§7.5.3) dengan jarak haversine **≤ radius** salah satu lokasi berlaku; pegawai tanpa aturan → tersimpan tanpa syarat lokasi | F5-9, F5-10 |
| D-03 "HANYA USR_SADEV(4675) & USR_SPC(4676)" | `pengguna.id` (bukan NIP) ∈ `Config\Presensi::$akunPresensiManual` **dan** role 1 | F5-14, K5-11 |
| D-03 "duplikat (nip,tgl) ditolak (UNIQUE)" | Tambah manual untuk `(nip,tgl)` yang sudah ada → 422 `errors.tgl` (tangkap 1062 PK) | F5-14 |
| D-03 "edit, delete" | + **tambah** (`add/{nip}` legacy) | F5-14, K5-12 |
| D-10 "akses role 1 only" | **Role 1 semua satker + role 3 dalam satkernya** (SG-2); 2/4/5/6/7/8 → 403 | SG-2 |
| D-10 "mismatch nama-NIP DI-LOG" | Baris ditolak (`nama_tidak_cocok`) **dan** satu baris `audit_logs` per kunci per pratinjau | F5-4, M-1..M-6 |
| D-10 "duplikat NIP+periode ditolak saat preview" | Kunci ganda **dalam berkas** → galat `baris_ganda`; kunci yang **sudah ada di DB** → status "duplikat" (ditimpa/dilewati sesuai opsi timpa) | I-8, I-11, I-12 |
| D-10 "UNIQUE violation saat commit → pesan jelas" | 1062 di commit (balapan) → **409 `konflik_data`**, seluruh transaksi di-rollback, pesan menyebut NIP + periode; tidak pernah 500 | §7.2.4 #5 |
| D-11 "`hr/sl_gaji/list` & `cetak` = role 1, 2" | **2/6/7 milik sendiri** (jalur pegawai) + **1 semua / 3 satker** (jalur admin, tanpa gerbang); 4/5/8 → 403 | SG-2 |
| D-11 "akses setelah tanggal 4 pukul 10:00 WIB bulan berjalan" | `GerbangPeriode::sudahRilis(p, $sekarang)` inklusif per detik pada **semua** jalur pegawai (daftar, status, buka, cetak) | G-1, G-6 |
| D-04 "periode 16 bulan lalu s.d. 15 bulan berjalan atau dari `bkn_periode_ekinper`" (bagian 5A) | Adapter membaca baris `bkn_periode_ekinper` → `PeriodeTukin::dariBulan(…, $periodeBkn)`; tanpa baris → bawaan 16–15 (Januari mulai 16 Desember) | §7.7 |
| D-05 "jam kerja dari `web_config`" (bagian 5A) | Key F5-16 (§7.7.2) → argumen `KonfigurasiTukin` | F5-16 |
| D-07 "tarif `web_config`" (bagian 5A) | Key `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` dari `WebConfigService`; tarif belum diatur → galat terkendali (bukan nilai 0 diam-diam) | §7.7.3 |
| Kolom "Lolos MTC-0xx" | **N/A** (MT fase tersendiri) | §1.2 |
| Sign-off "Horii" vs "Tech Lead" (`TS:745`) | Penandatangan Fase 5 = **Horii** (5C); 5A ditutup sign-off reviewer CR | §11 |

---

## 6. Matriks role × endpoint 5A (v2)

Pola Fase 3/4: prefix `api/v1/`; filter `jwt` di level grup; `Role::filter(...)` per route (pola `RoutesPegawai.php`) = kolom "Route"; aturan lebih rinci (kolom "Service") ditegakkan di **service**. Semua route 5A didaftarkan di berkas baru **`backend/app/Config/RoutesPresensi.php`** (ditambahkan ke `Config\Routing::$routeFiles`) yang memuat **dua grup**: (1) grup publik `api/v1/publik` **tanpa** `jwt` dengan filter `throttle`; (2) grup `api/v1` ber-`jwt`. Role: 1 Super Admin, 2 Pegawai PNS, 3 Admin Satker, 4 Admin View Eselon I, 5 Menteri, 6 PTT, 7 PPPK, 8 Pimpinan. Role di luar kolom "Route" → **403**.

Urutan pemeriksaan (semua endpoint ber-JWT): 401 → **403** role route → **403** role aksi (mis. bukan akun khusus) → **404** baris tidak ada / tidak terlihat → **403** target di luar cakupan role 3 (Slip Gaji: **403, bukan 404**, README `:79`) → **422** validasi. 403 ganti password memakai `code: password_change_required` (MAKE-020).

### 6.1 Slip Gaji — jalur pegawai (D-11)

| Method & path v2 | Legacy | Route | Service | Catatan |
|---|---|---|---|---|
| `GET slip-gaji/saya` | `hr/sl_gaji/list` (`Sl_gaji.php:18-71`) | 2,6,7 | NIP dari token (parameter NIP diabaikan) | Daftar periode tersedia `GerbangPeriode::daftarTersedia($sekarang)` (Januari 2024 → terbaru, G-3/G-4): `items[] {periode 'YYYY-MM', label, ada_data, dibuka, viewed_at}` + `periode_terbaru`. **Tanpa nominal** |
| `GET slip-gaji/saya/{periode}` | `list?month=` | 2,6,7 | gerbang (G-6) | `{periode, label, ada_data, dibuka, viewed_at, viewed_at_wib}` — **metadata saja, tanpa nominal** (nominal hanya di respons buka dan di PDF; layar "Sudah Dibuka" legacy tidak menampilkan nominal, `list.php:199-214` @`771f8b4`). Periode belum rilis → 422 `errors.periode` `periode_belum_rilis`; tidak ada baris → 404 `slip_tidak_ditemukan` |
| `POST slip-gaji/saya/{periode}/buka` | `mark_viewed` (`Sl_gaji.php:73-159`) | 2,6,7 | gerbang; atomik | §7.3.3. Respons 200 memuat rincian 28 kolom + ringkasan + uang makan. Sudah dibuka → 422 `slip_sudah_dibuka` |
| `GET slip-gaji/saya/{periode}/pdf` | `cetak` (`Sl_gaji.php:161-208`) | 2,6,7 | gerbang; hanya bila dibuka (`StatusSlip::bolehCetakPegawai`) | Belum dibuka → 422 `slip_belum_dibuka`. PDF §7.3.6 |

### 6.2 Slip Gaji — jalur admin (D-10, D-11; SG-2, F5-8)

| Method & path v2 | Legacy | Route | Service | Catatan |
|---|---|---|---|---|
| `GET slip-gaji?periode=&unit_satker=&status_view=&search=&page=&per_page=` | `admin` (`:214-256`), `admin_data` (`:258-334`) | 1,3 | role 1 semua; role 3 **dipaksa** satker akun (`unit_satker` diabaikan untuk role 3); `periode` **wajib** (tanpa periode bawaan, P5-33) | `items[] {id, nip, nama (bergelar), unit_satker, periode, dibuka, viewed_at, viewed_ip, aksi {lihat, cetak, koreksi, buka_ulang}}` + `ringkasan {jumlah, dibuka, belum_dibuka}` (statistik legacy `admin`) + `periode_tersedia[]`. `status_view` ∈ `semua|dibuka|belum` [K all/viewed/unviewed]. Tanpa gerbang (G-6) |
| `GET slip-gaji/{id}` | `get_detail/{id}` (`:336-353`) | 1,3 | cakupan `AksesSlip::dalamCakupan` | Rincian 28 kolom + ringkasan + uang makan + status + `aksi {}`. Tidak mengubah status |
| `PUT slip-gaji/{id}` | `edit_save` (`:355-371`) | 1,3 | cakupan | Koreksi 28 kolom (§7.3.4). Status & token tetap (S-6). `uang_makan*` **tidak** bisa dikoreksi [K `Lsl_gaji.php:810`] |
| `POST slip-gaji/{id}/buka-ulang` | `reset_access` (`:373-388`) | 1,3 | cakupan; hanya bila dibuka | §7.3.5. Belum dibuka → 422 `slip_belum_dibuka` |
| `GET slip-gaji/{id}/pdf` | `cetak?nip=` (admin) | 1,3 | cakupan; kapan saja, tanpa gerbang, tanpa ubah status | Tanpa QR bila tidak ada token aktif (U-3) |
| `POST slip-gaji/impor/pratinjau` (multipart `berkas`, `filter_bulan?`, `filter_tahun?`) | `import_preview` (`:426-468`) | 1,3 | role 3: cakupan satker (`nip_di_luar_cakupan`) | §7.2. Respons: `token_pratinjau`, `kedaluwarsa_at`, `timpa_bawaan: true`, `ringkasan {valid, duplikat, galat}`, `baris[]` |
| `POST slip-gaji/impor/pratinjau/{token}/commit` (body `{timpa: bool}`) | `import_commit` (`:470-489`) | 1,3 | pemilik token pratinjau = pemanggil; validasi ulang penuh | §7.2.4. Respons `{disisip, diperbarui, dilewati, gagal, baris[]}` |

Role 2/4/5/6/7/8 di endpoint admin → 403. Role 1/3 di endpoint `slip-gaji/saya*` → 403 (admin melihat slip sendiri lewat jalur admin, tanpa mengubah status, README "Hak akses" [I]).

### 6.3 Verifikasi publik (D-14) — ADR-032

| Path | Jenis | Akses | Catatan |
|---|---|---|---|
| `/verify/slip/{token}` | **Rute SPA** frontend, **di luar `api/v1`** (path legacy [K], dipakai QR PDF lama & baru). Nginx merutekan path ini ke SPA sejak switch produksi (catatan deploy, §8.5) | Publik, tanpa login; `meta.requiresAuth = false`, **tanpa** `guestOnly` (pengguna login juga bisa membuka) | Halaman memanggil endpoint di bawah, `<meta name="robots" content="noindex, nofollow">` |
| `GET api/v1/publik/verifikasi-slip/{token}` | Endpoint publik backend (grup tanpa `jwt`) | Publik; filter **`throttle`** 30 permintaan/menit per IP (F5-7) | §7.4. Header `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`. **Satu-satunya** route 5A tanpa `jwt` (diuji `RoutePresensiKontrakTest`) |

### 6.4 Presensi online (D-02) & data presensi (F5-15)

| Method & path v2 | Legacy | Route | Service | Catatan |
|---|---|---|---|---|
| `GET presensi/saya/hari-ini` | dashboard pegawai + `MYC:102-115` | 2,6,7 | NIP dari token | `{tanggal_lokal, jam_lokal, zonasi_menit, jendela {masuk: [mulai, selesai], pulang: [mulai, selesai]}, boleh_masuk, boleh_pulang, presensi {jam_masuk, jam_pulang} \| null, wajib_geofence, lokasi[] {id_lokasi_presensi, nama_lokasi, latitude, longitude, radius}}`; `boleh_*` dihitung server saat permintaan (jendela + aturan first-wins masuk) |
| `POST presensi/cek-lokasi` (body `{latitude, longitude, akurasi?}`) | JS `calcDistance` klien (`dashboard_pns.php:664-743`) | 2,6,7 | penilaian server (ADR-025: FE tidak menghitung) | `{wajib_geofence, dalam_radius, lokasi_terdekat {id, nama, radius, jarak_m} \| null}`. Tidak menulis apa pun. Koordinat di **body** (bukan query string) |
| `POST presensi/masuk` (body `{latitude?, longitude?, akurasi?}`) | `hr/services/local/po_in` (`Local.php:1737-1882`) | 2,6,7 | §7.5 | 201 baris baru / 200 baris diperbarui; `{tgl, jam_masuk, jam_pulang, id_lokasi_masuk, jarak_m}` |
| `POST presensi/pulang` (body sama) | `po_out` (`Local.php:1884-2048`) | 2,6,7 | §7.5 (last-wins) | 201/200 |
| `GET presensi/saya?bulan=YYYY-MM&page=&per_page=` | `view_presensi` (`Presensi.php:1214-1238`), `list_ptt` (`:1188-1212`), `preview_po` (`Local.php:2050-2083`) | 2,6,7 | NIP dari token | Riwayat pribadi, urut `tgl` turun; bawaan bulan lokal berjalan. Role 7 ikut [V2] (P5-16) |
| `GET presensi/pegawai?search=&id_unit=&id_satker=&page=&per_page=` | `list_ad` (`Presensi.php:1164-1186`) | 1,3,4,5 | `PegawaiScope::terapkanKeQuery` (role 3 satker sendiri, F5-15) | Daftar pegawai + `can_kelola_manual` (akun khusus) |
| `GET presensi/pegawai/{nip}?tgl_mulai=&tgl_akhir=&page=&per_page=` | `list/{nip}` (`Presensi.php:1240-1282`) | 1,3,4,5 | `PegawaiScope::bolehLihat`; NIP di luar lingkup → 403 | `items[] {nip, tgl, jam_masuk, jam_pulang, sumber, aksi {ubah, hapus}}` + `can_create`; `aksi`/`can_create` = true **hanya** untuk akun khusus. Bawaan 100 baris terbaru [K `L_presensi.php:3281-3290`] |

### 6.5 Presensi manual (D-03) — akun khusus

| Method & path v2 | Legacy | Route | Service | Catatan |
|---|---|---|---|---|
| `POST presensi/manual` (body `{nip, tgl, jam_masuk?, jam_pulang?}`) | `hr/presensi/add/{nip}` (`Presensi.php:1284-1341`) | 1 | `id_pengguna` ∈ akun khusus, kalau tidak 403 `code: bukan_akun_presensi_manual` | 201. §7.6 |
| `PUT presensi/manual/{nip}/{tgl}` (body `{jam_masuk?, jam_pulang?}`) | `edit/{nip}/{tgl}` (`:1343-1413`) | 1 | sama | 200. `nip`/`tgl` dari path, tidak bisa diubah [K `:1376-1378`] |
| `DELETE presensi/manual/{nip}/{tgl}` | `delete/{nip}/{tgl}` via GET (`:1415-1455`) | 1 | sama | 200. Method DELETE [V2] (P5-13/P5-24) |

### 6.6 Endpoint/route yang TIDAK dibuat di 5A
`hr/presensi/list_lt`, `list_lt_il`, `laporan_tukin*`, `rekap*` (5B); `hr/services/local/record_geolocation` (koordinat kini bagian request presensi, `user_geo` tidak diimpor — F5-12); `hr/presensi/holiday*` (sudah Fase 2); `download_template` slip (Q5A-06); pembatalan/monitoring di luar legacy (F5-8); endpoint FCM (Fase 6).

### 6.7 Throttle
- Hanya `api/v1/publik/verifikasi-slip/{token}`: **30 permintaan per 60 detik per IP** (F5-7), `CodeIgniter\Throttle\Throttler` (`service('throttler')`, driver cache bawaan). Kunci bucket = hash IP (mis. `md5`) agar aman sebagai kunci cache; IP **tidak** ditulis ke DB, log aplikasi, maupun audit. Melebihi → **429** `{status: "error", message: "Terlalu banyak permintaan. Coba lagi beberapa saat."}` + header `Retry-After`.
- Endpoint ber-JWT 5A tidak diberi throttle tambahan (penjaga platform yang ada tetap berlaku).

---

## 7. Spesifikasi per task

Format: tujuan · dependency · QASMTASK · file boundary · kontrak · aturan bisnis (rujukan legacy) · state/alur · AC · test wajib · DILARANG. ID AC (`AC-D01.n`, …) dipakai di Lampiran C dan laporan QA.

### 7.1 D-01 — Migration tabel presensi & gaji (DBV-023)

**Tujuan.** Menyiapkan seluruh skema 5A dalam **satu unit review DBV-023**, sebelum kode apa pun yang bergantung skema di-merge.

**Dependency.** Tanpa "Fase 4 DONE" (P-1). Dokumen boleh mulai M1. ⛔ Migration: DBV-022 di `main` + DBV approve DBV-023. **QASMTASK-076.** Actor: DB Validator (review/approve), coder (dokumen + migration).

**File boundary.**
- Dokumen: `backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md`; kueri audit baca-saja `backend/docs/db-review/sql/DBV-023-*.sql`.
- Migration baru: `backend/app/Database/Migrations/<YYYY-MM-DD-HHMMSS>_CreatePresensiGaji.php` (`dh_online`, `gaji_pegawai`, `gaji_pegawai_qr_history`) dan, bila disetujui, `<…>_AlterAuditLogsEventPresensi.php`. Timestamp **setelah** semua migration DBV-022.
- Registry NIP: `TabelAnakNip.php`, `NipCascadeService.php`, `NipReferenceRegistryTest.php`, `NipCascadeTest.php`, `TabelAnakNipTest.php` (§3.4).
- Test skema: `backend/tests/database/Presensi/SkemaPresensiGajiTest.php`, `MigrasiPresensiGajiSiklusTest.php` (`#[Group('db-isolasi-penuh')]`).
- DILARANG menyentuh migration lama di `main` (termasuk `2026-10-01-000001_CreateLokasiPresensi.php`, `2026-09-16-000001_CreateAuditLogs.php`).

#### 7.1.1 Daftar tabel & kolom (ringkas; DDL lengkap disalin ke DBV-023)

**(1) `dh_online`** — sumber D1:391-401 [K] + F5-12 [V2].

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `nip` | VARCHAR(30) NOT NULL | [K] | FK `fk_nip_dh_to_pegawai` → `pegawai(nip)` **RESTRICT/RESTRICT** [V2] (D1 CASCADE) |
| `tgl` | DATE NOT NULL | [K] | Tanggal **lokal satker** (WIB + zonasi), bukan UTC (COMMENT wajib) |
| `jam_masuk`, `jam_pulang` | TIME NULL | [K] | Jam dinding lokal satker (COMMENT wajib) |
| `lat_masuk`, `lng_masuk`, `lat_pulang`, `lng_pulang` | DECIMAL(10,7) NULL | [V2] F5-12 | Koordinat kiriman perangkat saat presensi online; NULL untuk manual/impor/tanpa GPS |
| `akurasi_masuk`, `akurasi_pulang` | DECIMAL(10,2) NULL | [V2] F5-12 | Meter (`coords.accuracy`); dicatat, tidak dipakai menolak (F5-10) |
| `id_lokasi_masuk`, `id_lokasi_pulang` | INT NULL | [V2] F5-12 | Lokasi yang mengesahkan presensi (terdekat dalam radius); NULL bila tanpa aturan/manual. FK → `lokasi_presensi(id_lokasi_presensi)` RESTRICT (usulan, DBV putuskan; lokasi dihapus lunak status 10, tidak fisik) |
| `sumber` | TINYINT NULL | [V2] F5-12 | **1** = online (semua isi dari presensi online), **2** = manual (baris pernah ditambah/diubah lewat D-03 — melekat, tidak kembali ke 1), **NULL** = data impor legacy (sumber tidak diketahui, PM-9). CHECK `sumber IN (1,2)` |
| `created_at` | DATETIME NOT NULL | [K] kolom, [V2] isi UTC | Diisi aplikasi (UTC) |
| `updated_at` | DATETIME NULL | [K] | UTC |
| `updated_by` | INT NULL | [K] | `id_pengguna` |
| `created_by` | INT NULL | [V2] usulan | `id_pengguna` (P5-25); DBV-Q3 |
| PK | `(nip, tgl)` | [K] D1:398 | **Memenuhi DoD "UNIQUE (nip, tgl) eksplisit di DB"** (§5.3). PK surrogate **tidak** dipakai kecuali DBV memutus lain (DBV-Q1) |
| Index | `KEY tgl (tgl)` | [V2] usulan | Daftar admin per tanggal; DBV putuskan |
| CHECK | `jam_masuk IS NOT NULL OR jam_pulang IS NOT NULL` | [V2] usulan | Hanya bila kueri audit legacy = 0 pelanggaran (DBV-Q5) |

Trigger `dh_online_beDel` (arsip ke `d_dh_online`, D1:7709-7714) **tidak dibawa**; penggantinya `audit_logs` event `delete` dengan `before` (F5-14). Tabel `d_dh_online`, `dh_online_coordinate`, `user_geo`, `user_lokasi_presensi`, `absen_real`, `absen_kategori` **tidak dibuat** (F5-12, DBV-020 butir 6).

**(2) `gaji_pegawai`** — sumber D1:1242-1273 (struktur, kunci, status/token) + SQL-L `2026_09_01…:51-79` (28 kolom) + SQL-L `2026_10_08…:27-29` (uang makan) [K `771f8b4`]; kolom generik lama dibuang [V2] (F5-2).

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI PK | [K] | Dipakai `testid` FE dan FK riwayat token |
| `nip` | VARCHAR(30) NOT NULL, **tanpa FK** | [K] | Registry `NonFkSlipGaji` (`TabelAnakNip.php:133-137`), tidak ikut Ubah NIP |
| `bulan` | TINYINT UNSIGNED NOT NULL | [K] | CHECK 1..12 [V2 usulan, DBV-Q10] |
| `tahun` | SMALLINT UNSIGNED NOT NULL | [K] | CHECK 1900..2100 [V2 usulan] |
| 22 kolom GPP: `gjpokok, tjistri, tjanak, tjupns, tjstruk, tjfungs, tjdaerah, tjpencil, tjlain, tjkompen, pembul, tjberas, tjpph, potpfkbul, potpfk2, potpfk10, potpph, potswrum, potkelbtj, potlain, pottabrum, bersih_gpp` | DECIMAL(14,2) NOT NULL DEFAULT 0.00, COMMENT "GPP: …" | [K] SQL-L | Sama persis dengan `KolomGaji` (`KolomGaji.php:25-48`) |
| 6 kolom TK: `kotor, potongan, bersih_tk, pajak, tunj_pajak, bersih_2` | DECIMAL(14,2) NOT NULL DEFAULT 0.00, COMMENT "TK: …" | [K] SQL-L | `KolomGaji.php:53-60` |
| `viewed_at` | DATETIME NULL | [K] kolom, [V2] UTC | NULL = belum dibuka (S-1) |
| `viewed_ip` | VARCHAR(50) NULL | [K] | IP saat pegawai membuka; tidak ditampilkan di verifikasi (AS-06); retensi Q5A-07 |
| `qr_token` | VARCHAR(64) NULL | [K] nama, [V2] isi | **Hash SHA-256 heks huruf kecil dari token aktif** (F5-6); NULL = tidak ada token aktif. `UNIQUE uk_qr_token` [V2 usulan, NULL ganda boleh] menggantikan `KEY idx_qr_token` (DBV-Q9) |
| `uang_makan_hari` | SMALLINT UNSIGNED NULL | [K] SQL-L | Hari berhak uang makan pada snapshot |
| `uang_makan_periode` | VARCHAR(7) NULL | [K] SQL-L | Bulan presensi sumber snapshot, format `m-YYYY` [K]; NULL = belum ada snapshot |
| `uang_makan` | DECIMAL(14,2) NOT NULL DEFAULT 0.00 | [K] D1 | Snapshot nominal uang makan N−1 (F5-1) |
| `created_at` | DATETIME NOT NULL | [K] | UTC |
| `updated_at` | DATETIME NULL | [K] | UTC |
| `created_by`, `updated_by` | INT NULL | [V2] usulan | `id_pengguna` |
| `qr_token_enkripsi` (Q5A-01 = a, 19-10-2026) | TEXT NULL | [V2] diputuskan; tipe final di DBV-023 | Token aktif terenkripsi untuk mencetak ulang QR di PDF |
| Kunci | `UNIQUE uk_nip_bulan_tahun (nip, bulan, tahun)` [K]; `KEY idx_bulan_tahun (bulan, tahun)` [K]; `KEY idx_nip` [K] (DBV boleh membuang karena tertutup prefiks UNIQUE) | | |
| CHECK | nominal ≥ 0 (28 kolom + `uang_makan`) | [V2] usulan | Aplikasi sudah menolak negatif (N-5); DBV-Q10 |

**Kolom D1 yang DIBUANG [V2] (F5-2):** `gaji_pokok, tunj_istri_suami, tunj_anak, tunj_umum, tunj_struktural, tunj_fungsional, tunj_lainlain, pembulatan, tunj_beras, tunj_khusus_pajak, pot_iuran_wajib, pot_bpjs_kesehatan, pot_pph, pot_taperum, tunj_kinerja` (15 kolom). `uang_makan` **dipertahankan** (dipakai `771f8b4` sebagai snapshot). ⛔ Bila hasil kartu DBA (Q5A-02) menunjukkan baris produksi > 0 yang berisi nilai di kolom yang dibuang → **BERHENTI**, DB Validator memutus pemetaan sebelum migration.

**(3) `gaji_pegawai_qr_history`** — sumber SQL-L `2026_09_02…:28-40` [K] (tidak ada di D1).

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI PK | [K] | |
| `id_gaji_pegawai` | BIGINT UNSIGNED NOT NULL | [K] | FK `fk_gpqh_id_gaji_pegawai` → `gaji_pegawai(id)` **RESTRICT** [V2] (SQL-L CASCADE; slip tidak dihapus fisik). **Bukan "FK nip"** (koreksi konteks lama, P5-47) |
| `qr_token` | VARCHAR(64) NOT NULL | [K] nama, [V2] isi hash | `UNIQUE uk_gpqh_qr_token` [V2] (menggantikan `KEY idx_qr_token`) |
| `viewed_at` | DATETIME NOT NULL | [K], [V2] UTC | = waktu terbit token (waktu buka) |
| `viewed_ip` | VARCHAR(50) NULL | [K] | Tidak ditampilkan di verifikasi |
| `snapshot_satker` | VARCHAR(255) NULL | [V2] AS-06 | Nama satker jabatan pegawai **saat token terbit** ("isi dari snapshot saat terbit, bukan data terkini") |
| `snapshot_nama` | VARCHAR(255) NULL | [V2] AS-06 | Nama pegawai **tersamar** saat terbit (§7.4.3); nama utuh tidak disimpan di tabel ini |
| `created_at` | DATETIME NOT NULL | [K] | UTC |
| Index | `KEY idx_id_gaji_pegawai (id_gaji_pegawai)` | [K] | |

Tidak punya kolom NIP → **tidak** masuk registry NIP (dicatat di DBV-023).

**(4) `audit_logs.event` (bila disetujui DBV, F5-4)** — migration **baru** `ALTER … MODIFY event ENUM(...)` menambah nilai usulan **`warning`** (catatan non-mutasi: ketidakcocokan nama–NIP impor) dan **`import`** (ringkasan per commit impor). Nilai yang ada (`create, update, delete, login, logout` + nilai Fase 4 bila ada) dipertahankan; `down()` mengembalikan ENUM semula **hanya** bila tidak ada baris bernilai baru (fail-closed, pola `2026-09-17-000004_AlterAuditLogsEventAddAuth.php`). Bila DBV menolak nilai baru → mismatch dicatat sebagai event yang DBV tetapkan; **DILARANG** fallback ke `log_message` saja (bukan silent-fail).

#### 7.1.2 Isi wajib dokumen `DBV-023-presensi-gaji-fase5a-schema.md`
Mengikuti pola `G-03-lokasi-presensi-schema.md`, `DBV-019`, `DBV-020`, `DBV-022`:
1. **Header**: unit DBV-023, cakupan = 4 butir §7.1.1, status "Menunggu review DB Validator", penulis, tanggal. **DILARANG** menulis "direview/disetujui DB Validator" sebelum ada persetujuan nyata (pra-review internal ditulis "pra-review internal sisi CR").
2. **Konvensi** (K4, ADR-008 RESTRICT, collation, status, UTC vs jam lokal, migration lama tidak diedit) dengan rujukan.
3. **Per tabel**: DDL sumber apa adanya (D1 baris / SQL-L baris), DDL v2 usulan, tabel deviasi `[K]/[V2]/[I]` per kolom/index/FK/CHECK + alasan, perlakuan trigger legacy, risiko impor.
4. **Semantik waktu** (DBV-Q14): `dh_online.tgl/jam_*` = jam dinding lokal satker [K]; `created_at/updated_at/viewed_at` = UTC [V2 AS-12]; konversi impor DATETIME WIB → UTC (DBV-011 R1a), DATE/TIME tidak dikonversi (R2).
5. **Token**: format simpan hash SHA-256 heks 64 [V2 F5-6]; token produksi legacy di-hash saat impor; token/PDF dev tidak diimpor (AS-10); keputusan Q5A-01.
6. **Registry NIP**: `dh_online.nip` = FK v2 (`fk_nip_dh_to_pegawai`), ikut `NipCascadeService` (FASE → 5); `gaji_pegawai.nip` = `NonFkSlipGaji` [K]; `gaji_pegawai_qr_history` tanpa NIP; versi migration untuk `NipReferenceRegistryTest::REGISTRY`.
7. **Bagian impor** (J1.07): urutan `dh_online` (apa adanya, `sumber` NULL, koordinat NULL) → `gaji_pegawai` (semua baris produksi, F5-2) → `gaji_pegawai_qr_history` (backfill dari `gaji_pegawai` yang `viewed_at` & `qr_token` terisi, pola SQL-L `2026_09_02…:45-48`, token di-hash); `d_dh_online`/`dh_online_coordinate`/`user_geo` tidak diimpor; hasil kartu DBA (Q5A-02) dicantumkan; urutan tier ikut Mapping Migrasi (`dh_online` Tier 3, K5-16).
8. **Kueri audit baca-saja** (WAJIB_0 kecuali disebut) di `sql/DBV-023-*.sql`: (1) `dh_online` NIP yatim terhadap `pegawai`; (2) `dh_online` baris dengan kedua jam NULL; (3) `dh_online` `jam_masuk > jam_pulang` (informasi, bukan WAJIB_0); (4) `dh_online` tanggal masa depan relatif tanggal dump; (5) `gaji_pegawai` `COUNT(*)` + distribusi `(tahun, bulan)` + baris berisi nilai di kolom generik yang dibuang; (6) `gaji_pegawai` duplikat `(nip, bulan, tahun)`, `bulan` di luar 1..12, nominal negatif; (7) `gaji_pegawai.qr_token` duplikat non-NULL; (8) collation/panjang `nip` > 30. Digabung ke runbook impor DBV-011.
9. **Pertanyaan untuk DBV** (§7.1.3) beserta usulan coder.
10. **Rencana test skema** (§7.1.5).

#### 7.1.3 Pertanyaan DBV yang WAJIB dijawab di DBV-023

| # | Pertanyaan | Status |
|---|---|---|
| DBV-Q1 | `dh_online` PK `(nip,tgl)` [K] vs surrogate + UNIQUE | **Usulan PRD: PK komposit [K]** (DoD terpenuhi); DBV konfirmasi |
| DBV-Q2 | FK `dh_online.nip` | **Terjawab K4/ADR-008**: RESTRICT + `NipCascadeService` |
| DBV-Q3 | `created_by`, `sumber` di `dh_online` | `sumber` **terjawab F5-12** (domain §7.1.1); `created_by` = DBV |
| DBV-Q4 | Arsip `d_dh_online` + trigger | **Terjawab F5-14**: tidak dibuat, diganti `audit_logs`; impor isi `d_dh_online` → tidak (◻ Q5A-15) |
| DBV-Q5 | CHECK minimal satu jam | DBV (setelah kueri audit 2) |
| DBV-Q6 | Bukti koordinat & nasib `user_geo` | **Terjawab F5-12** (kolom di `dh_online`, `user_geo` tidak diimpor); tipe kolom & FK lokasi = DBV |
| DBV-Q7 | `dh_online_coordinate`, `absen_real`, `absen_kategori` | **Terjawab F5-12**: tidak dibuat/diimpor; DBV konfirmasi ke Mapping |
| DBV-Q8 | Kolom generik lama `gaji_pegawai` | **Terjawab F5-2**: dibuang (kecuali temuan ⛔ Q5A-02) |
| DBV-Q9 | `gaji_pegawai.qr_token` UNIQUE | DBV (usulan UNIQUE) |
| DBV-Q10 | CHECK `bulan`, `tahun`, nominal ≥ 0 | DBV (usulan ya) |
| DBV-Q11 | `qr_history` FK & UNIQUE token | **Usulan PRD**: FK `id_gaji_pegawai` RESTRICT + `UNIQUE (qr_token)`; DBV putuskan aksi FK |
| DBV-Q12 | Cache hasil resolusi aturan lokasi (pengganti `user_lokasi_presensi`) | **◻ default: tidak** (resolusi dinamis per request, Q5A-04) |
| DBV-Q13 | Tabel pratinjau/log impor | **Terjawab F5-3/F5-4**: tanpa tabel; log ke `audit_logs` |
| DBV-Q14 | Semantik waktu | **Terjawab F5-11 + AS-12** (§7.1.2 #4) |
| DBV-Q15 | Collation tabel baru | **Terjawab**: `utf8mb4_unicode_ci` |
| DBV-Q16 | Nilai ENUM `audit_logs.event` baru (`warning`, `import`) | DBV (F5-4) |
| DBV-Q17 | Kolom snapshot verifikasi (`snapshot_satker`, `snapshot_nama`) & kolom token terenkripsi (Q5A-01) | DBV |

#### 7.1.4 ⛔ TITIK TUNGGU D-01
- ⛔ **Sebelum DBV menyetujui DBV-023 DAN DBV-022 ada di `main`: DILARANG** me-merge migration, model, service, controller, atau test yang bergantung tabel 5A. Yang boleh: dokumen DBV-023, kueri audit, draf migration **di branch DBV** untuk dibaca DBV, kode murni (`Libraries/Presensi/Geo`), adapter Tukin bagian non-skema (§7.7 butir 1–4), dan service yang diuji dengan fake **tanpa** di-merge sebelum M2.
- ⛔ Migration **DILARANG dijalankan** di DB dev/produksi; hanya di DB scratch uji (`simpeg_v2_t_dbv023*`, port 3307).
- ⛔ Hasil kartu DBA (Q5A-02) wajib tercantum di DBV-023 sebelum PR M2 di-merge.
- Alur: dokumen + draf migration → PR `[DBV]` → DB Validator review/approve → rebase ke `main` yang memuat DBV-022 → registry NIP (§3.4) → CI hijau → reviewer CR merge. Revisi setelah review = commit baru di PR yang sama.

#### 7.1.5 Acceptance criteria D-01 (QASMTASK-076)
- **AC-D01.1** Migration membuat `dh_online`, `gaji_pegawai`, `gaji_pegawai_qr_history` sesuai DBV-023 yang disetujui; test `information_schema` (kolom, tipe, NULL, default, PK, index, FK + aksi, collation, COMMENT, CHECK) lulus di MySQL 8.0 & MariaDB 10.4.
- **AC-D01.2** `dh_online` punya **PRIMARY KEY `(nip, tgl)`** (atau UNIQUE `(nip, tgl)` bila DBV memutus surrogate); INSERT `(nip, tgl)` ganda → error 1062 (test eksplisit).
- **AC-D01.3** FK: `dh_online.nip` → `pegawai` RESTRICT (1452 untuk NIP yatim, 1451 saat hapus pegawai ber-presensi); `gaji_pegawai_qr_history.id_gaji_pegawai` → `gaji_pegawai` (1452/1451); `gaji_pegawai` tanpa FK `nip`.
- **AC-D01.4** `gaji_pegawai` memuat tepat 28 kolom `KolomGaji` + `uang_makan`, `uang_makan_hari`, `uang_makan_periode` + kolom status/token; **tidak** memuat 15 kolom generik yang dibuang (test menegaskan ketiadaan); `UNIQUE (nip, bulan, tahun)` → 1062.
- **AC-D01.5** Keputusan volume histori `gaji_pegawai` (F5-2) + hasil kartu DBA tercatat di DBV-023 bagian impor (menutup DoD "volume histori").
- **AC-D01.6** Bukti review: PR berisi persetujuan DB Validator atas DBV-023 **sebelum** merge migration; tanggal persetujuan dicatat di `backend/docs/progress/05-Presensi.md`.
- **AC-D01.7** `NipReferenceRegistryTest` & `NipCascadeTest` hijau: `dh_online.nip` terklasifikasi FK v2 dan dikoreksi cascade; `gaji_pegawai.nip` `NonFk`; `NipCascadeTest` memakai `sampaiFase(NipCascadeService::FASE)`; `FASE = 5`.
- **AC-D01.8** Siklus `down()`/`up()` setiap migration 5A lulus (`db-isolasi-penuh`); `git diff origin/main -- backend/app/Database/Migrations/` hanya berisi berkas baru; timestamp migration 5A > semua migration DBV-022.
- **AC-D01.9** (bila ENUM disetujui) nilai `audit_logs.event` baru tersedia; `down()` fail-closed bila ada baris bernilai baru.

**DILARANG (D-01):** mengedit migration lama; membuat tabel di luar §7.1.1 tanpa persetujuan DBV; menyimpan token QR polos di kolom mana pun (kecuali kolom terenkripsi bila Q5A-01 disetujui); menulis "disetujui DB Validator" tanpa persetujuan nyata; menjalankan migration di DB dev; mengubah registry/cascade sebelum DBV-022 di `main`.

### 7.2 D-10 — Slip Gaji: impor Excel (3 langkah)

**Tujuan.** Admin mengunggah berkas Excel slip gaji (sheet `GPP` + `TK`), melihat pratinjau validasi server, lalu meng-commit ke `gaji_pegawai` — port perilaku legacy `Sl_gaji.php:390-489` / `Lsl_gaji.php:1063-1351` dengan perbaikan keamanan [V2] (P5-37..P5-40).

**Dependency.** D-01 (DBV-023 merged). **QASMTASK-085.** Logika murni sudah ada: `PratinjauImpor`, `KolomGaji`, `BarisImpor`, `NilaiSel`, `NominalGaji`, `PencocokanNama`, `AksesSlip` (CR-027/CR-039) — **dipakai, tidak ditulis ulang**.

**File boundary.** `Controllers/Api/Presensi/SlipGajiImportController.php`; `Libraries/Presensi/Slip/{ImporSlipService, PembacaExcelSlip, PenyimpanPratinjau}.php`; `Models/Presensi/GajiPegawaiModel.php`; `Config/Presensi.php`; `Config/RoutesPresensi.php`; berkas bersama aditif: `composer.json/lock` (PhpSpreadsheet), `ValidasiBerkas`/`AturanLampiran` (aturan berkas impor), `docs/adr/ADR-032-*.md` + `docs/adr/README.md`; test `tests/Presensi/Slip/Impor*Test.php`, `tests/unit/Presensi/Slip/PembacaExcelSlipTest.php`.

#### 7.2.1 Konstanta `Config\Presensi` (impor)
`$maksUkuranImporByte = 5 * 1024 * 1024` (◻ Q5A-05), `$maksBarisImporPerSheet = 10000` (◻ Q5A-05), `$ttlPratinjauDetik = 1800` (◻ Q5A-05), `$direktoriPratinjau = WRITEPATH . 'uploads/slip-impor/'`. Override `.env` hanya bila bilangan bulat positif sah (pola `Config\Konket::daftarDariEnv`); nilai tidak sah → bawaan.

#### 7.2.2 Langkah 1 — Unggah (`POST slip-gaji/impor/pratinjau`, multipart)
1. Role 1, atau role 3 dengan `id_satker` akun terisi (role 3 tanpa `id_satker` → **403**, fail-closed SG-2).
2. `berkas` wajib → 422 `errors.berkas` "Berkas wajib diunggah.".
3. Validasi berkas memakai `ValidasiBerkas` (CR-054) lewat **aturan baru aditif** untuk berkas impor non-lampiran (mis. pabrik `AturanLampiran::untukImpor(ekstensi, maksByte)` atau kelas `AturanBerkasImpor`; perilaku lampiran lama tidak berubah): ekstensi `xlsx`/`xls` [K `Sl_gaji.php:436-442`], MIME dari isi, **ekstensi ≙ isi** (OOXML `xl/`, OLE `xls`), tanpa ekstensi ditolak, ukuran dari disk ≤ batas. Pesan: "Format berkas harus .xlsx atau .xls.", "Ukuran berkas maksimal 5 MB.", "Isi berkas tidak sesuai dengan ekstensinya.".
4. `filter_bulan` + `filter_tahun` opsional, **berdua atau tidak sama sekali** (I-10) → `filter_periode_tidak_lengkap`; nilai tidak sah → `periode_tidak_valid`; periode tidak ada di berkas → `periode_tidak_ada_di_berkas`.
5. Simpan berkas ke `$direktoriPratinjau` (di bawah `writable/`, **tidak** dapat diakses publik) dengan nama = `sha256(token_pratinjau)` + ekstensi, **bukan** nama klien. Token pratinjau = 32 byte acak → 64 heks. Simpan metadata sidecar JSON: `id_pengguna`, `role`, `id_satker`, filter, `dibuat_at`/`kedaluwarsa_at` (UTC), nama berkas klien (metadata saja), hash SHA-256 isi berkas.
6. **Satu pratinjau aktif per pengguna** [V2]: unggahan baru menghapus pratinjau lama milik pengguna yang sama. Setiap unggah/commit juga menyapu berkas pratinjau yang kedaluwarsa.

#### 7.2.3 Langkah 2 — Pratinjau (dalam request unggah yang sama)
1. `PembacaExcelSlip` (PhpSpreadsheet, **di luar** folder murni): `IOFactory::createReaderForFile`, `setReadDataOnly(true)`; nilai sel **mentah** (tanpa format tampilan, `formatData = false`), rumus **dihitung** (`getCalculatedValue()`); hasil = baris mentah per sheet untuk `PratinjauImpor::uraiSheet($baris, $sheet)` (README D-10 "Menunggu Fase 5"). Sheet dicari `KolomGaji::cariSheetWajib` (`GPP` **dan** `TK` wajib, I-1). Baris data per sheet > `$maksBarisImporPerSheet` → 422 `errors.berkas` "Jumlah baris melebihi batas 10.000 per sheet.". Berkas rusak/tidak terbaca → 422 `errors.berkas` "Berkas tidak dapat dibaca. Pastikan berkas Excel (.xlsx/.xls) yang valid." — **tanpa pesan exception mentah** (P5-39); detail ke `log_message('error')` tanpa isi sel.
2. Data DB yang dioper ke `PratinjauImpor::susun(...)` (K-CR027-10): peta NIP → {nama, gelar awal/akhir, `id_satker` jabatan **saat ini**} untuk NIP di berkas; himpunan kunci `(nip, bulan, tahun)` yang sudah ada di `gaji_pegawai`; cakupan satker (role 1: tanpa batas; role 3: `id_satker` akun → `nip_di_luar_cakupan`, I-13).
3. Status per kunci: **galat > duplikat (sudah ada di DB) > valid** (I-11). Kode galat = daftar stabil README (`sheet_*`, `kolom_*`, `nip_bukan_teks`, `nip_tidak_valid`, `nip_tidak_terdaftar`, `nip_di_luar_cakupan`, `bulan_tidak_valid`, `tahun_tidak_valid`, `baris_ganda`, `tidak_ada_di_gpp`, `tidak_ada_di_tk`, `nama_kosong`, `nama_tidak_cocok`, `nominal_*`; info `baris_tanpa_nip`, `kolom_diabaikan`).
4. **Kunci ganda dalam berkas** (NIP + periode sama di satu sheet) → galat `baris_ganda` yang menyebut kedua nomor baris (I-8) — **ditolak saat pratinjau** (DoD 085-3).
5. **Mismatch nama–NIP** (M-1..M-6, F5-4): baris berstatus galat `nama_tidak_cocok` **dan** satu baris `audit_logs` per kunci per pratinjau: `entity = 'gaji_pegawai_impor'`, `entity_id = '{nip}:{YYYY-MM}'`, `event = 'warning'` (nilai DBV-023), `after_json = {nip, periode, sheet, baris, nama_berkas (dari sel), nama_db (bergelar), pratinjau: 8 karakter awal hash token}`, pelaku = pemanggil. Ditulis **sekali** saat pratinjau (bukan diulang saat commit, P5-40). Gagal menulis audit → pratinjau gagal 500 generik (bukan silent-fail).
6. Respons 200: `{token_pratinjau, kedaluwarsa_at, timpa_bawaan: true, filter, ringkasan {valid, duplikat, galat}, baris[] {nomor, nip, nama, periode, status: valid|duplikat|galat, rencana_bila_timpa, rencana_bila_tidak_timpa, galat[] {kode, pesan, sheet, baris}, info[], nilai {28 field: string desimal kanonik} }}`. Nilai nominal boleh ada di pratinjau (pemanggil admin berhak).

#### 7.2.4 Langkah 3 — Commit (`POST slip-gaji/impor/pratinjau/{token}/commit`)
1. Body `{timpa: boolean}`; tidak dikirim → `PratinjauImpor::TIMPA_BAWAAN` (`true`, F5-5). Nilai bukan boolean → 422 `errors.timpa`.
2. Token tidak ada / kedaluwarsa / bukan milik pemanggil (`id_pengguna` berbeda) → **404** `pratinjau_tidak_ditemukan` "Pratinjau tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkas." (pesan sama untuk ketiga alasan).
3. **Validasi ulang penuh di server** (F5-3): baca ulang berkas dari `writable/`, cek hash isi = metadata, jalankan ulang langkah 2 butir 1–4 dengan data DB **terkini** dan cakupan role pemanggil **terkini**. Baris dari klien **tidak diterima** dalam bentuk apa pun (P5-38). Mismatch nama **tidak** di-audit ulang.
4. **Satu transaksi** (`transBegin` → … → `transStatus` → `transCommit`/`transRollback` + `resetTransStatus`; **DILARANG `transException(true)`**):
   - kunci baris `gaji_pegawai` yang sudah ada untuk kunci di berkas `FOR UPDATE` (pola `getCompiledSelect() . ' FOR UPDATE'`), lalu klasifikasi ulang;
   - per kunci `PratinjauImpor::rencanaCommit($status, $timpa)`: **valid → sisip** (`nip`, `bulan`, `tahun`, 28 kolom, `created_at` UTC, `created_by`); **duplikat + timpa → perbarui** 28 kolom + `updated_at`/`updated_by` — **tidak menyentuh** `viewed_at`, `viewed_ip`, `qr_token`, `uang_makan*` (S-6 [K]); **duplikat tanpa timpa → lewati**; **galat → gagal** (tidak ditulis);
   - tulis lewat Query Builder dengan binding (impor massal; audit = ringkasan, §8.1).
5. **UNIQUE violation saat commit** (1062, mis. proses lain menyisipkan kunci yang sama setelah validasi ulang) → tangkap, **rollback seluruh transaksi**, respons **409** `{status: "error", code: "konflik_data", message: "Data slip NIP {nip} periode {label} sudah ditambahkan proses lain. Tidak ada data yang disimpan; ulangi unggah dan pratinjau.", errors: {nip_periode: ["{nip} {YYYY-MM}"]}}`. **Tidak pernah 500** untuk 1062. Galat DB lain → rollback, 500 dengan pesan generik "Impor gagal diproses. Tidak ada data yang disimpan." + `log_message` (tanpa nominal).
6. Sukses → satu baris `audit_logs` `event = 'import'` (nilai DBV-023), `entity = 'gaji_pegawai_impor'`, `entity_id` = 8 karakter awal hash token, `after_json = {nama_berkas, filter, timpa, disisip, diperbarui, dilewati, gagal, periode[]}` (tanpa nominal), dalam transaksi yang sama.
7. **Berkas pratinjau + sidecar dihapus** setelah commit, **termasuk bila gagal** (`finally`, README aturan 7).
8. Respons 200: `{disisip, diperbarui, dilewati, gagal, baris[] {nip, periode, hasil: sisip|perbarui|lewati|gagal, galat[]}}`.

#### 7.2.5 Alur & status pratinjau
`(tidak ada) --unggah sah--> AKTIF --commit (sukses/409/500)--> DIHAPUS`; `AKTIF --TTL lewat / unggahan baru pengguna sama--> DIHAPUS`. Tidak ada status lain; tidak ada tabel.

#### 7.2.6 Acceptance criteria D-10 (QASMTASK-085)
- **AC-D10.1** Tiga langkah terpisah: unggah+pratinjau (`POST …/pratinjau`) menghasilkan token; commit hanya lewat `POST …/pratinjau/{token}/commit`; commit tanpa token sah → 404; tidak ada endpoint yang menerima baris dari klien.
- **AC-D10.2** Mismatch nama–NIP: baris berstatus galat `nama_tidak_cocok` **dan** tercatat tepat satu baris `audit_logs` (`event` sesuai DBV-023) per kunci per pratinjau; commit tidak menambah baris audit mismatch.
- **AC-D10.3** NIP + periode ganda dalam satu berkas → galat `baris_ganda` saat pratinjau (menyebut kedua nomor baris); kunci yang sudah ada di DB → status `duplikat`.
- **AC-D10.4** 1062 saat commit tertangkap → 409 `konflik_data` dengan NIP + periode, seluruh transaksi di-rollback (jumlah baris `gaji_pegawai` tidak berubah), bukan uncaught exception/500.
- **AC-D10.5** Baris valid ter-commit; duplikat diperbarui bila `timpa = true` (bawaan) tanpa mengubah `viewed_at`/`viewed_ip`/`qr_token`/`uang_makan*`; dilewati bila `timpa = false`; galat tidak ditulis.
- **AC-D10.6** Akses: role 1 semua NIP; role 3 hanya NIP satkernya (lainnya galat `nip_di_luar_cakupan`); role 3 tanpa `id_satker` → 403; role 2/4/5/6/7/8 → 403 (SG-2).
- **AC-D10.7** Keamanan unggah: ekstensi/MIME/ukuran/ekstensi ≙ isi ditegakkan; berkas di `writable/` dengan nama acak; dihapus setelah commit sukses, 409, 500, kedaluwarsa, dan unggahan baru; pesan galat tanpa exception mentah.
- **AC-D10.8** Validasi ulang: perubahan data DB antara pratinjau dan commit (mis. pegawai dipindah satker, kunci baru masuk) tercermin di hasil commit; mengubah metadata/berkas di disk (hash berbeda) → 404 `pratinjau_tidak_ditemukan`.
- **AC-D10.9** Nilai mentah sel: float `4500000.5` → 450000050 sen; teks `1.234.567` → 123456700 sen; sel rumus → hasil hitung; NIP sel numerik → `nip_bukan_teks`.
- **AC-D10.10** TTL: pratinjau lewat `$ttlPratinjauDetik` → commit 404 dan berkas terhapus.

**Test wajib.** `tests/Presensi/Slip/ImporSlipPratinjauTest.php`, `ImporSlipCommitTest.php`, `ImporSlipMismatchNamaTest.php`, `ImporSlipDuplikatTest.php`, `ImporSlipKonflikUniqueTest.php` (1062 → 409, rollback; boleh memakai seam/double yang melempar 1062 atau koneksi kedua `db-isolasi-penuh`), `ImporSlipAksesTest.php` (role × cakupan), `ImporSlipBerkasTest.php` (ekstensi/MIME/ukuran/hapus/TTL), `tests/unit/Presensi/Slip/PembacaExcelSlipTest.php` (berkas `.xlsx` dan `.xls` sintetis dibuat test). Berkas uji **dibangkitkan** di test (PhpSpreadsheet writer), data sintetis.

**DILARANG (D-10):** menerima baris/status/flag duplikat dari klien; menyimpan berkas di `public/`; nama simpan dari nama klien; `transException(true)`; menelan 1062 jadi 500; pesan exception mentah; menulis ulang aturan `PratinjauImpor`/`KolomGaji` di service; membuat tabel pratinjau/log impor; kode PhpSpreadsheet di `Libraries/Presensi/SlipGaji`.

---

### 7.3 D-11 — Slip Gaji: akses pegawai & kelola admin

**Tujuan.** Pegawai melihat, membuka (sekali), dan mencetak slip miliknya setelah gerbang rilis; admin (1, 3 dalam satker) memantau, melihat detail, mengoreksi, membuka ulang, dan mencetak — port perilaku `771f8b4` (F5-1) dengan aturan v2 README.

**Dependency.** D-10 (data), D-01. **QASMTASK-086.** Library murni: `GerbangPeriode`, `PeriodeSlip`, `StatusSlip`, `TokenQr`, `RingkasanSlip`, `FormatSlip`, `AksesSlip`, `NominalGaji`.

**File boundary.** `Controllers/Api/Presensi/{SlipGajiController, SlipGajiAdminController}.php`; `Libraries/Presensi/Slip/{SlipGajiService, SlipGajiAdminService, TokenSlipService, PdfSlipGaji, UangMakanSlipInterface, UangMakanBelumTersedia, UangMakanSlip}.php`; `Models/Presensi/{GajiPegawaiModel, GajiPegawaiQrHistoryModel}.php`; `Views/pdf/slip_gaji.php`; `Libraries/Presensi/SlipGaji/RingkasanSlip.php` (perubahan §7.3.8 saja) + test-nya; `Libraries/Presensi/README.md`; test `tests/Presensi/Slip/*`.

#### 7.3.1 Gerbang & identitas (jalur pegawai)
- `$sekarang = Time::now()` dioper ke `GerbangPeriode` (G-5: WIB tetap untuk semua satker, **tanpa** zonasi). Gerbang ditegakkan di **daftar, status, buka, cetak** (G-6). Kode 422 `errors.periode`: `periode_wajib`, `periode_tidak_valid`, `periode_sebelum_awal`, `periode_belum_rilis`.
- NIP = NIP akun dari token JWT; parameter NIP apa pun dari klien diabaikan. Akun tanpa NIP → 403.
- Periode tersedia tetapi tidak ada baris → 404 `slip_tidak_ditemukan` "Slip gaji periode {label} belum tersedia.".
- **Nominal hanya dikirim di respons buka** (§7.3.3) dan di PDF (§7.3.6); daftar & status selalu metadata (lebih ketat dari README aturan 4, sesuai layar legacy `list.php` @`771f8b4`: kunjungan berikutnya menampilkan "Slip Gaji Sudah Dibuka" + waktu dibuka tanpa nominal).

#### 7.3.2 Hak akses (SG-2)
`AksesSlip::bolehMelihatMilikSendiri(role)` untuk 2/6/7; `bolehMengelola(role)` untuk 1/3; `dalamCakupan(role, satkerAdmin = AuthContext::idSatker, satkerPegawai = id_satker jabatan **saat ini** pegawai)`; role 3 tanpa `id_satker` → 403 seluruh jalur admin; target di luar cakupan → **403** (bukan 404); 4/5/8 → 403. Slip Gaji **tidak** memakai `PegawaiScope`.

#### 7.3.3 Buka (`POST slip-gaji/saya/{periode}/buka`) — atomik, satu transaksi
1. Gerbang (§7.3.1) → baris `(nip, bulan, tahun)` → 404 bila tidak ada.
2. `StatusSlip::periksaBuka(viewed_at)` → 422 `slip_sudah_dibuka` "Slip gaji ini sudah pernah dibuka dan tidak dapat dibuka kembali. Hubungi admin." (FSG §8).
3. `TokenSlipService`: `random_bytes(32)` → `TokenQr::dariByteAcak` (64 heks) → `hash('sha256', $token)`.
4. Uang makan N−1 (§7.3.7) bila `uang_makan_periode IS NULL`.
5. `UPDATE gaji_pegawai SET viewed_at = <UTC>, viewed_ip = <IP request>, qr_token = <hash> [, qr_token_enkripsi] [, uang_makan, uang_makan_hari, uang_makan_periode] WHERE id = ? AND viewed_at IS NULL`; jumlah baris terpengaruh **harus 1**, kalau tidak → rollback + 422 `slip_sudah_dibuka` (balapan, README aturan 3).
6. `INSERT gaji_pegawai_qr_history (id_gaji_pegawai, qr_token = <hash>, viewed_at, viewed_ip, snapshot_satker, snapshot_nama)` (§7.4.3).
7. Audit `update` entity `gaji_pegawai` (before `{viewed_at: null}`, after `{viewed_at, uang_makan_periode}`) **tanpa** IP, token, dan nominal.
8. Commit → 200: `{periode, label, viewed_at (UTC), viewed_at_wib (FormatSlip::waktuWib), rincian {28 field string desimal}, ringkasan {jumlah_penghasilan_gpp, jumlah_potongan_gpp, bersih_gpp, bersih_tk, bersih_2, uang_makan, total_penghasilan}, uang_makan {nominal, hari, periode_kehadiran, tersedia}}`. Token mentah **tidak** dikirim ke klien.
- Admin **tidak** bisa membuka atas nama pegawai (P5-32 [V2]); melihat/mencetak/mengoreksi/mengimpor oleh admin tidak mengubah status.

#### 7.3.4 Kelola admin (F5-8: fitur legacy saja)
- **Monitoring** `GET slip-gaji`: §6.2; `viewed_at` tampil WIB (P-6); `viewed_ip` tampil [K] (◻ Q5A-07); urut nama/NIP; paginasi pola `ListQuery` (`per_page` 1..100 bawaan 20).
- **Detail** `GET slip-gaji/{id}`: rincian + ringkasan + `aksi {lihat: true, cetak: true, koreksi: true, buka_ulang: dibuka}`.
- **Koreksi** `PUT slip-gaji/{id}`: body **28 field wajib** (nilai kanonik titik-desimal `NominalGaji::dariInputApi`, mis. `"4500000.00"`); negatif → 422 `nominal_negatif` "Nilai harus berupa angka non-negatif."; format salah → `nominal_tidak_valid`; nilai yang tidak berubah tetap sama setelah simpan (README kebenaran data #1). Lewat `GajiPegawaiModel` (`BaseAuditableModel`) → audit `update` before/after field yang berubah. `viewed_at`, `qr_token`, `uang_makan*` **tidak** berubah (S-6, P5-45).
- **Buka ulang** §7.3.5. **Cetak** §7.3.6.

#### 7.3.5 Buka ulang (`POST slip-gaji/{id}/buka-ulang`)
`StatusSlip::periksaReset(viewed_at)` (belum dibuka → 422 `slip_belum_dibuka`); `UPDATE … SET viewed_at = NULL, viewed_ip = NULL, qr_token = NULL [, qr_token_enkripsi = NULL] WHERE id = ? AND viewed_at IS NOT NULL` (cek 1 baris); **riwayat token dipertahankan** (PDF lama tetap terverifikasi); **snapshot uang makan dipertahankan** [K `Lsl_gaji.php:852-881`]; audit `update`. FE wajib `ConfirmDialog`.

#### 7.3.6 PDF slip (`…/pdf`)
- `App\Libraries\Pdf\Pdf::renderer()` + view `backend/app/Views/pdf/slip_gaji.php`; A4 portrait, margin 12 mm, helvetica 8,5 [K `Lsl_gaji.php:487-599`, view `hr/employee/sl_gaji/print/cetak.php` @`771f8b4`]; tata letak acuan PNG `UI Preview Slip Gaji (Export PDF).png` (F5-21).
- Kop dari `web_config` `pdf_header_nama_kementerian`, `pdf_header_nama_unit`, `pdf_header_alamat_kementerian` (HTML tersanitasi G-09); logo `logo_kementerian_pdf`, `logo_wonderful_pdf` (aset tidak ada → tanpa logo, tidak gagal).
- Isi: identitas (nama bergelar P-9, NIP, pangkat/golongan, jabatan, unit/satker — jabatan **saat ini** [K]), rincian GPP (penghasilan, potongan, bersih), TK (6 kolom), uang makan N−1 (label & susunan **disalin dari tampilan `771f8b4`** dengan rujukan baris di README), total penghasilan (`RingkasanSlip`), waktu dibuka "4 Oktober 2026, 10:00 WIB" (P-6), tanggal cetak tanpa jam (P-5). **Baris tukin kelas jabatan TIDAK ada** (F5-1).
- QR: berisi URL `{urlVerifikasiSlip}{token}`; `Config\Presensi::$urlVerifikasiSlip` bawaan = `config('App')->baseURL` + `verify/slip/` (setara `site_url` legacy), override `.env` `presensi.urlVerifikasiSlip` (URL absolut http/https, tidak sah → bawaan) (◻ Q5A-21). Gambar QR lewat `Pdf::qrImg` (wajib gambar; ekstensi `gd` wajib di CI & produksi — bila tidak tersedia, cetak **gagal** 500 generik, DILARANG mencetak teks token). **Tanpa QR** bila tidak ada token aktif (U-3, P5-36). Sumber token mentah untuk QR = keputusan ⛔ Q5A-01.
- Nama berkas `FormatSlip::namaBerkasPdf` (P-7); header `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store` (`Pdf::respon`).
- Pegawai: hanya bila dibuka (selama `viewed_at` terisi, kapan saja [K U-3]) + gerbang. Admin: kapan saja, tanpa gerbang, tanpa mengubah status.

#### 7.3.7 Uang makan N−1 — kontrak placeholder (nilai = 5B, F5-1)
```php
namespace App\Libraries\Presensi\Slip;

interface UangMakanSlipInterface
{
    /** Uang makan presensi bulan $periodeKehadiran (= periode slip ->sebelumnya()); null = sumber belum tersedia. */
    public function hitung(string $nip, \App\Libraries\Presensi\SlipGaji\PeriodeSlip $periodeKehadiran): ?UangMakanSlip;
}

final readonly class UangMakanSlip
{
    public function __construct(public int $totalSen, public int $hari, public int $tarifSen) {}
}
```
- Implementasi 5A: `UangMakanBelumTersedia::hitung()` **selalu `null`**. Pemilihan implementasi lewat `Config\Presensi::$sumberUangMakan` (nama kelas, bawaan `UangMakanBelumTersedia::class`); 5B mengganti nilai ini dengan implementasi D-09. DILARANG menghitung uang makan di 5A.
- Aturan snapshot [K `Lsl_gaji.php:157-205`] diimplementasikan **di 5A** (`SlipGajiService`) dan diuji dengan fake: hanya bila `uang_makan_periode IS NULL`; hasil `null` → kolom uang makan tidak disentuh, respons `uang_makan.tersedia = false`; hasil `totalSen > 0` **atau** `uang_makan` lama ≤ 0 → tulis `uang_makan`, `uang_makan_hari`, `uang_makan_periode = 'm-YYYY'` (bulan kehadiran); hasil 0 padahal `uang_makan` lama > 0 → nilai lama dipertahankan + `log_message('warning')` (tanpa nominal). Snapshot yang sudah ada **tidak** dihitung ulang (termasuk setelah buka ulang).
- **Go-live DILARANG** sebelum 5B mengganti `$sumberUangMakan` (F5-1); dicatat di progres sebagai blocker go-live (bukan blocker 5A DONE). Slip yang dibuka di lingkungan uji sebelum 5B tidak punya snapshot (◻ Q5A-23).

#### 7.3.8 Perubahan library & README Slip Gaji (F5-1)
- `RingkasanSlip::hitung(array $sen)`: total = `bersih_gpp + bersih_tk + uang_makan` [K `Lsl_gaji.php:416-446` @`771f8b4`]; kunci `uang_makan` opsional (tidak ada = 0) sehingga pemanggil & test lama tetap valid; tambah test kasus dengan uang makan. Kelas tetap murni.
- `Libraries/Presensi/README.md`: acuan legacy diubah `39b6b15` → **`771f8b4`**; revisi **R-1..R-4** (total termasuk uang makan N−1; tukin kelas jabatan dihapus), **P-8** (uang makan bulan−1 ditampilkan, label sesuai `771f8b4`), **BK-1** (rumus total baru); bagian "Menunggu Fase 5" diganti rujukan ke PRD ini; rujukan baris legacy diperbarui ke `771f8b4`.

#### 7.3.9 Acceptance criteria D-11 (QASMTASK-086)
- **AC-D11.1** Gerbang: dengan `$sekarang` = tanggal 4 pukul **09:59:59 WIB** periode berjalan, periode itu tidak ada di daftar dan status/buka/pdf → 422 `periode_belum_rilis`; pukul **10:00:00 WIB** → tersedia (test tepat sebelum & sesudah ambang, lintas bulan & tahun, awal Januari 2024, `$sekarang` ber-zona selain WIB tetap dinilai WIB).
- **AC-D11.2** Pegawai hanya slip sendiri: NIP dari token; parameter NIP diabaikan; tidak ada jalur pegawai yang mengembalikan slip NIP lain; role 4/5/8 → 403; role 3 di luar satker → 403; role 1 semua.
- **AC-D11.3** Cetak/unduh PDF pegawai hanya setelah gerbang **dan** setelah dibuka; admin mencetak kapan saja tanpa mengubah `viewed_at`.
- **AC-D11.4** Buka sekali & atomik: buka kedua → 422 `slip_sudah_dibuka`; dua permintaan bersamaan → tepat satu sukses (test konkurensi `db-isolasi-penuh`).
- **AC-D11.5** Nominal tidak muncul di respons daftar/status (sebelum maupun sesudah dibuka); hanya di respons buka dan PDF.
- **AC-D11.6** Token: baru setiap buka (termasuk setelah buka ulang); DB hanya menyimpan hash SHA-256 (tidak ada nilai kolom yang sama dengan token mentah); satu baris riwayat per buka; token lama tetap terverifikasi (D-14).
- **AC-D11.7** Koreksi admin: 28 field, negatif ditolak, audit before/after, status & token & uang makan tidak berubah.
- **AC-D11.8** Buka ulang hanya bila dibuka; riwayat token & snapshot uang makan dipertahankan; audit; FE `ConfirmDialog`.
- **AC-D11.9** Total = `bersih_gpp + bersih_tk + uang_makan`; tidak ada baris tukin kelas jabatan di layar/PDF; README diperbarui ke `771f8b4`.
- **AC-D11.10** Kontrak uang makan: dengan fake bernilai → snapshot ditulis sesuai aturan §7.3.7 (tiga cabang); dengan `UangMakanBelumTersedia` → kolom tidak berubah, `uang_makan.tersedia = false`.
- **AC-D11.11** PDF: tanpa QR bila tidak ada token aktif; nama berkas tersanitasi; tanggal cetak tanpa jam; header unduhan aman.

**Test wajib.** `tests/Presensi/Slip/GerbangSlipTest.php` (dataProvider ambang), `SlipPegawaiAksesTest.php`, `BukaSlipAtomikTest.php`, `BukaSlipKonkurensiTest.php` (`db-isolasi-penuh`), `SlipAdminTest.php` (monitoring/detail/koreksi/buka ulang/cakupan role 3), `PdfSlipGajiTest.php`, `UangMakanSnapshotTest.php`, `RoleMatriksSlipTest.php` (route × role 1–8); `tests/unit/Presensi/SlipGaji/RingkasanSlipTest.php` (diperbarui).

**DILARANG (D-11):** memakai jam klien atau zonasi untuk gerbang; mengirim nominal sebelum dibuka; admin membuka atas nama pegawai; menyimpan token mentah (selain ketentuan Q5A-01); menghitung uang makan di 5A; menampilkan tukin kelas jabatan; memakai `PegawaiScope` untuk slip.

---

### 7.4 D-14 — Verifikasi QR publik `/verify/slip/{token}` (task baru, F5-20)

**Tujuan.** Siapa pun yang memindai QR pada PDF slip dapat memastikan keaslian dokumen tanpa login, dengan data minimum (AS-06) dan perlindungan enumerasi.

**Dependency.** D-01, D-11 (riwayat token). Kartu QASMTASK D-14 dibuat reviewer (F5-20); AC di bawah = DoD-nya.

**File boundary.** `Controllers/Api/Presensi/VerifikasiSlipController.php`; `Libraries/Presensi/Slip/VerifikasiSlipService.php`; `Filters/ThrottlePublikFilter.php`; `Config/Filters.php` (alias, aditif); `Config/RoutesPresensi.php` (grup publik); `frontend/src/features/presensi/views/VerifikasiSlipPage.vue`; `frontend/src/router/index.ts` (rute publik, aditif); test `tests/Presensi/Slip/VerifikasiSlipTest.php`, `tests/Presensi/RoutePresensiKontrakTest.php`.

#### 7.4.1 Alur `GET api/v1/publik/verifikasi-slip/{token}`
1. Filter `throttle` (§6.7) → 429 bila melebihi.
2. `TokenQr::valid($token)` (64 heks huruf kecil, tanpa trim) — tidak valid → **404** seragam.
3. `hash('sha256', $token)` → cari `gaji_pegawai_qr_history.qr_token` → tidak ada → **404** seragam. Token dev/legacy tidak diimpor (AS-10) → otomatis 404.
4. Ada → 200 data §7.4.2.
- Pesan 404 seragam untuk semua alasan: `{status: "error", message: "Dokumen tidak ditemukan atau tidak valid."}` — DILARANG membedakan format salah / tidak ada / dev.
- Header semua respons: `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`.
- Log aplikasi `info` per permintaan (hasil: ditemukan / tidak / 429) **tanpa** IP dan **tanpa** token, untuk monitoring jumlah verifikasi (J7.07).

#### 7.4.2 Data yang ditampilkan (AS-06, F5-7)
`{status_keaslian: "asli", periode: "YYYY-MM", label_periode: "Oktober 2026", satker_penerbit: snapshot_satker, waktu_terbit: FormatSlip::waktuWib(viewed_at), nama: snapshot_nama (tersamar)}`. **DILARANG** menampilkan nominal apa pun, NIP (utuh atau sebagian), IP, token, id baris, status token aktif. Data dari **snapshot riwayat saat terbit**, bukan data terkini. Konfirmasi Biro Keuangan atas daftar ini **⛔ sebelum go-live** (Q5A-08), bukan sebelum coding.

#### 7.4.3 Snapshot saat terbit (ditulis di §7.3.3 langkah 6)
- `snapshot_satker` = nama satker jabatan pegawai saat buka (kosong → nama unit; keduanya kosong → NULL).
- `snapshot_nama` = nama tersamar ◻ Q5A-08: gelar dibuang; setiap kata → huruf pertama kapital + `***` (mis. "Budi Santoso" → "B*** S***").
- Baris riwayat hasil impor legacy: snapshot diisi saat impor dari data pegawai saat impor [I] (dicatat di DBV-023 §impor); NULL → field `null`, FE menampilkan "—".

#### 7.4.4 Halaman FE `/verify/slip/:token`
Rute publik (`meta.requiresAuth: false`, tanpa `guestOnly`), tanpa layout aplikasi berlogin; set `<meta name="robots" content="noindex, nofollow">` saat halaman aktif; memanggil endpoint §7.4.1; tampilan kartu "Dokumen Asli" + data §7.4.2 (gaya "verifikasi sertifikat", FSG §8); 404 → kartu "Dokumen tidak ditemukan atau tidak valid"; 429 → "Terlalu banyak permintaan…". Tidak ada tautan ke halaman berlogin selain tombol "Masuk" umum.

#### 7.4.5 Acceptance criteria D-14
- **AC-D14.1** Token sah (termasuk token lama sebelum buka ulang) → 200 dengan tepat field §7.4.2; tidak ada nominal/NIP/IP/token di respons (test menegaskan ketiadaan kunci).
- **AC-D14.2** Format salah, token tidak ada, token huruf besar, token dengan spasi → 404 dengan pesan & bentuk respons identik.
- **AC-D14.3** Lookup memakai hash; DB tidak menyimpan token mentah (kecuali kolom terenkripsi Q5A-01).
- **AC-D14.4** Permintaan ke-31 dalam 60 detik dari IP sama → 429; IP tidak tertulis di DB/log aplikasi (test memeriksa `audit_logs` & tabel 5A).
- **AC-D14.5** Header `X-Robots-Tag: noindex, nofollow` dan `Cache-Control: no-store` ada di 200/404/429; halaman FE memasang meta robots `noindex`.
- **AC-D14.6** Route publik ini **satu-satunya** route 5A tanpa `jwt` (`RoutePresensiKontrakTest`); path SPA `/verify/slip/:token` terdaftar tanpa `requiresAuth`.
- **AC-D14.7** Data dari snapshot: mengubah nama/satker pegawai setelah buka tidak mengubah hasil verifikasi.

**DILARANG (D-14):** login untuk verifikasi; menampilkan nominal/NIP/IP; pesan galat yang membedakan alasan; menyimpan/mencatat IP; mengubah path `/verify/slip/{token}`; menempatkan endpoint data di grup ber-`jwt`.

---

### 7.5 D-02 — Presensi online + geofence

**Tujuan.** Pegawai (2/6/7) merekam jam masuk/pulang dari perangkat; server menentukan tanggal & jam lokal (zonasi satker), menegakkan jendela waktu, dan memvalidasi geofence untuk pegawai yang tercakup aturan lokasi — port `po_in`/`po_out` (`Local.php:1737-2048`) + geofence server [V2] (F5-9).

**Dependency.** D-01 (`dh_online` merged), G-03 (`lokasi_presensi`, `dm_user_lokasi_presensi` di `main`), G-02 (`satker.zonasi`). **QASMTASK-077.** Logika murni `Geo` boleh dikerjakan sebelum D-01.

**File boundary.** `Controllers/Api/Presensi/PresensiController.php`; `Libraries/Presensi/Kehadiran/{PresensiOnlineService, ResolverLokasiPresensi, ZonaSatker}.php`; `Libraries/Presensi/Geo/{Haversine, PenilaianGeofence}.php` (murni); `Models/Presensi/DhOnlineModel.php`; `Config/Presensi.php`; test `tests/Presensi/Kehadiran/*`, `tests/unit/Presensi/Geo/*`. Dibaca saja (DILARANG diubah): `Libraries/MasterData/AturanLokasiPresensiHooks.php` (boleh memanggil `storedIds()` statis), master G-02/G-03.

#### 7.5.1 Pelaku & penolakan
- Role **2/6/7** saja (F5-9); role lain → 403. Akun tanpa NIP → 403.
- **Tidak** menolak pegawai yang sedang cuti/TB/konket atau di hari libur/akhir pekan (F5-13, PO-7 [K]); penilaian dilakukan di laporan Tukin/uang makan (5B).

#### 7.5.2 Waktu lokal (F5-11, PO-1..PO-3 [K])
- `ZonaSatker`: satker = `pegawai_mutasi_jabatan.id_satker` jabatan **saat ini** → `satker.zonasi` dibaca **langsung** (tanpa filter status satker, catatan G-02 `G-02-jabatan-unit-satker-schema.md:236`). Tanpa jabatan/satker → zonasi 0 [K] (◻ Q5A-24). Zonasi diterapkan bila > 0 [K PO-2] (CHECK 0..120 di v2).
- `waktuLokal = Time::now('Asia/Jakarta') + zonasi menit`; `tgl = Y-m-d` lokal, `jam = H:i:s` lokal; disimpan apa adanya (bukan UTC). Waktu dari klien **diabaikan**.

#### 7.5.3 Resolver aturan lokasi (port dinamis `Lm_lokasi.php:540-735` + `MYC:102-115`; pengganti `user_lokasi_presensi`)
Aturan `A` (`dm_user_lokasi_presensi`) **berlaku** untuk pegawai `P` (status pegawai aktif) pada tanggal lokal `D` ⇔ semua benar:
1. `A.status = 1` [V2 G-03];
2. `A.hari_berlaku` NULL/kosong **atau** memuat hari ISO `D` (1 = Senin … 7 = Minggu) [V2 G3-FR-13] (◻ Q5A-04);
3. cakupan target [K]: (a) `A.target_uns` tidak kosong **dan** `P.id_jenis_pegawai ∈ A.target_jp` **dan** (`'0' ∈ target_uns` **atau** `P.id_unit ∈ target_uns` **atau** `'sat_'.P.id_satker ∈ target_uns`) — unit/satker dari `pegawai_mutasi_jabatan` jabatan saat ini [K `Lm_lokasi.php:680-690`]; **atau** (b) `'0' ∉ target_uns` **dan** `P.nip ∈ A.target_peg` [K `:694-703`, target pegawai diabaikan bila seluruh kementerian];
4. `P.nip ∉ A.excl_peg` [K `:720-728`].

Lokasi berlaku = gabungan `A.target_lp` semua aturan berlaku (JSON dibaca `AturanLokasiPresensiHooks::storedIds`), disaring `lokasi_presensi.status = 1` [K `MYC:102-113`]. **Tercakup aturan** ⇔ lokasi berlaku tidak kosong. Lokasi dengan `latitude`/`longitude` bukan angka atau di luar rentang diabaikan + `log_message('warning')`; bila **semua** lokasi berlaku tidak sah → 422 `errors.lokasi` `lokasi_tidak_valid` "Lokasi presensi untuk Anda belum dikonfigurasi dengan benar. Hubungi admin." (fail-closed, ◻ Q5A-11). Radius < 10 m hasil impor dipakai apa adanya (G-03 K-3). Resolusi dihitung per permintaan, tanpa cache/tabel (◻ Q5A-04).

#### 7.5.4 Geofence server (F5-9, F5-10)
- `Haversine::jarakMeter(lat1, lng1, lat2, lng2)` dengan R = **6.371.000 m**; murni, diuji.
- `PenilaianGeofence`: sah ⇔ ada lokasi berlaku dengan `jarak ≤ radius` (**inklusif**) — **salah satu** lokasi cukup (memperbaiki P5-03). `id_lokasi_*` = lokasi sah dengan jarak terkecil (seri → `id_lokasi_presensi` terkecil). Akurasi **tidak** dipakai menolak.
- Tercakup + tidak sah → 422 `errors.lokasi` `di_luar_radius`: "Anda berada di luar radius lokasi presensi. Lokasi terdekat: {nama_lokasi} (jarak ±{jarak} m, radius {radius} m)." (jarak dibulatkan ke meter); tidak ada yang ditulis.
- Koordinat input: `latitude` (−90..90), `longitude` (−180..180) angka (JSON number atau string desimal), `akurasi` opsional angka 0..100000. Tercakup aturan → `latitude`/`longitude` **wajib** (422 `errors.latitude` `koordinat_wajib` "Lokasi perangkat belum terbaca. Aktifkan GPS dan izinkan akses lokasi, lalu coba lagi.", ◻ Q5A-12). Tidak tercakup → opsional; bila dikirim harus sah (422 "Koordinat tidak valid.") dan disimpan. Disimpan dibulatkan 7 desimal.
- `POST presensi/cek-lokasi` memakai `PenilaianGeofence` yang **sama** (hasil cek = hasil rekam pada data yang sama).

#### 7.5.5 Jendela waktu (F5-11 [K PO-4]) — konstanta `Config\Presensi`
`$jamMasukMulai = '04:00:00'`, `$jamMasukSelesai = '12:30:59'`, `$jamPulangMulai = '12:31:00'`, `$jamPulangSelesai = '23:59:59'` (inklusif, jam lokal; **tanpa** override `.env`). Di luar jendela → 422 `errors.jam` `di_luar_jendela`: "Presensi masuk hanya dapat direkam pukul 04:00–12:30 waktu setempat." / "Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.". Urutan cek: role → jendela → (masuk) sudah terekam → koordinat & geofence → simpan.

#### 7.5.6 Penulisan (satu transaksi, kunci baris `(nip, tgl)` `FOR UPDATE`)
- **Masuk** [K PO-5, first-wins]: baris ada & `jam_masuk` terisi → 422 `errors.jam` `sudah_presensi_masuk` "Presensi masuk tanggal {tanggal} sudah terekam pukul {HH:MM}."; baris ada & `jam_masuk` NULL (dibuat pulang) → UPDATE `jam_masuk`, `lat/lng/akurasi/id_lokasi_masuk` → 200; tidak ada → INSERT (`sumber = 1`) → 201. Tabrakan 1062 (dua permintaan bersamaan) → baca ulang dan terapkan aturan yang sama (tidak pernah 500).
- **Pulang** [K PO-6, last-wins]: baris ada → UPDATE `jam_pulang` + `lat/lng/akurasi/id_lokasi_pulang` (**menimpa** nilai lama) → 200; tidak ada → INSERT dengan `jam_masuk` NULL → 201 [K P5-10]. 1062 → ulang sebagai UPDATE sekali.
- `sumber`: tetap 2 bila baris sudah 2 (manual melekat); selain itu 1. `created_by`/`updated_by` = `id_pengguna`; stempel UTC.
- Tanpa cek `jam_masuk ≤ jam_pulang`, libur, cuti, konket, TB [K PO-7]. Tanpa antrean FCM/`andr_topic_queue` (Fase 6) dan tanpa `user_geo`.
- Audit: `create`/`update` `dh_online`, `entity_id = '{nip}:{tgl}'`, before/after jam & koordinat (§8.1).

#### 7.5.7 Acceptance criteria D-02 (QASMTASK-077)
- **AC-D02.1** Pegawai tercakup aturan dengan koordinat **tepat di batas radius** (jarak = radius) dan di dalam radius → presensi tersimpan dengan koordinat, akurasi, `id_lokasi_*`, `sumber = 1`.
- **AC-D02.2** Koordinat di luar radius semua lokasi berlaku → 422 `di_luar_radius` dengan nama lokasi terdekat, jarak, dan radius; tidak ada baris ditulis/diubah.
- **AC-D02.3** Zonasi: satker zonasi 0/60/120 menghasilkan `tgl`/`jam_*` lokal yang benar, termasuk pergantian tanggal (mis. 22:30 WIB + 120 menit = tanggal berikutnya 00:30 → di luar jendela pulang → 422); satker berstatus non-aktif tetap memakai zonasinya.
- **AC-D02.4** Jendela: 03:59:59 ditolak / 04:00:00 diterima (masuk); 12:30:59 diterima / 12:31:00 ditolak (masuk); 12:30:59 ditolak / 12:31:00 diterima (pulang); 23:59:59 diterima (pulang) — waktu disuntik.
- **AC-D02.5** Masuk kedua → 422 `sudah_presensi_masuk`; pulang kedua menimpa jam & koordinat pulang; pulang tanpa masuk membuat baris `jam_masuk` NULL dan masuk sesudahnya (dalam jendela) diterima.
- **AC-D02.6** Pegawai tanpa aturan berlaku → presensi tersimpan tanpa syarat koordinat (F5-10); dengan koordinat sah → koordinat ikut tersimpan.
- **AC-D02.7** Resolver: kasus `target_uns = 0` × `target_jp`; unit; `sat_<id>`; `target_peg` (diabaikan saat `0`); `excl_peg`; `hari_berlaku` cocok/tidak; aturan status 2/10 diabaikan; lokasi status ≠ 1 diabaikan; jenis pegawai di luar `target_jp` tidak tercakup; beberapa lokasi → sah bila salah satu dalam radius.
- **AC-D02.8** Role 1/3/4/5/8 → 403 untuk `masuk`, `pulang`, `cek-lokasi`, `saya*`; pegawai sedang cuti/konket tetap bisa presensi.
- **AC-D02.9** `cek-lokasi` dan `masuk` memberi putusan yang sama untuk input yang sama; `cek-lokasi` tidak menulis apa pun.
- **AC-D02.10** Konkurensi: dua `masuk` bersamaan → tepat satu sukses, satu 422; tidak ada 500.

**Test wajib.** `tests/unit/Presensi/Geo/HaversineTest.php` (titik referensi, batas radius), `PenilaianGeofenceTest.php`; `tests/Presensi/Kehadiran/ResolverLokasiPresensiTest.php`, `PresensiOnlineTest.php`, `PresensiZonasiTest.php`, `PresensiJendelaTest.php` (dataProvider), `PresensiKonkurensiTest.php` (`db-isolasi-penuh`), `RoleMatriksPresensiTest.php`.

**DILARANG (D-02):** memercayai waktu/tanggal dari klien; menolak karena akurasi; menolak pegawai tanpa aturan; memakai `<` untuk radius; menghitung geofence di FE sebagai penentu; membaca koordinat dari query string; menulis `user_geo`; mengantre notifikasi.

---

### 7.6 D-03 — Presensi manual (akun khusus)

**Tujuan.** Akun khusus menambah, mengubah, dan menghapus baris `dh_online` pegawai mana pun — port `Presensi.php:1284-1455` / `L_presensi.php:3304-3478` dengan validasi [V2] (F5-14). Pengecualian akun ini **disengaja** (SRS-NFR-006), berbeda dengan Konket/LKH v2 yang sudah menghapus pengecualian akun pengembang (`KonketService.php:46`, `LkhService.php:43`).

**Dependency.** D-01. **QASMTASK-078.**

**File boundary.** `Controllers/Api/Presensi/PresensiManualController.php`, `PresensiController.php` (daftar admin); `Libraries/Presensi/Kehadiran/PresensiManualService.php`; `Models/Presensi/DhOnlineModel.php`; `Config/Presensi.php`; test `tests/Presensi/Kehadiran/PresensiManual*Test.php`.

#### 7.6.1 Otorisasi
- `Config\Presensi::$akunPresensiManual = [4675, 4676]` (`pengguna.id`; legacy `config/constants.php:324-325` membandingkan `$user['id']`, bukan NIP, K5-11). Override `.env` `presensi.akunPresensiManual = 4675,4676`: daftar bilangan bulat positif dipisah koma; kosong/ada token tidak sah → **seluruhnya diabaikan**, bawaan dipakai (pola `Config\Konket::daftarDariEnv`).
- Route `Role::filter(1)`; service: `AuthContext::idPengguna ∈ $akunPresensiManual`, kalau tidak → **403** `{code: "bukan_akun_presensi_manual", message: "Hanya akun khusus yang dapat mengelola presensi manual."}` — dicek **sebelum** 404/422 (tidak membocorkan keberadaan data). Role 1 lain ditolak (DoD 078-1).

#### 7.6.2 Kontrak & validasi (422 per field, pesan baku)
| Field | Aturan | Pesan |
|---|---|---|
| `nip` (tambah) | wajib; pegawai ada di `pegawai` (status apa pun [K]) | "NIP wajib diisi." / "Pegawai tidak ditemukan." |
| `tgl` (tambah) | wajib; `YYYY-MM-DD` valid; **≤ tanggal hari ini lokal satker pegawai** (§7.5.2) [V2] (◻ Q5A-17) | "Tanggal wajib diisi." / "Format tanggal harus YYYY-MM-DD." / "Tanggal tidak boleh melebihi hari ini." |
| `jam_masuk`, `jam_pulang` | opsional masing-masing; `HH:MM` atau `HH:MM:SS` (00:00..23:59:59) → disimpan `HH:MM:SS`; string kosong/`null` = kosong (NULL) [K `L_presensi.php:3470-3478`]; **minimal satu terisi** [K]; bila keduanya terisi `jam_masuk ≤ jam_pulang` [V2] | `errors.jam_masuk`: "Isi minimal salah satu: jam masuk atau jam pulang." / "Format jam harus HH:MM atau HH:MM:SS."; `errors.jam_pulang`: "Jam pulang tidak boleh lebih awal dari jam masuk." |
| duplikat (tambah) | `(nip, tgl)` sudah ada → ditolak (pra-cek + tangkap 1062 PK) | `errors.tgl`: "Presensi pegawai ini pada tanggal tersebut sudah ada. Gunakan Edit." |
| field lain | diabaikan (whitelist, menutup P5-21) | — |

Jendela waktu dan geofence **tidak** berlaku untuk presensi manual [K].

#### 7.6.3 Aksi
- **Tambah** (`POST presensi/manual`): INSERT `sumber = 2`, koordinat NULL, `created_by`/`updated_by`; audit `create`. 201.
- **Ubah** (`PUT presensi/manual/{nip}/{tgl}`): baris wajib ada → 404 "Data presensi tidak ditemukan."; `nip`/`tgl` dari path, tidak bisa diubah [K]; body `jam_masuk`, `jam_pulang` (keduanya dikirim; kosong = NULL); validasi §7.6.2 atas nilai akhir; jam yang **berubah** mengosongkan koordinat/akurasi/`id_lokasi` jam tersebut (nilai lama tetap di audit) (◻ Q5A-16); `sumber = 2`; audit `update` before/after. 200.
- **Hapus** (`DELETE presensi/manual/{nip}/{tgl}`): baris wajib ada → 404; **hapus fisik** [K]; audit `delete` dengan `before` baris lengkap (pengganti trigger `d_dh_online`). 200. FE `ConfirmDialog`.
- Semua aksi dalam transaksi; audit di transaksi yang sama.

#### 7.6.4 Lihat data presensi (admin, F5-15)
`GET presensi/pegawai`, `GET presensi/pegawai/{nip}` untuk role **1, 3, 4, 5** (legacy `list_ad` 1/4, `list/{nip}` 1/4/5 + role 3 lingkup satker F5-15; ◻ Q5A-09), lingkup `PegawaiScope`. `aksi {ubah, hapus}` dan `can_create` = true **hanya** untuk akun khusus [K `Presensi.php:1274-1276`].

#### 7.6.5 Acceptance criteria D-03 (QASMTASK-078)
- **AC-D03.1** Akun role 1 dengan `pengguna.id` ∉ {4675, 4676} → 403 `bukan_akun_presensi_manual` untuk tambah/ubah/hapus; akun 4675 dan 4676 → berhasil; role 2–8 → 403 route; override `.env` sah/tidak sah diuji.
- **AC-D03.2** `nip` dan `tgl` wajib (422 per field).
- **AC-D03.3** Minimal salah satu `jam_masuk`/`jam_pulang` terisi (422).
- **AC-D03.4** Duplikat `(nip, tgl)` saat tambah → 422 `errors.tgl` (termasuk jalur tangkap 1062).
- **AC-D03.5** Audit WAJIB: setiap tambah/ubah/hapus menulis `audit_logs` (`create`/`update`/`delete`, before/after, pelaku = akun khusus); hapus = baris hilang dari `dh_online`.
- **AC-D03.6** [V2] `jam_masuk > jam_pulang` → 422; `tgl` > hari ini lokal → 422.
- **AC-D03.7** `PUT` tidak bisa mengubah `nip`/`tgl`; field di luar whitelist diabaikan; `sumber = 2` setelah tambah/ubah.
- **AC-D03.8** Daftar admin: `aksi`/`can_create` true hanya untuk akun khusus; role 3 di luar lingkup → 403; role 2/6/7/8 → 403.

**Test wajib.** `tests/Presensi/Kehadiran/PresensiManualAksesTest.php`, `PresensiManualValidasiTest.php`, `PresensiManualAuditTest.php`, `DataPresensiAdminTest.php`.

**DILARANG (D-03):** memakai NIP sebagai identitas akun khusus; meng-hard-code 4675/4676 di luar `Config\Presensi`; menerima `$_POST` utuh; hapus lunak/tabel arsip; aksi tulis lewat GET; menerapkan geofence/jendela pada manual.

---

### 7.7 5A-4 — Adapter data ke `KalkulasiTukin` (sisi integrasi D-04/D-05/D-07)

**Tujuan.** Menyiapkan semua argumen `KalkulasiTukin::hitung(PeriodeTukin, list<string> $hariLibur, array $harian, KonfigurasiTukin, ?string $hariIni, ?DataPegawaiTukin)` dari data v2 yang **tidak** bergantung Fase 4, sehingga 5B tinggal menyambung cuti/TB dan laporan. Library murni CR-037/CR-039 **tidak diubah** (kecuali aditif yang disetujui reviewer).

**Dependency.** Butir 1–4 tanpa skema baru (boleh mulai setelah M0); butir 5 setelah D-01. QASMTASK-079/080/082 bagian integrasi (DoD rumus murni sudah dipenuhi CR-037/039, status IN_PROGRESS di progres). Prasyarat DEV-006 "cross-check rumus resmi / eskalasi Horii sebelum D-04" tidak memblokir adapter 5A karena rumus tidak diubah (⛔ Q5A-13, wajib sebelum 5B).

**File boundary.** `Libraries/Presensi/TukinData/*` (§2.8); berkas bersama aditif `Config/WebConfig.php`, `Libraries/MasterData/WebConfigTypes.php` + test; test `tests/Presensi/TukinData/*`, `tests/unit/Libraries/WebConfigTypesTest.php`. Dibaca saja: `WebConfigService`, `HariLiburService`, `Libraries/Presensi/Tukin/**`.

#### 7.7.1 Kontrak layanan
```php
namespace App\Libraries\Presensi\TukinData;

final class TukinDataService
{
    public function __construct(/* sumber-sumber di bawah, termasuk SumberCutiTbInterface */) {}

    /** Argumen siap pakai untuk KalkulasiTukin::hitung() satu pegawai × satu bulan tukin. */
    public function siapkan(string $nip, int $tahun, int $bulan, ?string $hariIni = null): ArgumenTukin;
}

final readonly class ArgumenTukin
{
    /** @param list<string> $hariLibur  @param array<string, array<string, mixed>> $harian */
    public function __construct(
        public \App\Libraries\Presensi\Tukin\PeriodeTukin $periode,
        public array $hariLibur,
        public array $harian,
        public \App\Libraries\Presensi\Tukin\KonfigurasiTukin $konfigurasi,
        public ?\App\Libraries\Presensi\Tukin\DataPegawaiTukin $pegawai,
    ) {}
}
```
5A **tidak** membuat endpoint tukin (D-08 = 5B). Galat terkendali adapter = exception domain di folder `TukinData` (mis. `KonfigurasiTukinTidakLengkap`, `RentangPuasaGanda`, `DataTukinBelumTersedia`) — bukan `InvalidArgumentException` mentah dari library.

#### 7.7.2 Key `web_config` baru (F5-16) — katalog `Config\WebConfig`, grup baru `jam_kerja` ("Jam Kerja & Puasa"), tanpa migration
| Key | Tipe | Bawaan | Arti |
|---|---|---|---|
| `jam_masuk_normal` | `time` | `07:30:00` | Jam masuk hari kerja normal |
| `jam_pulang_normal` | `time` | `16:00:00` | Jam pulang Senin–Kamis normal |
| `jam_pulang_jumat_normal` | `time` | `16:30:00` | Jam pulang Jumat normal |
| `jam_masuk_puasa` | `time` | `08:00:00` | Jam masuk selama rentang puasa |
| `jam_pulang_puasa` | `time` | `15:00:00` | Jam pulang Senin–Kamis puasa |
| `jam_pulang_jumat_puasa` | `time` | `15:30:00` | Jam pulang Jumat puasa |
| `rentang_puasa` | **`date_range_list`** (tipe baru) | `''` (tidak ada rentang) | Rentang puasa per tahun, satu per baris `YYYY-MM-DD..YYYY-MM-DD` (inklusif) |

Bawaan = nilai `05-Presensi.md` D-05 / `KonfigurasiTukin` (`:66-76`). Tipe baru `date_range_list` di `WebConfigTypes` (aditif): baris dipisah LF (CRLF → LF), setiap baris cocok `^\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2}$`, tanggal valid, mulai ≤ selesai, rentang tidak tumpang tindih, maks 50 baris; disimpan unik & terurut naik; nilai bertipe = `list<array{mulai: string, selesai: string}>`. Pesan galat siap tampil Bahasa Indonesia; test tipe baru di `WebConfigTypesTest`. Tipe lain tidak berubah.

#### 7.7.3 `KonfigurasiTukinDariWebConfig`
- Tarif: `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` lewat `WebConfigService::values()`; satu saja `null` (belum diatur) → `KonfigurasiTukinTidakLengkap` (daftar key) — **DILARANG** memakai 0 atau nilai karangan. Tarif opsional (`CUTI_*`, `SKP_*`) = `KonfigurasiTukin::TARIF_BAWAAN` [K rekap]; `LKH` tidak dipakai.
- Jam: 6 key `time` → argumen konstruktor (`JamKerja::normalisasiJam` menerima `HH:MM:SS`).
- Puasa: rentang dari `rentang_puasa` yang **beririsan** dengan `[awal, akhir]` periode tukin; tidak ada → `null`; lebih dari satu → `RentangPuasaGanda`.
- Cache `WebConfigService` (3600 s, diinvalidasi saat tulis) dipakai apa adanya → perubahan tarif lewat `WebConfigService` langsung memengaruhi kalkulasi berikutnya (DoD regression D-13, QAFUNC-006).

#### 7.7.4 Hari libur & periode
- `PeriodeTukin::dariBulan($tahun, $bulan, $periodeBkn)`; `$periodeBkn` dari baris `bkn_periode_ekinper` (tahun, bulan) dengan pemetaan kolom awal/akhir **sesuai legacy `L_presensi.php:11852-11876`** [K]; tidak ada baris → `null` (bawaan 16–15; Januari mulai 16 Desember, ditangani library). Pemetaan tidak tunggal → BERHENTI & eskalasi.
- Hari libur: `HariLiburService::tanggalLibur(awal periode sebelumnya, akhir periode)` (periode sebelumnya untuk lookback cuti sakit, README Tukin "Kontrak data"); DATE tanpa konversi zona. Akhir pekan dieliminasi oleh `HariKerja` (library).

#### 7.7.5 `DataPegawaiTukin` [K rekap, README Tukin]
- `predikatSkp` = `riwayat_skp_periodik.hasil_akhir` periode BKN (tanpa baris BKN / data → `null`);
- `jumlahAnak` = jumlah `detail_anak` ber-`tgl_lahir` ≤ akhir periode pada `riwayat_keluarga` aktif [K `:12170-12198`];
- `tmtMasuk` = `tmtsk_pangkat` bila CPNS (`gol_ruang` diawali "CPNS") atau PPPK dengan TMT setelah hari kerja pertama, selain itu `null` [K `:12699-12716`]; sumber `gol_ruang`/`tmtsk_pangkat` dan "hari kerja pertama" = **F5-23** [K]; PPPK dikenali lewat `id_jenis_pegawai == 6` (konstanta bersama) = **F5-24 [V2]**, bukan nama;
- `tmtKeluar` = `pegawai.tmt_status` bila `status = 2` [V2 README Tukin].

#### 7.7.6 Harian (`$harian` per tanggal, periode sebelumnya + periode berjalan)
- `presensi {masuk?, pulang?}` dari `dh_online` (jam lokal apa adanya, dibandingkan dengan jam kerja lokal [K]) — **setelah D-01**.
- `konket {affect_tukin, kategori?}` dari `absen_ijin` + `jenis_konket` (sudah di `main`, B-13) dengan status & rentang tanggal **sesuai rekap legacy** (`L_presensi.php` sekitar `:12346`, `:12828-12846`) [K]; pemetaan status yang tidak tunggal → BERHENTI & eskalasi.
- `tugas_belajar`, `cuti`: lewat `SumberCutiTbInterface` (dibuat 5A):
```php
interface SumberCutiTbInterface
{
    /** @return array<string, array{tugas_belajar?: mixed, cuti?: array<string, mixed>}> per tanggal YYYY-MM-DD */
    public function untuk(string $nip, string $awal, string $akhir): array;
}
```
  Implementasi 5A `SumberCutiTbBelumTersedia::untuk()` **melempar** `DataTukinBelumTersedia('Data cuti/tugas belajar tersedia di Fase 5B.')` (fail-closed: tukin tanpa cuti/TB tidak boleh dihitung di produksi). Pemilihan lewat `Config\Presensi::$sumberCutiTb` (bawaan kelas tersebut); 5B menggantinya (kontrak Fase 4 §8: `riwayat_cuti.status = 1`, `id_jenis_cuti` 1..6, `bebasPresensiPenuh`, `rentangBebasPresensi`). Test 5A memakai fake.

#### 7.7.7 Acceptance criteria adapter
- **AC-TK.1** (D-04) Ada baris `bkn_periode_ekinper` → periode = baris itu (Januari tetap mulai 16 Desember); tidak ada → 16 bulan lalu – 15 bulan ini; hari libur `hari_libur` aktif dalam rentang ikut daftar, di luar rentang tidak; Sabtu/Minggu tidak dihitung hari kerja (lewat `HariKerja`).
- **AC-TK.2** (D-05) Empat kombinasi normal/puasa × Jumat/bukan Jumat memakai nilai dari `web_config` (test mengubah nilai key lalu memeriksa `JamKerja`); rentang puasa beririsan dipilih; dua rentang beririsan → `RentangPuasaGanda`.
- **AC-TK.3** (D-07) Tarif dari `web_config`; satu tarif belum diatur → `KonfigurasiTukinTidakLengkap` (bukan 0, bukan exception mentah).
- **AC-TK.4** Regression: mengubah `TL1/PSW1` lewat `WebConfigService` lalu menghitung ulang → `HasilTukin::totalPotongan` berubah sesuai tarif baru (tanpa membersihkan cache manual).
- **AC-TK.5** `DataPegawaiTukin` dan `harian` presensi/konket terisi benar untuk data sintetis; `SumberCutiTbBelumTersedia` → `DataTukinBelumTersedia`.
- **AC-TK.6** `git diff origin/main -- backend/app/Libraries/Presensi/Tukin/` kosong (atau hanya aditif yang disetujui); pemindai kemurnian hijau.

**Test wajib.** `tests/Presensi/TukinData/KonfigurasiTukinDariWebConfigTest.php`, `PeriodeDanLiburTukinTest.php`, `DataPegawaiTukinAdapterTest.php`, `HarianTukinAdapterTest.php`, `UbahTarifWebConfigTest.php`; `tests/unit/Libraries/WebConfigTypesTest.php` (tipe baru).

**DILARANG (5A-4):** membaca `riwayat_cuti`/`riwayat_tb`; mengubah rumus di `Libraries/Presensi/Tukin`; meng-hard-code tarif/jam; membuat endpoint laporan tukin; menambah migration untuk key `web_config`.

---

### 7.8 FE 5A — Frontend Presensi & Slip Gaji (F5-21)

**Tujuan.** Antarmuka 5A sesuai QAUI-005 (bagian 5A), AGENTS §1, token redesign, mobile-first untuk Presensi (ADR-028). Acuan QA Lapis 1: PNG Figma Slip Gaji (`Figma - UI Slip Gaji.png`, `UI Preview Slip Gaji (Export PDF).png`) + pola komponen Fase 3; layar tanpa Figma (presensi, impor, data presensi, verifikasi) → pola komponen Fase 3 (⛔ Q5A-18 checklist QA).

**Dependency.** Backend halaman terkait merged (§10). **QASMTASK-087** (bagian 5A).

**File boundary.** `frontend/src/features/presensi/**` (`views/`, `components/`, `composables/`, `services/`, `stores/`, `schemas/`, `types.ts`, `roles.ts`, `routes.presensi.ts`, `__tests__/`); berkas bersama aditif `router/index.ts`, `shared/layouts/nav.config.ts` + spec (§3.3). DILARANG mengubah perilaku komponen bersama.

#### 7.8.1 Halaman & route
| Route (name) | Halaman | Role (meta, UX saja) | Isi |
|---|---|---|---|
| `/presensi` (`presensi-saya`) | `PresensiPage.vue` | 2,6,7 | Mobile-first: tanggal & jam lokal (dari server), status hari ini (jam masuk/pulang), tombol **"Rekam Masuk"** / **"Rekam Pulang"** (aktif sesuai `boleh_masuk`/`boleh_pulang` server); **panel status lokasi** sebelum submit: izin lokasi (diizinkan/ditolak/tidak tersedia), koordinat & akurasi, hasil `POST presensi/cek-lokasi` (dalam/luar radius, lokasi terdekat, jarak, radius) + **indikator radius** (diagram SVG lingkaran radius & posisi relatif, tanpa peta tile pihak ketiga, ◻ Q5A-10). Bila `wajib_geofence` dan di luar radius/GPS tidak tersedia → tombol dinonaktifkan dengan alasan (UX; server tetap penentu). Riwayat presensi pribadi (filter bulan, paginasi) |
| `/presensi/slip-gaji` (`presensi-slip-gaji`) | `SlipGajiPage.vue` | 2,6,7 | Pilih periode (dari `GET slip-gaji/saya`), peringatan "Data pada slip gaji berasal dari Biro Perencanaan Keuangan…" [K `list.php:116`]; empat keadaan legacy [K `list.php`]: tidak ada periode / data belum tersedia / **tersedia belum dibuka** (tombol "Lihat Slip Gaji" → modal konfirmasi "Slip gaji hanya dapat dibuka satu kali" + centang setuju [K `list.php:270-290`]) / **sudah dibuka** pada kunjungan berikutnya ("Slip Gaji Sudah Dibuka" + waktu dibuka WIB + "hubungi Admin SIMPEG", **tanpa nominal & tanpa tombol cetak** [K `cfa66c4`]). Setelah buka sukses (sesi yang sama): rincian GPP/TK/uang makan/total + tombol **"Cetak PDF"** (`GET …/pdf`). Tampilan acuan PNG Figma |
| `/presensi/slip-gaji/kelola` (`presensi-slip-gaji-kelola`) | `SlipGajiKelolaPage.vue` | 1,3 | Tab **Monitoring**: filter periode (wajib), unit/satker (role 1), status dibuka, pencarian; ringkasan jumlah/dibuka/belum; tabel NIP · Nama · Unit/Satker · Periode · Status (badge) · Waktu dibuka · Aksi ⋮ (`slipgaji-actions-<id>`: **Edit** (koreksi) → **Lihat detail** → **Cetak PDF** → **Buka ulang** (`hidden` bila belum dibuka, `ConfirmDialog`)). Tab **Impor**: unggah (`UiFileField`, .xlsx/.xls), filter bulan+tahun opsional → **tabel pratinjau** (status per baris badge Valid/Duplikat/Galat, baris galat **ditandai** warna + daftar pesan, ringkasan) → kotak centang **"Timpa data yang sudah ada"** (bawaan **tercentang**, F5-5) → tombol "Simpan ke Database" (`ConfirmDialog`) → hasil commit; 409 ditampilkan dengan ajakan unggah ulang |
| dialog | `SlipGajiKoreksiDialog.vue` | 1,3 | Form 28 field (2 kolom → 1 kolom ≤ 600 px), input kanonik titik-desimal, 422 per field |
| `/presensi/data` (`presensi-data`) | `DataPresensiPage.vue` | 1,3,4,5 | Daftar pegawai (lingkup) → detail presensi per pegawai (rentang tanggal); kolom Sumber (badge Online/Manual/Impor); untuk akun khusus (`can_create`/`aksi`): tombol "Tambah Presensi" + ⋮ (`presensi-actions-<nip>-<tgl>`: **Edit** → **Hapus** `danger`) |
| dialog | `PresensiManualDialog.vue` | akun khusus | NIP (tambah), tanggal, jam masuk, jam pulang; 422 per field |
| `/verify/slip/:token` (`verifikasi-slip`) | `VerifikasiSlipPage.vue` | publik | §7.4.4 |

Hak tampil tombol/aksi = **dari server** (`aksi {}`, `can_*`, `boleh_*`). FE DILARANG menghitung gerbang slip, jendela presensi, jarak geofence sebagai penentu, atau validasi impor (ADR-025).

#### 7.8.2 Menu per role (`nav.config.ts`, grup "Presensi" `phase: 5`, F5-18)
- Tambahan aditif: `NavItem.rilis?: boolean` (tampil walau `phase > ACTIVE_PHASE`); `NavChild.roles?: Role[]` dan penyaringan anak per role di `buildNav`; item tanpa `rilis` berperilaku seperti sekarang. Grup Presensi diberi `rilis: true` (dihapus saat 5C menaikkan `ACTIVE_PHASE` ke 5). Test lama "menu fase 4+ belum tampil" disesuaikan agar mengecualikan item ber-`rilis`; tambah test per role.

| Item (anak grup Presensi) | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
|---|---|---|---|---|---|---|---|---|
| Presensi Saya | – | ✓ | – | – | – | ✓ | ✓ | – |
| Slip Gaji | – | ✓ | – | – | – | ✓ | ✓ | – |
| Kelola Slip Gaji | ✓ | – | ✓ | – | – | – | – | – |
| Data Presensi | ✓ | – | ✓ | ✓ | ✓ | – | – | – |
Grup Presensi tampil bila minimal satu anak tampil (role 8 tidak melihat grup). Konstanta role di `features/presensi/roles.ts`. Guard route = UX; penegakan di backend.

#### 7.8.3 Data, responsif, aksesibilitas
- **Normalisasi angka**: semua kode/angka dari backend (MySQLi → string) dinormalkan di `services/*.service.ts` (`Number(...)`) sebelum dibandingkan (pola `features/kepegawaian/services/pegawai.service.ts:38-39`, CR-049); Vitest **wajib** memakai payload string. Nominal ditampilkan dari string desimal server (format rupiah 0 desimal) — tanpa aritmetika float di FE.
- Responsif **1440 / 600 / 390 px** tanpa scroll horizontal halaman (tabel scroll di wadahnya), kolom Aksi sticky kanan (`useStickyActionsShadow`), dialog muat di 390 px; halaman Presensi dirancang dari 390 px.
- Geolokasi: `navigator.geolocation.getCurrentPosition` (`enableHighAccuracy: true`, `timeout: 10000`, `maximumAge: 0`) diulang tiap 10 detik selama halaman aktif; `cek-lokasi` maks sekali per 10 detik; koordinat dikirim di body. Butuh HTTPS di produksi (◻ Q5A-22).
- Galat 422 per kolom; 403 → "Anda tidak berhak …"; 404 → "Data tidak ditemukan"; 409 impor → pesan server; 429 → pesan server.
- WCAG 2.1 AA: label form, aria-label ⋮ "Aksi untuk …", fokus dialog, status lokasi juga sebagai teks (bukan warna saja).

#### 7.8.4 AC FE 5A (QASMTASK-087 bagian 5A, QAUI-005 bagian 5A)
- **AC-FE.1** Layar presensi mobile: tombol masuk/pulang + status lokasi/GPS **tervisualisasi sebelum submit** (izin, akurasi, dalam/luar radius, jarak, indikator radius) (DoD 077-4, MTC-031 acuan).
- **AC-FE.2** Pratinjau impor: tabel validasi sebelum commit, baris galat ditandai, opsi timpa bawaan tercentang, commit lewat `ConfirmDialog`.
- **AC-FE.3** Slip pegawai: empat keadaan legacy, modal konfirmasi sekali buka, rincian + Cetak PDF hanya pada sesi buka, tanpa nominal di keadaan "sudah dibuka".
- **AC-FE.4** Kelola slip: ⋮ sesuai AGENTS §1 (Edit → Lihat detail → Cetak PDF → Buka ulang), "Buka ulang" `hidden` bila belum dibuka + `ConfirmDialog`; `testid` `slipgaji-actions-<id>`.
- **AC-FE.5** Data presensi: ⋮ Edit/Hapus hanya untuk akun khusus (dari `aksi`); Hapus `danger` + `ConfirmDialog`; `testid` `presensi-actions-<nip>-<tgl>`.
- **AC-FE.6** Menu per role sesuai §7.8.2 dengan `rilis`; `ACTIVE_PHASE` tidak berubah; Vitest per role.
- **AC-FE.7** Halaman verifikasi publik tanpa login, meta robots `noindex`, keadaan asli/tidak ditemukan/429.
- **AC-FE.8** Responsif 1440/600/390; Vitest memakai payload string; tidak ada logika bisnis (gerbang, jendela, jarak, validasi impor) di FE.
- **AC-FE.9** QA Lapis 1 Slip Gaji vs PNG Figma (layar & PDF) sesuai checklist Q5A-18; tetap **IN_PROGRESS** sampai lolos walau QA fungsional lolos.

**Test wajib.** `features/presensi/__tests__/{PresensiPage,SlipGajiPage,SlipGajiKelolaPage,ImporPratinjau,DataPresensiPage,PresensiManualDialog,VerifikasiSlipPage,navPresensi}.spec.ts` + `shared/layouts/__tests__/nav.config.spec.ts` (diperbarui).

**DILARANG (FE 5A):** tombol ikon aksi per baris; menghitung gerbang/jendela/jarak/validasi di FE sebagai penentu; menyimpan nominal/token di `localStorage`/`sessionStorage`; peta tile pihak ketiga tanpa keputusan; data contoh/mock di halaman produksi; menaikkan `ACTIVE_PHASE`; mengubah `features/layanan/**`.

---

### 7.9 Ringkasan test wajib 5A (nama berkas)

| Area | Berkas | Isi minimal |
|---|---|---|
| Skema | `tests/database/Presensi/SkemaPresensiGajiTest.php`, `MigrasiPresensiGajiSiklusTest.php` | AC-D01.* |
| Registry NIP | `tests/database/Kepegawaian/NipReferenceRegistryTest.php`, `tests/Kepegawaian/NipCascadeTest.php`, `tests/unit/Kepegawaian/Nip/TabelAnakNipTest.php` (diperbarui) | AC-D01.7 |
| Impor slip | `tests/Presensi/Slip/Impor*Test.php`, `tests/unit/Presensi/Slip/PembacaExcelSlipTest.php` | AC-D10.* (mismatch, duplikat, constraint = bagian D-13) |
| Akses slip | `tests/Presensi/Slip/{GerbangSlip,SlipPegawaiAkses,BukaSlipAtomik,BukaSlipKonkurensi,SlipAdmin,PdfSlipGaji,UangMakanSnapshot,RoleMatriksSlip}Test.php`; `tests/unit/Presensi/SlipGaji/RingkasanSlipTest.php` | AC-D11.* (gerbang waktu = bagian D-13) |
| Verifikasi | `tests/Presensi/Slip/VerifikasiSlipTest.php` | AC-D14.* |
| Presensi | `tests/unit/Presensi/Geo/*`, `tests/Presensi/Kehadiran/*` | AC-D02.*, AC-D03.* |
| Adapter | `tests/Presensi/TukinData/*`, `tests/unit/Libraries/WebConfigTypesTest.php` | AC-TK.* (ubah tarif = bagian D-13) |
| Route | `tests/Presensi/RoutePresensiKontrakTest.php` | Semua path §6 terdaftar dengan filter benar; **hanya** `publik/verifikasi-slip` tanpa `jwt` |
| Kemurnian | `SlipGajiMurniTest`, `KalkulasiMurniTest` (tidak diubah), pemindaian `Libraries/Presensi/Geo` (baru) | §2.8 |
| FE | §7.8.4 | AC-FE.* |

**DILARANG (test):** melonggarkan assertion; `markTestSkipped`/`incomplete` untuk DoD; test ber-DB tanpa `DatabaseTestCase`; bergantung jam sistem; data pribadi nyata; memanggil layanan eksternal.

---

## 8. Aturan lintas modul

### 8.1 Audit
- Model 5A **wajib** `extends BaseAuditableModel` (`Models/Presensi/README.md`). Bila `BaseAuditableModel` tidak mendukung PK komposit `dh_online`, tulis audit manual `AuditLogModel::record('dh_online', '{nip}:{tgl}', event, before, after, idPengguna, nipPelaku)` di **transaksi yang sama** — hasil sama dengan audit model.
- Wajib diaudit: tambah/ubah/hapus presensi (online & manual); buka slip (tanpa IP/token/nominal); koreksi slip (before/after field berubah); buka ulang; ringkasan commit impor (`import`, tanpa nominal); mismatch nama (`warning`). Impor massal ditulis lewat Query Builder dengan audit **ringkasan** (README aturan 10), bukan per baris.
- Audit **tidak pernah** memuat token QR (mentah maupun hash), IP, atau isi berkas. Kegagalan menulis audit → operasi gagal (tidak silent-fail).

### 8.2 Transaksi & konkurensi
- **DILARANG `transException(true)`**. Pola: `transBegin()` → kerja → `transStatus()` → `transCommit()`/`transRollback()` + `resetTransStatus()` (`RiwayatEngine.php:715-744`).
- Kunci baris yang akan diubah `FOR UPDATE` (`getCompiledSelect() . ' FOR UPDATE'`, pola `PenggunaModel.php:174`); buka/buka ulang slip memakai UPDATE bersyarat + cek jumlah baris; 1062 ditangkap sesuai aturan task (presensi: baca ulang; manual: 422; impor: 409 + rollback). DILARANG merakit SQL dari input.
- Berkas sementara dihapus di `finally` sesudah commit/rollback.

### 8.3 Unggah berkas
`ValidasiBerkas` (CR-054) lewat aturan impor aditif: ukuran dari disk, MIME dari isi, ekstensi ≙ isi, tanpa ekstensi ditolak. Berkas di `writable/` (tidak publik), nama dibangkitkan server, dihapus setelah diproses termasuk bila gagal. Impor slip **bukan** `document_attachment`/`AttachmentService`.

### 8.4 ADR-032 — Library PDF/Excel/QR & pola rute publik (DIPUTUSKAN di PRD ini, F5-17)
| Butir | Keputusan |
|---|---|
| PDF | TCPDF lewat `App\Libraries\Pdf\Pdf` (ADR-033, sudah di `main`); satu generator `PdfSlipGaji` |
| QR | `Pdf::qrImg` (TCPDF2DBarcode) di backend; tanpa paket QR baru; ekstensi `gd` wajib (tanpa fallback teks) |
| Excel | `phpoffice/phpspreadsheet` — rilis stabil terbaru yang mendukung matriks PHP CI (◻ Q5A-19); `ext-*` yang diwajibkan paket ditambahkan ke `composer.json` dan diverifikasi di CI; hanya dipakai di `Libraries/Presensi/Slip` (dilarang di folder murni) |
| Rute publik | Halaman SPA `/verify/slip/:token` (di luar `api/v1`, path legacy) + endpoint data `GET api/v1/publik/verifikasi-slip/{token}` di grup tanpa `jwt` dengan filter `throttle` (Throttler CI4, 30/menit/IP, IP tidak disimpan) |
Berkas: `docs/adr/ADR-032-library-pdf-excel-qr-rute-publik.md` (status "Diterima 19-10-2026", rujukan PRD ini) + baris ADR-032 di `docs/adr/README.md` diperbarui — dibuat di **M3**.

### 8.5 Keamanan endpoint publik & catatan deploy
- Endpoint publik hanya satu (§6.3); validasi format token sebelum query; respons seragam; throttle; `noindex`; `no-store`; data minimum AS-06; tanpa IP tersimpan. Rincian ancaman/eksploit **tidak** ditulis di repo publik.
- Catatan deploy ditulis di `backend/app/Controllers/Api/Presensi/README.md` bagian "Catatan deploy" (bukan berkas deploy bersama): rute `/verify/slip/*` ke SPA sejak switch produksi (SG-1), header `X-Robots-Tag: noindex` untuk path itu, HTTPS wajib (geolokasi), ekstensi PHP (gd, zip, xml, dll.), direktori `writable/uploads/slip-impor/` dapat ditulis, daftar key `.env` `presensi.*` (tanpa nilai rahasia). **Tanpa** nama host/IP.

### 8.6 Kontrak API (pola Fase 3/4)
- Envelope sukses `{status: "success", data}`; gagal `{status: "error", message, code?, errors?: {<field>: ["…"]}}` (ADR-001); nama field = nama kolom DDL snake_case; tanggal bisnis `YYYY-MM-DD`; stempel UTC (ISO 8601) + padanan `*_wib` bila tampil; periode slip `YYYY-MM`; nominal sebagai **string desimal kanonik** (mis. `"4500000.00"`), tidak pernah float.
- Daftar: `data: {items, total, page, per_page, …}` (`ListQuery`; `per_page` 1..100 bawaan 20; parameter array → 422 "Parameter daftar tidak valid.").
- Hak per item `aksi {}` + hak halaman `can_*`/`boleh_*` = penegakan server.
- Koordinat **tidak** dikirim di endpoint daftar mana pun (data lokasi pribadi; disimpan untuk bukti). Admin melihat `id_lokasi`/nama lokasi dan `sumber` saja.
- Dokumentasi endpoint per task di `backend/app/Controllers/Api/Presensi/README.md` (tabel `Method & path | Role | Isi`, kode status, contoh payload sintetis) — bagian dari DoD.

### 8.7 Frontend & akun uji
Normalisasi angka string MySQLi di service FE + Vitest payload string (§7.8.3). Akun uji `must_change_password = 0` (§2.5). Data uji sintetis.

---

## 9. Ketergantungan ke fase/paket lain

| Pihak | Butir | WAJIB disediakan 5A | DILARANG dikerjakan sekarang |
|---|---|---|---|
| **5B** | D-06 precedence, D-08 laporan tukin, D-09 rekap & uang makan (+ nilai uang makan slip) | `dh_online` (+ kolom F5-12); `TukinDataService` + `ArgumenTukin`; `SumberCutiTbInterface` (+ implementasi fail-closed) dan `Config\Presensi::$sumberCutiTb`; `UangMakanSlipInterface` + aturan snapshot (§7.3.7) dan `Config\Presensi::$sumberUangMakan`; key `web_config` F5-16; dokumentasi kontrak di README `Libraries/Presensi` | Laporan/rekap tukin, nilai uang makan, membaca `riwayat_cuti`/`riwayat_tb`, `bebasPresensiPenuh` |
| **5C** | D-12 sisa, D-13 lengkap (12 skenario terpisah), sign-off Horii, `ACTIVE_PHASE` 4→5 | Test 5A sebagai bagian regression (impor slip, gerbang waktu, ubah tarif); flag `rilis` yang dapat dihapus; progres lengkap | Tabel laporan densitas tinggi, color-coding TL/PSW/TK/TPM/TPP, menaikkan `ACTIVE_PHASE` |
| **Fase 4** | Cuti/TB final (kontrak PRD Fase 4 §8) | Tidak ada; 5A tidak bergantung pada Fase 4 kecuali urutan merge DBV-022 (§3.5) | Menyentuh berkas Fase 4 (§3.2) |
| **Fase 6** | FCM presensi (`pegawai_blm_masuk/pulang/lkh`), adapter real | Tidak ada antrean push; tidak memakai `PushNotifGatewayInterface` | Notifikasi presensi, `firebase_token` |
| **Fase 7** | Dashboard (rekam presensi di dashboard, "Kehadiran Tim", hitung presensi hari ini per satker) | `GET presensi/saya/hari-ini` dapat dipakai ulang | Mengubah dashboard |
| **Cutover** | SG-1 (D-11 & verifikasi live sejak switch produksi), AS-14 (jendela cutover di luar tanggal 1–5), impor DBV-023 | Bagian impor DBV-023; catatan deploy §8.5 | Menjalankan impor/migration di produksi |
| **MT** | MT-00x / MTC-030..036 | — | Apa pun (fase tersendiri) |

---

## 10. Urutan kerja (runbook) dengan ⛔ TITIK TUNGGU

Aturan setiap milestone: (a) branch baru dari **`origin/main` terbaru** (`git fetch` dulu) bernama `fase5a/<key>-<ringkas>`; (b) satu key per milestone; (c) berkas bersama di commit terpisah (§3.3); (d) gate cepat lokal; (e) push branch; (f) tunggu CI `quality-gate` **hijau** untuk SHA itu; (g) **lapor** (format §11.4); (h) ⛔ tunggu review & merge reviewer CR (atau PR DBV) sebelum milestone yang bergantung. Coder tidak push ke `main`.

| No | Milestone | Task | Key | Branch | Menunggu | Membuka jalan |
|---|---|---|---|---|---|---|
| M0 | Baca PRD + inspeksi repo (`git show origin/main:…`) + laporan pemahaman | — | (tanpa commit) | — | — | ⛔ reviewer CR mengonfirmasi key & menjawab §13 butir bertanda "M0/M1" |
| M1 | Dokumen DBV-023 + kueri audit + draf migration (tidak dijalankan di DB dev) | D-01 | DBV-023 | `fase5a/dbv-023-skema-presensi-gaji` (PR `[DBV]`) | M0 | ⛔ **DB Validator approve DBV-023** + jawaban Q5A-01, Q5A-03 |
| M2 | Migration + registry NIP/cascade (§3.4) + (ENUM audit) + test skema, sesuai DBV-023 disetujui | D-01 | DBV-023 (PR sama) | idem | M1 approve; ⛔ **DBV-022 di `main`** (rebase); ⛔ hasil kartu DBA Q5A-02 | ⛔ merge PR → M3–M6 |
| M3 | Impor Slip Gaji 3 langkah + PhpSpreadsheet + ADR-032 + `Config\Presensi` + `RoutesPresensi` (dasar) | D-10 | MAKE-030 | `fase5a/make-030-impor-slip-gaji` | M2 merged | M4, M7 (impor) |
| M4 | Slip pegawai (gerbang, buka, PDF+QR), kelola admin legacy, verifikasi publik + throttle, kontrak uang makan, README Slip `771f8b4` | D-11, D-14 | MAKE-031 | `fase5a/make-031-akses-verifikasi-slip` | M3 merged | M7 (slip, verifikasi) |
| M5 | Presensi online + geofence server + presensi manual + data presensi admin | D-02, D-03 | MAKE-032 | `fase5a/make-032-presensi-online-manual` | M2 merged (logika `Geo` murni boleh disiapkan sejak M0) | M6 butir presensi, M7 (presensi) |
| M6 | Adapter Tukin + key `web_config` F5-16 + tipe `date_range_list` | 5A-4 (D-04/05/07 integrasi) | MAKE-033 | `fase5a/make-033-adapter-tukin` | Mulai setelah M0; merge setelah M2 (butir presensi `dh_online`) | 5B |
| M7 | Frontend 5A + menu `rilis` + rute publik | FE 5A | MAKE-034 | `fase5a/make-034-frontend-presensi-slip` | Per halaman setelah backend-nya merged; ⛔ Q5A-18 sebelum QA Lapis 1 | M8 |
| M8 | Verifikasi akhir 5A: seluruh AC, matriks role 403, keterlacakan, progres, README, laporan akhir | semua 5A | MAKE-035 | `fase5a/make-035-verifikasi-5a` | M3–M7 merged | ⛔ sign-off reviewer CR **5A** (bukan Fase 5) |

**Boleh paralel** (setelah M2 merged, berkas tidak tumpang tindih): M3 ∥ M5 ∥ M6; M4 setelah M3 (berbagi service/model slip). Berkas milik 5A yang sama (`RoutesPresensi.php`, `Config/Presensi.php`, `Controllers/Api/Presensi/README.md`) → milestone yang merge belakangan **rebase** dan menambah secara aditif. **Tidak boleh**: apa pun yang bergantung tabel 5A di-merge sebelum M2; M2 sebelum DBV-022 di `main`.

**Isi laporan M0** (wajib sebelum M1): ringkasan pemahaman per task (≤ 3 baris); daftar berkas repo yang dipakai ulang (path); konfirmasi tidak ada migration/controller/model/route Presensi di `main` selain README/placeholder; status DBV-022 & migration Fase 4 terbaru di `main`; konfirmasi key MAKE-030..035/DBV-023; jawaban yang dibutuhkan dari §13; risiko tabrakan yang terlihat (berkas bersama §3.3).

---

## 11. Definition of Done 5A & exit criteria

### 11.1 5A DONE hanya bila SEMUA berikut terpenuhi
1. Seluruh AC §7 (AC-D01.*, AC-D10.*, AC-D11.*, AC-D14.*, AC-D02.*, AC-D03.*, AC-TK.*, AC-FE.*) lulus dengan bukti test/run CI; setiap butir DoD QASMTASK-076/077/078/085/086 + kartu D-14 + bagian 5A QASMTASK-079/080/082/087/088 punya AC (Lampiran C).
2. CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4) dan `./check.sh` lulus pada SHA akhir; test Fase 0–4 yang ada tetap hijau.
3. Persetujuan DB Validator atas DBV-023 tercatat (tanggal) di `backend/docs/progress/05-Presensi.md`; registry NIP & cascade hijau.
4. Matriks role × endpoint §6: role tak berhak = 403 untuk setiap route (test route × role 1–8), satu-satunya route tanpa `jwt` = verifikasi publik.
5. Dokumentasi endpoint lengkap di `Controllers/Api/Presensi/README.md`; README `Libraries/Presensi/README.md` diperbarui ke `771f8b4`; ADR-032 di repo.
6. Semua butir ⛔ §13 yang jatuh tempo di 5A terjawab & tercatat; deviasi [V2] tercatat.
7. QA Lapis 1 FE 5A lolos sesuai checklist Q5A-18 — sampai lolos, task FE tetap **IN_PROGRESS** walau QA fungsional lolos.
8. Sign-off eksplisit reviewer CR untuk 5A setelah M8.

### 11.2 5A DONE ≠ Fase 5 DONE
- **Fase 5 DONE** hanya setelah 5B dan 5C selesai, D-13 lengkap lulus sebagai regression suite terpisah, dan **sign-off Horii** (`05-Presensi.md` exit criteria; K5-22). **DILARANG** menyatakan Fase 5 DONE atau "siap go-live" di laporan 5A.
- Blocker go-live yang wajib dicantumkan di laporan 5A: nilai uang makan slip (5B, F5-1); konfirmasi Biro Keuangan atas data verifikasi AS-06 (F5-7, Q5A-08); hasil impor & checksum produksi (Q5A-02, Q5A-14).
- D-04, D-05, D-07 tetap **IN_PROGRESS** sampai 5B (bagian murni + adapter 5A selesai; precedence/laporan di 5B).

### 11.3 Aturan IN_PROGRESS
Sesi berhenti sebelum task selesai → status task **IN_PROGRESS** + catatan progres detail di `backend/docs/progress/05-Presensi.md` (milik Fase 5 saja), format seperti `03-Kepegawaian.md`: judul, rujukan PRD ini, "Terakhir diperbarui" (tanggal, branch, SHA, status CI), entry criteria (dengan pengecualian P-1), tabel `Task | Paket | Milestone | Key | Status | Ringkas` (D-01..D-14), lalu subbab per milestone: yang sudah jalan, yang belum, berkas terakhir yang disentuh, test gagal/belum, keputusan & deviasi, blocker (termasuk blocker go-live §11.2).

### 11.4 Format laporan akhir (dan per milestone)
```
Status: D-01 DONE|IN_PROGRESS|BLOCKED, D-02 …, D-03 …, D-10 …, D-11 …, D-14 …, 5A-4 …, FE 5A …
Branch/SHA/CI: <branch> <sha> <url run> (hijau/merah)
File utama: <daftar path>
Test & validasi: <berkas test + hasil>; geofence/zonasi/jendela: <bukti>; presensi manual akun khusus: <bukti>;
                 impor (mismatch/duplikat/1062): <bukti>; gerbang 09:59:59/10:00:00: <bukti>; verifikasi publik/throttle: <bukti>;
                 ubah tarif web_config: <bukti>; 403 per role: <bukti>; registry NIP: <bukti>; check.sh/CI: <bukti>
Tabrakan Fase 4: <berkas bersama yang disentuh + SHA commit terpisah>, rebase terhadap <sha main>
Deviasi [V2] & keputusan: <daftar>
Blocker / pertanyaan terbuka: <daftar + rujukan §13>; blocker go-live: <daftar>
```

---

## 12. Checklist verifikasi sebelum serah (centang per butir di laporan)

- [ ] DBV-023 disetujui DB Validator **sebelum** merge migration; DBV-022 sudah di `main`; timestamp migration 5A lebih baru
- [ ] `dh_online` PK `(nip, tgl)` + 1062 teruji; FK RESTRICT; kolom F5-12 ada; `gaji_pegawai` tanpa 15 kolom generik; `qr_history` FK `id_gaji_pegawai` + UNIQUE token
- [ ] Registry NIP & `NipCascadeService::FASE = 5`; `NipCascadeTest` memakai `FASE`; tidak ada perubahan klasifikasi Fase 4
- [ ] Impor: 3 langkah; validasi ulang server di commit; tidak menerima baris klien; mismatch → `audit_logs`; `baris_ganda`; 1062 → 409 + rollback; timpa bawaan tercentang; berkas di `writable/` & terhapus
- [ ] Gerbang slip 09:59:59 ditolak / 10:00:00 diterima (semua jalur pegawai); WIB tetap
- [ ] Buka slip sekali & atomik; token baru tiap buka; DB hanya hash; riwayat token; nominal hanya di respons buka & PDF
- [ ] Admin: role 1 semua, role 3 satker (403 di luar, fail-closed tanpa `id_satker`), 4/5/8 403; koreksi & buka ulang diaudit; status/token tetap saat koreksi/timpa
- [ ] Total slip = `bersih_gpp + bersih_tk + uang_makan`; tanpa tukin kelas jabatan; uang makan via `UangMakanSlipInterface` (5A: belum tersedia)
- [ ] PDF: tanpa QR bila tanpa token; QR gambar (bukan teks); nama berkas tersanitasi; header aman
- [ ] Verifikasi publik: format token dulu, 404 seragam, throttle 30/menit, `noindex`, `no-store`, data AS-06 dari snapshot, tanpa nominal/NIP/IP
- [ ] Presensi online: role 2/6/7; jendela [K]; zonasi `satker.zonasi`; geofence server haversine `≤`; salah satu lokasi cukup; pegawai tanpa aturan bebas; akurasi dicatat; pulang last-wins; masuk first-wins
- [ ] Presensi manual: hanya `pengguna.id` 4675/4676 dari `Config\Presensi`; validasi [V2]; duplikat 422; hapus fisik + audit; whitelist field
- [ ] Adapter: tarif/jam/puasa dari `web_config`; tarif kosong → galat terkendali; ubah tarif → hasil berubah; cuti/TB fail-closed; library `Tukin` tidak berubah
- [ ] Kemurnian `SlipGaji`/`Tukin` (+ `Geo`) hijau; tidak ada PhpSpreadsheet/DB di folder murni
- [ ] AGENTS §1: ⋮ `RowActionsMenu`, urutan & label baku, `hidden`/`disabled` dari server, `ConfirmDialog`, aria-label "Aksi untuk …", testid `slipgaji-actions-<id>` / `presensi-actions-<nip>-<tgl>`, Vitest via `data-action`
- [ ] Menu per role §7.8.2 lewat `rilis`; `ACTIVE_PHASE` tidak berubah
- [ ] Normalisasi angka string MySQLi di service FE; Vitest payload string; responsif 1440/600/390
- [ ] Test DB `extends DatabaseTestCase`; DDL/konkurensi bertanda `db-isolasi-penuh`; DB scratch port 3307; `.env` dev tidak disalin; waktu disuntik
- [ ] Akun uji `must_change_password = 0`
- [ ] Tidak ada `transException(true)`
- [ ] Migration lama di `main` tidak diubah
- [ ] Berkas bersama hanya aditif di commit terpisah; tidak ada berkas Fase 4 yang tersentuh
- [ ] Commit Bahasa Indonesia `feat|fix(scope): KEY …`, **tanpa** trailer `Co-Authored-By` & tanpa penyebutan AI
- [ ] Tidak ada rahasia/IP/host internal/data pribadi nyata/detail celah di diff; data uji sintetis
- [ ] Tidak ada pemanggilan layanan eksternal; tidak ada antrean push; tidak membaca `riwayat_cuti`/`riwayat_tb`
- [ ] Dokumentasi endpoint, README modul, ADR-032, `backend/docs/progress/05-Presensi.md` diperbarui
- [ ] Tidak ada pekerjaan/laporan MT-00x/MTC dalam laporan 5A; tidak ada klaim "Fase 5 DONE"

---

## 13. Pertanyaan terbuka yang TERSISA

> **Status 19-10-2026:** user/reviewer CR menjawab **Q5A-01 = opsi (a)** (kolom [V2] `gaji_pegawai.qr_token_enkripsi`, token aktif terenkripsi Encrypter CI4, lookup verifikasi tetap via hash; finalisasi tipe kolom tetap di DBV-023) dan **menyetujui semua default ◻**. Kolom "Default / usulan" = keputusan yang berlaku. Butir ⛔ yang tersisa menunggu pihaknya: Q5A-02 (kartu DBA ISSUE-049), Q5A-03 (DB Validator, DBV-023), Q5A-13 (sebelum 5B), Q5A-18 (sebelum QA Lapis 1), Q5A-08 (konfirmasi Biro Keuangan sebelum go-live). Hal baru di luar tabel → BERHENTI dan eskalasi.

Aturan: butir **⛔** WAJIB dijawab pihak yang disebut **sebelum** batas yang disebut; coder **berhenti** pada bagian itu dan mengerjakan bagian lain. Butir **◻** = default PRD berlaku bila reviewer CR tidak menolaknya saat review laporan M0; bila ditolak, ikuti jawaban reviewer. Tidak ada butir yang boleh "diputuskan sendiri" oleh coder. Hal baru di luar tabel ini → BERHENTI dan eskalasi.

| ID | Sifat | Pertanyaan | Default / usulan | Pemilik | Batas |
|---|---|---|---|---|---|
| Q5A-01 | ⛔ | Token disimpan hash (F5-6), tetapi PDF dapat dicetak ulang selama dibuka (pegawai) / kapan saja (admin) dan QR membutuhkan token **mentah**. Dari mana token mentah untuk cetak ulang? | Usulan (a): kolom [V2] `gaji_pegawai.qr_token_enkripsi` berisi token aktif terenkripsi (Encrypter CI4, kunci `encryption.key`), dikosongkan saat buka ulang; lookup verifikasi tetap via hash. Alternatif (b): setiap cetak menerbitkan token baru + baris riwayat (menyimpang dari Q-1 legacy "token baru setiap dibuka") | reviewer CR + DB Validator | M1 (sebelum DBV-023 dikunci) |
| Q5A-02 | ⛔ | Hasil kartu DBA (F5-20): `SHOW CREATE TABLE gaji_pegawai` + `COUNT(*)` produksi (dugaan 0 baris; D1 masih struktur lama) | Dicatat di DBV-023 bagian impor; bila > 0 dengan nilai di kolom generik → DBV memutus pemetaan (§7.1.1) | reviewer CR (kartu DBA) | Sebelum merge M2 |
| Q5A-03 | ⛔ (DBV) | DBV-Q1 (konfirmasi PK), Q3 (`created_by`), Q5, Q6 (tipe & FK lokasi), Q9, Q10, Q11 (aksi FK), Q16 (nilai ENUM), Q17 (kolom snapshot/token) | Usulan §7.1.1/§7.1.3 | DB Validator | M1 |
| Q5A-04 | ◻ | `hari_berlaku` aturan lokasi diterapkan saat presensi (legacy tidak memakainya, G-03 mendefinisikannya); resolusi dinamis tanpa cache | Diterapkan [V2]; tanpa cache/tabel | reviewer CR | Review M0 |
| Q5A-05 | ◻ | Batas impor: ukuran berkas, jumlah baris per sheet, TTL pratinjau (legacy tanpa batas) | 5 MB, 10.000 baris/sheet, 30 menit (`Config\Presensi`, override `.env` tervalidasi) | reviewer CR | Review M0 |
| Q5A-06 | ◻ | Templat unduhan impor (legacy `download_template` ada, tombol disembunyikan sejak `4d2aec2`) | Tidak dibuat di 5A (ikut tampilan legacy); bila dibutuhkan → kartu terpisah (F5-8) | reviewer CR | Review M0 |
| Q5A-07 | ◻ | `viewed_ip`: disimpan & ditampilkan di monitoring admin [K]; retensi | Disimpan [K], tampil di monitoring/detail admin, tidak di verifikasi/audit; retensi mengikuti kebijakan data pribadi umum (keputusan terpisah) | reviewer CR | Review M0 |
| Q5A-08 | ◻ (⛔ go-live) | Data verifikasi AS-06 & format nama tersamar | Tampil: status keaslian, periode, satker penerbit, waktu terbit, nama tersamar (huruf pertama + `***` per kata, gelar dibuang). Konfirmasi Biro Keuangan **⛔ sebelum go-live** (F5-7) | user + Biro Keuangan | Review M0 (default); go-live (konfirmasi) |
| Q5A-09 | ◻ | Role lihat data presensi admin (legacy `list_ad` 1/4, `list/{nip}` 1/4/5; F5-15 menambah role 3) | Role 1, 3, 4, 5 dengan `PegawaiScope` | reviewer CR | Review M0 |
| Q5A-10 | ◻ | Peta (map picker) presensi memakai tile pihak ketiga? (QAUI-005 Info; G-03 X-4 opsional) | Tidak di 5A: indikator radius SVG tanpa tile; peta tile = keputusan 5C (dependensi + kebijakan privasi/CSP) | reviewer CR | Review M0 |
| Q5A-11 | ◻ | Lokasi presensi dengan koordinat tidak sah (data impor) | Diabaikan + log; bila semua lokasi berlaku tidak sah → 422 `lokasi_tidak_valid` (fail-closed) | reviewer CR | Review M0 |
| Q5A-12 | ◻ | Koordinat wajib bagi pegawai tercakup aturan (izin GPS ditolak) | Wajib → 422 `koordinat_wajib` | reviewer CR | Review M0 |
| Q5A-13 | ⛔ (5B) | Prasyarat DEV-006: cross-check rumus Tukin ke aturan resmi atau eskalasi Horii **sebelum D-04** — 5A tidak mengubah rumus (K-1 ikut rekap) | 5A adapter berjalan tanpa menunggu; cross-check/eskalasi wajib selesai sebelum 5B D-06/D-08 | reviewer CR / Horii | Sebelum 5B (tidak memblokir 5A) |
| Q5A-14 | ◻ (⛔ go-live) | Checksum kode legacy produksi (AS-09) untuk `Local.php`, `Presensi.php`, `L_presensi.php`, `Sl_gaji.php`, `Lsl_gaji.php`, `Verify.php` | Pengembangan memakai `771f8b4`; selisih dengan produksi yang ditemukan kemudian → CR | user / tim legacy | Sebelum go-live |
| Q5A-15 | ◻ | Isi `d_dh_online` (arsip hapus legacy) diimpor? | Tidak diimpor (DBV-Q4) | DB Validator | M1 |
| Q5A-16 | ◻ | Ubah manual jam yang semula dari presensi online: koordinat jam itu | Dikosongkan (nilai lama tetap di audit) | reviewer CR | Review M0 |
| Q5A-17 | ◻ | "Hari ini" untuk validasi `tgl ≤ hari ini` presensi manual; jendela waktu untuk manual | Tanggal lokal satker pegawai; jendela tidak berlaku untuk manual [K] | reviewer CR | Review M0 |
| Q5A-18 | ⛔ | Checklist QA Lapis 1 FE 5A: Slip Gaji vs PNG Figma; layar tanpa Figma (presensi, impor, data presensi, verifikasi) vs pola komponen Fase 3 | Reviewer/QA menyepakati checklist tertulis sebelum QA | reviewer CR / QA | Sebelum QA Lapis 1 M7 |
| Q5A-25 | ⛔ (cutover) | Nama `jenis_pegawai` id 6 di produksi (F5-24) | Kueri di butir F5-24 dijalankan saat cutover; bila ≠ 'PPPK' → keputusan bisnis sebelum Tukin PPPK dipakai | reviewer CR / DBA | Sebelum go-live |
| Q5A-19 | ◻ | Versi PhpSpreadsheet | Rilis stabil terbaru yang mendukung matriks PHP CI, dicatat di ADR-032 | reviewer CR | M3 |
| Q5A-20 | — | Revisi dokumen: `05-Presensi.md` (J0.04: D-01..D-14, SG-2, J7.02 opsi timpa), Matriks/FSD/TS (hak Slip Gaji, aktor D-02), errata DBV-011 (`offset_zona_menit` → `satker.zonasi`, K5-14), audit legacy (PK `dh_online`, K5-07), header dokumen G-03, PRD Fase 4 path (F5-22) | Di luar coder — reviewer CR | reviewer CR | — |
| Q5A-21 | ◻ | URL dasar QR | `config('App')->baseURL` + `verify/slip/`, override `.env` `presensi.urlVerifikasiSlip` | reviewer CR | Review M0 |
| Q5A-22 | ◻ | Kebutuhan deploy (HTTPS, rute Nginx, ekstensi PHP) | Dicatat di README controller (§8.5), dieksekusi tim infra saat cutover | reviewer CR / infra | Sebelum go-live |
| Q5A-23 | ◻ | Slip yang dibuka di lingkungan non-produksi sebelum 5B tidak punya snapshot uang makan | Diterima (bukan data produksi); setelah 5B, buka ulang admin bila perlu [K catatan SQL-L `2026_10_08`] | reviewer CR | Review M0 |
| Q5A-24 | ◻ | Pegawai tanpa jabatan/satker saat presensi | Zonasi 0 (WIB) [K]; resolver aturan unit/satker tidak cocok (hanya `target_peg`/`0` bisa mencakup) | reviewer CR | Review M0 |

---

## 14. Lampiran

### Lampiran A — Rujukan sumber

| Kode | Berkas | Bagian |
|---|---|---|
| Riset reviewer | Berkas kerja riset reviewer CR (keputusan user, kontrak Trello, spesifikasi dokumen, perilaku legacy, DDL, kondisi repo) | **Riset internal reviewer, tidak dipublikasikan; isi mengikat sudah disalin ke PRD ini.** Butuh rincian tambahan → minta ke reviewer CR |
| Spek | `Bahan Baku SIMPEG/05-Presensi.md` (D-01..D-13, exit criteria), `00-INDEX.md`, `Matriks_Role_x_Endpoint_SIMPEG_v2` (Modul D), `SIMPEG_Technical_Specification_00-08` (§2.6, §4.1, §9, §11), `SIMPEG_v2_ADR` (ADR-001/005/008/011/012/013/015/016/025/028/029), `Mapping_Migrasi_Data_SIMPEG_v2` (Tier 3/5, Item Kritis #4), `Legacy_System_Audit_SIMPEG`, FSD/SRS/RTM Final-15926, `Test_Case_Manual_SIMPEG_v2` (MTC-030..036, acuan saja) | |
| PRD/dokumen proyek | `PRD_20261001.md` (SG-1..SG-3, K1–K4, J1.07, J7.01–J7.07, AS-05/06/09/10/12/14, §5b), `Feature Slip Gaji.md`, `brainstorm_slip_gaji.md`, `PRD_G-03_Lokasi_Presensi.md`, `PRD_Kalkulasi_Tukin.md`, PNG Figma Slip Gaji (2 berkas), `docs/fase4/PRD_Fase4.md` (§2, §8) | |
| Legacy (`771f8b4`) | `controllers/hr/services/Local.php` (`po_in` 1737-1882, `po_out` 1884-2048, `preview_po` 2050-2083, `record_geolocation` 4071-4123); `controllers/hr/Presensi.php` (1164-1455); `libraries/hr/L_presensi.php` (3216-3478; rekap tukin 11852-13057); `core/MY_Controller.php` (102-115); `views/user/dashboard_pns.php` (5, 152, 166, 563-743, 765-863); `libraries/hr/master/Lm_lokasi.php` (505-760); `controllers/hr/Sl_gaji.php` (5-496); `libraries/hr/Lsl_gaji.php` (9-1380); `controllers/Verify.php` (11-22); `views/verify/slip.php` (86-108); `views/hr/employee/sl_gaji/{list,admin_import,_slip_content}.php`, `print/cetak.php`; `config/constants.php` (300-302, 324-325); SQL-L `2026_09_01`, `2026_09_02`, `2026_10_08` | |
| DDL D1 | `simpeg01_struktur_lengkap_20261001.sql` | `dh_online` 391-401; `dh_online_coordinate` 406-416; `dm_user_lokasi_presensi` 474-493; `d_dh_online` 627-635; `gaji_pegawai` 1242-1273; `lokasi_presensi` 2212-2224; `satker.zonasi` 6895; `user_geo` 7547-7560; `user_log` 7577-7590; `user_lokasi_presensi` 7595-7605; `web_config` 7622-7631; trigger `dh_online_beDel` 7709-7714 |
| Repo v2 (`39b5331`) | `AGENTS.md`; `backend/app/Libraries/Presensi/{README.md, SlipGaji/*, Tukin/*}`; `backend/docs/progress/05-Presensi.md`; `Controllers/Api/Presensi/README.md`, `Models/Presensi/README.md`, `frontend/src/features/presensi/README.md`; `Config/{Routing,Routes,RoutesPegawai,Filters,WebConfig,MasterData,Konket,Integrations}.php`; `Libraries/MasterData/{WebConfigService,WebConfigTypes,HariLiburService,AturanLokasiPresensiHooks}.php`; `Libraries/Kepegawaian/{NipCascadeService.php, Nip/TabelAnakNip.php, Lampiran/ValidasiBerkas.php, Riwayat/AturanLampiran.php, Riwayat/RiwayatEngine.php}`; `Libraries/Pdf/*`; `Models/{AuditLogModel,BaseAuditableModel}.php`; migrations `2026-10-01-000001_CreateLokasiPresensi`, `2026-09-30-100000_CreateMasterJabatanUnitSatker`, `2026-10-01-100000_CreateWebConfig`, `2026-09-30-130400_CreateRiwayatSkpLkh`, `2026-09-30-130500_CreateAbsenIjin`, `2026-09-17-000004_AlterAuditLogsEventAddAuth`; `backend/docs/db-review/{G-03-lokasi-presensi-schema, G-02-jabatan-unit-satker-schema, G-09-web-config-schema, DBV-011-…, DBV-019-…, DBV-020-…}.md`; `tests/_support/{DatabaseTestCase.php, Libraries/PemindaiKemurnian.php}`; `tests/database/Kepegawaian/NipReferenceRegistryTest.php`; `tests/Kepegawaian/{NipCascadeTest, RoutePegawaiKontrakTest}.php`; `frontend/src/{router/index.ts, shared/layouts/nav.config.ts, shared/components/{RowActionsMenu,ConfirmDialog}.vue}`; `docs/adr/{README.md, ADR-033-library-pdf-tcpdf.md}`; `.github/workflows/quality-gate.yml` | |

### Lampiran B — Anomali legacy & perlakuan

Legenda: **[K]** dibiarkan seperti legacy · **[V2]** diperbaiki · **F5-x** diputuskan keputusan user · **Q5A-x** default/konfirmasi §13. Butir keamanan ditulis **kode + jenis** saja; **rincian tidak ditulis di repo publik**.

| ID | Temuan legacy (ringkas) | Rujukan | Perlakuan 5A |
|---|---|---|---|
| P5-01 | Geofence hanya di klien (jenis: kontrol hanya di klien) | `dashboard_pns.php:664-725`; `Local.php:1737-2048` | [V2] F5-9 validasi server §7.5.4 |
| P5-02 | Daftar lokasi hanya role 2; PTT/PPPK tidak digeofence | `MYC:104` | [V2] F5-9 semua yang tercakup |
| P5-03 | Lokasi terakhir menentukan status tombol | `dashboard_pns.php:676-690` | [V2] salah satu lokasi cukup |
| P5-04 | `hari_berlaku` tidak dipakai | D1:491 | [V2] diterapkan (◻ Q5A-04) |
| P5-05 | Ekspansi aturan statis | `Lm_lokasi.php:355-375`, `:423-445` | [V2] resolusi dinamis §7.5.3 |
| P5-06 | Hitung presensi hari ini dashboard tanpa zonasi | `L_user.php:126`, `:341-343` | Di luar 5A (Fase 7) |
| P5-07 | Zonasi negatif diabaikan | `Local.php:1775` | [K] (CHECK 0..120 v2) |
| P5-08 | Jendela hard-code | `Local.php:1780-1781`, `:1927-1928` | [K] F5-11 konstanta `Config\Presensi` |
| P5-09 | Pulang last-wins, masuk first-wins | `Local.php:1929-1948`, `:1783-1786` | [K] F5-11 |
| P5-10 | Pulang tanpa masuk membuat baris `jam_masuk` NULL | `Local.php:1951-1971` | [K] |
| P5-11 | Tanpa cek libur/cuti/konket/aktif saat absen | po_in/po_out | [K] F5-13 |
| P5-12 | DoD menyebut legacy "hanya app logic" padahal PK `(nip,tgl)` | D1:398 | §5.3 pemetaan |
| P5-13 | Perubahan state via GET (jenis: CSRF/state-changing GET) | `dashboard_pns.php:819-848` | [V2] POST + JWT |
| P5-14 | Koordinat klien dipercaya, dicatat sesudah sukses (jenis: data klien dipercaya) | `Local.php:4071-4123` | [V2] koordinat bagian request & divalidasi |
| P5-15 | Tanpa selfie/ikatan perangkat | — | [K] keputusan "tidak" |
| P5-16 | `preview_po` mengecualikan role 7 | `Local.php:2062` | [V2] 2/6/7 seragam |
| P5-17 | Tanpa sesi dijawab 404 | `Local.php:1748-1752` | [V2] 401 standar |
| P5-18 | SQL dirangkai string (jenis: injection) | beberapa berkas | [V2] Query Builder + binding |
| P5-20 | Hak akses via `pengguna.id` hard-code | `constants.php:324-325` | F5-14 dipertahankan, dipindah ke `Config\Presensi` |
| P5-21 | Seluruh `$_POST` diteruskan (jenis: mass assignment) | `L_presensi.php:3470-3478` | [V2] whitelist |
| P5-22 | Tanpa validasi format/urutan jam/tanggal masa depan | `L_presensi.php:3434-3466` | [V2] F5-14 |
| P5-23 | Edit tidak bisa mengubah tanggal | `Presensi.php:1376-1378` | [K] |
| P5-24 | Hapus fisik + trigger arsip; hapus via GET | `Presensi.php:1415-1455`; D1:7712-7714 | [K] hapus fisik + [V2] DELETE + audit |
| P5-25 | Insert tanpa `created_by` | `L_presensi.php:3476` | [V2] usulan `created_by` (DBV-Q3) |
| P5-26 | Cabang role 3 mati & tanpa cakupan di daftar presensi admin | `Presensi.php:1166`, `:1242` | [V2] F5-15 + `PegawaiScope` |
| P5-27 | Manual tidak dibedakan dari online | D1:391-401 | [V2] F5-12 `sumber` |
| P5-30 | Skema Slip Gaji kode legacy tidak ada di D1 produksi | D1:1242-1273; git `4268a96` | F5-2 + Q5A-02 |
| P5-31 | `771f8b4` ≠ baseline README `39b6b15` (uang makan, tukin kelas jabatan) | `Lsl_gaji.php:186-202`, `:416-446` | F5-1 (§7.3.7, §7.3.8) |
| P5-32 | Admin membuka slip atas nama pegawai | `Sl_gaji.php:80-91` | [V2] buka hanya pemilik |
| P5-33 | Admin tanpa NIP mendapat slip "contoh" baris terbaru | `Sl_gaji.php:30-35` | [V2] periode & pegawai wajib eksplisit |
| P5-34 | Buka tidak atomik | `Lsl_gaji.php:157-220` | [V2] UPDATE bersyarat + transaksi |
| P5-35 | Cetak pegawai "sesi aktif" hanya di klien | `Sl_gaji.php:194-196`; `cfa66c4` | [K] server: cetak selama dibuka; FE ikut layar legacy |
| P5-36 | QR `no-token` | `Lsl_gaji.php:510` | [V2] tanpa QR |
| P5-37 | Unggah ke direktori publik, validasi lemah (jenis: unggah tidak aman) | `Sl_gaji.php:436-464` | [V2] §7.2.2 |
| P5-38 | Commit memercayai baris klien | `Sl_gaji.php:470-489`; `Lsl_gaji.php:1237-1351` | [V2] F5-3 validasi ulang |
| P5-39 | Pesan exception mentah (jenis: kebocoran informasi) | `Lsl_gaji.php:1071`, `:1281`, `:1315` | [V2] pesan generik + log |
| P5-40 | Log mismatch ditulis ulang tiap pratinjau | `Lsl_gaji.php:1155-1172` | [V2] sekali per pratinjau, `audit_logs` (F5-4) |
| P5-41 | Verifikasi publik tanpa throttle/validasi format, menampilkan data lebih (jenis: enumerasi & kebocoran data) | `Lsl_gaji.php:650-656`; `views/verify/slip.php:96-108` | [V2] F5-6/F5-7, AS-06 |
| P5-42 | Role 3 tanpa cakupan satker | `Sl_gaji.php:214-496` | [V2] SG-2 |
| P5-43 | Buka ulang mempertahankan snapshot uang makan | `Lsl_gaji.php:186-202` | [K] |
| P5-44 | Stempel ditulis string WIB | `Lsl_gaji.php:166`, `:827` | [V2] UTC |
| P5-45 | Uang makan tidak bisa dikoreksi | `Lsl_gaji.php:810` | [K] |
| P5-46 | Nama berkas PDF tanpa sanitasi (jenis: header/nama berkas) | `Lsl_gaji.php:596` | [V2] `FormatSlip::namaBerkasPdf` |
| P5-47 | Konteks lama menyebut `qr_history` "FK nip" | SQL-L `2026_09_02:28-40` | Dikoreksi: FK `id_gaji_pegawai` |
| P5-48 | Pratinjau gagal hanya bila kedua sheet hilang | `Lsl_gaji.php:1074-1079` | [V2] keduanya wajib (I-1) |
| P5-49 | Bulan tidak valid jatuh diam-diam ke periode terakhir | `Sl_gaji.php:44-50` | [V2] galat berkode (G-6) |

### Lampiran C — Matriks keterlacakan (pemeriksaan kelengkapan)

#### C.1 DoD Trello → AC
| DoD | AC | | DoD | AC |
|---|---|---|---|---|
| 076-1 `dh_online` & `gaji_pegawai` dibuat | AC-D01.1, AC-D01.4 | | 085-1 upload → preview → commit | AC-D10.1 |
| 076-2 UNIQUE `(nip,tgl)` di DB | AC-D01.2, §5.3 | | 085-2 mismatch nama-NIP di-log | AC-D10.2 |
| 076-3 volume histori final | AC-D01.5, F5-2, Q5A-02 | | 085-3 duplikat NIP+periode ditolak saat preview | AC-D10.3 |
| 076-4 review & approve DBV | AC-D01.6, §7.1.4 | | 085-4 UNIQUE violation pesan jelas | AC-D10.4 |
| 076 dep "Fase 4 DONE" | §5.3 (dikecualikan P-1), §3.5 | | 085-5 valid ter-commit | AC-D10.5 |
| 077-1 dalam radius tersimpan | AC-D02.1, AC-D02.6 | | 085-6 hanya role 1 | AC-D10.6 (SG-2) |
| 077-2 luar radius ditolak pesan jelas | AC-D02.2 | | 086-1 gate tgl 4 10:00 WIB | AC-D11.1 |
| 077-3 zonasi per satker | AC-D02.3 | | 086-2 hanya slip sendiri | AC-D11.2 |
| 077-4 status GPS/lokasi tervisualisasi | AC-FE.1 | | 086-3 cetak/unduh PDF setelah gate | AC-D11.3, AC-D11.11 |
| 077 actor 1,2,3,6,7 | AC-D02.8 (F5-9: 2/6/7) | | 086-4 unit test gate sebelum & sesudah | AC-D11.1, `GerbangSlipTest` |
| 078-1 akun lain ditolak termasuk role 1 | AC-D03.1 | | 086 role 1,2 | AC-D11.2 (SG-2) |
| 078-2 `nip` & `tgl` wajib | AC-D03.2 | | 079 (5A) periode BKN/16–15, libur & akhir pekan | AC-TK.1 |
| 078-3 minimal satu jam | AC-D03.3 | | 079 rumus cross-check resmi | Q5A-13 (5B) |
| 078-4 duplikat ditolak (UNIQUE) | AC-D03.4 | | 080 (5A) jam dari `web_config`, 4 kombinasi | AC-TK.2 |
| 078-5 audit WAJIB | AC-D03.5 | | 082 (5A) tarif `web_config` | AC-TK.3 (boundary 30/60 = test murni CR-037/039) |
| D-14 (kartu baru) verifikasi publik | AC-D14.1–14.7 | | 087 (5A) FE presensi/slip, map picker | AC-FE.1–FE.9, Q5A-10 |
| 088 (5A) impor slip mismatch/duplikat/constraint | AC-D10.2–10.4 | | 088 (5A) gerbang waktu | AC-D11.1 |
| 088 (5A) ubah tarif `web_config` | AC-TK.4 | | 081/083/084, 088 skenario lain | 5B/5C (§1.2) |
| DEV-006 WAJIB UNIQUE `(nip,tgl)` | AC-D01.2 | | DEV-006 WAJIB D-03 hanya 4675/4676 | AC-D03.1 |
| DEV-006 WAJIB tarif & jam dari `web_config` | AC-TK.2, AC-TK.3 | | DEV-006 prasyarat rumus/Horii | Q5A-13 |

QAUI-005 (bagian 5A): map picker + indikator radius → AC-FE.1 (+ Q5A-10); layar presensi mobile → AC-FE.1, AC-FE.8; preview impor tabel validasi baris error ditandai → AC-FE.2; slip pegawai + cetak/unduh PDF → AC-FE.3, AC-D11.3; menu per role → AC-FE.6; tabel laporan tukin, color-coding, filter laporan → 5B/5C. QAFUNC-006 (bagian 5A): mismatch di-log → AC-D10.2; duplikat saat preview → AC-D10.3; UNIQUE pesan jelas → AC-D10.4; gate sebelum & sesudah → AC-D11.1; pegawai hanya slip sendiri → AC-D11.2; regression ubah tarif → AC-TK.4; skenario kalkulasi & laporan tukin → 5B/5C. **Hasil: semua butir DoD 076/077/078/085/086, kartu D-14, dan bagian 5A dari 079/080/082/087/088 + QAUI-005/QAFUNC-006 punya AC; butir non-5A ditandai pemiliknya.**

#### C.2 Validasi prompt eksekusi lama DEV-006 (1/2 & 2/2)
| Butir prompt | Tercakup di |
|---|---|
| Ikuti `05-Presensi.md` | Cara pakai #2 (PRD + keputusan menang), §5.3 |
| Pastikan Fase 2/3/4 DONE | §5.3 + P-1 (Fase 4 dikecualikan), §3.5 (DBV-022 di `main`) |
| DBV review sebelum migration | §7.1.4, AC-D01.6, §10 M1–M2 |
| Eskalasi rumus Tukin | Q5A-13, §7.7 (rumus tidak diubah) |
| Unit test per skenario | §7.9, test wajib per task |
| Tarif & jam dari `web_config` | §7.7.2–7.7.3, AC-TK.2–TK.4 |
| Matriks ketat | §6, AC-D02.8, AC-D03.1, AC-D10.6, AC-D11.2, §11.1 #4 |
| Geofence + timezone satker | §7.5.2–7.5.4, AC-D02.1–02.3 |
| Export tukin besar synchronous | 5B (§1.2, §9) |
| Slip gaji preview → validation → commit | §7.2, AC-D10.1 |
| Validasi boundary TL/PSW 30 & 60, precedence, kombinasi bertumpuk | Murni CR-037/039 (sudah di `main`); precedence/kombinasi = 5B/5C |
| Validasi weekend/libur/cuti/TB/konket/`web_config` | AC-TK.1, AC-TK.4, AC-TK.5 (cuti/TB = 5B) |
| Validasi geofence/timezone/duplikat/impor slip/gate waktu | AC-D02.*, AC-D03.4, AC-D10.*, AC-D11.1 |
| D-13 regression | §7.9 (bagian 5A), 5C |
| `check.sh` | §2.4, §11.1 #2 |
| Final report D-01..D-13 | §11.4 (5A: D-01/02/03/10/11/14 + 5A-4 + FE) |
| Jangan DONE sebelum tervalidasi + siap sign-off Horii | §11.1, §11.2 |
| Requirement belum jelas → eskalasi | Cara pakai #3, §13 |
| Sesi berhenti → IN_PROGRESS + progres | §11.3 |

#### C.3 Keputusan → bagian
| ID | Bagian | | ID | Bagian |
|---|---|---|---|---|
| P-1 | §1, §3, §5.3, §9 | | F5-11 | §7.5.2, §7.5.5, §7.5.6 |
| P-2 | §2.2, §10 | | F5-12 | §7.1.1, §7.5.4, §7.6.3 |
| P-3 | Header, §2.2, §7.1, §10 | | F5-13 | §7.5.1, AC-D02.8 |
| P-4 | Header | | F5-14 | §7.6, AC-D03.* |
| F5-1 | §7.3.6–7.3.8, §11.2 | | F5-15 | §6.4, §7.6.4 |
| F5-2 | §7.1.1, AC-D01.4–01.5 | | F5-16 | §7.7.2 |
| F5-3 | §7.2.2–7.2.5 | | F5-17 | §8.4, §3.3 |
| F5-4 | §7.1.1 (4), §7.2.3 #5, AC-D10.2 | | F5-18 | §3.5, §7.8.2 |
| F5-5 | §7.2.4 #1, §7.8.1, AC-FE.2 | | F5-19 | §2.8, §3 |
| F5-6 | §7.1.1, §7.3.3, §7.4.1, Q5A-01 | | F5-20 | §7.4, Q5A-02 |
| F5-7 | §6.7, §7.4.2, Q5A-08 | | F5-21 | §7.8, Q5A-18 |
| F5-8 | §6.2, §7.3.4, Q5A-06 | | F5-22 | §3.6, Q5A-20 |
| F5-9 | §6.4, §7.5.1, §7.5.4 | | Tanpa keputusan (FCM, precedence, selfie, AS-09) | §1.2, §9, Q5A-14 |
| F5-10 | §7.5.3–7.5.4 | | | |
Hasil: **semua P-1..P-4 dan F5-1..F5-22 tercermin** di bagian terkait.

#### C.4 Konflik K5-01..K5-22
| K | Penyelesaian | | K | Penyelesaian |
|---|---|---|---|---|
| K5-01 urutan fase | P-1 | | K5-12 cakupan aksi D-03 | F5-14 (tambah ikut) |
| K5-02 hak Slip Gaji | SG-2 (§5.3, §6.1–6.2) | | K5-13 sumber jam/puasa | F5-16 (§7.7.2) |
| K5-03 opsi timpa | F5-5 | | K5-14 nama kolom zonasi | `satker.zonasi` (§7.5.2); errata Q5A-20 |
| K5-04 modul admin | F5-8 | | K5-15 precedence tukin | K-1, 5B |
| K5-05 uang makan & total | F5-1 | | K5-16 tier migrasi `dh_online` | Mapping (Tier 3) di DBV-023 §impor |
| K5-06 skema `gaji_pegawai` | F5-2 | | K5-17 penyimpanan token | F5-6, Q5A-01 |
| K5-07 PK `dh_online` | PK komposit [K] (§7.1.1); errata Q5A-20 | | K5-18 data verifikasi | F5-7, AS-06, Q5A-08 |
| K5-08 tabel pendamping | F5-12 | | K5-19 library | F5-17, ADR-032 §8.4 |
| K5-09 tempat geofence | F5-9, F5-10 | | K5-20 tanggal di PDF | P-5 [K] tanpa jam (§7.3.6) |
| K5-10 aktor presensi | F5-9 | | K5-21 spek tertinggal | PRD ini menggantikan; revisi Q5A-20 |
| K5-11 identitas akun khusus | F5-14 (`pengguna.id`) | | K5-22 penandatangan | Horii (§11.2) |

#### C.5 Pertanyaan spek Q-01..Q-21
Q-01 → P-1; Q-02 → F5-5; Q-03 → F5-10 (+ Q5A-12); Q-04 → F5-12; Q-05 → F5-11; Q-06 → F5-9; Q-07 → F5-11 + AS-12 (DBV-Q14); Q-08 → F5-14; Q-09 → F5-14 (hapus fisik); Q-10 → F5-6 + Q5A-01; Q-11 → F5-7; Q-12 → Q5A-07; Q-13 → F5-1; Q-14 → F5-16; Q-15 → F5-7 + Q5A-08; Q-16 → F5-2 + Q5A-02; Q-17 → P-3; Q-18 → F5-8; Q-19 → F5-21 + Q5A-18; Q-20 → Fase 6 (§9); Q-21 → Q5A-14. **Hasil: semua terjawab atau masuk §13.**

#### C.6 Eskalasi riset legacy E-01..E-15
E-01 → F5-1; E-02 → F5-9; E-03 → F5-10; E-04 → F5-10 (`≤`, akurasi dicatat); E-05 → F5-11; E-06 → F5-12; E-07 → keputusan "selfie/ikatan perangkat: tidak" (§1.2); E-08 → F5-12 (`sumber`); E-09 → F5-2 + Q5A-02; E-10 → F5-3; E-11 → F5-13; E-12 → F5-14; E-13 → F5-14; E-14 → F5-15 + Q5A-09; E-15 → P-1, P-3. **Hasil: semua terjawab.**

#### C.7 Pertanyaan DBV DBV-Q1..DBV-Q17
DBV-Q1 → usulan PK komposit, DBV (Q5A-03); Q2 → K4/ADR-008; Q3 → F5-12 (`sumber`) + DBV (`created_by`, Q5A-03); Q4 → F5-14 + Q5A-15; Q5 → DBV (Q5A-03); Q6 → F5-12 + DBV tipe/FK (Q5A-03); Q7 → F5-12; Q8 → F5-2; Q9 → DBV (Q5A-03); Q10 → DBV (Q5A-03); Q11 → usulan RESTRICT + UNIQUE, DBV (Q5A-03); Q12 → Q5A-04; Q13 → F5-3/F5-4; Q14 → F5-11 + AS-12; Q15 → `utf8mb4_unicode_ci`; Q16 → F5-4 + DBV (Q5A-03); Q17 → AS-06/Q5A-01 + DBV. **Hasil: semua terjawab keputusan atau menjadi tugas DBV di M1 (Q5A-03).**

#### C.8 Hasil pemeriksaan kelengkapan (dilakukan penyusun, 19-10-2026)
- Struktur wajib 0–14 ada (Header + Cara pakai, §1–§14).
- Setiap DoD QASMTASK-076/077/078/085/086 (semua butir), kartu D-14, dan bagian 5A dari 079/080/082/087/088 → AC (C.1); validasi prompt lama → bagian (C.2).
- Setiap F5-1..F5-22 & P-1..P-4 → bagian (C.3); K5-01..22 (C.4), Q-01..21 (C.5), E-01..15 (C.6), DBV-Q1..17 (C.7) → keputusan atau §13.
- Celah yang ditemukan saat pemeriksaan dan dimasukkan ke §13 (bukan ditebak): sumber token mentah untuk cetak ulang QR (Q5A-01), batas impor (Q5A-05), templat (Q5A-06), `viewed_ip` (Q5A-07), format nama tersamar (Q5A-08), peran lihat presensi (Q5A-09), peta tile (Q5A-10), lokasi tidak sah (Q5A-11), koordinat wajib (Q5A-12), koordinat saat ubah manual (Q5A-16), "hari ini" manual (Q5A-17), URL dasar QR (Q5A-21), pegawai tanpa satker (Q5A-24).
- Tidak ada rujukan ke berkas kerja riset; tidak ada IP/host internal, kredensial, data pribadi nyata, atau rincian eksploit.

— Akhir dokumen —
