# Progress Fase 7 — Modul F: Dashboard & News

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak paket pertama: `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md` (F-03 + F-09, tanpa skema). Kontrak endpoint: `backend/app/Controllers/Api/Dashboard/README.md`.

**Terakhir diperbarui:** 21 Oktober 2026 — M1 MAKE-036 backend F-03 statistik (branch `fase7/make-036-statistik`, menunggu review reviewer CR). Fase 7 **IN_PROGRESS**: hanya paket F-03 + F-09 yang dikerjakan sekarang (PRD Fase 7 §1); F-01, F-02, F-04..F-08, F-11..F-13 sisa, dan F-10 Arsip **ditunda** (keputusan Horii 20-10-2026) belum dimulai.
**Entry criteria paket:** PRD Fase 7 v1.0 di `main` `90362c6` (21-10-2026); Fase 3 DONE (sumber data snapshot `pegawai_*`); tidak ada ketergantungan ke Fase 4/5A.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| F-03 Widget Statistik Kepegawaian — backend (M1) | MAKE-036 | IN_PROGRESS (menunggu review) | `Config/RoutesDashboard.php` (+1 entri `Routing.php`), `StatistikController`, `Libraries/Dashboard/Statistik/*` (lingkup, kueri agregat, bucket usia, urutan golongan, cache, bentuk laporan), XLSX via `EksporTabel`, audit unduhan, kontrak role di `KontrakMatriksRoleTest` (MAKE-038). Test: 3 unit + 4 feature (22 test) |
| F-03 — frontend (M2) | MAKE-036 | TODO | `features/laporan` 7 tipe, `UiBarChart`, route/nav aditif (menu tetap `phase: 7`) |
| F-09 Generator Dokumen — backend (M3) | MAKE-037 | TODO | DUK, Nominatif, Proyeksi Pensiun, Baperjakat: JSON/PDF/XLSX, `KopDokumen`, uji volume ≥ 5.000 baris |
| F-09 — frontend (M4) | MAKE-037 | TODO | `features/dokumen` |
| F-10 Arsip Digital | — | DITUNDA | Belum ada konfigurasi server Arsip resmi (Horii 20-10-2026) |
| F-01, F-02, F-04..F-08, F-11..F-13 | — | TODO | Paket Fase 7 berikutnya (butuh DBV / Fase 4) |

## M1 — MAKE-036 backend F-03 (21-10-2026)

Keputusan PRD yang dipakai: F7-1..F7-14, F7-26, F7-27. Ringkasan implementasi:

- **Endpoint** `GET api/v1/statistik/lingkup`, `GET api/v1/statistik/{jenis}`, `GET api/v1/statistik/{jenis}/xlsx` (role 1, 3, 4, 5, 8). Lingkup `kementerian|unit|satker` + `id` (F7-2); role 3 default satker/unit akun, lingkup lain → 403 (F7-3, F7-22); unit/satker nonaktif → 404.
- **Kueri** (`KueriStatistik`): `pegawai` JOIN snapshot `pegawai_mutasi_jabatan` + unit/satker status 1, populasi status 1 & jenis 1/6 [K], `COUNT(DISTINCT nip)`, `PegawaiScope::terapkanKeQuery`. Deviasi [V2] sesuai PRD: bucket usia (F7-6), golongan urut `pangkat.order` + CPNS digabung (F7-7), pendidikan LEFT JOIN + "Tanpa data" (F7-9), tanpa pengecualian akun uji (F7-5).
- **Cache** (`StatistikCache`): key `dashboard_stat_{jenis}_{lingkup}_{id}_{scope}` TTL 900; invalidasi (a) listener `RiwayatEngine::EVENT_NOTIFIKASI` di `Config/Events.php`, (b) hook `PegawaiModel` (aditif). **Tambahan di luar daftar PRD (untuk dikonfirmasi reviewer):** (c) listener `DBQuery` yang menghapus cache pada kueri tulis langsung ke `pegawai`/`pegawai_*` — menutup jalur riwayat alur Admin yang langsung Disetujui (engine tidak memicu event saat `tambah` oleh admin), `SiklusPegawai`, dan koreksi NIP tanpa menyentuh berkas Fase 3. `SiklusPegawai` dan `NipCascadeService` tidak diubah (F7-12).
- **XLSX** `EksporTabel::xlsx` satu sheet (judul tebal, lingkup, periode, header, data, SUBTOTAL); **audit** `audit_logs` `dokumen_unduhan`/`statistik_{jenis}`/`create` (F7-27, ENUM yang ada).
- **Berkas bersama (aditif, commit terpisah):** `Config/Routing.php`, `Config/Events.php`, `Models/Kepegawaian/PegawaiModel.php`, `tests/feature/KontrakMatriksRoleTest.php` (prefiks `statistik` + 3 baris kontrak).
- **Test:** `tests/unit/Dashboard/{BucketUsiaTest, UrutanGolonganTest, StatistikCacheKeyTest}.php`; `tests/Dashboard/{StatistikEndpointTest, StatistikScopeTest, StatistikCacheInvalidasiTest, StatistikEksporTest}.php`; fixture `tests/_support/Dashboard/StatistikUjiTrait.php` (MockCache per test, master sintetis, snapshot). Termasuk isolasi cache satker A vs B dan invalidasi lewat model, engine (`proses` setujui), dan kueri langsung.
- **Catatan:** `EksporTabel::xlsx` hanya menebalkan baris pertama → judul statistik yang tebal, header kolom biasa (keterbatasan penulis OOXML yang ada; phpspreadsheet milik 5A). Header `Cache-Control` unduhan ditambahkan ke nilai bawaan framework (`no-store, …, private, no-store`).
- **Pertanyaan terbuka:** PRD §13 Q-2 (rilis menu), Q-8 (`SiklusPegawai` — ditutup oleh listener DBQuery bila diterima).
