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

## F-13 Frontend Dashboard — MAKE-087 (Jalur D, PRD Fase 7 v2.0 §5.10)

### M1 — kerangka + `Config\Dashboard` (branch `fase7/make-087-dashboard`)

- `Config\Dashboard`: `$tampilanKomposisi` donut/angka (Q7-36), `$nipDisembunyikan` (Q7-41, `.env` server saja),
  `$idJabatanDirektur` (Q7-38), `$ttlRingkasanDetik`.
- `GET api/v1/dashboard/ringkasan` (jwt): keluarga admin = 11 tile `dashboard_ad` (komposisi + status hari ini, persen
  terhadap total aktif) per lingkup kementerian/satker/unit, letak pengecualian NIP & TB perpanjangan ikut legacy;
  keluarga pegawai = kerangka.
- FE `HomeView` → `AdminDashboard` (`KomposisiPegawaiCard` dua mode) / `PegawaiDashboard` (kerangka); `home-role` tetap.
- Berkas bersama (aditif, commit terpisah): `Config/RoutesDashboard.php`, `tests/feature/KontrakMatriksRoleTest.php`
  (+route, +prefiks `dashboard`), `README-deploy.md` §1k, progres ini.
- Test: `tests/database/Dashboard/RingkasanBerandaTest.php` (11), `features/dashboard/__tests__/{HomeView,beranda.amplop}.spec.ts`.
- Sisa M2: pengingat Pensiun/KP/TJ/KGB, ulang tahun (Q7-45), direktur (Q7-38), keluarga pegawai (prediksi, kelengkapan,
  bawahan), `UiCarouselPanel`. M3: pasang komponen Jalur A/B/C.

### M2a — beranda admin (branch yang sama)

- BE: `pengingat` di `dashboard/ringkasan` (`PengingatBeranda` + `ProyeksiTandaJasa` port `get_all_tj`; kalkulasi KP/KGB/Pensiun
  memakai ulang kelas murni `Integrasi\Stat` tanpa mengubahnya), `GET dashboard/direktur` (`DirekturBeranda`, role 1/4).
- FE: `PengingatCards`, `ButuhApprovalCard` (adapter sementara kontrak Jalur C), `DirekturCard` di `AdminDashboard`.
- Test: `PengingatBerandaTest` (6), `DirekturBerandaTest` (3), `ProyeksiTandaJasaTest` (12), `HomeView.spec` (+M2a).
- Terbuka: Q-D12, tepi [I] KP satker/unit, ulang tahun (menunggu reviewer). Berikutnya M2b keluarga pegawai.

### M2b (tahap 1) — prediksi pegawai

- BE `PrediksiPegawai` (port `next_kp` sendiri karena `RekomendasiKp::deteksi`/`get_all_kp` hanya tahun berjalan; `next_tj` =
  `ProyeksiTandaJasa::prediksiPegawai`; KGB `RekomendasiKgb`; AK JF `AkPermenpanrb`); FE `PrediksiCards`.
- Test: `PrediksiPegawaiTest` (6), `ProyeksiTandaJasaTest` (+8 next_tj), `HomeView.spec` (+6).
- Tahap 2: `BawahanPegawai` + `BawahanCards` (presensi & AK bawahan atasan role 2); `BawahanPegawaiTest` (3), `HomeView.spec` (+3).
- Tahap 3: deviasi [V2] Tanda Jasa satker/unit + saklar rollback (Horii 10-11), fixture percepatan next_kp, tautan Presensi
  Saya (Q7-42; komponen Fase 5 tidak ada), ulang tahun (`UlangTahunBeranda`, `dashboard/ulang-tahun` + foto Q-D19,
  Config Q-D20) + `UiCarouselPanel`. Q7-44 dikoreksi: tautan update FK PTT legacy dikomentari → tidak dipasang.
- Sisa M2: kelengkapan data (`kd_*`, Q-D13..D17 menunggu Horii), Q-D18 (pengecualian NIP ulang tahun), Q-D12.
- 10-11: `ButuhApprovalCard` memakai `useRingkasanNotifikasi()` asli `@/features/notifikasi` (F-08 MAKE-085 di `main`);
  adapter sementara dihapus; ikut aturan lonceng (`ACTIVE_PHASE < FASE_LONCENG` → "—" tanpa tautan/polling).
- Lanjutan MAKE-087 (branch `fase7/make-087-kelengkapan`, 10-11): kelengkapan data `KelengkapanData` + `KelengkapanCards`
  (Q-D13..Q-D17 A), `.env` rahasia `dashboard.penggunaLihatSemua` (Q-D12 A) dan `dashboard.nipDikecualikanUlangTahun`
  (Q-D18 A). Sisa: M3 (komponen Jalur A dan B).
- M3 bagian A (branch `fase7/make-087-m3`, 11-11, dari `main` `f2d5580a`): `BannerCarousel` (semua role, Q7-39) +
  `PopupAktifDialog` di `HomeView`, `BeritaTerbaruWidget` di kedua keluarga, digerbang `FASE_KONTEN = 7` (tanpa request
  selama `ACTIVE_PHASE < 7`, review 11-11); `HomeView.konten.spec` (7, fase 7) + `HomeView.spec` (+2, fase 6). Popup form digital/SKL
  sudah dipasang Jalur B di shell. Sisa M3: ringkasan SKL admin (Q7-26) menunggu endpoint rekap Jalur B.

### Jalur A — konten admin (MAKE-079 Popup, MAKE-080 Banner), branch `fase7/make-079-popup` (lokal, belum di main)

- BE: `Libraries/Dashboard/Konten/Penargetan/{SpesifikasiTarget,PemekarTarget,SinkronPenerima,TabelPenerima}` (dipakai juga Jalur B
  untuk form digital), `Konten/Gambar/{JenisGambar,PenyimpananGambar,KontenGambarService}`, `Models/Konten/{Popup,Banner}Model`
  (audit otomatis, Q7-16), `Controllers/Api/Konten/{Popup,Banner}Controller`, `Config/RoutesKonten.php`, `Config/Konten.php`.
- Endpoint (`api/v1/konten/{popup|banner}`): daftar, buat/ubah (POST multipart `berkas`), hapus, `urutan` naik/turun, `target`,
  `penerima`, `aktif` (role 2–8), `gambar` (terautentikasi; role 1 atau penerima, selain itu 404); `konten/opsi/{unit-satker,pegawai}`.
- Keputusan: penargetan pegawai eksplisit ikut legacy (tanpa cek status; diabaikan bila "0"); gambar privat kunci acak,
  banner wajib rasio 4:1 (±2%) lebar 800–4000 px, popup sisi 200–4000 px (angka ⚑ keputusan v2, `Config\Konten`); popup periode
  DATETIME WIB, banner DATE; popup/banner tanpa status/soft delete (Hapus permanen) sampai DBV-032 menetapkan lain.
- ⚑ Bergantung draf DBV-032 (kolom bisa berubah setelah D1): `popup/banner.{tgl_*,order,url,file,keterangan,target_uns,target_peg,excl_peg}`,
  `user_popup/user_banner`, nama kolom induk di `TabelPenerima`.
- Test: `SpesifikasiTargetTest` (12), `KontenPenargetanTest` (4), `KontenGambarServiceTest` (9), `KontenGambarEndpointTest` (3),
  `KontrakMatriksRoleTest` (+20 baris kontrak). Test database memuat migration draf `MigrationsDitahan/` (grup `db-isolasi-penuh`).
- FE: `features/konten` (kelola popup/banner/berita/DIBO/master, portal berita, DIBO baca, `GambarKonten` blob, `TargetPicker`,
  `UiTeksSederhana`); rute dan menu `phase: 7`.
- Sisa: berita (MAKE-081, sanitasi HTMLPurifier + approval + `SumberBerita`), DIBO (MAKE-082); antrean dialog menunggu MAKE-083.

#### Jalur A — M3 F-07 Berita + master (MAKE-081, bagian dari MAKE-082), branch `fase7/make-079-popup`

- BE: `Konten/Berita/{BeritaService,SanitasiBerita,SumberBerita}`, `Konten/{JenisMaster,MasterKontenService}` (kategori berita + jenis DIBO),
  `Models/Konten/{News,NewsCategory,JenisDibo}Model`, `Controllers/Api/Konten/{Berita,BeritaKategori,DiboJenis,MasterKonten}Controller`,
  rute di `RoutesKonten.php` (`konten/berita*`, `konten/berita/kategori*`, `konten/dibo-jenis*`).
- Aturan: baca semua role (terbit + `publish_at` + target kementerian/unit/satker; role 1 semua yang terbit di portal dan semua di detail; detail =
  aturan portal, perbaikan anomali legacy); kelola role 1,3; role 3 → menunggu, kategori `allow_ap`, hanya miliknya; ubah oleh role 3
  kembali menunggu [V2]; proses role 1 (alasan wajib bila tolak, hanya status 0); isi disanitasi HTMLPurifier `p,br,strong,em,ul,ol,li,a[href]`;
  `news_flag` per berita (tanpa DELETE global legacy), `POST konten/berita/dibuka` mereset penanda pembaca; publish_at disimpan UTC (masukan WIB).
- `SumberBerita` (kontrak A↔C) siap + teruji, tetapi binding `Services::sumberNotifikasiBerita()` kini memakai `SumberBerita` (11-11, setelah DBV-032 di `main`);
  `PusatNotifikasiTest` menguncinya (berita = 0 tanpa berita terbit; tiket tetap null sampai Fase 8). Tanpa `tableExists` di kode konten.
- Belum dibawa: lampiran berita (`news.lampiran`, FE belum memakai), hitung berita per kategori. Nilai awal `news.show_notif` ⚑ D1.
- Test: `BeritaServiceTest` (12), `SumberBeritaTest` (4), `KontrakMatriksRoleTest` (+19 baris), FE `KelolaTeks.spec` (+penolakan beralasan).

- **Hapus popup/banner = fisik (bukan hapus lunak)**, bukti legacy `application/libraries/hr/L_announcement.php`: `delete_popup` (:313) memanggil
  `m_simpeg->delete("popup", "id_popup='{$id}'")` lalu `unlink(PS_BASE . $popup['file'])` setelah commit; `delete_dashban` (:1467) idem untuk `banner`.
  Tabel `popup`/`banner` legacy tanpa kolom status (DBV-032 §3.1/3.3). v2: baris + berkas gambar privat dihapus; `user_*` ikut lewat FK CASCADE;
  audit Q7-16 menyimpan snapshot baris sebelum dihapus (`BaseAuditableModel`, dicek di `KontenGambarServiceTest::testGeserDanHapus`).
- **Pemilih pegawai target = pencarian server** (`GET pegawai?search=`, min. 3 huruf, debounce 300 ms, pola `PegawaiPicker` Modul C); daftar semua
  pegawai TIDAK dikirim. `GET konten/{popup|banner}/{id}/target` menambah `label_pegawai` agar form ubah menampilkan nama.
- Sanitasi berita memakai ulang `Libraries/Html/HtmlSanitizer` (kini menerima whitelist opsional; default tidak berubah) dengan `p,br,strong,b,em,i,ul,ol,li,a[href]`.
- CI memakai `DBPrefix = t_` untuk grup `tests`: SQL mentah wajib `prefixTable()`; `select()` beralias harus SETELAH `join()`; total dari builder terpisah
  tanpa join (clone + countAllResults mengosongkan pelacakan alias koneksi CI4).

- **Berita — paritas legacy (cek 10-11, `application/libraries/hr/L_news.php`, `views/hr/news/*`):**
  - **Lampiran DIBAWA** (legacy memakainya): unggah satu berkas JPG/JPEG/PNG/GIF/PDF ≤ 5 MB (`insert:282-340`, `update:489-545`), "Lihat Lampiran" di detail
    (`detail.php:15`), ikon di daftar (`list.php:97`), berkas lama dihapus setelah commit saat diganti (`:717`) dan saat berita dihapus (`:812`); ubah tanpa
    berkas mempertahankan lampiran lama (tanpa opsi hapus). v2: penyimpanan privat kunci acak `berita/<hex32>.<ext>` (perbaikan `nl_{publish_at}` yang bisa
    saling menimpa), `GET konten/berita/{id}/lampiran` terautentikasi dengan visibilitas yang sama dengan detail.
  - **Jam terbit** dibawa (`pub_date` + `pub_time` wajib di `form.php:62-66`): masukan WIB `Y-m-d H:i`, disimpan UTC.
  - **KEPUTUSAN Horii 10-11: ubah berita oleh role 3 LANGSUNG BERLAKU seperti legacy [K]** — default `Config\Konten::$beritaUbahRole3KembaliMenunggu = false` (saklar + test kedua nilai dipertahankan). Catatan awal:
  - **Role 3 mengubah berita → kembali menunggu = [V2], BUKAN legacy**: form ubah legacy tidak punya field status, `update` (`:489`, `set_param:877` hanya
    mengisi status bila terkirim) membiarkan status. Diberi saklar `Config\Konten::$beritaUbahRole3KembaliMenunggu` (default true; false = paritas legacy) —
    menunggu keputusan Horii.
  - Target tunggal (`set_param:894-925`: `sat_<id>` → satker, `0` → kementerian, selain itu unit) = skema [K]. Salinan `category`/`unit_satker_target` tidak dibawa
    (diturunkan saat baca). `news.show_notif` nilai awal menunggu D1.

## F-08 Pusat Notifikasi — MAKE-085 (Jalur C, PRD Fase 7 v2.0 §5.9)

- 10-11: BE + FE di `main` `7cacf4d5`. Kontrak: `app/Controllers/Api/Notifikasi/README.md`, `frontend/src/features/notifikasi/README.md`.
  Butir terbuka: histori PIC TB/IB/PMK (`show_pl`) DITUNDA (Horii 10-11); sumber berita AKTIF sejak 11-11 (`SumberBerita`, F-07 + DBV-032); tiket tetap null sampai Fase 8.
- 10-11 CR-113 (CI #711 merah, 6 failure `PusatNotifikasiTest`, hanya pada proses pertama di DB baru): `SumberShowNotif` dan
  `HistoriApproval` memakai `tableExists($tabel)`. Mode cache CI4 bisa basi, karena tabel dari `CREATE TABLE` SQL mentah
  tidak masuk cache `table_names`; mode `false` tanpa prefix → `SHOW TABLES LIKE` tanpa `t_`. Diganti ke
  `tableExists($db->prefixTable($tabel), false)`. Ditambah test regresi cache basi (`PusatNotifikasiTest`) dan guard
  `tests/unit/Arsitektur/TableExistsBerprefixTest` (semua `->tableExists(` di app/).
- Catatan: `getFieldNames()` CI4 juga ber-cache per tabel (`dataCache['field_names']`). Di modul ini aman karena kolom
  sumber tidak diubah sesudah dibaca dalam satu proses. Bila kelak ada migration/test yang mengubah kolom di tengah
  proses, pakai `information_schema.COLUMNS`.

#### Jalur A — F-12 DIBO inti (MAKE-082), branch `fase7/make-079-popup`

- BE: `Libraries/Dashboard/Konten/Dibo/{DiboService,PenyimpananDibo}`, `Models/Konten/DiboModel`, `Controllers/Api/Konten/DiboController`, rute
  `konten/dibo*` di `RoutesKonten.php`; master `jenis_dibo` lewat `MasterKontenService` (sudah ada, M3).
- Aturan (legacy `L_dibo.php:77-270,546-608`, views `hr/dibo`): wajib jenis (aktif), nomor, tahun, tentang; `file_1` wajib saat tambah; `file_1..3`
  PDF/XLS/XLSX/DOC/DOCX ≤ 5 MB (paritas), tipe dari ISI berkas; ubah: berkas tak dikirim dipertahankan, `hapus_file_2/3 = 1` menghapus (padanan
  `deleted_file[]`), berkas lama dihapus setelah commit. Berkas PRIVAT kunci acak `dibo/<hex32>.<ext>` (legacy: `assets/upload/dibo` publik di webroot),
  unduh `GET konten/dibo/{id}/berkas/{1..3}` terautentikasi (attachment, no-store).
- **Baca semua role HANYA status 1** (Q7-13) — perbaikan anomali legacy: filter status dikomentari (`L_dibo.php:12-27,62-66`) sehingga semua status tampil ke
  semua role; admin (role 1) juga boleh mengunduh berkas status 2/10. Legacy `detail` menolak role 1 (`userAuth` 2–8): v2 mengizinkan. Filter jenis/tahun/cari
  nomor|tentang + paginasi. Status 1 aktif / 2 dicabut [K] / 10 dihapus [V2: hapus lunak, berkas dipertahankan, bisa dipulihkan]. Audit Q7-16 otomatis.
- Test: `DiboServiceTest` (8), `DiboEndpointTest` (3), `KontrakMatriksRoleTest` (+6 baris); FE `KelolaTeks.spec` + `BeritaLampiran.spec` (multipart DIBO).
- Tidak dibawa/menunggu: duplikat jenis+nomor+tahun tidak dicek (legacy juga tidak; anomali dicatat M0 §4.2).

- **Self-review keamanan unggah/unduh (Jalur A, 10-11)** — popup/banner, lampiran berita, DIBO: (1) indeks berkas DIBO di luar 1..3 (termasuk 0, -1, 99, 2^32) → 404 (service + HTTP); (2) kunci
  penyimpanan di DB yang rusak/diubah (`../`, `dibo/../../x`, path absolut, byte nol) tidak bisa membaca berkas di luar akar — TEMUAN: adapter melempar
  `InvalidArgumentException` sehingga hasilnya 500; kini `ada()` pada `PenyimpananGambar/Lampiran/Dibo` menganggap kunci tak valid = tidak ada → 404; (3) nama unduhan
  hanya dari id angka + ekstensi `[a-z0-9]{1,5}` (selain itu `bin`) lewat `NamaBerkas::ekstensi()` — CR/LF, kutip, titik-koma, garis miring tidak pernah masuk header;
  (4) berkas palsu ditolak 422 (PHP/HTML/SVG berskrip/PDF yang menyamar .pdf/.png/.docx, ekstensi terlarang .php/.html/.svg, ZIP polos bernama .docx, berkas kosong). Test:
  `NamaBerkasTest` (13), `DiboServiceTest` (+3 skenario, 10 kasus palsu), `BeritaLampiranTest` (+3), `KontenGambarKeamananTest` (baru), `DiboEndpointTest` (+indeks).
  Tambahan: kunci tak valid yang terdeteksi dicatat `log_message('warning', 'Kunci berkas {jenis} tidak valid (data diubah?): id={id}')` — HANYA jenis konten
  (`popup`/`banner`/`lampiran-berita`/`dibo`) + id baris, bukan isi kunci/path (tanda data diubah; log tidak memuat masukan berbahaya). Diuji dengan `assertLogged`.
