# Progress Fase 7 — Modul F: Dashboard & News

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak paket pertama: `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md` (F-03 + F-09, tanpa skema). Kontrak endpoint: `backend/app/Controllers/Api/Dashboard/README.md`.

**Terakhir diperbarui:** 21 Oktober 2026 — M4 MAKE-037 frontend F-09 (halaman `/dokumen/:jenis`, branch `fase7/make-037-generator-dokumen`, menunggu review reviewer CR); M1–M3 di `main`. Fase 7 **IN_PROGRESS**: hanya paket F-03 + F-09 yang dikerjakan sekarang (PRD Fase 7 §1); F-01, F-02, F-04..F-08, F-11..F-13 sisa, dan F-10 Arsip **ditunda** (keputusan Horii 20-10-2026) belum dimulai.
**Entry criteria paket:** PRD Fase 7 v1.0 di `main` `90362c6` (21-10-2026); Fase 3 DONE (sumber data snapshot `pegawai_*`); tidak ada ketergantungan ke Fase 4/5A.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| F-03 Widget Statistik Kepegawaian — backend (M1) | MAKE-036 | DONE (main `7fb3011`) | `Config/RoutesDashboard.php` (+1 entri `Routing.php`), `StatistikController`, `Libraries/Dashboard/Statistik/*` (lingkup, kueri agregat, bucket usia, urutan golongan, cache, bentuk laporan), XLSX via `EksporTabel`, audit unduhan, kontrak role di `KontrakMatriksRoleTest` (MAKE-038). Test: 3 unit + 4 feature (22 test) |
| F-03 — frontend (M2) | MAKE-036 | DONE (main `1f76989`) | `features/laporan` (halaman `/laporan/:tipe` 7 tipe, service API, route), `shared/ui/UiBarChart` dari mockup `67fa767`, nav `laporan` + `roles` + 7 anak (tetap `phase: 7`), spec 8 test + nav spec |
| F-09 Generator Dokumen — backend (M3) | MAKE-037 | DONE (main `6a03b19`) | DUK, Nominatif, Proyeksi Pensiun, Baperjakat: JSON/PDF/XLSX, `KopDokumen`, audit, throttle; fallback PDF F7-15 (per unit/satker, maks 1.700 baris, ADR-034); uji volume 5.000 baris |
| F-09 — frontend (M4) | MAKE-037 | IN_PROGRESS (menunggu review) | `features/dokumen` (halaman `/dokumen/:jenis` 4 jenis, unduh PDF/XLSX + pesan fallback, Baperjakat pilih NIP + judul, ⋮ Lihat DRH), nav `dokumen` (tetap `phase: 7`), spec 10 test + nav spec |
| F-10 Arsip Digital | — | DITUNDA | Belum ada konfigurasi server Arsip resmi (Horii 20-10-2026) |
| F-01, F-02, F-04..F-08, F-11..F-13 | — | TODO | Paket Fase 7 berikutnya (butuh DBV / Fase 4) |

## M1 — MAKE-036 backend F-03 (21-10-2026)

Keputusan PRD yang dipakai: F7-1..F7-14, F7-26, F7-27. Ringkasan implementasi:

- **Endpoint** `GET api/v1/statistik/lingkup`, `GET api/v1/statistik/{jenis}`, `GET api/v1/statistik/{jenis}/xlsx` (role 1, 3, 4, 5, 8). Lingkup `kementerian|unit|satker` + `id` (F7-2); role 3 default satker/unit akun, lingkup lain → 403 (F7-3, F7-22); unit/satker nonaktif → 404.
- **Kueri** (`KueriStatistik`): `pegawai` JOIN snapshot `pegawai_mutasi_jabatan` + unit/satker status 1, populasi status 1 & jenis 1/6 [K], `COUNT(DISTINCT nip)`, `PegawaiScope::terapkanKeQuery`. Deviasi [V2] sesuai PRD: bucket usia (F7-6), golongan urut `pangkat.order` + CPNS digabung (F7-7), pendidikan LEFT JOIN + "Tanpa data" (F7-9), tanpa pengecualian akun uji (F7-5).
- **Cache** (`StatistikCache`): key `dashboard_stat_{jenis}_{lingkup}_{id}_{scope}` TTL 900; invalidasi (a) listener `RiwayatEngine::EVENT_NOTIFIKASI` di `Config/Events.php`, (b) hook `PegawaiModel` (aditif). `SiklusPegawai` dan `NipCascadeService` tidak diubah (F7-12). **Diterima basi paling lama 15 menit (TTL):** jalur Admin menambah riwayat yang langsung Disetujui (`RiwayatEngine::tambah` alur Admin tidak memicu `EVENT_NOTIFIKASI`), `SiklusPegawai`, dan koreksi NIP — sesuai F7-12 (usulan listener `DBQuery` sebagai titik ke-3 **ditolak** reviewer CR 21-10-2026: overhead per kueri, pencocokan SQL rapuh, berkas bersama).
- **XLSX** `EksporTabel::xlsx` satu sheet (judul tebal, lingkup, periode, header, data, SUBTOTAL); **audit** `audit_logs` `dokumen_unduhan`/`statistik_{jenis}`/`create` (F7-27, ENUM yang ada).
- **Berkas bersama (aditif, commit terpisah):** `Config/Routing.php`, `Config/Events.php`, `Models/Kepegawaian/PegawaiModel.php`, `tests/feature/KontrakMatriksRoleTest.php` (prefiks `statistik` + 3 baris kontrak).
- **Test:** `tests/unit/Dashboard/{BucketUsiaTest, UrutanGolonganTest, StatistikCacheKeyTest}.php`; `tests/Dashboard/{StatistikEndpointTest, StatistikScopeTest, StatistikCacheInvalidasiTest, StatistikEksporTest}.php`; fixture `tests/_support/Dashboard/StatistikUjiTrait.php` (MockCache per test, master sintetis, snapshot). Termasuk isolasi cache satker A vs B dan invalidasi lewat model, engine (`proses` setujui), dan kueri langsung.
- **Catatan:** `EksporTabel::xlsx` hanya menebalkan baris pertama → judul statistik yang tebal, header kolom biasa (keterbatasan penulis OOXML yang ada; phpspreadsheet milik 5A). Header `Cache-Control` unduhan ditambahkan ke nilai bawaan framework (`no-store, …, private, no-store`).
- **Pertanyaan terbuka:** PRD §13 Q-2 (rilis menu), Q-8 (`SiklusPegawai` — diterima basi ≤ TTL); **Q-10 (baru):** apakah `RiwayatEngine::tambah` sebaiknya memicu `EVENT_NOTIFIKASI` saat riwayat langsung Disetujui (alur Admin)? Perubahan Fase 3 di luar paket ini, diputuskan terpisah.

## M2 — MAKE-036 frontend F-03 (21-10-2026)

- **Halaman** `frontend/src/features/laporan/views/LaporanPage.vue` (`/laporan/:tipe`, 7 tipe, `meta.roles` 1/3/4/5/8): pemilih lingkup dari `GET statistik/lingkup` (kementerian → unit → satker; role 3 hanya menerima lingkup miliknya dari server), diagram batang (`UiBarChart`), dua donat, tabel + bilah progres + baris Subtotal dari server (`subtotal`/`total`), pencarian baris klien, ekspor Excel → `GET statistik/{jenis}/xlsx` lewat helper blob `berkasUnduhan`, galat API → notice (`describeApiError`). Testid: `laporan-page`, `laporan-lingkup`, `laporan-table`, `laporan-row-<kategori_id>`, `laporan-subtotal`, `laporan-export`, `laporan-failure`, `laporan-empty`.
- **Service** `laporan.service.ts` (lingkup, statistik, xlsx; angka string MySQLi dinormalkan), `types.ts` (kontrak API + `LAPORAN_ROLES`, 7 tipe, palet), `routes.laporan.ts`.
- **Komponen bersama baru** `shared/ui/UiBarChart.vue` (disalin apa adanya dari mockup `67fa767`) + ekspor di `shared/ui/index.ts`.
- **Berkas bersama (aditif, commit terpisah):** `router/index.ts` (+`laporanRoutes`), `nav.config.ts` (item `laporan`: +`roles`, anak 3 → 7; `Struktural` → `/laporan/eselon`; tetap `phase: 7`, **tanpa** `rilis`, `ACTIVE_PHASE` tidak disentuh) + `nav.config.spec.ts`.
- **Tidak ada aksi per baris** (tidak ada `RowActionsMenu`; tombol ekspor = level halaman).
- **Test:** `features/laporan/__tests__/LaporanPage.spec.ts` (8 test: muat lingkup+statistik, pilih lingkup → parameter, pindah tipe, pencarian, ekspor xlsx, 403, `lingkupKeQuery`, `isLaporanTipe`); `nav.config.spec.ts` (7 anak + role 1,3,4,5,8 + tetap tersembunyi).
- **Catatan:** ekspor hanya Excel (F7-11); QA UI lewat URL langsung karena menu `phase: 7` (F7-14, Q-2); mockup hanya menggambarkan 3 tipe — 4 tipe lain memakai layout yang sama (PRD Q-3).

## M3 — MAKE-037 backend F-09 (21-10-2026, di `main` `6a03b19`)

Keputusan PRD yang dipakai: F7-15..F7-25, F7-27 (tanpa skema, F7-26). Ringkasan implementasi:

- **Endpoint** `GET api/v1/dokumen/{duk|nominatif|pensiun|baperjakat}` (JSON berhalaman), `GET dokumen/{duk|nominatif|pensiun}/{pdf|xlsx}`, `POST dokumen/baperjakat/{pdf|xlsx}` (role 1, 3, 4, 5, 8; role 3 lingkup satker/unit akun). Kontrak lengkap di README modul (bagian F-09).
- **Library** `Libraries/Dashboard/Dokumen/*`: `DokumenService` (orkestrasi, lingkup, throttle 6/menit, audit, fallback PDF), `KueriDaftarPegawai` (satu builder `pegawai` + snapshot + master per dokumen, `PegawaiScope`), `UrutanDuk` (F7-17, ORDER BY SQL), `KolomDokumen` (37/11/37 kolom XLSX, subset PDF, format `d M Y`, eselon), `ProyeksiPensiun` (F7-20), `BaperjakatInput` (F7-21), `RenderDokumenPdf`; `Libraries/Pdf/KopDokumen.php` (aditif, `web_config`, tanpa logo).
- **Audit** `audit_logs` `dokumen_unduhan`/`<jenis>`/`create` sesudah berkas dibangun (fail-closed); 401/403/404/422/429 tidak diaudit.
- **Fallback PDF F7-15 (LOCKED, ADR-034).** Percobaan pertama uji volume: PDF DUK lingkup kementerian 5.000 baris kehabisan memori TCPDF di 512 MB. Karena itu:
  - PDF hanya `lingkup=unit|satker`. Lingkup kementerian → 422 `errors.lingkup` "PDF hanya tersedia per unit/satker.".
  - PDF maksimal **1.700 baris** (`DokumenService::PDF_MAKS_BARIS`). Di atas batas → 422 `errors.lingkup` "Persempit lingkup atau unduh XLSX.". Batas yang sama dikenakan pada jumlah NIP Baperjakat PDF (`errors.nip`; XLSX tetap s.d. 2.000 NIP).
  - Lingkup dan batas baris dicek sebelum throttle dan sebelum render (`COUNT` dulu). Pensiun pengecualian: batas baru diketahui sesudah kueri, karena disaring di PHP menurut BUP.
  - XLSX tetap untuk seluruh lingkup.
- **Hasil uji volume** (`DokumenVolumeTest`, 5.000 PNS sintetis; mesin i7-11370H, RAM 15,7 GB, Windows 11, PHP 8.4.2, MySQL 8.0.30, `memory_limit` 1G, memori puncak per permintaan termasuk dasar proses uji 34–62 MB):

  | Skenario | Baris | Waktu | Puncak | Target |
  |---|---|---|---|---|
  | PDF DUK kementerian (sebelum fallback) | 5.000 | — | > 512 MB | gagal |
  | PDF DUK satker | 2.000 | 13,2 s | 270 MB | > 256 MB |
  | PDF DUK satker (batas) | 1.700 | 10,2–10,6 s | 238 MB | ≤ 30 s / 256 MB ✓ |
  | XLSX DUK kementerian | 5.000 | 0,9–1,0 s | 104 MB | ≤ 10 s / 128 MB ✓ |

  Batas 1.700 dipilih agar satker sekitar 1.667 pegawai (5.000 dibagi rata ke 3 satker) masih lolos; kenaikan memori PDF sekitar 112 MB per 1.000 baris. XLSX semula memuncak 242 MB. Penyebabnya tiga salinan penuh baris hidup bersamaan; sekarang baris dilepas satu per satu saat dipetakan, sehingga turun ke 104 MB.
- **Uji volume di CI:** di-skip kecuali env `RUN_VOLUME_TEST=1` (`markTestSkipped` di `setUp`). `phpunit.dist.xml` (berkas bersama) tidak diubah. Jalankan lokal setiap kali `PDF_MAKS_BARIS`, kolom PDF, atau `TcpdfRenderer` berubah:
  `RUN_VOLUME_TEST=1 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Dashboard/DokumenVolumeTest.php`.
- **Penyederhanaan dari PRD §7.2.1:**
  - Satu view generik `Views/pdf/dokumen_daftar.php` untuk keempat jenis, menggantikan empat view `pdf/{duk,nominatif,pensiun,baperjakat}.php`. Kolom PDF per jenis datang dari `KolomDokumen::kolomPdf()`, judul/subjudul/teks Baperjakat lewat parameter.
  - `KopDokumen::html()` menerima koneksi DB opsional (untuk test).
  - Test PDF, audit, dan kop digabung di `tests/Dashboard/DokumenUnduhTest.php`, menggantikan `DokumenPdfTest`, `DokumenAuditTest`, dan `KopDokumenTest` terpisah.
- **Berkas bersama (aditif):** `tests/feature/KontrakMatriksRoleTest.php` (prefiks `dokumen` + baris kontrak route, satu commit dengan `Config/RoutesDashboard.php`), `docs/adr/README.md` (+1 baris ADR-034). `Routing.php` tidak berubah (entri `RoutesDashboard.php` sudah ada sejak M1).
- **Test:** unit `tests/unit/Dashboard/{UrutanDukTest (6), KolomDokumenTest (5), ProyeksiPensiunTest (3), BaperjakatInputTest (3)}.php`; feature `tests/Dashboard/{DokumenEndpointTest (5), DokumenScopeTest (3), DokumenSnapshotTest (1), DokumenUnduhTest (8), DokumenVolumeTest (1, skip default)}.php`; fixture `tests/_support/Dashboard/DokumenUjiTrait.php`.
- **Pertanyaan terbuka:** PRD Q-6 (nomor ADR-034, mohon konfirmasi reviewer) dan Q-9 (angka batas volume untuk mesin dev; batas 1.700 dapat disesuaikan setelah melihat spesifikasi server). `memory_limit` PHP produksi untuk endpoint PDF perlu ≥ 256 MB (dicatat untuk runbook deploy). **Q-10** (dari M1, tetap terbuka): `RiwayatEngine::tambah` alur Admin tidak memicu `EVENT_NOTIFIKASI`. Ini tidak berdampak pada F-09 karena dokumen tanpa cache dan selalu kueri langsung; dampaknya hanya pada kesegaran statistik F-03 (≤ TTL).

## M4 — MAKE-037 frontend F-09 (21-10-2026)

- **Halaman** `frontend/src/features/dokumen/views/DokumenPage.vue` (`/dokumen/:jenis(duk|nominatif|pensiun|baperjakat)`, `meta.roles` 1/3/4/5/8).
  - Pemilih lingkup dari `GET statistik/lingkup`, sama dengan laporan F-03.
  - Pencarian nama/NIP dan filter `tahun`/`sampai` Pensiun dikirim ke server dengan jeda 300 ms.
  - Tabel berhalaman (20 per halaman) dengan kolom inti per jenis; label dari `kolom` respons, kolom lengkap ada di XLSX.
  - Tombol level halaman "Unduh PDF" / "Unduh XLSX" lewat helper blob.
- **Fallback PDF F7-15 di UI (ADR-034):**
  - Tombol PDF dinonaktifkan dengan alasan tertulis bila lingkup efektif kementerian (dipilih, atau bawaan akun role 1/4/5/8) atau `total` > 1.700 baris. Untuk Baperjakat: lebih dari 1.700 pegawai terpilih.
  - XLSX selalu aktif.
  - Pesan 422 server tetap ditampilkan apa adanya. Contohnya bila data berubah sesudah daftar dimuat: "Persempit lingkup atau unduh XLSX.".
  - Body galat unduhan datang sebagai Blob (`responseType: 'blob'`) dan tidak terbaca interceptor, jadi dibaca `pesanGalatUnduhan()` di `dokumen.service.ts`. `lib/axios` tidak diubah.
- **Baperjakat:**
  - Centang per baris + "Pilih semua di halaman ini"; pilihan bertahan lintas halaman.
  - Form judul (wajib, ≤ 150), subjudul (≤ 150), teks (≤ 1.000) → `POST dokumen/baperjakat/{pdf|xlsx}` dengan `nip[]` terpilih.
  - Validasi klien: minimal 1 pegawai dan judul wajib. 422 server (`errors.nip`, mis. NIP di luar lingkup) tampil sebagai notice.
- **Aksi per baris:** `RowActionsMenu` ⋮ "Lihat DRH" → `GET pegawai/{nip}/drh` (unduh). Testid `dokumen-actions-<nip>`, aria-label "Aksi untuk <nama>".
- **Testid:** `dokumen-page`, `dokumen-lingkup`, `dokumen-table`, `dokumen-row-<nip>`, `dokumen-unduh-pdf`, `dokumen-unduh-xlsx`, `dokumen-pdf-alasan`, `dokumen-unduh-galat`, `dokumen-failure`, `dokumen-baperjakat`, `dokumen-pilih-<nip>`, `dokumen-pilih-halaman`, `dokumen-actions-<nip>`.
- **Berkas bersama (aditif, commit terpisah):** `router/index.ts` (+`dokumenRoutes`), `nav.config.ts` (item `dokumen` + 4 anak, `roles` 1/3/4/5/8, tetap `phase: 7`, **tanpa** `rilis`, `ACTIVE_PHASE` tidak disentuh) + `nav.config.spec.ts`.
- **Test:** `features/dokumen/__tests__/DokumenPage.spec.ts` (10 test):
  - muat + kolom inti + XLSX kementerian;
  - PDF terkunci kementerian → satker aktif;
  - batas 1.700 + 422 Blob;
  - Pensiun tahun/sampai;
  - ⋮ Lihat DRH;
  - 403;
  - Baperjakat validasi + POST;
  - Baperjakat lintas halaman + 422 `errors.nip`;
  - `paramsDokumen`;
  - `pesanGalatUnduhan`.

  `nav.config.spec.ts` (+1: Dokumen 4 anak, role 1/3/4/5/8, tetap tersembunyi). Uji mutasi: pembacaan body Blob dimatikan → 3 test gagal.
- **Catatan:** QA visual oleh coder 2 lewat URL langsung (menu `phase: 7`, F7-14, PRD Q-2), tanpa preview browser di M4. Mockup halaman Dokumen tidak ada; layout mengikuti halaman laporan (PRD Q-3).
