# PRD Fase 7 (paket pertama) — F-03 Widget Statistik Kepegawaian & F-09 Generator Dokumen (SIMPEG v2)

| | |
|---|---|
| Versi | 1.0 — 21-10-2026 (draf untuk dikunci Horii; acuan `main` `2cd3fa0`) |
| Modul | F — Dashboard & News (Fase 7), **paket tanpa skema** |
| Task | **F-03** Widget Statistik Kepegawaian, **F-09** Generator Dokumen (DUK, Nominatif, Proyeksi Pensiun, Baperjakat) |
| Key | Pekerjaan baru: **MAKE-036** (F-03, M1–M2), **MAKE-037** (F-09, M3–M4). Perbaikan review/QA: CR-### berikutnya dari reviewer CR. **Tanpa DBV** (tidak ada tabel/kolom/index baru) |
| Branch | `fase7/make-036-statistik`, `fase7/make-037-generator-dokumen` |
| Sesi pelaksana | Worker Fable |
| Acuan legacy | Salinan `1_legacy_simpeg…` (HEAD `771f8b4` + modifikasi lokal) — path legacy di dokumen ini relatif `application/` |
| Riset pendukung | `f7/legacy-chart.md`, `f7/legacy-dokumen.md`, `f7/repo-spek.md` (scratchpad reviewer; tidak di repo) |

## Cara pakai (WAJIB dibaca)

1. Dokumen ini adalah **satu-satunya sumber kebenaran** untuk paket F-03 + F-09. Bila spek lain (`Bahan Baku SIMPEG/07-Dashboard.md`, Technical Spec, mockup `67fa767`) berbeda, dokumen ini yang menang; perbedaan dicatat di §14.B.
2. Keputusan di §5 sudah **LOCKED** (Horii, 20-10-2026, setelah review reviewer CR). Jangan dibuka ulang; butuh perubahan → tulis pertanyaan di laporan milestone, tunggu jawaban.
3. **Jangan menebak.** Bila sumber tidak cukup, hentikan bagian itu dan jadikan pertanyaan (§13). Keputusan produk → eskalasi ke Horii lewat reviewer CR.
4. F-10 Arsip Digital **DITUNDA** (keputusan Horii 20-10): v2 belum punya konfigurasi server Arsip resmi; alamat/kredensial legacy tidak boleh dipakai.
5. Urutan kerja: §10 (runbook M1–M4). Setiap milestone diakhiri laporan (§11.4) dan ⛔ menunggu reviewer CR.

---

## 1. Scope

### 1.1 Di dalam scope

| Task | Isi | Key |
|---|---|---|
| F-03 | 7 statistik agregat pegawai (unit kerja, jenis kelamin, jabatan, eselon, pendidikan, usia, golongan) per lingkup kementerian/unit/satker; respons JSON untuk chart + tabel; ekspor XLSX per statistik; cache file F0-14 dengan invalidasi saat data pegawai/snapshot berubah; halaman FE `/laporan/:tipe` | MAKE-036 |
| F-09 | 4 dokumen daftar: DUK, Nominatif, Proyeksi Pensiun, Baperjakat — JSON (halaman tabel), PDF lanskap sinkron, XLSX; kop dari `web_config`; audit unduhan; halaman FE `/dokumen/:jenis` | MAKE-037 |
| Bersama | `Config/RoutesDashboard.php`, helper kop `Libraries/Pdf/KopDokumen`, komponen `UiBarChart` (dari mockup), entri nav/route aditif, `backend/docs/progress/07-Dashboard.md` | — |

### 1.2 DI LUAR scope (DILARANG dikerjakan atau dilaporkan sebagai bagian paket ini)

| Di luar scope | Milik | Catatan |
|---|---|---|
| F-01 migration dashboard (popup, banner, form digital, news, SKL, DIBO), F-02 sortir inbox, F-04..F-08, F-11, F-12, dashboard per role (`HomeView` admin/pegawai), F-13 selain halaman laporan/dokumen | Fase 7 paket berikutnya | Butuh DBV / Fase 4 |
| F-10 Arsip Digital | Ditunda | Keputusan Horii 20-10 |
| Statistik legacy lain: `fungsional`, `agama`, `detail_jf`, `kinerja`, `download_all` (xls 11 sheet), PDF/print statistik | — | Tidak ada di DoD F-03 (`07-Dashboard.md:33-43`) |
| Nominatif KP & KGB (`hr/document/kp`, `kgb`), Tanda Jasa, Alamat Struktural, Ulang Tahun | — | Tidak ada di DoD F-09; pertanyaan §13 Q-7 |
| Cron `rekomendasi_pensiun` (`services/s_stat/*`) | Fase 6 H-08 | F-09 hanya daftar, bukan notifikasi |
| Mekanisme flag `rilis` di nav, `ACTIVE_PHASE` | 5A / Fase 4 M7 / 5C | **DILARANG** dibuat atau diubah di paket ini (F7-14) |
| Penambahan `phpoffice/phpspreadsheet` atau perubahan `composer.json`/`composer.lock` | 5A MAKE-030 | Excel = `EksporTabel` yang sudah ada (F7-11) |
| Nilai ENUM `audit_logs.event` baru | DBV-023 (5A) sedang mengubahnya | Bila butuh → STOP & lapor (F7-27) |
| Manual Testing MT-00x / MTC-0xx | Fase tersendiri | Hanya referensi |

---

## 2. Aturan proyek WAJIB

### 2.1 UI aksi baris tabel (AGENTS.md §1)
Halaman laporan/dokumen tidak punya aksi per baris selain tautan DRH (Nominatif/DUK). Bila ada aksi baris → `RowActionsMenu` (⋮), label "Lihat DRH", `testid` `dokumen-actions-<nip>`. Tombol level halaman (Unduh PDF/XLSX, filter lingkup) bukan aksi baris.

### 2.2 Commit, key, branch, PR, merge
- Pesan Bahasa Indonesia `feat(dashboard): MAKE-036 …` / `fix(dashboard): CR-### …`; **tanpa menyebut AI** dan tanpa trailer `Co-Authored-By` (AGENTS.md §2).
- Satu milestone = satu branch dari `origin/main` terbaru (`git fetch` dulu) → gate cepat lokal → push → CI `quality-gate` hijau → lapor (§11.4) → ⛔ reviewer CR me-merge. Coder **tidak** push ke `main`.
- Berkas bersama (§3.3) diubah di **commit terpisah** berjudul jelas.

### 2.3 Rahasia & repo publik
Tidak ada IP/host/kredensial, tidak ada isi `application/config/constants.php` legacy (berisi kredensial produksi), tidak ada NIP/nama pegawai nyata di test, fixture, dokumen, atau pesan commit. Data uji sintetis.

### 2.4 Quality gate
`./check.sh` (PHPStan level 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build) lolos lokal **dan** CI hijau untuk SHA yang dilaporkan.

**Kontrak Matriks role (MAKE-038, `main` ≥ `2cd3fa0`).** `backend/tests/feature/KontrakMatriksRoleTest.php` mewajibkan setiap route `api/v1` di prefiks yang dicakup (`PREFIKS_CAKUPAN`) punya baris kontrak role eksplisit; tes sapu 401/403 berjalan atas kontrak itu. Wajib untuk paket ini, **di commit yang sama dengan route-nya**:
- Tambahkan prefiks `statistik` dan `dokumen` ke `PREFIKS_CAKUPAN`.
- Tambahkan baris kontrak untuk setiap route §6 (`'GET api/v1/statistik/lingkup' => [1, 3, 4, 5, 8]`, `'GET api/v1/statistik/([^/]+)' => [1, 3, 4, 5, 8]`, `'GET api/v1/statistik/([^/]+)/xlsx' => …`, `'GET api/v1/dokumen/(duk|nominatif|pensiun|baperjakat)' => …`, `…/pdf`, `…/xlsx`, `'POST api/v1/dokumen/baperjakat/(pdf|xlsx)' => …`) dengan komentar rujukan baris Matriks v2 (`hr/chart/*`, `hr/document/*` = 1,3,4,5,8).
- Bila paket ini menambah route di prefiks yang sudah dicakup (mis. `pegawai/…`), tambahkan barisnya juga.
`KontrakMatriksRoleTest` adalah berkas bersama (§3.3): perubahan hanya aditif.

### 2.5 Test database
DB uji hanya scratch MySQL 127.0.0.1:3307 bernama **`simpeg_v2_t_wf_make036`** / **`simpeg_v2_t_wf_make037`**; drop setelah milestone selesai. **DILARANG** menyentuh `simpeg_v2`, `simpeg_v2_testing`, `simpeg_v2_t_ws2_*`, atau DB sesi lain. Jangan menyalin `.env` dev ke worktree; tulis `database.tests.*` eksplisit.

### 2.6 Skema
Tidak ada migration. Paket ini hanya **membaca** `pegawai`, snapshot `pegawai_*`, master, `web_config`, dan **menulis** `audit_logs` dengan nilai ENUM yang sudah ada. Bila ternyata butuh tabel/kolom/index/ENUM baru → **STOP & lapor** (F7-26, F7-27).

### 2.7 Kontrak beku = aditif
Kontrak API Fase 1–3 tidak berubah. Endpoint baru ikut format respons ADR-001 (`status`, `data`, `message`, `errors`), parameter daftar lewat `App\Libraries\ListQuery` (CR-016).

### 2.8 Lapisan
Controller tipis; kalkulasi, kueri agregat, scoping, cache di `App\Libraries\Dashboard\**` (ADR-005). Kalkulasi murni (bucket usia, urutan DUK, kolom dokumen) tanpa akses DB dan ber-unit-test.

---

## 3. ATURAN PARALEL & ANTI-TABRAKAN dengan Fase 4 dan 5A

Fase 4 (coder Wahyu, `docs/fase4/PRD_Fase4.md`) dan 5A (sesi WS-2, `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`) berjalan bersamaan. Aturan berikut **wajib**; pelanggaran = berhenti & lapor.

### 3.1 Berkas MILIK paket ini
`backend/app/Config/RoutesDashboard.php`, `backend/app/Controllers/Api/Dashboard/**`, `backend/app/Libraries/Dashboard/**`, `backend/app/Models/Dashboard/**`, `backend/app/Libraries/Pdf/KopDokumen.php`, `backend/app/Views/pdf/{duk,nominatif,pensiun,baperjakat}.php`, `backend/tests/Dashboard/**`, `backend/tests/unit/Dashboard/**`, `backend/tests/database/Dashboard/**`, `backend/docs/progress/07-Dashboard.md`, `frontend/src/features/laporan/**`, `frontend/src/features/dokumen/**`, `frontend/src/shared/ui/UiBarChart.vue` (+ spec), `docs/fase7/**`.

### 3.2 Berkas MILIK fase lain — DILARANG disentuh
- Fase 4: `backend/app/Libraries/Layanan/**`, `Controllers/Api/Layanan/**`, `Models/Layanan/**`, `Config/RoutesLayanan.php`, `Config/Cuti.php`, `Config/Layanan.php`, `Interfaces/StatusTugasBelajarInterface.php`, migration/seed Fase 4, `docs/db-review/DBV-022-*`, `docs/progress/04-Layanan.md`, `tests/Layanan/**`, `frontend/src/features/layanan/**`, `docs/fase4/**`.
- 5A: `Config/RoutesPresensi.php`, `Config/Presensi.php`, `Controllers/Api/Presensi/**`, `Libraries/Presensi/**`, `Models/Presensi/**`, `Filters/ThrottlePublikFilter.php`, `Views/pdf/slip_gaji.php`, migration 5A, `tests/Presensi/**`, `docs/db-review/DBV-023-*`, `docs/progress/05-Presensi.md`, `docs/adr/ADR-032-*.md`, `frontend/src/features/presensi/**`.
- Keduanya: `backend/composer.json`, `backend/composer.lock`, `backend/app/Config/Services.php`, `backend/app/Config/WebConfig.php`, `backend/app/Libraries/Kepegawaian/NipCascadeService.php`, `Libraries/Kepegawaian/Nip/TabelAnakNip.php`, `.github/workflows/quality-gate.yml`.

### 3.3 Berkas BERSAMA — hanya ADITIF, commit TERPISAH
| Berkas | Perubahan yang diizinkan |
|---|---|
| `backend/app/Config/Routing.php` | Tambah satu entri `APPPATH . 'Config/RoutesDashboard.php'` di `$routeFiles` (hunk sama dengan Fase 4/5A → saat konflik pertahankan semua sisi) |
| `backend/tests/feature/KontrakMatriksRoleTest.php` | Tambah prefiks `statistik`, `dokumen` ke `PREFIKS_CAKUPAN` + baris kontrak role per route §6 (§2.4), di commit yang sama dengan `RoutesDashboard.php` |
| `backend/app/Config/Events.php` | Tambah satu listener `Events::on(RiwayatEngine::EVENT_NOTIFIKASI, [StatistikCache::class, 'invalidasiDariEvent'])` (F7-12a) |
| `backend/app/Models/Kepegawaian/PegawaiModel.php` | Tambah handler ke `$afterInsert`, `$afterUpdate`, `$afterDelete` yang memanggil `StatistikCache::invalidasi()` (F7-12b); handler audit yang ada tidak diubah |
| `backend/app/Libraries/Kepegawaian/Pegawai/SiklusPegawai.php` | **Hanya bila** terbukti jalur tulis raw yang tidak lewat (a)/(b): tambah satu panggilan `StatistikCache::invalidasi()` setelah commit. Kalau ragu → lewati, andalkan TTL (F7-12c) |
| `frontend/src/router/index.ts` | Tambah impor + spread `laporanRoutes`, `dokumenRoutes` di tempat `pegawaiRoutes`/`riwayatRoutes` |
| `frontend/src/shared/layouts/nav.config.ts` + `__tests__/nav.config.spec.ts` | Item `laporan` (sudah ada, `phase: 7`): tambah `roles: [1,3,4,5,8]` dan 4 child baru (jabatan, pendidikan, usia, golongan); item baru `dokumen` (`phase: 7`, `roles` sama, 4 child). **`ACTIVE_PHASE` dan field `rilis` DILARANG disentuh** |
| `frontend/src/shared/ui/index.ts` | Ekspor `UiBarChart` |
| `docs/adr/README.md` | Tambah satu baris bila addendum ADR-033 ditulis (§8.4) |

### 3.4 Titik tabrakan kritis
1. **`NipCascadeService`** sedang diubah Fase 4 M2 dan 5A M2 → **tidak disentuh** (keputusan reviewer). Koreksi NIP tidak mengubah agregat; TTL 900 detik menjadi pengaman.
2. **`audit_logs.event`** sedang diubah DBV-023 (5A). Paket ini memakai nilai ENUM yang ada di `main` (`create`, `update`, `delete`, `login`, `logout`).
3. **`nav.config.ts`**: Fase 4 M7 menaikkan `ACTIVE_PHASE` 3→4; 5A menambah `rilis`/`roles` di `NavChild`. Perubahan paket ini hanya pada item `laporan`/`dokumen`.
4. **`composer`**: tidak disentuh sama sekali. PDF = TCPDF yang sudah ada; XLSX = `EksporTabel`.

### 3.5 Urutan merge
M1 → M2 (MAKE-036) → M3 → M4 (MAKE-037). Tidak bergantung pada Fase 4 maupun 5A; bila 5A merge lebih dulu dan `nav.config.ts` konflik → rebase, pertahankan kedua sisi, jalankan `nav.config.spec.ts`.

### 3.6 Menangani konflik
Konflik teks di berkas bersama → pertahankan kedua sisi, jalankan test terkait. Konflik **makna** (kontrak/ENUM/perilaku) → **BERHENTI**, lapor reviewer CR dengan SHA kedua sisi, jangan "memperbaiki" kode fase lain.

---

## 4. Glosarium & label

| Istilah | Arti |
|---|---|
| Lingkup | Cakupan statistik/dokumen: `kementerian` (dirinci per unit eselon I), `unit` (dirinci per satker di bawah unit itu), `satker` (satu satker) — padanan legacy `0` / `id_unit` / `sat_<id>` (`controllers/hr/Chart.php:34`) |
| Snapshot | Tabel `pegawai_*` (PK `nip`, satu baris per pegawai) = riwayat aktif resmi v2 (ADR-006, `SnapshotSync`), padanan trigger legacy |
| Populasi statistik | `pegawai.status = 1` AND `id_jenis_pegawai IN (1, 6)` (PNS, PPPK) |
| BUP | Batas usia pensiun, `Libraries\Kepegawaian\Kalkulasi\Pensiun::batasUsia()` |
| [K] | Ikut legacy terkonfirmasi (kode/DDL) · [V2] deviasi disengaja · [I] dugaan, harus diverifikasi |
| DoD | Definition of Done Trello/`07-Dashboard.md` |

---

## 5. Keputusan (LOCKED) & pemetaan DoD

### 5.1 Pola umum (keputusan Horii 20-10-2026)
Hak LIHAT ikut Matriks v2 (role 1, 3, 4, 5, 8 untuk semua endpoint paket ini). Alur data ikut legacy [K] kecuali deviasi [V2] di tabel. Tidak ada tabel baru. Perubahan reviewer CR (20-10) sudah dimasukkan: F7-12, F7-15, F7-17, F7-27.

### 5.2 Tabel keputusan

| No | Topik | Keputusan | Label |
|---|---|---|---|
| F7-1 | Cakupan F-03 | 7 statistik: `unit-kerja`, `jenis-kelamin`, `jabatan`, `eselon`, `pendidikan`, `usia`, `golongan`. Statistik legacy lain tidak dibuat | DoD |
| F7-2 | Parameter lingkup | `?lingkup=kementerian\|unit\|satker&id=<id>`; `id` wajib untuk unit/satker. Default `kementerian` untuk role 1/4/5/8; untuk role 3 default = satker akun (bila ada) atau unit akun | [V2] format, [K] semantik |
| F7-3 | Scoping | `PegawaiScope::terapkanKeQuery($builder, $auth, 'pegawai.nip')` pada setiap builder; daftar lingkup yang boleh dipilih dihitung dari scope yang sama. Lingkup di luar hak → **403** | [K] + Matriks v2 |
| F7-4 | Populasi | Status 1, jenis pegawai 1 (PNS) dan 6 (PPPK); PTT (2) dan lainnya tidak dihitung; seri PNS/PPPK pada statistik unit kerja | [K] `L_chart.php:41` |
| F7-5 | Akun uji | Tidak ada pengecualian `NIP_TESTER` di v2 | [V2] |
| F7-6 | Bucket usia | `≤30`, `31–40`, `41–45`, `46–50`, `51–56`, `57–60`, `>60`; umur = `TIMESTAMPDIFF(YEAR, tgl_lahir, hari ini)`; tampil sebagai chart + tabel | [V2] (legacy aktif tanpa bucket; bucket = versi legacy 2025-08 dengan perbaikan <18) |
| F7-7 | Golongan | Kolom PNS = `pangkat.gol_ruang` urut `pangkat.order` ASC; prefiks "CPNS " dibuang (CPNS digabung); kolom PPPK = `gol_pppk.gol_pppk` urut `order` setelah PNS; bug `group_by id_satker` legacy tidak ditiru; ada chart | [V2] urutan, [K] isi |
| F7-8 | Eselon | Seri = `sub_group_jabatan` dengan `id_group_jabatan = 1`, status 1, urut `id_sub_group_jabatan`; label apa adanya | [K] |
| F7-9 | Pendidikan | LEFT JOIN `pegawai_pendidikan`; pegawai tanpa snapshot pendidikan masuk kategori **"Tanpa data"** sehingga total = statistik unit kerja | [V2] (legacy INNER JOIN) |
| F7-10 | Unit/satker nonaktif | Hanya `unit.status = 1` / `satker.status = 1` yang menjadi kategori; pegawai di unit nonaktif tidak dihitung | [K] |
| F7-11 | Ekspor statistik | XLSX per statistik via `EksporTabel::xlsx` (satu sheet: judul, nama lingkup, "Periode: <tanggal>", header, baris, SUBTOTAL). Tanpa PDF/print server, tanpa "unduh semua". Tidak menyentuh composer | DoD minimal |
| F7-12 | Cache | Key `dashboard_stat_{jenis}_{lingkup}_{id}_{scope}` dengan `{scope}` = `all` (role 1/4/5/8) atau `s<id_satker>`/`u<id_unit>` akun (role 3); **TTL 900 detik**. Invalidasi `invalidateMatching('dashboard_stat_*')` dari **dua titik**: (a) listener `Events::on(RiwayatEngine::EVENT_NOTIFIKASI)` — mencakup semua transisi riwayat/snapshot; (b) hook `afterInsert/afterUpdate/afterDelete` `PegawaiModel`. (c) `SiklusPegawai` hanya bila terbukti jalur raw tak lewat (a)/(b); kalau ragu lewati. **`NipCascadeService` tidak disentuh** | F0-14, reviewer 20-10 |
| F7-13 | Bentuk respons | `LaporanData`: `kategori[]`, `seri[]`, `baris[]` (nilai per seri + total), `subtotal[]`, `total`, `periode`, `lingkup` — kompatibel mockup `features/laporan/types.ts` | FE |
| F7-14 | FE & rilis menu | Halaman `/laporan/:tipe` (7 tipe) di `features/laporan/`, `/dokumen/:jenis` (4 jenis) di `features/dokumen/`; `UiBarChart` dari mockup ke `shared/ui`. Item nav tetap `phase: 7` (tersembunyi). **DILARANG** membuat flag `rilis` atau mengubah `ACTIVE_PHASE`. QA lewat URL langsung | reviewer 20-10 |
| F7-15 | Keluaran F-09 | Tiap dokumen: JSON (tabel FE, berhalaman) + **PDF lanskap A4 sinkron** (TCPDF via `Pdf::renderer()`, `DokumenPdf`) + XLSX. Kop = `KopDokumen` (pola DRH/LKH: nama kementerian, unit/satker, alamat, tanpa logo). **Uji volume ≥ 5.000 baris sintetis** di DB scratch dengan batas waktu dan memori §7.2.7; bila tidak lolos → PDF **wajib** `lingkup=unit\|satker`, XLSX tetap untuk semua | [V2], reviewer 20-10 |
| F7-16 | Kolom | XLSX = seluruh kolom legacy (DUK/Nominatif 37, Pensiun 11, Baperjakat 37); PDF = subset inti §7.2.3 | [V2] |
| F7-17 | Urutan DUK | **Urutan inti**: golongan (`pangkat.order` DESC) → TMT pangkat ASC → masa kerja (TMT CPNS ASC) → usia (`tgl_lahir` ASC). Kriteria lain (eselon/jabatan, TMT jabatan, diklat, pendidikan) **tidak dipakai** sampai sumber resmi dikutip (§13 Q-1) | [V2], reviewer 20-10 |
| F7-18 | Urutan Nominatif / Baperjakat | Nominatif: `unit.order, satker.order, kelas_jabatan DESC`; Baperjakat: `kelas_jabatan DESC, pangkat.order DESC, tmtsk_pangkat ASC, id_satker ASC` | [K] `L_document.php:87-90`, `L_employee.php:5061-5065` |
| F7-19 | Cakupan dokumen | DUK = PNS aktif (jenis 1, termasuk CPNS); Nominatif = semua aktif (1/2/6); Pensiun = PNS aktif; Baperjakat = aktif saja | [V2] DUK & Baperjakat, [K] lainnya |
| F7-20 | Proyeksi pensiun | `tahun` (default tahun berjalan) dan `sampai` opsional (≥ `tahun`, maks `tahun + 4`); BUP = `Pensiun::batasUsia` (58 / Struktural E.I–II 60 / `jabatan.umur_pensiun`), TMT = `Pensiun::tanggalPensiun`; pegawai tanpa jabatan aktif tidak diberi tanggal pensiun; PPPK tidak masuk | [V2] rentang, [K] rumus `function_helper.php:2400-2418` |
| F7-21 | Baperjakat | Daftar seluruh pegawai aktif (kolom §7.2.3) + pilih `nip[]` + `judul`, `subjudul`, `teks` bebas → PDF/XLSX; tanpa kriteria otomatis | [K] maksud (`views/hr/document/baperjakat.php:13-40`; cetak legacy tidak berfungsi) |
| F7-22 | Scoping dokumen | Role 3 ikut `PegawaiScope` (satker/unit akun); legacy menolak level 3 pada DUK/Nominatif/Baperjakat, Matriks v2 memberi 1,3,4,5,8 | Matriks v2 |
| F7-23 | TMT jabatan/unit | `pegawai_mutasi_jabatan.tmtsk` snapshot; tidak menghitung deretan riwayat berurutan seperti legacy (`L_employee.php:4853-4876`) | [V2] |
| F7-24 | Usia / masa kerja / KGB | Dihitung per `TanggalBisnis::hariIni()`; tanpa parameter "tanggal per" | [K] |
| F7-25 | KP & KGB nominatif | Tidak dibuat | DoD |
| F7-26 | Skema | Tidak ada tabel/kolom/index baru. Bila perlu → STOP & lapor | aturan |
| F7-27 | Audit unduhan | Setiap unduhan PDF/XLSX F-09 dan ekspor XLSX F-03 dicatat ke `audit_logs` lewat `AuditLogModel::record()`: `entity = 'dokumen_unduhan'`, `entity_id = <jenis>` (`duk`, `nominatif`, `pensiun`, `baperjakat`, `statistik_<jenis>`), `event = 'create'` (ENUM yang ada), `after_json = {jenis, format, lingkup, id, parameter, jumlah_baris}`, pelaku dari `AuthContext`. Tanpa nilai ENUM baru; bila ternyata perlu → STOP & lapor | [V2], reviewer 20-10 |

### 5.3 Pemetaan teks DoD → arti v2

| Teks DoD (`07-Dashboard.md`) | Arti di v2 |
|---|---|
| `hr/chart/unit_kerja` distribusi pegawai per unit + export Excel | `GET api/v1/statistik/unit-kerja` (+ `/xlsx`), seri PNS/PPPK per unit/satker |
| `hr/chart/usia, jabatan, gender, eselon, education, gol` | `GET api/v1/statistik/{usia\|jabatan\|jenis-kelamin\|eselon\|pendidikan\|golongan}` |
| Pakai file-based caching (F0-14) | `CacheService::remember` key `dashboard_stat_*`, TTL 900 |
| Cache invalidation berfungsi | Test §7.1.8: tambah pegawai / setujui riwayat → statistik berubah tanpa menunggu TTL |
| DUK, Nominatif, Proyeksi Pensiun, Baperjakat → PDF | `GET api/v1/dokumen/{jenis}/pdf` (Baperjakat `POST`), lanskap A4, sinkron |
| Hasil PDF match ke snapshot aktif | Test §7.2.8: setujui riwayat KP → PDF/JSON berikutnya memuat pangkat baru |
| Export PDF besar synchronous | Tanpa queue; batas §7.2.7 |
| Header PDF dibaca dari `web_config` | `KopDokumen` membaca `pdf_header_nama_kementerian ?? nama_kementerian`, `pdf_header_alamat_kementerian`, override `unit.alamat_pdf_header` / `satker.alamat_pdf_header` |
| Sesuai matriks: role 1, 3, 4, 5, 8 | `Role::filter(1,3,4,5,8)` + `PegawaiScope` untuk role 3 |

---

## 6. Matriks role × endpoint (v2)

Semua route di grup `api/v1`, filter `jwt` (grup) + `Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)` per route. Role 2/6/7 → 403. Role 3 → data dibatasi `PegawaiScope`; lingkup di luar hak → 403 `message: "Lingkup di luar hak akses."`.

### 6.1 Statistik (F-03)
| Method | Path | Keterangan |
|---|---|---|
| GET | `statistik/lingkup` | Daftar lingkup yang boleh dipilih: `{kementerian: {nama}?, unit: [{id, nama, satker: [{id, nama}]}]}` sesuai scope akun |
| GET | `statistik/{jenis}` | `jenis` ∈ `unit-kerja, jenis-kelamin, jabatan, eselon, pendidikan, usia, golongan`; query `lingkup`, `id` |
| GET | `statistik/{jenis}/xlsx` | Unduh XLSX; query sama; dicatat audit (F7-27) |

### 6.2 Dokumen (F-09)
| Method | Path | Keterangan |
|---|---|---|
| GET | `dokumen/duk` | JSON berhalaman (`page`, `per_page` ≤ 100, `lingkup`, `id`, `search` nama/NIP) |
| GET | `dokumen/duk/pdf`, `dokumen/duk/xlsx` | Unduh; `lingkup`/`id` opsional (PDF wajib bila §7.2.7 gagal) |
| GET | `dokumen/nominatif`, `/pdf`, `/xlsx` | idem |
| GET | `dokumen/pensiun`, `/pdf`, `/xlsx` | + `tahun`, `sampai` |
| GET | `dokumen/baperjakat` | JSON berhalaman seluruh pegawai aktif (kolom lengkap) |
| POST | `dokumen/baperjakat/pdf`, `dokumen/baperjakat/xlsx` | Body JSON `{nip: [..] (1..2000), judul (≤150), subjudul? (≤150), teks? (≤1000)}` |

### 6.3 Endpoint yang TIDAK dibuat
`statistik/fungsional|agama|detail-jf|kinerja|semua`, `dokumen/kp|kgb|tanda-jasa|alamat-struktural|ulang-tahun`, PDF/print statistik, endpoint dashboard beranda.

### 6.4 Throttle
Unduhan PDF/XLSX dokumen: maks **6 per menit per akun** (CI4 throttler yang sudah dipakai auth; key `dok_unduh_<id_pengguna>`) → 429. Tanpa throttle untuk JSON.

---

## 7. Spesifikasi per task

### 7.1 F-03 — Widget Statistik (MAKE-036)

#### 7.1.1 Berkas
- `backend/app/Config/RoutesDashboard.php` (namespace `App\Controllers\Api\Dashboard`).
- `Controllers/Api/Dashboard/StatistikController.php` (padanan `ChartController` spek), `README.md` diisi kontrak.
- `Libraries/Dashboard/Statistik/StatistikService.php` (orkestrasi, cache, scope, ekspor), `LingkupStatistik.php` (parse & validasi `lingkup`/`id`, daftar lingkup per scope), `KueriStatistik.php` (builder agregat per jenis), `BucketUsia.php` (murni), `UrutanGolongan.php` (murni), `StatistikCache.php` (key, TTL, `invalidasi()`, `invalidasiDariEvent()`), `BentukLaporan.php` (DTO respons).
- Test: `tests/unit/Dashboard/{BucketUsiaTest, UrutanGolonganTest, StatistikCacheKeyTest}.php`; `tests/Dashboard/{StatistikEndpointTest, StatistikScopeTest, StatistikCacheInvalidasiTest, StatistikEksporTest}.php`.

#### 7.1.2 Kontrak respons `GET statistik/{jenis}`
```json
{ "status": "success", "data": {
  "jenis": "unit-kerja", "judul": "Formasi Unit Kerja",
  "lingkup": { "tipe": "kementerian", "id": null, "nama": "<nama_kementerian>" },
  "kategori": [ { "id": "3", "label": "Sekretariat Kementerian" }, … ],
  "seri":     [ { "key": "pns", "label": "PNS" }, { "key": "pppk", "label": "PPPK" } ],
  "baris":    [ { "kategori_id": "3", "label": "…", "nilai": [120, 15], "total": 135 }, … ],
  "subtotal": [ 1826, 310 ], "total": 2136,
  "periode": "2026-10-20", "cache": { "hit": true, "kedaluwarsa": "2026-10-20T08:15:00Z" } } }
```
Kategori = unit (lingkup kementerian, urut `unit.order`), satker (lingkup unit, urut `satker.order, id_satker`), atau satu satker. Seri per jenis: unit-kerja `PNS/PPPK`; jenis-kelamin `Laki-laki/Perempuan` (`jenis_kelamin` 1 = L, selain itu P [K]); jabatan `Struktural/Fungsional/Pelaksana/Lain-lain` (`id_group_jabatan` 1/2/3/lainnya termasuk NULL [K]); eselon = master (F7-8); pendidikan = `jenjang_pendidikan` status 1 urut `order` + "Tanpa data" (F7-9); usia = 7 bucket (F7-6); golongan = kolom PNS lalu PPPK (F7-7).

#### 7.1.3 Kueri (pola, padanan legacy `L_chart.php`)
Builder dasar: `pegawai peg` JOIN `pegawai_mutasi_jabatan pmj ON pmj.nip = peg.nip` LEFT JOIN `unit unt ON unt.id_unit = pmj.id_unit` (atau `satker sat`), WHERE `peg.status = 1 AND peg.id_jenis_pegawai IN (1, 6) AND unt.status = 1` (+ `pmj.id_unit = ?` / `pmj.id_satker = ?`), `COUNT(DISTINCT peg.nip)`, GROUP BY kategori + dimensi; lalu `PegawaiScope::terapkanKeQuery($b, $auth, 'peg.nip')`. Dimensi: `peg.id_jenis_pegawai`; `peg.jenis_kelamin`; `pmj.id_group_jabatan`; `pmj.id_sub_group_jabatan` (+ `pmj.id_group_jabatan = 1`, JOIN `sub_group_jabatan` status 1); `ppen.id_jenjang_pendidikan` (LEFT JOIN `pegawai_pendidikan ppen`, NULL → "Tanpa data"); `TIMESTAMPDIFF(YEAR, peg.tgl_lahir, CURDATE())` → bucket di PHP; golongan PNS `pkp.id_pangkat` (JOIN `pegawai_kp pkp`, JOIN `pangkat` status 1) dan PPPK `pmj.id_gol_pppk` (JOIN `gol_pppk`). Kategori yang tidak punya data tetap tampil dengan nilai 0.

#### 7.1.4 Lingkup & scope
`LingkupStatistik::dari($query, $auth)` → `{tipe, id}`; validasi: `unit`/`satker` harus ada & status 1 (404 bila tidak ada), lalu `PegawaiScope` memutuskan boleh (403). Role 3 tanpa `lingkup` → default satker akun bila ada, selain itu unit akun. `statistik/lingkup` memakai `PegawaiScope` yang sama untuk menyusun daftar.

#### 7.1.5 Cache (F7-12)
`StatistikCache::key($jenis, $lingkup, $auth)` = `dashboard_stat_{jenis}_{tipe}_{id|0}_{all|s<id>|u<id>}`; `TTL = 900`. `remember` membungkus seluruh hasil (`BentukLaporan`). `invalidasi()` = `Services::cacheService()->invalidateMatching('dashboard_stat_*')`. Titik panggil: (a) `Config/Events.php` → `StatistikCache::invalidasiDariEvent(array $payload)` (abaikan isi payload; semua tipe memicu); (b) `PegawaiModel::$afterInsert/$afterUpdate/$afterDelete` → `invalidasiStatistik` (statis, tanpa DB). Kegagalan cache (handler file tidak bisa tulis) tidak boleh mengubah respons (bungkus try/catch, log warning).

#### 7.1.6 Ekspor XLSX (F7-11)
`EksporTabel::xlsx($header, $baris, $judulSheet)`: baris 1 judul statistik, baris 2 nama lingkup, baris 3 `Periode: <d M Y>`, baris 4 header (`NO`, `<KATEGORI>`, seri…, `TOTAL`), data, baris `SUBTOTAL`. Nama berkas `statistik_<jenis>_<lingkup>_<YmdHis>.xlsx`, MIME `EksporTabel::MIME['xlsx']`, `Content-Disposition: attachment`, `Cache-Control: private, no-store`. Dicatat audit F7-27.

#### 7.1.7 Frontend (M2)
- `features/laporan/`: `routes.laporan.ts` (`/laporan/:tipe(unit-kerja|jenis-kelamin|jabatan|eselon|pendidikan|usia|golongan)`, `meta.roles = LAPORAN_ROLES`), `views/LaporanPage.vue` (dari mockup: pemilih lingkup dari `statistik/lingkup`, `UiBarChart`, dua `UiDonutChart`, tabel + subtotal, `UiExportMenu` hanya opsi Excel → `GET …/xlsx` lewat helper blob), `laporan.service.ts` (ganti mock), `types.ts`, `__tests__/LaporanPage.spec.ts`.
- `shared/ui/UiBarChart.vue` dipindah dari `67fa767` apa adanya (+ spec), ekspor di `index.ts`.
- Nav: item `laporan` + `roles` + 4 child baru (§3.3). Tetap `phase: 7`.
- Testid: `laporan-page`, `laporan-lingkup`, `laporan-table`, `laporan-row-<id>`, `laporan-subtotal`, `laporan-export`.

#### 7.1.8 Test wajib
| Berkas | Isi |
|---|---|
| `BucketUsiaTest` | batas tiap bucket (30/31, 40/41, 45/46, 50/51, 56/57, 60/61), umur 0 dan 17 → `≤30`, 99 → `>60` |
| `UrutanGolonganTest` | urut `pangkat.order`, "CPNS III/a" digabung ke "III/a", PPPK setelah PNS |
| `StatistikCacheKeyTest` | key memuat jenis, lingkup, id, scope akun; role 3 satker A ≠ satker B |
| `StatistikEndpointTest` | 7 jenis × 3 lingkup: bentuk respons, total = jumlah seri, kategori kosong = 0, PTT tidak dihitung, unit nonaktif tidak tampil, "Tanpa data" pendidikan, 404 unit tidak ada, 422 parameter array/`lingkup` tak dikenal |
| `StatistikScopeTest` | role 3 satker: default satker akun, minta unit/kementerian → 403, data hanya satkernya; role 3 unit: boleh unit + satker di bawahnya; role 2/6/7 → 403; **cache satker A tidak tampil untuk satker B** (dua akun role 3, data berbeda, request berurutan) |
| `StatistikCacheInvalidasiTest` | hit kedua dari cache (`RecordingCacheService` atau cek `cache.hit`); tambah pegawai lewat `PegawaiModel` → nilai berubah; setujui riwayat mutasi jabatan lewat `RiwayatEngine` (event) → nilai berubah; semua tanpa menunggu TTL |
| `StatistikEksporTest` | `%PK` (zip) + MIME + nama berkas + isi header/subtotal; audit `dokumen_unduhan`/`statistik_unit-kerja` tercatat; role 2 → 403 |

### 7.2 F-09 — Generator Dokumen (MAKE-037)

#### 7.2.1 Berkas
- `Controllers/Api/Dashboard/DokumenController.php` (padanan `DocumentController` spek).
- `Libraries/Dashboard/Dokumen/DokumenService.php` (orkestrasi, scope, throttle, audit), `KueriDaftarPegawai.php` (builder bersama: `pegawai` + snapshot + master, mirip `PegawaiService::builderDaftar`), `UrutanDuk.php` (murni), `KolomDokumen.php` (murni: definisi kolom XLSX & PDF per jenis + pemformat), `ProyeksiPensiun.php` (murni di atas `Kalkulasi\Pensiun`), `BaperjakatInput.php` (validasi body), `RenderDokumenPdf.php` (HTML → `DokumenPdf` → `Pdf::renderer()`).
- `Libraries/Pdf/KopDokumen.php` (baru, aditif): `KopDokumen::html(?int $idUnit, ?int $idSatker): string` memakai `Services::webConfigService()->values()`; tiga generator lama tidak diubah.
- `Views/pdf/{duk,nominatif,pensiun,baperjakat}.php` (tabel HTML ter-`esc()`, font 7–8 pt, lanskap).
- Test: `tests/unit/Dashboard/{UrutanDukTest, KolomDokumenTest, ProyeksiPensiunTest, BaperjakatInputTest, KopDokumenTest}.php`; `tests/Dashboard/{DokumenEndpointTest, DokumenScopeTest, DokumenPdfTest, DokumenSnapshotTest, DokumenAuditTest, DokumenVolumeTest}.php`.

#### 7.2.2 Sumber data & cakupan (F7-19)
Builder: `pegawai` LEFT JOIN `pegawai_kp`, `pegawai_cpns`, `pegawai_pns`, `pegawai_mutasi_jabatan` (+ `unit`, `satker`, `jabatan`, `kelas_jabatan`), `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_kgb`, `pegawai_tanda_jasa`, `agama`, `pangkat`; `PegawaiScope::terapkanKeQuery(..., 'pegawai.nip')`; filter `lingkup`/`id` seperti §7.1.4. WHERE per jenis: DUK `status = 1 AND id_jenis_pegawai = 1`; Nominatif `status = 1`; Pensiun `status = 1 AND id_jenis_pegawai = 1`; Baperjakat `status = 1`. Satu kueri per dokumen (tidak menarik riwayat yang tidak dipakai, cf. legacy `get_all_pegawai`).

#### 7.2.3 Kolom
- **XLSX DUK/Nominatif** (37, urutan legacy `L_document.php:26`): NAMA (dengan gelar), NIP, NIP LAMA, KP (GOL), KP (TMT), GOL, RUANG, PANGKAT, JABATAN, TMT JABATAN, SATUAN KERJA, TMT UNIT, DIKLAT STRUKTUR, STRATA, JURUSAN, INSTITUSI PENDIDIKAN, TGL LULUS, IPK, USIA (TH), USIA (BL), TEMPAT LAHIR, TGL LAHIR, TMT CPNS, TMT PNS, AGAMA, JENIS KELAMIN, MKER (TH), MKER (BL), KGB (TH), KGB (BL), KGB (MKER), TGL SERTIFIKAT, NAMA TANDA JASA, ESELON, TGL PENSIUN, PENSIUN (TH), PENSIUN (BL). TMT UNIT = TMT jabatan (F7-23). Eselon = `E.I`…`E.IV` dari `id_sub_group_jabatan` 1–4 bila `id_group_jabatan = 1` [K `function_helper.php:164-166`].
- **XLSX Pensiun** (11): NAMA, NIP, TGL LAHIR, JABATAN, TMT JABATAN, SATKER, ESELON, TGL PENSIUN, PENSIUN (TH), PENSIUN (BL), USIA (TH), USIA (BL) — kolom hidden legacy (`id_unit`, `id_satker`) tidak diekspor.
- **XLSX Baperjakat** (37): sama dengan DUK/Nominatif.
- **PDF (subset inti, F7-16)**: NO, NAMA, NIP, PANGKAT/GOL, TMT PANGKAT, JABATAN, TMT JABATAN, UNIT/SATKER, PENDIDIKAN (strata + jurusan), TMT CPNS, MASA KERJA, TGL LAHIR (usia), DIKLAT STRUKTURAL, TGL PENSIUN. Pensiun PDF = kolom XLSX Pensiun. Baperjakat PDF = judul/subjudul/teks di atas tabel subset inti.
- Format tanggal `d M Y` (mis. `05 Mar 2024`) [K `dateToUser`]; usia/masa kerja `MasaKerja::selisih` terhadap `TanggalBisnis::hariIni()` (F7-24). Semua sel XLSX teks (`EksporTabel` anti formula-injection).

#### 7.2.4 Urutan (F7-17, F7-18)
`UrutanDuk::bandingkan(a, b)`: `pangkat.order` DESC (NULL terakhir) → `pegawai_kp.tmtsk` ASC → `pegawai_cpns.tmtsk` ASC → `tgl_lahir` ASC → `nip` ASC (penstabil). Diterapkan sebagai ORDER BY SQL (bukan sort PHP). Nominatif dan Baperjakat sesuai F7-18 dengan `nip` sebagai penstabil.

#### 7.2.5 Proyeksi pensiun (F7-20)
Untuk tiap PNS aktif dengan jabatan aktif: `bup = Pensiun::batasUsia(jabatan.umur_pensiun, pmj.id_group_jabatan, pmj.id_sub_group_jabatan)`, `tmt = Pensiun::tanggalPensiun(tgl_lahir, bup)`; filter `YEAR(tmt) BETWEEN tahun AND sampai` dilakukan di SQL (ekspresi `DATE_ADD` yang setara, diuji sama dengan kalkulasi PHP) atau di PHP bila ekspresi tidak bisa menyamai; urut `tmt ASC, id_satker, nip`. Validasi: `tahun` 4 digit 2000..2100, `sampai` ≥ `tahun` dan ≤ `tahun + 4` → selain itu 422.

#### 7.2.6 Baperjakat (F7-21)
`POST dokumen/baperjakat/{pdf|xlsx}` body `{nip: string[1..2000] unik, judul: string ≤150 wajib, subjudul?: ≤150, teks?: ≤1000}`; NIP yang tidak ada/non-aktif/di luar scope → 422 `errors.nip` (daftar NIP bermasalah, maks 20 ditampilkan). Urutan F7-18. PDF: judul (bold, tengah), subjudul, teks (paragraf), tabel, kaki "Dicetak <d M Y H:i> oleh <username>".

#### 7.2.7 PDF sinkron & uji volume (F7-15)
- `RenderDokumenPdf`: HTML dibangun bertahap (string builder, bukan view dengan array raksasa), `DokumenPdf(judul, html, KopDokumen::html(...), kaki, 'L', 'A4')`, `Pdf::respon($response, $isi, $nama, inline = ?inline=1)`.
- **Uji volume** (`DokumenVolumeTest`, grup `@group volume`, dijalankan lokal & dicatat di laporan M3; di CI boleh dilewati bila > 60 s): seed sintetis **≥ 5.000 pegawai** (NIP sintetis `19xx…`, nama acak, snapshot lengkap) di DB scratch; ukur `DUK /pdf` lingkup kementerian: **waktu ≤ 30 detik, memori puncak ≤ 256 MB** (`memory_get_peak_usage(true)`), berkas valid (`%PDF`, jumlah halaman > 1). XLSX ≥ 5.000 baris: ≤ 10 detik, ≤ 128 MB.
- Bila PDF gagal batas → terapkan **fallback LOCKED**: `lingkup` wajib `unit`/`satker` untuk PDF (422 `errors.lingkup: "PDF hanya tersedia per unit/satker."`), XLSX tetap semua; keputusan dicatat di laporan M3 dan `07-Dashboard.md`. Angka batas boleh disesuaikan reviewer bila mesin uji jauh berbeda (laporkan spesifikasi mesin).

#### 7.2.8 Match snapshot aktif
`DokumenSnapshotTest`: pegawai dengan pangkat III/c; setujui riwayat KP baru (IV/a) lewat `RiwayatEngine` (jalur Fase 3 yang ada) → `GET dokumen/duk` dan `/pdf` berikutnya memuat IV/a dan posisi urutan berubah; tolak/batalkan → kembali. Tidak ada cache untuk dokumen (selalu kueri langsung).

#### 7.2.9 Audit (F7-27)
`DokumenService::catatUnduhan($auth, $jenis, $format, $lingkup, $parameter, $jumlahBaris)` → `AuditLogModel::record('dokumen_unduhan', $jenis, 'create', null, [...], $auth->idPengguna(), $auth->nip())`. Dicatat **setelah** berkas berhasil dibangun, sebelum respons dikirim; kegagalan audit → 500 (fail-closed, konsisten F0-04 reset). `DokumenAuditTest` memeriksa baris audit per format dan tidak ada baris saat 403/422.

#### 7.2.10 Frontend (M4)
`features/dokumen/`: `routes.dokumen.ts` (`/dokumen/:jenis(duk|nominatif|pensiun|baperjakat)`, `meta.roles`), `views/DokumenPage.vue` (pemilih lingkup, filter tahun/sampai untuk pensiun, tabel berhalaman kolom inti, tombol "Unduh PDF" / "Unduh XLSX" lewat helper blob; Baperjakat: checkbox pilih baris + form judul/subjudul/teks + tombol unduh), `dokumen.service.ts`, `types.ts`, `__tests__/DokumenPage.spec.ts`. Tautan DRH per baris lewat `RowActionsMenu` ("Lihat DRH" → `pegawai/{nip}/drh` yang sudah ada). Nav item `dokumen` baru (`phase: 7`). Testid `dokumen-page`, `dokumen-table`, `dokumen-row-<nip>`, `dokumen-unduh-pdf`, `dokumen-unduh-xlsx`, `dokumen-actions-<nip>`.

#### 7.2.11 Test wajib
| Berkas | Isi |
|---|---|
| `UrutanDukTest` | 6 kasus: beda golongan, sama golongan beda TMT, sama TMT beda TMT CPNS, sama semua beda usia, NULL pangkat terakhir, CPNS vs PNS |
| `KolomDokumenTest` | jumlah & urutan kolom XLSX per jenis (37/11/37), subset PDF, format tanggal, eselon E.I–E.IV, "Tanpa data" |
| `ProyeksiPensiunTest` | BUP 58/60/`umur_pensiun`, TMT = tgl 1 bulan setelah ultah, rentang tahun..sampai, tanpa jabatan → tidak masuk, PPPK tidak masuk, 422 rentang |
| `BaperjakatInputTest` | batas 1..2000 NIP, unik, judul wajib/≤150, NIP asing/non-aktif → 422 |
| `KopDokumenTest` | nama kementerian uppercase dari `pdf_header_nama_kementerian` lalu `nama_kementerian`; alamat override unit/satker; HTML ter-escape |
| `DokumenEndpointTest` | 4 jenis: JSON berhalaman (ListQuery: page/per_page/array → 422), cakupan per jenis (PTT masuk Nominatif, tidak di DUK; nonaktif tidak di Baperjakat), urutan |
| `DokumenScopeTest` | role 3 hanya satker/unit akun (JSON, PDF, XLSX, Baperjakat NIP luar scope → 422); role 2/6/7 → 403; lingkup luar hak → 403 |
| `DokumenPdfTest` | `%PDF`, `application/pdf`, nama berkas `duk_<lingkup>_<YmdHis>.pdf`, inline vs attachment, kop memuat nama kementerian, throttle 429 pada unduhan ke-7 dalam semenit |
| `DokumenSnapshotTest` | §7.2.8 |
| `DokumenAuditTest` | §7.2.9 |
| `DokumenVolumeTest` | §7.2.7 (`@group volume`) |

### 7.3 Ringkasan test wajib (nama berkas) — lihat 7.1.8 dan 7.2.11. Semua test feature memakai `DatabaseTestCase` + `AuthTestTrait`; fixture pegawai sintetis di `tests/_support/Database/Seeds/DashboardSeeder.php` (baru, milik paket).

---

## 8. Aturan lintas modul

### 8.1 Audit
Hanya unduhan (F7-27). Membaca statistik/JSON tidak diaudit. Tidak ada perubahan `BaseAuditableModel`.

### 8.2 Transaksi
Paket ini tidak menulis data bisnis. Audit unduhan = satu INSERT.

### 8.3 Keamanan
`PegawaiScope` fail-closed; semua parameter lewat `ListQuery`; HTML PDF di-`esc()`; XLSX teks + anti formula-injection; respons unduhan `no-store`, `nosniff`; throttle §6.4; tidak ada data pribadi di URL (Baperjakat = POST body).

### 8.4 ADR
ADR-033 (TCPDF) mencakup Fase 3 dan menyatakan "modul lain lewat ADR/addendum sendiri". ADR-033 tidak diedit; paket ini membuat berkas baru `docs/adr/ADR-034-pdf-sinkron-dokumen-daftar.md` (keputusan: PDF daftar sinkron dengan batas §7.2.7 dan fallback lingkup; Excel via `EksporTabel` sampai phpspreadsheet 5A masuk) + satu baris di `docs/adr/README.md`. Nomor ADR dikonfirmasi reviewer (§13 Q-6).

### 8.5 Kontrak API
README `Controllers/Api/Dashboard/README.md` memuat semua endpoint §6, parameter, kode galat, contoh respons. Format respons ADR-001.

### 8.6 Frontend & akun uji
Akun uji per role dari seeder yang sudah ada (`AuthSeeder`); tidak menambah akun. Komponen `shared/ui` yang ada tidak diubah perilakunya.

---

## 9. Ketergantungan ke fase/paket lain

| Fase/paket | Paket ini memakai | Paket ini TIDAK menyentuh |
|---|---|---|
| Fase 3 | `PegawaiScope`, `PegawaiModel` (hook aditif), `RiwayatEngine::EVENT_NOTIFIKASI`, `SnapshotSync` (hanya efeknya), `Kalkulasi\{Pensiun, MasaKerja, TanggalBisnis}`, `EksporTabel`, `Pdf`, `DokumenPdf`, `WebConfigService`, `ListQuery`, `AuditLogModel` | `NipCascadeService`, `PegawaiService`, definisi riwayat |
| Fase 4 | — | Semua berkas §3.2 |
| 5A | Setelah merge: tidak ada perubahan wajib; `rilis` tetap tidak dipakai paket ini | phpspreadsheet, `WebConfig.php`, `audit_logs` ENUM |
| Fase 7 berikutnya | Dashboard beranda boleh memakai `StatistikService` | — |
| Fase 6 H-08 | Cron rekomendasi pensiun boleh memakai `ProyeksiPensiun` | — |

---

## 10. Urutan kerja (runbook) dengan ⛔ TITIK TUNGGU

Aturan setiap milestone: (a) branch baru dari `origin/main` terbaru; (b) satu key; (c) gate cepat lokal; (d) push; (e) CI `quality-gate` hijau untuk SHA itu; (f) lapor §11.4; (g) ⛔ tunggu review & merge reviewer CR sebelum milestone berikutnya.

| M | Milestone | Key | Branch | Menunggu | Membuka |
|---|---|---|---|---|---|
| M0 | PRD dikunci Horii; reviewer push `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md` ke `main` | — | — | — | M1 |
| M1 | Backend F-03: RoutesDashboard, 7 endpoint + lingkup, scope, cache + invalidasi (a)(b), XLSX, audit, README, test 7.1.8, `07-Dashboard.md` | MAKE-036 | `fase7/make-036-statistik` | M0 | M2 |
| M2 | FE F-03: `features/laporan`, `UiBarChart`, route/nav aditif (commit terpisah), spec | MAKE-036 | idem (commit lanjutan) | M1 merged | M3 |
| M3 | Backend F-09: 4 dokumen JSON/PDF/XLSX, `KopDokumen`, ADR-034, audit, throttle, uji volume + keputusan fallback, test 7.2.11 | MAKE-037 | `fase7/make-037-generator-dokumen` | M2 merged | M4 |
| M4 | FE F-09: `features/dokumen`, route/nav aditif, spec; laporan akhir paket | MAKE-037 | idem | M3 merged | ⛔ QA reviewer (URL langsung, menu tersembunyi) |

Boleh paralel: tidak ada (satu sesi). Setelah M4: QA fungsional + UI Lapis 1 oleh reviewer; rilis menu diputuskan Horii terpisah (F7-14).

---

## 11. Definition of Done & exit criteria

### 11.1 Paket DONE bila
1. 7 endpoint statistik + lingkup + XLSX lolos test 7.1.8, termasuk invalidasi cache dan isolasi cache antar satker.
2. 4 dokumen JSON/PDF/XLSX lolos test 7.2.11, termasuk match snapshot, audit, scope, throttle; hasil uji volume dicatat dan fallback (bila dipakai) diterapkan.
3. Halaman `/laporan/:tipe` dan `/dokumen/:jenis` berfungsi lewat URL langsung untuk role 1/3/4/5/8, 403 untuk 2/6/7.
4. `check.sh` dan CI hijau; `07-Dashboard.md` dan README kontrak terbarui; tidak ada perubahan di berkas §3.2; berkas bersama hanya aditif di commit terpisah.
5. Tidak ada tabel/kolom/index/ENUM baru.

### 11.2 ≠ Fase 7 DONE
Fase 7 masih IN_PROGRESS (F-01, F-02, F-04..F-08, F-10..F-13 sisa).

### 11.3 IN_PROGRESS
Status Trello task F-03/F-09 diperbarui reviewer; kartu GATE tidak dibuat karena paket ini tidak punya titik tunggu eksternal.

### 11.4 Format laporan milestone
SHA, URL run CI, ringkasan berkas (milik / bersama), test ditambah (nama berkas + jumlah), deviasi [V2] baru, hasil uji volume (M3), blocker, pertanyaan.

---

## 12. Checklist verifikasi sebelum serah

- [ ] `git diff --name-only origin/main` tidak memuat berkas §3.2.
- [ ] Berkas bersama §3.3 masing-masing di commit sendiri, hanya baris tambahan.
- [ ] `ACTIVE_PHASE` dan `rilis` tidak berubah (`git diff` nav.config.ts).
- [ ] Tidak ada `composer.json`/`composer.lock`/`Services.php`/`WebConfig.php` di diff.
- [ ] Tidak ada migration baru; `audit_logs.event` hanya `create`.
- [ ] `KontrakMatriksRoleTest`: prefiks `statistik`/`dokumen` + baris kontrak semua route §6 ada di commit route; tes sapu 401/403 lolos.
- [ ] Test scope: role 3 satker A vs B (JSON, XLSX, PDF, cache).
- [ ] Test invalidasi cache lewat `PegawaiModel` dan `RiwayatEngine`.
- [ ] `grep -ri "192\.\|10\.\|@kemenpar\|password" ` pada berkas baru = kosong (repo publik).
- [ ] Nama/NIP di test & seed sintetis.
- [ ] DB scratch `simpeg_v2_t_wf_make03x` di-drop.

---

## 13. Pertanyaan terbuka yang TERSISA

| No | Pertanyaan | Dampak bila belum dijawab |
|---|---|---|
| Q-1 | **Urutan DUK formal.** Reviewer mensyaratkan kutipan sumber resmi (nomor peraturan + pasal) sebelum kriteria eselon/jabatan, TMT jabatan, diklat, pendidikan dipakai. Dugaan [I]: PP No. 15 Tahun 1979 tentang DUK PNS (pangkat → jabatan → masa kerja → latihan jabatan → pendidikan → usia) — **belum diverifikasi dari teks peraturan** dan status berlakunya pasca UU ASN belum dicek. Horii/reviewer: sediakan teks atau tetap urutan inti F7-17 | Urutan inti dipakai; tidak memblokir |
| Q-2 | Rilis menu Laporan/Dokumen: kapan dan lewat mekanisme apa (flag `rilis` 5A setelah merge, atau `ACTIVE_PHASE` 7 oleh penutup Fase 7)? | QA lewat URL; tidak memblokir |
| Q-3 | Mockup FE hanya ada untuk unit-kerja/jenis-kelamin/struktural; 4 tipe lain (jabatan, pendidikan, usia, golongan) dan halaman Dokumen memakai layout yang sama — perlu konfirmasi QA UI Lapis 1 (Figma?) | M2/M4 memakai layout mockup yang ada |
| Q-4 | Isian `jabatan.umur_pensiun` di data produksi: kelengkapan belum diaudit (menunggu salinan data, ISSUE-003). Proyeksi memakai 58/60 bila kosong | Akurasi proyeksi pensiun |
| Q-5 | `pegawai_tanda_jasa` legacy hanya id 26/27/28 (trigger). v2 memakai aturan `AturanSnapshot` yang ada — perlu konfirmasi bahwa isinya setara untuk kolom NAMA TANDA JASA | Kolom XLSX |
| Q-6 | Nomor ADR untuk PDF sinkron dokumen daftar: ADR-034 (usulan) — reviewer mengonfirmasi penomoran | M3 |
| Q-7 | Nominatif KP/KGB, Tanda Jasa, Alamat Struktural, Ulang Tahun (menu Dokumen legacy) — dibuat di paket Fase 7 berikutnya atau tidak sama sekali? | Tidak memblokir |
| Q-8 | `SiklusPegawai` (aktif/nonaktif pegawai): apakah menulis `pegawai.status` lewat `PegawaiModel` (hook b menangkap) atau raw builder? Diperiksa di M1; bila raw → tambah panggilan (c) atau andalkan TTL (laporkan) | Kesegaran statistik ≤ 15 menit |
| Q-9 | Batas uji volume (30 s / 256 MB PDF; 10 s / 128 MB XLSX) ditetapkan untuk mesin dev; reviewer boleh menyesuaikan setelah melihat hasil M3 | Fallback PDF per unit/satker |

---

## 14. Lampiran

### 14.A Rujukan sumber (legacy, relatif `application/`)
- Chart: `controllers/hr/Chart.php:13-1214` (12 method, `userAuth(['1','3','4','5','8'])`, parameter `id_unit_satker`/`format`, scoping level 3 `:24-87`); `libraries/hr/L_chart.php` — unit kerja `:14-169`, gender `:441-599`, eselon `:872-1042`, pendidikan `:1330-1503`, jabatan `:1839-1995`, usia `:3211-3433`, golongan `:4159-4530`; bucket usia lama `libraries/hr/L_chart_20250813.php:2523`; tanpa cache; view C3/D3 `views/hr/chart/unit_kerja.php:108-153`.
- Dokumen: `controllers/hr/Document.php:10,34,82,235`; `libraries/hr/L_document.php:10-81` (DUK, urut `:14-16`), `:83-155` (Nominatif, urut `:87-90`), `:202-253` (Pensiun, scoping `:207-216`, tahun berjalan `:223-224`), `:520-638` (Baperjakat); `libraries/hr/L_employee.php:4432-5069` (`get_all_pegawai`), `:1226-1375` (pensiun), `:7108-7215` (pola PDF DRH, kop `:7147-7166`); `helpers/function_helper.php:164-166` (eselon), `:2400-2418` (BUP); `views/hr/document/{duk,nominatif,pensiun,baperjakat}.php`; `libraries/PdfCreator.php`.
- DDL legacy `simpeg01_struktur_lengkap_20261001.sql`: `pegawai :2497`, `pegawai_kp :3131`, `pegawai_mutasi_jabatan :3192`, `pegawai_pendidikan :3326`, `jabatan :1448`, `unit :7505`, `satker :6886`, `web_config :7622`; trigger snapshot `:9506-10396`.
- v2 `origin/main` `2cd3fa0`: `tests/feature/KontrakMatriksRoleTest.php:55` (`PREFIKS_CAKUPAN`), `Libraries/CacheService.php`, `Config/Cache.php:27`, `Libraries/MasterData/MasterService.php:1528-1534` (pola invalidasi), `Models/BaseAuditableModel.php:44-52`, `Models/Kepegawaian/PegawaiModel.php`, `Libraries/Kepegawaian/Riwayat/RiwayatEngine.php:59,685`, `Libraries/Kepegawaian/Scope/PegawaiScope.php`, `Libraries/Kepegawaian/Pegawai/{PegawaiService.php:228-243, EksporTabel.php, DrhPegawai.php:234-262}`, `Libraries/Kepegawaian/Kalkulasi/{Pensiun.php, MasaKerja.php, TanggalBisnis.php}`, `Libraries/Pdf/{Pdf.php, DokumenPdf.php}`, `Config/WebConfig.php:53-129`, `Models/AuditLogModel.php:33`, migration `2026-09-30-120000_CreatePegawai.php`, `2026-09-30-120100_CreatePegawaiSnapshot.php`, `2026-09-25-130000_AlterAuthKeUnicodeCi.php:82` (ENUM audit), `frontend/src/shared/layouts/nav.config.ts:61,119-158`, mockup `67fa767:frontend/src/features/laporan/**`, `shared/ui/UiBarChart.vue`.
- Spek: `Bahan Baku SIMPEG/07-Dashboard.md:33-43, 92-102, 133-147`; `00-INDEX.md:90-97`; Matriks Role × Endpoint (docx) baris `hr/chart/*`, `hr/document/*` = 1,3,4,5,8; Technical Spec: PDF sinkron, cache file.

### 14.B Anomali legacy yang TIDAK ditiru
1. Usia tanpa bucket (satu kolom per tahun), bucket lama memasukkan <18 ke ">60" → F7-6.
2. Golongan urut string PHP `sort()`; bug `group_by id_satker` pada PPPK cabang kementerian; kolom PNS difilter via `satker` sementara data via `unit` → F7-7.
3. Pendidikan INNER JOIN menghilangkan pegawai tanpa data → F7-9.
4. Menu level 3/4/5/8 menautkan URL chart usang (404) → v2 satu halaman per tipe.
5. DUK/Nominatif/Pensiun tanpa PDF; cetak Baperjakat POST ke handler tak ada → F7-15, F7-21.
6. `get_all_pegawai` menarik riwayat SKP/AK/seminar/TB/diklat untuk semua pegawai walau tidak dipakai → satu kueri per dokumen.
7. Urutan DUK `array_multisort` tidak stabil, string sort "CPNS III/a" di bawah → F7-17 + penstabil `nip`.
8. Level 8 tidak mendapat dropdown unit di DUK; level 7 boleh `pensiun` tanpa menu → v2 ikut Matriks.
9. Kop hard-code `id_unit` 6/7 → v2 memakai `UNIT_UPT`/`UNIT_LAIN` dari `PegawaiScope` bila diperlukan; default alamat per unit/satker lewat kolom `alamat_pdf_header`.

### 14.C Matriks keterlacakan DoD → §
F-03 unit_kerja+Excel → 7.1.2/7.1.6; demografi 6 jenis → 7.1.2–7.1.3; role → 6; cache F0-14 → 7.1.5; invalidasi → 7.1.5/7.1.8; F-09 4 dokumen PDF → 7.2.1–7.2.7; match snapshot → 7.2.8; sinkron → 7.2.7; header web_config → 7.2.1 `KopDokumen`; role → 6/7.2.2.
