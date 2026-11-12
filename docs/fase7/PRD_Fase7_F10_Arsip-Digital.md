# PRD F-10 Arsip Digital v1.1 (draf untuk ditinjau Horii)

| Atribut | Isi |
|---|---|
| Versi | **v1.1 draf, 12-11-2026** (CR-120). Disusun reviewer CR dari dokumen kerja `Mapping_F-10_Arsip_Digital.md` (folder kerja Horii, tidak di repo) dan kode `main` `0af9be4f`. Temuan dua peninjau (lensa keputusan/legacy dan lensa teknis) sudah dimasukkan |
| Status | **DRAF, menunggu persetujuan Horii.** Keputusan Horii 12-11-2026 (#1–#18) **sudah final** dan dicatat apa adanya (§3.1). Butir F10-19 dst. adalah bawaan usulan reviewer yang masih perlu dikonfirmasi Horii (§3.2). Untuk bawaan §3.2, reviewer condong ke perilaku legacy bila tidak menyentuh pola keamanan v2 (F10-09), tetapi semuanya tetap usulan yang menunggu konfirmasi Horii (QF10-6). Horii 12-11-2026 menjawab permintaan konfirmasi bawaan, R8, dan prioritas pratinjau atas CSP dengan "Yes, decision ini berdasarkan simpeg legacy basecode", lalu meminta **Plan B versi penyesuaian** sebagai pembanding (§14) untuk ditinjau |
| Scope | F-10 Arsip Digital (Trello QASMTASK-107). Ditunda sejak 20-10-2026, dibuka kembali Horii 12-11-2026 sesudah sign-off Fase 7 (11-11-2026). Dikerjakan dalam dua tahap (§5, §6) |
| Calon path | `docs/fase7/PRD_Fase7_F10_Arsip-Digital.md` |
| Izin kerja | Belum ada. Kode izin workload diajukan reviewer **sesudah** PRD ini disetujui (F10-13, §13) |
| Acuan | PRD Fase 7 v2.1 (`docs/fase7/PRD_Fase7_v2_Dashboard-Konten-Notifikasi.md`: pola format, §2 aturan, §11 #16 gerbang fase); `docs/migrasi/MAKE-069-rencana.md` §4 (K-M3 opsi A); `AGENTS.md` §1–§2; PRD 01-10-2026 J3.06 (folder kerja, **gugur**, F10-02); DoD Bahan Baku `07-Dashboard.md` (F-10 dan butir explorer F-13); Mapping F-10 §2–§8 |

**Ringkasan untuk Horii.** F-10 membuat halaman baru bernama **"Arsip"**. Halaman ini menampilkan semua berkas lampiran seorang pegawai yang tercatat di tabel `document_attachment`, yaitu:
- lampiran riwayat;
- Arsip Data Umum (kode 37/38);
- arsip yang belum terhubung (kode 0);
- lampiran layanan: PMK (kode 42, sudah ada di legacy) serta cuti/TB/IB (kode 43–50, baru di v2).

Berkas dikelompokkan ke dalam "folder" per jenis riwayat. Folder ini hanya cara menampilkan, tidak ada tabel folder baru. Berkas yang tidak ada di disk diberi tanda **"Berkas hilang"** dan tidak menimbulkan galat.

Berkas dibuka **persis seperti legacy**: lewat alamat publik langsung yang disajikan server web (Nginx), tanpa pemeriksaan izin per berkas, dan tampil di jendela popup. Ini keputusan sadar Horii. Risikonya dicatat di §7 dan Lampiran A, termasuk satu akibat yang perlu Horii ketahui: lampiran layanan cuti/TB/IB (termasuk surat cuti ber-TTE) juga bisa dibuka lewat alamat publik (R8).

Tahap 1 hanya untuk melihat. Tahap 2 menambah aksi legacy: Hubungkan, Ubah nama, Hapus arsip yatim, Ekspor, dan filter "belum terhubung". Aturan aksinya **persis legacy**, tetapi pola keamanan v2 tetap dipakai. Unggah tetap lewat form riwayat/Data Umum. Skema database tidak berubah, jadi DB Validator tidak diperlukan.

**Plan B (§14).** Atas permintaan Horii, PRD ini juga memuat versi penyesuaian sebagai pembanding: per butir dicatat status legacy, status v2 sekarang, gap, serta kelebihan dan kekurangannya. Plan A (persis legacy) tetap rencana yang berjalan sampai Horii memilih butir Plan B tertentu.

## Cara pakai (WAJIB dibaca)
1. Label perilaku:
   - **[K]**: ikut kode legacy. Rujukan `legacy:` = folder `application/` repo legacy.
   - **[V2]**: berbeda dari legacy, alasannya ada di register.
   - **[I]**: inferensi yang belum terbukti, dicek saat D1/QA.
2. Rujukan kode v2 memakai path relatif repo `main` `0af9be4f`.
3. Pertanyaan baru diajukan ke reviewer, lalu reviewer meneruskannya ke Horii. Jangan menebak keputusan bisnis. Keputusan F10-01..F10-18 tidak dibuka ulang.
4. Repo ini publik. Jangan menulis NIP, IP, host, atau kredensial di kode, test, log, maupun dokumen. Contoh URL memakai `<nip>`/`<kunci>`.

---

## 0. Gerbang & prasyarat
| ID | Prasyarat | Menahan |
|---|---|---|
| G10-1 | PRD ini sudah ada di `main` (izin Horii) | Semua koding F-10 |
| G10-2 | Kode izin workload untuk pelaksana (F10-13) | Mulai koding paket MAKE-090..093 |
| G10-3 | **D1 F-10**: Horii menjalankan paket `Downloads/MenParekRaf/D1_f10` (`BACA_DULU_permintaan_D1_f10.md`) di **salinan dev legacy (simpegdev)**, bukan produksi. Angkanya diverifikasi ulang di produksi saat cutover | Angka di §10 dan pelaporan QF10-2. **Tidak** menahan koding Tahap 1 |
| G10-4 | Setelan Nginx `/berkas/` (§7.3) terpasang di server dev dan sudah diverifikasi: user Nginx bisa membaca berkas, `curl -I` memberi header yang benar, serta pratinjau PDF/JPG/PNG tampil di Chrome, Edge, dan Firefox. Hasilnya dicatat di README-deploy §1l | QA Lapis 1 F-10 |
| B10-1 | **Blocker DEPLOY/cutover** (F10-03): penanggung jawab disk, kapasitas + pemantauan, backup folder berkas, retensi berkas yatim (sudah dikomentari di Trello DEPLOY-002). Tambahan syarat teknis: tidak boleh ada symlink di dalam akar berkas. Bila disk dipindah, mount langsung ke path akar, bukan lewat symlink (§7.3) | Cutover. Tidak menahan koding/QA |
| B10-2 | **Blocker cutover di luar F-10** (F10-14): QR surat cuti ber-TTE legacy yang menunjuk server arsip lama (Trello DEPLOY-002, belum diputus). Catatan teknis: alias `/berkas/arsip/…` (§7.3) secara teknis membuka opsi redirect QR lama ke v2 | Mematikan server arsip legacy |
| B10-3 | Setelan Nginx `/berkas/` di server produksi + README-deploy §1l (§7) | Rilis F-10 ke produksi |

## 1. Scope
### 1.1 Di dalam scope
| Bagian | Isi | Tahap | Key usulan |
|---|---|---|---|
| Indeks arsip (BE) | Folder agregat per `jenis_rwy` + daftar berkas berhalaman + penanda berkas hilang / tidak dapat dibuka + URL berkas + catatan pembukaan daftar (F10-17) | 1 | MAKE-090 |
| Halaman Arsip (FE, modul Kepegawaian) | Route, menu `arsip`, pemilih pegawai (admin), breadcrumb, folder, tabel berkas, ⋮ "Buka", tautan dari Detail Pegawai, label tombol lama → "Arsip Data Umum" | 1 | MAKE-091 |
| Aksi legacy | Hubungkan, Ubah nama (Edit), Hapus arsip yatim, Ekspor Excel/CSV, filter "Belum terhubung" + jenis di tampilan "Semua berkas" | 2 | MAKE-092 |
| Penyajian berkas | Alias Nginx `/berkas/` (kunci berkas v2, berkas legacy `arsip/...`, dan `assets/upload/...` F10-33), rute khusus mesin dev, README-deploy, koreksi dokumen usang | 1 | MAKE-093 |
| Penutup | QA Lapis 1 F-10 + catatan sign-off | — | Reviewer |

### 1.2 Di luar scope
| Hal | Alasan / milik |
|---|---|
| QR surat cuti ber-TTE legacy ke server arsip lama | F10-14: blocker cutover di luar F-10 (DEPLOY-002) |
| Berkas yang disimpan di kolom tabel lain (foto pegawai, surat cuti `file_*`, karpeg `file_1..5`, SKP periodik `f_arsip_*`, bukti izin presensi, dll.) | F10-05: tidak ditampilkan di halaman Arsip (legacy pun tidak, `legacy:libraries/hr/L_arsip.php:12`). Catatan: berkas kolom lain yang kebetulan berada di subtree `arsip/` atau `assets/upload/` tetap bisa dibuka lewat URL publik, seperti di legacy (R6) |
| Unggah dari halaman Arsip | F10-11: unggah tetap lewat form riwayat, Data Umum (`ArsipKepegawaianDialog`), dan layanan |
| Kuota per pegawai, versi berkas, "unduh semua (ZIP)" | Tidak ada di legacy (Mapping §8.7) |
| Entitas/tabel folder baru | F10-04: folder = tampilan dari `jenis_rwy` |
| Audit + throttle unduh berkas | F10-08: tidak berlaku akibat F10-18 (unduh tidak lewat aplikasi) |
| Adapter penyimpanan remote / server arsip terpisah | F10-01/F10-02: J3.06 gugur, diganti MAKE-069 opsi A |
| Tarik dokumen SIASN → `document_attachment` (legacy `hr/services/Siasn.php`) | Belum diputus dan bukan bagian F-10. Bila dibutuhkan, dibuat CR terpisah (Mapping §8.9) [I] |
| Penurunan "folder" dari isi path | Path legacy memuat NIP lama yang tidak ikut koreksi NIP (Mapping §8.3). Folder selalu diturunkan dari `id_riwayat` |
| Perbaikan kolom `url` yang ikut terkirim di respons lampiran B-18 | Bila terbukti (§7.5, [I]), dibuat CR terpisah di luar F-10 |

---

## 2. Aturan proyek
Semua aturan `AGENTS.md` dan PRD Fase 7 v2.1 §2 berlaku:
- menu ⋮ `RowActionsMenu`;
- commit Bahasa Indonesia `feat(scope): …` dengan key, tanpa penyebutan AI dan tanpa trailer `Co-Authored-By`;
- repo publik;
- quality gate `./check.sh`;
- worktree + DB scratch 3307 milik sendiri dengan `.env` minimal;
- aturan grep legacy: `--include` di depan, lalu exclude lengkap; alat Grep memakai glob negasi.

Tambahan khusus F-10:
- **Tanpa migration.** Bila implementasi ternyata butuh kolom baru, nilai ENUM baru, atau kode `jenis_rwy` baru, **berhenti** dan lapor reviewer. Perubahan seperti itu wajib lewat DBV (`backend/docs/db-review/`).
- **Alur merge:** hanya `[CR]`, jadi langsung ke `main` sesudah quality gate lolos dan Horii memberi izin (AGENTS.md §2).
- Tidak ada aksi pengubah data lewat `GET` (F10-09).
- Koreksi kontrak beku `backend/app/Controllers/Api/Kepegawaian/README.md` yang **tidak aditif** (§7.5) wajib mendapat izin pemilik WS-2 lewat reviewer.

## 3. Register keputusan F-10

### 3.1 Keputusan Horii 12-11-2026 (🔒 final)
| # | Keputusan | Label | Sumber |
|---|---|---|---|
| F10-01 🔒 | F-10 dibuka di atas **penyimpanan lokal v2** (MAKE-069 opsi A: berkas legacy disalin ke v2 saat cutover dengan struktur folder relatif yang sama). Tidak ada server arsip terpisah (`docs/migrasi/MAKE-069-rencana.md:150-158`) | [V2] | Horii 12-11-2026 |
| F10-02 🔒 | J3.06 PRD 01-10 ("v2 terhubung ke server Arsip yang sama") **gugur**, digantikan MAKE-069 opsi A. Entri `arsip` di `Config\Integrations` tidak dibuat | [V2] | Horii 12-11-2026 |
| F10-03 🔒 | Prasyarat server (penanggung jawab disk, kapasitas + pemantauan, backup folder berkas, retensi berkas yatim) = **blocker deploy/cutover** (DEPLOY-002). Tidak menghambat koding | — | Horii 12-11-2026 |
| F10-04 🔒 | Cakupan **bertahap**. Tahap 1: lihat daftar + buka berkas + tanda "berkas hilang" + tampilan folder (Pegawai → Jenis Riwayat → berkas; folder virtual dari `jenis_rwy`, tanpa entitas folder baru). Tahap 2: Hubungkan (kode 0 → riwayat), Ubah nama (`tag`), Hapus arsip yatim (kode 0), Ekspor Excel/CSV, filter "belum terhubung" | [K] aksi / [V2] folder | Horii 12-11-2026 |
| F10-05 🔒 | Yang tampil: `document_attachment` kode riwayat + Data Umum 37/38 + belum terhubung (0) + lampiran layanan cuti/TB/IB/PMK (42–50). Berkas di kolom lain **tidak** tampil | [K] (42 PMK = kode legacy `ARSIP_RWY`, `CreateJenisRwy.php:20`, `:84`); 43–50 [V2]: kode layanan v2 (`2026-10-26-100800_SeedJenisRwyLayananFase4.php:36-45`) | Horii 12-11-2026 |
| F10-06 🔒 | Endpoint & halaman berada di **modul Kepegawaian** (dekat `OtorisasiLampiran`), baik backend maupun frontend (`frontend/src/features/kepegawaian/`). Menu nav `arsip` diaktifkan. Pegawai (2/6/7) melihat arsip sendiri lewat menu; admin lewat menu + tautan dari Detail Pegawai | [K] pintu masuk / [V2] menu admin | Horii 12-11-2026 |
| F10-07 🔒 | Role 4 (Admin View/Eselon I) dan 5 (Menteri): **hanya lihat**, dengan lingkup = `PegawaiScope` role itu (saat ini semua pegawai, `PegawaiScope.php:65`). Role 8 **tidak boleh**. Role 3 dalam lingkupnya. Role 1 semua. Role 2/6/7 hanya NIP sendiri | [K] (`legacy:controllers/hr/Arsip.php:12-46`) | Horii 12-11-2026 |
| F10-08 🔒 | Audit + throttle unduh **gugur**: tidak berlaku akibat F10-18 | — | Horii 12-11-2026 |
| F10-09 🔒 | Aturan aksi Tahap 2 **ikut legacy persis**: Hapus hanya menghapus baris DB (berkas fisik tertinggal) dan hanya untuk arsip kode 0; Hubungkan/Ubah nama **tanpa** cek antrean SIASN. Hubungkan ke kode 9/36/41/5 bisa membuat berkas memenuhi syarat antre unggah SIASN; ini **risiko yang diterima** (§6.6). Pola keamanan v2 tetap dipakai: tanpa aksi pengubah lewat GET (POST/PATCH/DELETE + perlindungan standar, §6.1), `tag` disanitasi (anti-XSS), cek kepemilikan/lingkup di setiap aksi (anti-IDOR), dan audit perubahan baris lewat model auditable (event `update`/`delete`) | [K] aturan / [V2] keamanan | Horii 12-11-2026 |
| F10-10 🔒 | Berkas dibuka sebagai **pratinjau di browser** (PDF/gambar), seperti legacy (`legacy:views/hr/employee/arsip/list.php:81`) | [K] | Horii 12-11-2026 |
| F10-11 🔒 | **Tidak ada unggah** dari halaman Arsip | [K] | Horii 12-11-2026 |
| F10-12 🔒 | Tampilan memakai pola komponen v2 yang sudah ada (breadcrumb + daftar folder + tabel berkas, ⋮ AGENTS.md §1), tanpa menunggu Figma | [V2] | Horii 12-11-2026 |
| F10-13 🔒 | Pelaksana ditunjuk **sesudah** PRD disetujui, lewat kode izin workload (§13) | — | Horii 12-11-2026 |
| F10-14 🔒 | QR surat cuti ber-TTE legacy yang menunjuk server arsip lama = blocker cutover **di luar F-10** (DEPLOY-002: URL/server lama read-only vs migrasi/redirect QR ke v2, belum diputus) | — | Horii 12-11-2026 |
| F10-15 🔒 | Halaman **baru** bernama **"Arsip"**. Tombol lama "Arsip Kepegawaian" (dialog Data Umum 37/38, MAKE-014) diganti label menjadi **"Arsip Data Umum"** | [V2] | Horii 12-11-2026 |
| F10-16 🔒 | Lampiran "menggantung" (riwayat status 10 / pengajuan / `id_entri` tidak valid) **ikut legacy**: tetap tampil dan bisa dibuka oleh role yang boleh melihat pegawai itu (`legacy:libraries/hr/L_arsip.php:12`; `legacy:helpers/function_helper.php:2260-2383` tanpa syarat status) | [K] | Horii 12-11-2026 |
| F10-17 🔒 | Pembukaan **daftar** arsip pegawai lain oleh role 1/3/4/5 **dicatat** (siapa membuka arsip siapa, tanpa isi berkas). Pegawai yang membuka arsip sendiri tidak dicatat | [V2] (legacy tanpa log) | Horii 12-11-2026 |
| F10-18 🔒 | Berkas dibuka **persis legacy**: lewat **URL publik langsung** (disajikan Nginx dari folder berkas), **tanpa** cek izin per berkas. Horii memilih ini **sesudah** diberi tahu risikonya: URL bisa ditebak (path legacy memuat NIP); KTP/NPWP terbuka bagi yang tahu URL; audit unduh tidak bisa dilakukan; dan ini **membalik pola privat lampiran sejak Fase 3** untuk berkas yang sama. Rincian di §7 dan Lampiran A; rekomendasi ADR di §7.7 | [K] (membalik pola v2) | Horii 12-11-2026 |

### 3.2 Bawaan usulan reviewer (◻ menunggu konfirmasi Horii)
| # | Usulan bawaan | Label | Alasan |
|---|---|---|---|
| F10-19 | Nama arsip = `tag`; bila kosong `display_name`; bila kosong `filename`. Ditampilkan sebagai teks biasa | [V2] kecil | Legacy memakai `tag` → `filename` (`list.php:79`). Berkas unggahan v2 ber-`filename` acak (`AttachmentService.php:65`), jadi tanpa `display_name` namanya tidak terbaca |
| F10-20 | Daftar berkas berhalaman di server (bawaan 25, maks 100), urut bawaan `id_attachment` menurun | [K] urutan / [V2] paginasi | Legacy urut id DESC (`L_arsip.php:12`), DataTables di klien tanpa paginasi server |
| F10-21 | Pencarian `q` atas `tag`/`display_name`/`filename` (LIKE literal, `%`/`_` di-escape). Ringkasan riwayat tidak ikut dicari | [V2] | Legacy mencari di semua kolom tabel klien |
| F10-22 | "Data Riwayat" = ringkasan seperti `previewRwy` legacy untuk kode 1, 3, 5, 7, 9, **36**, 10, 11, 13, 14, 20, 22, 23, 32 (kode 36 memakai ringkasan yang sama dengan 9: jabatan + satker). Kode lain (37–50) tampil "—" | [K] | `legacy:helpers/function_helper.php:2260-2383` (`:2305` `9 \|\| 36`) |
| F10-23 | `url_berkas` hanya diisi bila (a) path cocok dengan salah satu regex penyajian di `Config\Arsip` (§7.2), regex yang sama persis dengan blok Nginx, dan (b) berkasnya ada di disk. Kasus (a) gagal → `null` + badge "Tidak dapat dibuka". Kasus (b) gagal → `null` + badge "Berkas hilang" | [V2] | DoD "berkas hilang ditandai, bukan 500". Backend tidak boleh memberi URL yang ternyata 404 di Nginx |
| F10-24 | Saklar rollback `Config\Arsip::$penyajianBerkas` = `'publik'` (bawaan, F10-18) / `'privat'` (endpoint terautentikasi, Lampiran A). Kedua mode dijaga test | [V2] | Rollback cukup lewat konfigurasi (pola Q7-36) |
| F10-25 | Penguatan Nginx `/berkas/` (§7.3): hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; tipe lain selalu diunduh sebagai `application/octet-stream`; `nosniff`; tanpa listing folder; tanpa PHP; tanpa symlink di dalam akar; `Cache-Control: no-store`; tanpa log akses/not-found. Ini **bukan** cek izin, sehingga F10-18 tetap utuh | [V2] | Mencegah berkas (mis. HTML/SVG) berjalan sebagai halaman di alamat aplikasi |
| F10-26 | Catatan F10-17 memakai `audit_logs` entity `arsip_lihat`, event `create`, dengan dedup satu catatan per (aktor, NIP) per 15 menit untuk folder/daftar. Ekspor arsip pegawai lain dicatat **setiap kali** (tanpa dedup, `endpoint: "ekspor"`) | [V2] | §8.2 |
| F10-27 | Ekspor CSV berekstensi `.csv` | [V2] | Bug legacy: CSV terunduh sebagai `.txt` (`list.php:221-253`) |
| F10-28 | Daftar jenis tujuan Hubungkan = 1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, ditambah 38 (tanpa data riwayat). Kode 37/39/40/41/42 dan 43–50 tidak ditawarkan | [K] daftar / [V2] penolakan server | Legacy tidak punya cabang data untuk 37/39–42, sehingga dropdown kosong (`Local.php:3475-3722`); 43–50 tidak ada di legacy. Penolakan server untuk kode lain = [V2] (legacy tanpa cabang `else`, `Local.php:3561-3690`) sesuai pola keamanan F10-09 |
| F10-29 | Penempatan folder: "Belum Terhubung" (kode 0) paling bawah; folder dengan 0 berkas tidak ditampilkan [V2 tampilan; legacy tabel datar]. Baris ber-`id_riwayat` NULL, bila ada (D1 blok 9), masuk folder **"Tanpa Jenis"** (kunci `tanpa_jenis`) dan hanya bisa di-Edit [K aksi: legacy menawarkan Hubungkan/Hapus hanya bila `id_riwayat == '0'`, `list.php:125-134`]. Keberadaan baris NULL [I] sampai D1. Kode di luar `jenis_rwy` tidak mungkin ada karena FK (`CreateDocumentAttachment.php:70`): baris seperti itu tertolak saat impor tier 10, bukan tampil di F-10 | [V2] tampilan / [K] aksi / [I] | `jenis_rwy.order` 0 = sentinel (`CreateJenisRwy.php:26-28`) |
| F10-30 | Halaman Arsip **tidak** memakai izin Definisi per kode, karena legacy menampilkan semua baris per NIP. Akibatnya, untuk role yang boleh melihat pegawai itu (termasuk pemiliknya), tampil juga: (a) lampiran, (b) **ringkasan Data Riwayat**, dan (c) calon tujuan Hubungkan dari jenis yang tersembunyi di tab B-18. Contoh: hukdis di B-18 hanya boleh dilihat role 1/3 (`Definisi/Hukdis.php:87-97`), tetapi di Arsip role 2/4/5/6/7 melihat lampiran dan ringkasannya (TMT + tingkat hukuman), dan opsi Hubungkan kode 7 memuat riwayat hukdis milik NIP itu | [K] | `legacy:libraries/hr/L_arsip.php:12-19`; `legacy:helpers/function_helper.php:2297-2302`; `legacy:controllers/hr/services/Local.php:3272-3285`, `:3339` |
| F10-31 | Aksi F-10 **tidak** memakai `OtorisasiLampiran::bolehUnggah`/kunci baris B-18 maupun `ArsipDataUmum::bolehTulis()`. Akibatnya: role 2/6/7 bisa meng-Hubungkan arsip ke riwayat **Disetujui** miliknya (di B-18 unggah ke baris itu ditolak, `OtorisasiLampiran.php:382-389`), dan role 2/3/6/7 bisa mengubah nama arsip kode 37 (di B-18 tulis 37 hanya role 1, `ArsipDataUmum.php:52-56`). Kunci B-18 tetap berlaku di B-18 | [K] | Legacy tanpa cek kunci (`Local.php:3496-3699`, `:3809-3890`) |
| F10-32 | Semua ekstensi bisa dibuka seperti legacy (`pathToSrc` tanpa saring ekstensi) [K]. Hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; ekstensi lain (doc, xls, rar, zip, tif, svg, html, dll.) selalu **diunduh** sebagai `application/octet-stream` [V2 kecil]. Ekstensi diambil dari segmen terakhir path ternormalisasi (huruf kecil), **bukan** dari kolom `file_ext` (legacy CI3 menyimpan dengan titik/huruf besar, `legacy:libraries/hr/L_employee.php:301`; v2 tanpa titik, `AttachmentService.php:84`) | [K] / [V2] kecil | Di legacy, berkas `arsip/` disajikan dari server arsip (alamat dasar `ARSIP`, terpisah dari aplikasi [I]). Di v2 berkas disajikan dari alamat aplikasi, sehingga HTML/SVG yang dibuka inline bisa menjadi XSS (R5) |
| F10-33 | Baris `document_attachment` berpath `assets/upload/...` juga dibuka lewat URL publik (alias ketiga `/berkas/assets/upload/...`), seperti legacy yang menyajikannya publik dari server aplikasi (`legacy:helpers/function_helper.php:314-326`). Berkasnya ikut disalin MAKE-069 dengan struktur yang sama (`MAKE-069-rencana.md:150`, `:177-181`). Jumlah baris dari D1 blok 4 dilaporkan, tetapi tidak menahan | [K] | Usulan reviewer (paritas legacy, konsekuensi F10-18); menunggu konfirmasi Horii |

---

## 4. Ringkasan perilaku legacy yang dibawa
| Aspek | Legacy | Bukti |
|---|---|---|
| Akses halaman | Level 1–7 boleh, 8 tidak. Pegawai yang membuka NIP lain dialihkan ke arsip sendiri (tanpa galat). Level 3 lewat `validateAccess_AP`. Level 1/4/5 boleh NIP mana pun | `legacy:controllers/hr/Arsip.php:12`, `:15-22`, `:41-46` |
| Isi daftar | Semua baris `document_attachment` milik NIP, urut id DESC, tanpa syarat status riwayat | `legacy:libraries/hr/L_arsip.php:12-19` |
| Kolom | Nama Arsip (`tag` / `filename`, `strip_tags`), ikon Arsip (popup), Jenis Riwayat, Data Riwayat (`previewRwy`), Aksi | `legacy:views/hr/employee/arsip/list.php:57-68`, `:79-81` |
| Filter | Select Jenis Riwayat + centang "Arsip Yang Belum Terhubung". Mencentang me-reset select ke "All". Bila sesudah itu jenis dipilih, kedua saringan berlaku bersama dan hasilnya kosong | `list.php:37-53`, `:269-274`, `:431-442` |
| Role 4/5 | Tombol aksi disembunyikan untuk 4/5 [K]. Service aksi menolak `UL_ES1` [I], karena isi konstanta belum diverifikasi (Mapping §7.1) | `list.php:86`, `:109`, `:124`; `legacy:controllers/hr/services/Local.php:3516`, `:3749`, `:3835` |
| Aksi | Kode 0: Ubah Nama, Hubungkan, Hapus. Terhubung: Ubah Nama saja | `list.php:125-129`, `:134` |
| Hubungkan | Baris harus kode 0; riwayat milik NIP berstatus 1; set `id_riwayat`, `nama_riwayat`, `id_entri` (38 → NULL); dilakukan lewat GET, tanpa log. Daftar calon riwayat (`list_arsip_rwy_data`) hanya mengecek login | `Local.php:3272-3285`, `:3496-3498`, `:3553-3554`, `:3565`, `:3693-3699` |
| Hapus | Hanya kode 0; hanya baris DB yang dihapus; berkas fisik tertinggal; tanpa log | `Local.php:3786-3787`, `:3797` |
| Ubah nama | Kolom `tag`, untuk semua baris; nama wajib diisi | `Local.php:3809-3890` (`:3829`, `:3878`) |
| Buka berkas | `window.open(pathToSrc(path), 'popup', 'width=600,height=600')` = URL publik tanpa sesi. `arsip/` dari server arsip, path lain dari server aplikasi | `list.php:81`; `legacy:helpers/function_helper.php:307-326` |
| Bug yang **tidak** ditiru | Aksi lewat GET tanpa perlindungan; `tag` mentah di tooltip (XSS); cek level 3 longgar di service aksi; salah ketik `$_GET['nip']`; CSV berekstensi `.txt` | `Local.php:3496-3498`, `:3527-3544`; `list.php:81` (atribut `tippy-title`); `list.php:221-253` |

---

## 5. Tahap 1 — Lihat, buka, tandai hilang, folder

### 5.1 Perilaku
- **Pintu masuk.**
  - Role 2/6/7: menu "Arsip" → arsip sendiri.
  - Role 1/3/4/5: menu "Arsip" → pilih pegawai (memakai pencarian pegawai yang sudah ada, `GET pegawai?search=`), lalu folder pegawai itu.
  - Semua role 1–7: tombol **"Arsip"** di header Detail Pegawai.
  - Role 8: tidak ada menu dan tombol, dan API menolak.
  - Selama menu masih tersembunyi oleh gerbang fase (§9.1), pegawai tetap bisa masuk lewat tombol "Arsip" di Detail Pegawai NIP sendiri.
- **Pemilih pegawai role 3** memakai lingkup jalur *daftar*, sedangkan arsip memakai lingkup jalur *detail* (`PegawaiScope.php:30-42`, `:116-129`, `:177-185`). Pegawai yang muncul di pemilih tetapi berada di luar lingkup detail mendapat 403, sama dengan Detail Pegawai [K].
- **Folder** (tampilan, bukan tabel):
  - satu kartu per `jenis_rwy` yang punya ≥ 1 berkas, urut `jenis_rwy.order`;
  - folder semu **"Semua berkas"** di paling atas;
  - "Belum Terhubung" dan "Tanpa Jenis" paling bawah (F10-29);
  - setiap kartu menampilkan label, jumlah berkas, jumlah berkas hilang, dan jumlah berkas yang tidak dapat dibuka.
- **Tabel berkas** (per folder atau "Semua berkas"): kolom Nama Arsip | Jenis Riwayat | Data Riwayat | Status Berkas (badge saja: "Berkas hilang" / "Tidak dapat dibuka") | Aksi ⋮. Ada kotak cari `q` dan paginasi.
- **Buka** (⋮): `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')`.
  - Jendela popup 600×600 = [K] (`list.php:81`); `noopener` = [V2 keamanan]. Di browser ponsel, popup biasanya terbuka sebagai tab.
  - PDF/gambar tampil sebagai pratinjau (F10-10). Tipe lain terunduh (F10-32).
- **Menggantung** (F10-16): baris milik riwayat status 10 / pengajuan / `id_entri` tak valid tetap tampil dan bisa dibuka, tanpa badge tambahan [K].
- **Catatan pembukaan** (F10-17, §8.2).

### 5.2 AC Tahap 1
| AC | Kriteria (bisa diuji) |
|---|---|
| AC1-01 | Role 1 membuka arsip NIP mana pun; role 4 dan 5 juga (lingkup `PegawaiScope` = semua) |
| AC1-02 | Role 3 hanya pegawai dalam lingkup detail `PegawaiScope` (`PegawaiScope.php:116-129`); di luar lingkup → 403. Pegawai yang muncul di pemilih tetapi di luar lingkup detail → 403, sama dengan Detail Pegawai [K] |
| AC1-03 | API: role 2/6/7 terhadap NIP lain → 403 [V2]. FE: `/arsip` **dan** `/arsip/<nip lain>` untuk role 2/6/7 dialihkan ke `/arsip/<nip sendiri>` tanpa pesan galat [K `Arsip.php:15-21`] |
| AC1-04 | Role 8 → 403 di semua endpoint F-10; menu dan tombol tidak tampil |
| AC1-05 | Daftar memuat **semua** baris `document_attachment` milik NIP dengan kode riwayat, 0, 37, 38, 42–50 (F10-05), dan NULL bila ada. Termasuk baris, **ringkasan Data Riwayat**, dan (Tahap 2) calon tujuan Hubungkan dari jenis yang tersembunyi bagi role itu di B-18, mis. hukdis untuk role 2/4/5/6/7 (F10-30, [K]) |
| AC1-06 | Baris menggantung (riwayat status 10, pengajuan, `id_entri` tak ada) tampil, dan `url_berkas` terisi bila berkasnya ada |
| AC1-07 | Path cocok regex tetapi berkas tidak ada di disk → `berkas_ada: false`, `url_berkas: null`, badge "Berkas hilang", ⋮ Buka `disabled`. Respons tetap 200, bukan 500 |
| AC1-08 | Path tidak sah (`PathLegacy::normalisasi()` = null) atau tidak cocok regex §7.2 (mis. segmen berawalan `.`, `kolom/…`, `form-digital/…`) → `url_berkas: null`, badge "Tidak dapat dibuka", tanpa 500, tanpa path di log |
| AC1-09 | Folder: jumlah per kode = jumlah baris. `jumlah_hilang` hanya menghitung baris cocok regex yang berkasnya tidak ada; baris yang tidak cocok dihitung di `jumlah_tak_dapat_dibuka`. Folder 0 berkas tidak dikirim. Urutan sesuai `order`, kode 0 dan `tanpa_jenis` paling bawah |
| AC1-10 | Respons memakai daftar kolom tertutup (§8.1). `path`, `url`, `basename`, dan `document_id` **tidak pernah** dikirim (test: kunci-kunci itu tidak ada di JSON). Satu-satunya turunan path adalah `url_berkas` |
| AC1-11 | `q` diperlakukan literal (input `%`, `_` tidak menjadi wildcard); `per_page` > 100 dipotong menjadi 100 |
| AC1-12 | Role 1/3/4/5 membuka daftar pegawai **lain** → tercatat di `audit_logs` (`entity` `arsip_lihat`, `event` `create`) tanpa nama/isi berkas dan tanpa teks pencarian. Bila F10-26 disetujui: permintaan **berurutan** dalam 15 menit untuk (aktor, NIP) yang sama → satu baris (permintaan bersamaan boleh menghasilkan lebih dari satu baris). Bila F10-26 tidak disetujui: setiap pembukaan dicatat. Membuka arsip sendiri (role apa pun) → tidak dicatat |
| AC1-13 | Ringkasan "Data Riwayat" untuk kode di F10-22 (termasuk 36 = bentuk ringkasan 9) sesuai bentuk `previewRwy`; kode lain tampil "—". Tanpa N+1 query (satu query per kode per halaman) |
| AC1-14 | Tombol lama di header Detail Pegawai berlabel "Arsip Data Umum", **tetap** ber-testid `btn-arsip`, dan tetap membuka dialog 37/38 lama. Tombol baru "Arsip" (`pegawai-header-arsip`) menuju `/arsip/<nip>` (role 1–7) |
| AC1-15 | Pada viewport 1440 px dan 390 px tidak ada scroll horizontal halaman. Tabel boleh scroll di dalam kartu dengan kolom Aksi **sticky** di kanan (pelajaran QAUI-003, Mapping §8.6) |
| AC1-16 | `url_berkas` terisi **jika dan hanya jika** path cocok salah satu regex `Config\Arsip` dan berkasnya ada (mis. `….PDF` → terisi; segmen `.foo` → null). Ekstensi non-pratinjau → `jenis_buka: "unduh"` |

---

## 6. Tahap 2 — Aksi legacy

### 6.1 Perilaku
- **Perlindungan standar** (F10-09) sama dengan endpoint tulis v2 lain: cookie httpOnly `SameSite=Lax` (`Jwt.php:42`, `:60`) + metode non-GET. Tidak ada token CSRF terpisah (`Config/Filters.php:91`) [V2 yang berlaku]. QA tidak perlu mengharapkan token CSRF.
- **Aksi F-10 tidak memakai `OtorisasiLampiran` atau `ArsipDataUmum::bolehTulis()`** (F10-31). Izin aksi = role + lingkup `PegawaiScope` (ubah) + kepemilikan baris.
- **Edit (Ubah nama)**:
  - Berlaku untuk semua baris, terhubung maupun yatim, termasuk 37/38 dan baris riwayat yang sudah disetujui [K].
  - Role yang boleh: 1, 3 (lingkup ubah), 2/6/7 (NIP sendiri).
  - Dialog "Ubah Nama Arsip" dengan satu isian. Nama wajib diisi [K `Local.php:3829`].
- **Hubungkan**:
  - Hanya untuk baris kode 0. Pengguna memilih jenis (F10-28), lalu memilih data riwayat. Untuk kode 38 tidak ada pilihan data (`id_entri` NULL) [K].
  - Baris tujuan dicari lewat **Definisi pemilik kode** (`RiwayatRegistry`), dengan pola `OtorisasiLampiran::entriMilik` (`OtorisasiLampiran.php:351-377`): milik NIP (kode 3 lewat induk, `entriLampiran`), status terpetakan = `StatusRiwayat::Disetujui` [K "status 1"], dibaca ulang `FOR UPDATE` di dalam transaksi.
  - **Tidak** memakai `jenis_rwy.tabel_entri`, karena kolom itu hanya nama tabel; kode 3 = `detail_anak` tanpa kolom NIP; 9/36 berbagi tabel (`CreateJenisRwy.php:40`, `:66`).
  - `opsi-hubungkan` memakai sumber yang sama.
- **Hapus**:
  - Hanya untuk baris kode 0, lewat `ConfirmDialog`.
  - Hanya baris DB yang dihapus; berkas fisik **tertinggal** [K] (F10-09).
  - Tidak memakai `AttachmentService::hapus()`, karena metode itu menjadwalkan penghapusan berkas (`AttachmentService.php:120-139`).
- **Ekspor**:
  - Tombol halaman (`UiExportMenu`) di tampilan tabel, format Excel (`.xlsx`) / CSV (`.csv`), mengikuti filter yang aktif.
  - Kolom: Nama Arsip, Jenis Riwayat, Data Riwayat [K], tanpa URL berkas. Boleh untuk semua role yang boleh melihat (1–7) [K].
  - Ditulis lewat `EksporTabel` yang sudah ada (CSV UTF-8 BOM + XLSX `inlineStr`, nilai berawalan `= + - @ TAB CR` diberi apostrof, `backend/app/Libraries/Kepegawaian/Pegawai/EksporTabel.php:5-9`, `:29-33`). Tanpa penulis ekspor baru.
- **Filter** di tampilan "Semua berkas": select Jenis Riwayat + centang **"Belum terhubung"**.
  - Mencentang "Belum terhubung" me-reset select ke "Semua" [K].
  - Bila sesudah itu jenis dipilih, kedua saringan berlaku bersama, sehingga hasilnya kosong [K `list.php:431-442`].
  - Di API: `belum_terhubung=1` dan `id_jenis_rwy` digabung dengan AND.

### 6.2 AC Tahap 2
| AC | Kriteria |
|---|---|
| AC2-01 | Semua aksi tulis memakai `PATCH`/`POST`/`DELETE`; tidak ada route `GET` yang mengubah data (test route). Perlindungan = cookie `SameSite=Lax` + non-GET (§6.1) |
| AC2-02 | Role 4/5/8 → 403 untuk semua aksi tulis dan `opsi-hubungkan`; di FE item tulis `hidden` untuk role 4/5 |
| AC2-03 | Role 2/6/7 terhadap NIP lain → 403; `{id}` milik NIP lain → 404 (anti-IDOR, `WHERE id_attachment = ? AND NIP = ?`) |
| AC2-04 | Role 3 di luar lingkup **ubah** (`PegawaiScope::bolehUbah`) → 403 |
| AC2-05 | Hubungkan/Hapus pada baris bukan kode 0 → 422 `errors.id_attachment` dengan pesan legacy apa adanya: "Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat." [K `Local.php:3554`, `:3787`] |
| AC2-06 | Hubungkan: jenis di luar F10-28 → 422 [V2]. Riwayat bukan milik NIP / bukan Disetujui / tidak ada → 404. Bila sukses: `id_riwayat`, `nama_riwayat` (= `jenis_rwy.jenis_rwy`), dan `id_entri` (NULL untuk 38) berubah, dengan satu baris audit `update` (`document_attachment`) |
| AC2-07 | Hubungkan tidak membaca dan tidak menulis `siasn_upload_arsip` (risiko diterima, §6.6) |
| AC2-08 | Edit: `tag` dibersihkan (tag HTML dibuang, byte kontrol dibuang, trim). Panjang sesudah dibersihkan 1–255 untuk semua kode (batas kolom `tag varchar(255)`, `CreateDocumentAttachment.php:58`; wajib diisi [K]); selain itu 422 `errors.tag`. Ditampilkan di FE sebagai teks biasa (tanpa `v-html`); ada audit `update`. Batas 100 di form Arsip Data Umum (`ArsipDataUmum.php:79-93`) tidak berubah |
| AC2-09 | Hapus: baris hilang dari DB, audit `delete` dengan snapshot baris; **berkas di disk tetap ada** (test memeriksa berkas) |
| AC2-10 | Ekspor xlsx/csv: kolom sesuai §6.1, filter sama dengan daftar, `Cache-Control: no-store`, ditulis lewat `EksporTabel` (test: `tag` `=1+1` keluar sebagai `'=1+1` di CSV dan teks di XLSX). Ekspor arsip pegawai lain oleh 1/3/4/5 dicatat setiap kali (F10-26) |
| AC2-11 | Menu ⋮ sesuai AGENTS.md §1 (§9.3); Hapus `danger` + `ConfirmDialog`; selama satu aksi berjalan, item tulis lain `disabled` |
| AC2-12 | Sesudah Hubungkan, baris pindah folder dan jumlah folder diperbarui tanpa muat ulang halaman penuh |
| AC2-13 | Role 2/6/7 bisa meng-Hubungkan ke riwayat Disetujui miliknya; role 2/3/6/7 bisa mengubah nama arsip kode 37 (F10-31, [K]) |

### 6.6 Risiko dan efek yang diterima (F10-09)
- **Antrean SIASN.** Hubungkan ke kode 9/36/41 (Jabatan) atau 5 (Diklat sertifikasi) bisa membuat baris itu memenuhi syarat antre unggah SIASN pada jalannya `AntreArsip` berikutnya (`backend/app/Libraries/Siasn/Arsip/AntreArsip.php:17-20`: berkas PDF + riwayat yang sudah terkirim). Kode 41 tidak ada di daftar tujuan Hubungkan (F10-28), jadi dalam praktik risikonya hanya untuk 9/36/5. Tidak ada pencegahan, sama seperti legacy.
- **Efek data lain yang ikut diterima [K]:**
  - Berkas yang dihubungkan ke 9/10/11/32 ikut tampil di respons `services/s_stat` untuk klien eksternal (`backend/app/Libraries/Integrasi/Stat/PenyajiStat.php:24-28`). Kode 46/47/49/50 juga dipetakan di sana, tetapi tidak ditawarkan untuk Hubungkan.
  - Hubungkan ke 11 mengubah kelengkapan beranda (`backend/app/Libraries/Dashboard/Beranda/KelengkapanData.php:470-480`, tanpa cache).
- QA mencatat semua perilaku ini sebagai **sesuai keputusan**, bukan bug.

---

## 7. Penyajian berkas lewat URL publik (F10-18)

### 7.1 Inti
- Server web (Nginx) menyajikan berkas langsung dari akar penyimpanan lampiran v2 `writable/uploads/lampiran` (`LocalStorageAdapter.php:29`). Di server, `writable` adalah symlink ke `shared/writable` (`deploy/post-receive:160-162`, `README-deploy.md:67-68`). Alias Nginx menunjuk langsung ke `shared/writable/uploads/lampiran`.
- **Tidak ada** pemeriksaan login atau izin saat URL dibuka. Siapa pun yang tahu URL bisa membuka berkasnya, seperti di legacy.
- Backend hanya **membentuk** URL (`url_berkas`) di respons daftar yang sudah terautentikasi dan tersaring lingkup.
- **Yang terjangkau web hanya tiga pola di §7.2 di bawah `uploads/lampiran`.** Folder `writable/cache` (berisi token OAuth FCM), `logs`, `uploads/konten`, `uploads/slip-impor`, serta `kolom/` dan `form-digital/` di bawah akar lampiran tetap tidak terjangkau. README-deploy §1l menegaskan hal ini, karena README saat ini menyatakan `writable/` tidak boleh terjangkau web (`README-deploy.md:250`). Pengecualiannya sempit dan disengaja.

### 7.2 Bentuk URL dan pola yang disajikan
Dasar URL: `Config\Arsip::$dasarUrlBerkas`, bawaan `/berkas` (relatif ke host aplikasi). Nilai ini bisa diganti lewat `.env` `arsip.dasarUrlBerkas` (mis. untuk subdomain berkas, §7.7). Host tidak pernah ditulis di repo.

Ketiga regex didefinisikan **satu kali** sebagai konstanta di `Config\Arsip` dan dipakai `PenyajiUrlBerkas`. Contoh Nginx di README §1l memuat regex yang sama persis, dan test membandingkan keduanya sebagai string.

| Sumber baris | Path di DB | Regex (atas path ternormalisasi) | URL | Label |
|---|---|---|---|---|
| Unggahan v2 (riwayat, 37/38, layanan 42–50) | `YYYY/MM/<32 hex>.<ext>` (`AttachmentService.php:65-66`) | `^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.[a-z0-9]{1,10}$` | dasar + `/` + path | — |
| Impor legacy (server arsip) | `arsip/<NIP>/<sub>/<nama>` (bisa diawali `/`, berspasi, `\`) | `^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$` | dasar + `/` + `PathLegacy::normalisasi(path)`, **setiap segmen** di-`rawurlencode` | [K] |
| Impor legacy (server aplikasi) | `assets/upload/...` | `^assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*$` | sama dengan baris di atas | [K] (F10-33) |
| Lainnya (`kolom/...`, `form-digital/...`, segmen berawalan `.`, path tak sah) | — | tidak cocok | `url_berkas: null` ("Tidak dapat dibuka") | [V2] kecil untuk segmen berawalan `.` |

`jenis_buka` = `pratinjau` bila ekstensi segmen terakhir (huruf kecil) ∈ pdf/jpg/jpeg/png/gif; selain itu `unduh` (F10-32). Kolom `file_ext` tidak dipakai.

### 7.3 Kebutuhan teknis Nginx (MAKE-093; contoh, final diuji di server dev)
Wajib:
1. `location ^~ /berkas/` (prefix `^~` supaya blok regex PHP tidak pernah terpakai) **tanpa** `fastcgi_pass`, dengan `autoindex off` dan `disable_symlinks on from=<akar lampiran>`. Symlink hanya diperiksa di bawah akar, sehingga symlink `writable` di atasnya tidak berpengaruh. Prasyarat (README §1l, B10-1): di dalam akar tidak boleh ada symlink, dan disk yang dipindah di-mount langsung ke path akar. Alasannya: PHP (`realpath`, `LocalStorageAdapter.php:118-129`) mengizinkan symlink di dalam akar. Kalau ada symlink, badge `berkas_ada` di halaman bisa berbeda dengan kenyataan di Nginx.
2. Hanya tiga pola §7.2 yang lolos (regex sama persis dengan `Config\Arsip`); selain itu 404. Pola kunci v2 sengaja **tidak** mencakup `kolom/` (`KolomBerkas.php:51`) dan `form-digital/` (`IsianFormDigital.php:117`).
3. Tipe dari daftar tertutup: hanya `application/pdf`, `image/jpeg`, `image/png`, `image/gif`, dengan `default_type application/octet-stream`. `Content-Disposition` lewat `map` di konteks `http`: `inline` untuk pdf/jpg/jpeg/png/gif, `attachment` untuk lainnya. Header lain: `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, dan `Content-Security-Policy` untuk respons berkas. CSP **tanpa** direktif `sandbox` dan tidak boleh menghalangi penampil PDF bawaan browser. Contoh awal: `default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'`. Bila nilai CSP apa pun mematahkan pratinjau di salah satu browser uji, pratinjau (F10-10) didahulukan dan CSP disesuaikan; `nosniff` dan daftar tipe tetap dipasang. Karena `add_header` di blok dalam menimpa header blok luar, semua header ditulis lewat satu `include` snippet di **setiap** blok dalam.
4. Log: `access_log off;` dan `log_not_found off;` di `location ^~ /berkas/`, karena URL dan path berkas memuat NIP. `error_log` server tetap dibatasi aksesnya, karena bisa berisi path.
5. **Hak baca.** Berkas ditulis PHP dengan hak `0640` dan folder `0750` (`LocalStorageAdapter.php:43`, `:59`). Begitu juga hasil `lampiran:tarik`. User worker Nginx wajib bisa membaca akar: masukkan user Nginx ke grup pemilik berkas (user PHP-FPM/deploy), atau jalankan keduanya dengan user yang sama. Hak `0640`/`0750` tidak diubah. Verifikasi: `sudo -u <user-nginx> test -r <berkas contoh>` dan `curl -I` → 200. Hasilnya dicatat di README §1l.
6. Blok `/berkas/` ditambahkan ke contoh server di README-deploy §1 (`README-deploy.md:100-117`), sebelum blok regex PHP.

Contoh indikatif (path server mengikuti README-deploy):
```nginx
# konteks http (sekali)
map $uri $disposisi_berkas {
    ~*\.(pdf|jpe?g|png|gif)$  inline;
    default                   attachment;
}

# F-10 (PRD F-10 §7, F10-18): berkas arsip dibuka lewat URL publik, persis legacy. Tanpa PHP, tanpa listing.
location ^~ /berkas/ {
    autoindex off;
    access_log off;
    log_not_found off;
    disable_symlinks on from=/var/www/simpeg-v2/shared/writable/uploads/lampiran;
    types { application/pdf pdf; image/jpeg jpg jpeg; image/png png; image/gif gif; }
    default_type application/octet-stream;

    location ~ "^/berkas/(?<kunci>[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.[a-z0-9]{1,10})$" {
        alias /var/www/simpeg-v2/shared/writable/uploads/lampiran/$kunci;
        include snippets/simpeg-berkas-header.conf;
    }
    location ~ "^/berkas/(?<kunci>arsip/(?:[^/.][^/]*/)*[^/.][^/]*)$" {
        alias /var/www/simpeg-v2/shared/writable/uploads/lampiran/$kunci;
        include snippets/simpeg-berkas-header.conf;
    }
    location ~ "^/berkas/(?<kunci>assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*)$" {   # F10-33
        alias /var/www/simpeg-v2/shared/writable/uploads/lampiran/$kunci;
        include snippets/simpeg-berkas-header.conf;
    }
    return 404;
}

# snippets/simpeg-berkas-header.conf
add_header X-Content-Type-Options nosniff always;
add_header Cache-Control "no-store" always;
add_header Referrer-Policy no-referrer always;
add_header Content-Disposition $disposisi_berkas always;
add_header Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'" always;
```
Catatan coder: header (termasuk `Content-Disposition` per tipe dan pencocokan tipe untuk ekstensi huruf besar seperti `.PDF`) wajib diverifikasi dengan `curl -I`, dan pratinjau PDF/JPG/PNG wajib dibuka di Chrome, Edge, dan Firefox di server dev. Hasilnya dicatat di README-deploy §1l (baru). Ini syarat G10-4.

### 7.4 Perilaku di mesin dev (tanpa Nginx)
- Rute khusus dev `GET berkas/(:any)` (`BerkasDevController`) didaftarkan di `RoutesPegawai.php` **di luar** grup `api/v1` (atau di berkas route baru yang didaftarkan di `Routing::$routeFiles`, `Config/Routing.php:33-58`). Rute ini **hanya** didaftarkan bila `ENVIRONMENT === 'development'` **dan** `.env` `arsip.sajiBerkasDev = true`.
- Rute ini memakai regex `Config\Arsip`, daftar tipe, dan header yang sama, serta membaca lewat `LocalStorageAdapter::baca()` (realpath di dalam akar, `LocalStorageAdapter.php:104-130`). Tidak ada cek izin, meniru Nginx.
- `.env` dev: `arsip.dasarUrlBerkas = http://localhost:<port backend>/berkas`. FE Vite tidak punya proxy (`frontend/vite.config.ts`), jadi URL-nya absolut ke backend dev dan dibuka di popup (tanpa CORS).
- Batasan yang diterima: nama berkas dengan karakter di luar `permittedURIChars` CI4 (`a-z 0-9~%.:_\-`, `Config/App.php:86`), mis. `( ) , ' & +`, tidak bisa dibuka lewat rute dev. `permittedURIChars` **tidak** dilonggarkan. QA memakai Nginx.
- Test penjaga: rute tidak terdaftar di `testing`/`production`. Tidak ada penjaga konfigurasi produksi terpisah untuk `sajiBerkasDev`, karena rute hanya didaftarkan di `development`.
- Kunci `arsip.penyajianBerkas`, `arsip.dasarUrlBerkas`, dan `arsip.sajiBerkasDev` ditambahkan ke `backend/.env.example` dengan nilai bawaan.
- QA Lapis 1 memakai server dev dengan Nginx (G10-4), bukan rute dev.

### 7.5 Dampak ke endpoint dan invarian lama
| Hal | Dampak |
|---|---|
| `GET pegawai/{nip}/lampiran/{id}/unduh` (B-18) | **Tetap privat dan tidak berubah**: jwt + Definisi + `PegawaiScope`, `attachment`, `nosniff`, `no-store` (`LampiranController.php:79-94`, `OtorisasiLampiran.php:110-125`). Berkas yang sama kini **juga** bisa dibuka lewat URL publik. Dua jalur ini sengaja berbeda |
| Endpoint berkas layanan cuti/TB/IB/PMK | **Endpoint tetap privat dan tidak berubah.** Tetapi berkas `document_attachment` kode 42–50 (lampiran pengajuan, SK/surat cuti/TB/IB, termasuk surat ber-TTE kode 45; `CutiService.php:747`, `SuratCutiService.php:162`) disimpan dengan kunci `YYYY/MM/<kunci>` (`AttachmentService.php:65-66`), sehingga **juga** bisa dibuka lewat URL publik `/berkas/YYYY/MM/<kunci>`. Ini konsekuensi sadar F10-05 + F10-18. Untuk 43–50 [V2]: legacy menyimpan berkas ini di kolom `riwayat_cuti`/`riwayat_tb`, bukan di `document_attachment`. Untuk 42 PMK [K]. Lihat R8 |
| Foto, Konket, Plt/Plh, berkas `kolom/...` dan `form-digital/...` | Tidak berubah, tetap privat (tidak cocok regex §7.2). Pengecualian: berkas kolom lain yang path-nya berada di subtree `arsip/` atau `assets/upload/` (R6) |
| Invarian "path tidak pernah ke klien" (`AttachmentService.php:29-38`) | Endpoint Arsip mengirim `url_berkas`, yang **memuat path** (untuk legacy memuat NIP lama). Ini deviasi sadar F10-18. **[I]**: baris impor legacy membawa kolom `url` yang berisi nilai yang sama dengan `path` (`legacy:libraries/hr/L_employee.php:296-297`), dan `untukKlien()` B-18 hanya membuang `path` (`AttachmentService.php:38`, `:175-177`), sehingga `url` mungkin sudah terkirim di B-18. Dicek di salinan dev. Bila benar, dibuat CR terpisah di luar F-10 untuk menambah `url` ke `KOLOM_INTERNAL`. Endpoint Arsip sendiri memakai daftar kolom tertutup (AC1-10) |
| SIASN unggah arsip, `s_stat`, kelengkapan beranda | Kodenya tidak berubah (membaca storage lewat adapter). **Datanya** bisa berubah akibat Hubungkan (§6.6) |
| Dokumen usang "path arsip legacy → 404" | Koreksi **non-aditif** kontrak beku "Kontrak API Modul B" (`backend/app/Controllers/Api/Kepegawaian/README.md:7-10`, `:154`, `:204`, `:217`). Perilakunya sudah berubah sejak adapter MAKE-069 (baca legacy lewat `PathLegacy`, `KolomBerkas.php:77-80` → `LocalStorageAdapter.php:104-116`), **bukan** karena F-10. Butuh izin pemilik WS-2 lewat reviewer. Disertai test: foto/Plt-Plh/Konket dengan path `arsip/…` yang berkasnya sudah disalin → 200 (bukan 404). Ikut dikoreksi: komentar `PenugasanService.php:189` dan docblock `LocalStorageAdapter.php:12-13` ("adapter remote menyusul di Fase 6", bertentangan dengan F10-02) |
| Komentar "Arsip Kepegawaian" di backend | `backend/app/Libraries/Kepegawaian/Lampiran/ArsipDataUmum.php:13` diganti menjadi "Arsip Data Umum" (komentar saja) |

### 7.6 Risiko (dicatat sebagai keputusan sadar Horii)
| # | Risiko | Mitigasi yang tetap dipasang (tanpa cek izin) |
|---|---|---|
| R1 | URL legacy bisa ditebak (memuat NIP + nama berkas) | Tanpa listing folder; log akses/not-found dimatikan. Kunci v2 128-bit acak tidak bisa ditebak |
| R2 | KTP/NPWP/BPJS (kode 38) terbuka bagi yang tahu URL (mis. URL yang dibagikan atau tersimpan di riwayat browser) | `Cache-Control: no-store`, `Referrer-Policy: no-referrer` |
| R3 | Audit unduh tidak mungkin (F10-08) | Hanya pembukaan daftar yang dicatat (F10-17) |
| R4 | Membalik pola privat Fase 3 untuk berkas yang sama | Endpoint lama tetap privat (§7.5); saklar rollback (Lampiran A) |
| R5 | Berkas unggahan disajikan dari alamat aplikasi (cookie sesi `SameSite=Lax`, `Jwt.php:60`), sehingga berkas HTML/SVG bisa menjadi XSS | Hanya pdf/gambar yang inline; lainnya diunduh sebagai octet-stream; `nosniff`; tanpa PHP; CSP (§7.3, F10-25, F10-32); rekomendasi domain terpisah (§7.7) |
| R6 | Alias `arsip/` dan `assets/upload/` membuka **seluruh** subtree hasil salinan MAKE-069, termasuk berkas kolom lain yang mungkin berada di sana (surat cuti, `esign_hist.file_pdf_to_sign`, bukti izin presensi, foto) | [K]: sama dengan legacy, yang menyajikan subtree itu publik. Keberadaan berkas kolom lain di sana [I]: dihitung dari manifest (QF10-2) |
| R7 | Berkas yang dihapus dari DB tetap bisa dibuka lewat URL lama (Hapus = baris saja, F10-09) | Retensi berkas yatim = blocker B10-1 |
| R8 | Lampiran layanan kode 43–50 (termasuk surat cuti ber-TTE, kode 45) bisa dibuka lewat URL publik, padahal di legacy berkas ini disimpan di kolom tabel layanan [V2] | Kunci v2 128-bit acak (tidak bisa ditebak); hanya diketahui lewat respons daftar terautentikasi. Endpoint layanan tetap privat |
| R9 | Lampiran + ringkasan riwayat yang tersembunyi di B-18 (mis. hukdis) tampil di Arsip untuk role yang boleh melihat pegawai itu, termasuk pemiliknya (F10-30) | Tidak ada, sesuai legacy |

### 7.7 Rekomendasi ADR
Reviewer menulis **ADR baru** "Penyajian berkas arsip lewat URL publik (F-10)". Isinya:
- konteks: legacy `pathToSrc`, pola privat sejak Fase 3;
- keputusan F10-18 beserta tanggal dan pengambilnya;
- risiko R1–R9;
- penguatan Nginx dan hak baca;
- saklar rollback;
- opsi lanjutan.

Opsi lanjutan:
- Memindahkan berkas ke alamat terpisah lewat `arsip.dasarUrlBerkas`. Catatan: subdomain dari domain yang sama termasuk *same-site*, sehingga cookie `SameSite=Lax` tetap terkirim pada POST dari subdomain dan form-urlencoded tidak memicu preflight (`ApiController.php:120-134`). Jadi subdomain **tidak** menggantikan daftar tipe tertutup + `nosniff`. Isolasi penuh butuh domain terdaftar yang berbeda.
- URL bertanda tangan berumur pendek sebagai jalan tengah, bila kelak diputus.

ADR ini merujuk ADR penyimpanan lampiran yang sudah ada.

---

## 8. Backend

### 8.1 Endpoint baru (`backend/app/Config/RoutesPegawai.php`, grup jwt `:28`, ditambahkan aditif di titik `:94`)
Semua endpoint berada di bawah `api/v1`. `{nip}` = `(:segment)`, `{id}` = `(:num)`. Route literal (`ekspor`, `opsi-hubungkan`) didaftarkan sebelum route berparameter.

| Metode & path | Tahap | Isi |
|---|---|---|
| `GET pegawai/{nip}/arsip` | 1 | Folder agregat + identitas ringkas pegawai + catatan F10-17 |
| `GET pegawai/{nip}/arsip/berkas?id_jenis_rwy=&belum_terhubung=&q=&sort=&order=&page=&per_page=` | 1 (`belum_terhubung` Tahap 2) | Daftar berkas berhalaman. `id_jenis_rwy` berupa angka atau `tanpa_jenis` |
| `GET pegawai/{nip}/arsip/ekspor?format=xlsx\|csv&<filter sama>` | 2 | Berkas ekspor |
| `GET pegawai/{nip}/arsip/opsi-hubungkan?id_riwayat=` | 2 | Calon data riwayat (Disetujui, milik NIP) + ringkasan |
| `PATCH pegawai/{nip}/arsip/berkas/{id}` | 2 | `{ tag }`: Edit/Ubah nama |
| `POST pegawai/{nip}/arsip/berkas/{id}/hubungkan` | 2 | `{ id_riwayat, id_entri? }` |
| `DELETE pegawai/{nip}/arsip/berkas/{id}` | 2 | Hapus baris kode 0 (berkas fisik tertinggal) |
| `GET pegawai/{nip}/arsip/berkas/{id}/buka` | 1 (saklar) | **Hanya dipakai saat `penyajianBerkas = 'privat'`** (rollback): `inline` untuk pdf/gambar, `attachment` + octet-stream untuk lainnya, `nosniff`, `private, no-store`; izin sama dengan daftar. Saat mode `publik` → 404 |

Urutan galat: 401 → 403 role (`Role::filter`) → 403 lingkup (NIP tak ada bagi role ber-lingkup = 403, sama dengan `GET pegawai/{nip}`) → 404 NIP → 404 baris → 422.

**Bentuk respons** (envelope sukses v2, daftar kolom tertutup; `path`, `url`, `basename`, `document_id` tidak pernah dikirim):
```json
// GET pegawai/{nip}/arsip
{ "pegawai": { "nip": "<nip>", "nama": "…", "glr_depan": "…", "glr_belakang": "…" },
  "folder": [ { "id_jenis_rwy": 9, "kode": "jabatan", "label": "Jabatan", "urutan": 6, "jumlah": 12, "jumlah_hilang": 1, "jumlah_tak_dapat_dibuka": 0 },
              { "id_jenis_rwy": 0, "kode": "belum_terhubung", "label": "Belum Terhubung", "urutan": 0, "jumlah": 3, "jumlah_hilang": 0, "jumlah_tak_dapat_dibuka": 0 },
              { "id_jenis_rwy": null, "kode": "tanpa_jenis", "label": "Tanpa Jenis", "urutan": null, "jumlah": 0, "jumlah_hilang": 0, "jumlah_tak_dapat_dibuka": 0 } ],
  "total": 40, "total_hilang": 2, "total_tak_dapat_dibuka": 0, "mode_penyajian": "publik",
  "aksi": { "tulis": false } }
// GET pegawai/{nip}/arsip/berkas
{ "items": [ { "id_attachment": 123, "nama_arsip": "SK Jabatan", "tag": "SK Jabatan", "display_name": "…",
               "id_riwayat": 9, "jenis_riwayat": "Jabatan", "id_entri": "456", "ringkasan_riwayat": "…",
               "file_type": "application/pdf", "created_at": "…",
               "berkas_ada": true, "status_berkas": "ada", "url_berkas": "/berkas/2026/11/<kunci>.pdf", "jenis_buka": "pratinjau",
               "aksi": { "edit": false, "hubungkan": false, "hapus": false } } ],
  "total": 40, "page": 1, "per_page": 25 }
```
Catatan bentuk respons:
- Folder `tanpa_jenis` hanya dikirim bila `jumlah` > 0. Contoh di atas hanya memperlihatkan bentuknya.
- `status_berkas` ∈ `ada` / `hilang` / `tak_dapat_dibuka`.
- `aksi.*` di Tahap 1 selalu `false`. Di Tahap 2 dihitung server per baris (role, lingkup ubah, kode 0). FE memakai `aksi.*` untuk `hidden`; server tetap menegakkan aturan.
- `sort` ∈ `id` (bawaan, `desc`), `nama`, `jenis`, `created_at`.
- Endpoint folder memeriksa `ada()` untuk **semua** baris milik NIP pada setiap permintaan, tanpa cache. Angka maksimum baris per NIP dari D1 dicatat untuk memastikan biayanya wajar.

### 8.2 Catatan pembukaan daftar (F10-17)
- Ditulis lewat `AuditLogModel::record()` (`backend/app/Models/AuditLogModel.php:33-55`, pola `DokumenService.php:334-344`):
  - `entity` = `arsip_lihat`, `entity_id` = NIP sasaran, `event` = `create`, `before` = null;
  - `after` = `{ "endpoint": "folder"|"berkas"|"ekspor", "filter": { "id_jenis_rwy": …, "belum_terhubung": …, "ada_pencarian": true|false } }`. Teks `q` **tidak** dicatat;
  - aktor = `idPengguna` + NIP aktor;
  - **tanpa** nama berkas, path, atau isi.
- Kondisi pencatatan: role ∈ {1, 3, 4, 5} **dan** NIP aktor ≠ `{nip}`.
- Dedup (F10-26) untuk folder/daftar: kunci cache = hash (id_pengguna, NIP), berlaku 15 menit, dan diisi **sesudah** insert berhasil. Ekspor dicatat tanpa dedup, dengan syarat yang sama.
- **Fail-closed:** `record()` tidak melempar galat saat insert gagal, jadi hasilnya wajib diperiksa. Hasil ≤ 0 → 500 envelope (pola `PenyimpanKlien.php:252`). Dicek QA.

### 8.3 Struktur kode (usulan, pemilik Kepegawaian)
- `Controllers/Api/Kepegawaian/ArsipController.php` (`castNumericParams = false`, pola `LampiranController.php:37`).
- `Libraries/Kepegawaian/Arsip/`:
  - `ArsipService`: folder, daftar, filter, `berkas_ada` lewat `StorageAdapterInterface::ada()` dalam try/catch.
  - `OtorisasiArsip`: role + `PegawaiScope` lihat/ubah. Memakai ulang `PegawaiScopeInterface` dan **tidak** memakai izin Definisi per kode, `OtorisasiLampiran`, atau `ArsipDataUmum::bolehTulis()` (F10-30, F10-31).
  - `PenyajiUrlBerkas`: regex §7.2 + saklar mode.
  - `RingkasanRiwayat`: F10-22.
  - `AksiArsip` (Tahap 2): dalam transaksi, kunci baris `FOR UPDATE` dengan pola `OtorisasiLampiran::kunciHapus`; baris tujuan Hubungkan lewat `RiwayatRegistry` + pola `entriMilik` (§6.1).
  - `EksporArsip` (Tahap 2): menulis lewat `EksporTabel`.
- `Config/Arsip.php` (baru): `$penyajianBerkas`, `$dasarUrlBerkas`, `$sajiBerkasDev`, konstanta regex penyajian (§7.2), daftar ekstensi pratinjau, daftar kode tujuan Hubungkan (F10-28).
- Perubahan baris lewat `DocumentAttachmentModel` (`BaseAuditableModel`, audit otomatis di `BaseAuditableModel.php:116`, `:143`).
- Kontrak didokumentasikan di `backend/app/Controllers/Api/Kepegawaian/README.md`, bagian baru "Arsip F-10" (aditif).

### 8.4 Skema dan DBV
**Tidak perlu DBV** untuk Tahap 1 maupun Tahap 2:
- Data dibaca dari `document_attachment` (`2026-09-30-130900_CreateDocumentAttachment.php:47-72`, indeks NIP `:67`, FK `id_riwayat` → `jenis_rwy` `:70`) dan `jenis_rwy` (`2026-09-30-130000_CreateJenisRwy.php:63-85`; `2026-10-26-100800_SeedJenisRwyLayananFase4.php:36-45`). Tidak ada kolom atau kode baru.
- Aksi Tahap 2 hanya meng-update `id_riwayat`/`nama_riwayat`/`id_entri`/`tag` atau menghapus baris. Kode 0 ada di `jenis_rwy` (FK aman). `siasn_upload_arsip.id_da` tanpa FK, jadi Hapus aman.
- Audit F10-17: `audit_logs.entity` bertipe `VARCHAR(100)` bebas (`2026-09-16-000001_CreateAuditLogs.php:21`), jadi `arsip_lihat` tidak butuh migrasi. Event `create`/`update`/`delete` sudah ada di ENUM (`CreateAuditLogs.php:23`; nilai saat ini `create, update, delete, login, logout, warning, import`, `2026-10-27-100100_AlterAuditLogsEventPresensi.php:27`, `:30`).
- Satuan `file_size` campuran (KB legacy vs byte v2) tidak berpengaruh, karena kolom ukuran tidak ditampilkan [K].

---

## 9. Frontend (modul Kepegawaian, F10-06)

### 9.1 Route dan menu
- Halaman berada di `frontend/src/features/kepegawaian/`: `views/ArsipPage.vue` (pemilih + folder) dan `views/ArsipFolderPage.vue` (tabel), komponen di `components/arsip/*`, route di `routes.arsip.ts` dalam folder yang sama (didaftarkan seperti `routes.pegawai.ts`). Pola `meta.roles` mengikuti `features/dokumen/routes.dokumen.ts:9-17`.
  - `/arsip` (name `arsip`): role 2/6/7 dialihkan ke `/arsip/<nip sendiri>`; role 1/3/4/5 melihat pemilih pegawai.
  - `/arsip/:nip` (name `arsip-pegawai`): daftar folder. Role 2/6/7 dengan `:nip` ≠ NIP sendiri dialihkan ke NIP sendiri [K].
  - `/arsip/:nip/:folder` (name `arsip-folder`; `:folder` = `semua`, `id_jenis_rwy`, atau `tanpa_jenis`): tabel berkas.
  - `meta.roles` = 1–7. Guard FE hanya untuk kenyamanan pengguna; backend tetap menegakkan aturan.
- Nav (`shared/layouts/nav.config.ts:170`): F10-06 dipenuhi dengan mengisi `to: { name: 'arsip' }` dan `roles` 1–7 (tanpa 8).
  - Seperti semua menu Fase 7, menu tampil mengikuti F7-14 (`phase: 7`, `ACTIVE_PHASE` ≥ 7 saat rilis; `nav.config.ts:94`, `:210`, `:227-228`; PRD Fase 7 v2.1 §11 #16).
  - QF10-3 hanya menanyakan **waktu** menu tampil (`rilis` lebih awal atau ikut gerbang), bukan apakah menu diaktifkan.
  - Selama tersembunyi, pegawai masuk lewat tombol "Arsip" di Detail Pegawai NIP sendiri, dan QA lewat URL langsung.
  - Ekspektasi menu di `shared/layouts/__tests__/*` diperbarui.
- Path SPA `/arsip/...` tidak bertabrakan dengan Nginx `/berkas/...`.

### 9.2 Tampilan
- Breadcrumb `UiBreadcrumb`: **Arsip** › **<Nama Pegawai (gelar)>** › **<Jenis Riwayat / Semua berkas>**. Untuk 2/6/7, tautan "Arsip" menuju arsip sendiri.
- Pemilih pegawai (admin): `UiCombobox` dari `GET pegawai?search=` (role 1/3/4/5; role 3 tersaring lingkup *daftar*, §5.1).
- Folder: grid kartu (`UiCard`), menjadi satu kolom di 390 px; badge jumlah hilang / tidak dapat dibuka (`UiBadge`).
- Tabel: kolom Nama Arsip | Jenis Riwayat (hanya di "Semua berkas") | Data Riwayat | Status Berkas (badge) | Aksi (⋮, sticky). Ada `UiSearchInput`, `UiPagination`, dan keadaan kosong/galat/memuat (`UiNotice`). Semua teks diinterpolasi (tanpa `v-html`).
- Tahap 2: `UiSelect` Jenis Riwayat + `UiCheckbox` "Belum terhubung" (hanya di "Semua berkas", perilaku §6.1), `UiExportMenu` (Excel/CSV), dialog Edit, dialog Hubungkan (pilih jenis → pilih data riwayat), `ConfirmDialog` Hapus.
- Penggantian label lama → **"Arsip Data Umum"** (F10-15), label dan docblock saja:
  - `PegawaiHeaderCard.vue:5`, `:163`;
  - `ArsipKepegawaianDialog.vue:3`, `:85` (judul dialog);
  - `DetailPegawaiPage.vue:12`, `:123`;
  - komentar backend `ArsipDataUmum.php:13` (§7.5).
- Tombol lama **tetap** ber-testid `btn-arsip`. Test `features/kepegawaian/__tests__/DetailPegawaiPage.spec.ts:350`, `:374` hanya diganti teks labelnya.
- Tombol baru **"Arsip"** (`pegawai-header-arsip`) → `arsip-pegawai` (role 1–7).

### 9.3 Menu ⋮ (AGENTS.md §1)
| Urutan | Item (`data-action`) | Label | Tahap | `hidden` | `disabled` |
|---|---|---|---|---|---|
| 1 | `edit` | Edit | 2 | role 4/5 atau `aksi.edit = false` | sedang memproses |
| 2 | `buka` | Buka | 1 | — | `url_berkas = null` (hilang / tak dapat dibuka) |
| 3 | `hubungkan` | Hubungkan | 2 | bukan kode 0, atau role 4/5 | sedang memproses |
| 4 | `hapus` | Hapus (`danger: true`, garis pemisah otomatis) | 2 | bukan kode 0, atau role 4/5 | sedang memproses |

- Tombol ⋮: `label` = "Aksi untuk <nama arsip>", `testid` = `arsip-actions-<id_attachment>`.
- Testid lain: `arsip-folder-<id_jenis_rwy|semua|tanpa_jenis>`, `arsip-berkas-table`, `arsip-berkas-row-<id>`, `arsip-badge-hilang-<id>`, `arsip-badge-tak-dapat-dibuka-<id>`, `arsip-filter-jenis`, `arsip-filter-belum-terhubung`, `arsip-ekspor`, `arsip-pemilih-pegawai`, `pegawai-header-arsip`. Tombol Arsip Data Umum tetap `btn-arsip`.
- Prefiks tidak bertabrakan dengan `ArsipTable.vue` lama (`lampiran-actions-<id>`, `ArsipTable.vue:326`).
- "Buka" memanggil `window.open(url, 'arsip-popup', 'width=600,height=600,noopener')` (§5.1).
- Pada `mode_penyajian = 'privat'` (rollback), FE mengambil berkas lewat klien API (agar interceptor refresh token ikut, `frontend/src/lib/axios.ts:46`) sebagai blob lalu membuka object URL. Tujuannya mencegah JSON 401 mentah sesudah token akses kedaluwarsa (`Jwt.php:27`).

---

## 10. Impor dan cutover
| Langkah | Isi | Rujukan |
|---|---|---|
| Data baris | `document_attachment` ikut impor tier 10, path disalin apa adanya sesudah `stripslashes` (DBV-029). Kode di luar `jenis_rwy` tertolak FK saat impor | `backend/app/Libraries/Impor/DaftarTabel.php:410-438`; `docs/migrasi/MAKE-069-rencana.md:173-174` |
| Berkas fisik | `php spark lampiran:manifest` → `lampiran:tarik` (alamat dasar diisi operator lewat opsi/env, tidak di repo/log) → `lampiran:periksa`. Mencakup `arsip/…` dan `assets/upload/…` | `MAKE-069-rencana.md:176-195`; `backend/app/Commands/LampiranTarik.php` |
| Hak baca | Sesudah tarik, user Nginx bisa membaca akar (§7.3 butir 5) | README-deploy §1l |
| Freeze | Delta berkas selama freeze ditarik ulang. Tanda "berkas hilang" baru bisa dipercaya sesudah `lampiran:periksa` bersih | `MAKE-069-rencana.md:188-189`; Mapping §8.5 |
| Rekonsiliasi | Angka `jumlah_hilang` per pegawai/folder di halaman Arsip bisa dipakai sebagai alat bantu cek sesudah tarik | — |
| Angka D1 | **Menyusul** dari paket `Downloads/MenParekRaf/D1_f10` (Horii, salinan dev simpegdev): jumlah baris, sebaran per kode, kode 0, segmen awal path (`arsip` vs `assets`), ekstensi, menggantung, baris NULL, pertumbuhan, dan maksimum baris per NIP. Bukan angka produksi; diverifikasi ulang saat cutover | `D1_f10/BACA_DULU_permintaan_D1_f10.md` |
| Angka manifest | Untuk QF10-2: jumlah path berawalan `arsip/` dan `assets/upload/` yang **tidak** dirujuk `document_attachment.path`, per `tabel.kolom` (jumlah saja) | `backend/app/Libraries/Lampiran/ManifestLampiran.php` (`rujukan: {"tabel.kolom": n}`) |
| Setelan Nginx | §7.3 di server produksi (B10-3); README-deploy §1l | — |
| Blocker | B10-1 (F10-03), B10-2 (F10-14), B10-3 | §0 |

---

## 11. Test, QA, dan DoD

### 11.1 PHPUnit (backend)
- `tests/feature/KontrakMatriksRoleTest.php`: semua baris §12.1 (aditif). Rute dev di luar `api/v1` tidak masuk `PREFIKS_CAKUPAN` (`:58`) dan diuji terpisah.
- `tests/Kepegawaian/RoutePegawaiKontrakTest.php`: path Arsip ditambahkan ke `pathKontrakProvider` (tanpa token → 401, tidak pernah 500; `:71-100`).
- Fitur Arsip:
  - matriks role 1–8 per endpoint; 2/6/7 NIP lain → 403; role 3 di luar lingkup lihat/ubah → 403; NIP tidak ada; `{id}` milik NIP lain → 404;
  - semua kode (riwayat/0/37/38/42–50/NULL) tampil; menggantung tampil;
  - `path`/`url`/`basename`/`document_id` tidak ada di respons;
  - berkas hilang → flag tanpa 500; path tak sah/traversal (`../`, `..\`, ter-encode, byte kontrol) → `url_berkas` null;
  - `jumlah_hilang` vs `jumlah_tak_dapat_dibuka`; `q` literal; paginasi maks; ringkasan per kode (termasuk 36) tanpa N+1.
- `PenyajiUrlBerkas`:
  - path legacy (awalan `/`, spasi, `\`) → URL ter-encode per segmen; kunci v2; `assets/upload/` → terisi (F10-33); `kolom/`, `form-digital/`, segmen berawalan `.` → null; `.PDF` → terisi + `pratinjau`; ekstensi lain → `unduh`;
  - mode `privat` → URL endpoint `buka`;
  - **teks regex di README-deploy §1l sama persis dengan konstanta `Config\Arsip`** (pembanding string).
- Catatan F10-17: dicatat/tidak per role dan per NIP sendiri; dedup (berurutan); isi tanpa nama berkas dan tanpa `q`; `record()` ≤ 0 → 500.
- Tahap 2: AC2-01..AC2-13, termasuk berkas fisik tetap ada sesudah Hapus, `siasn_upload_arsip` tidak tersentuh, Hubungkan lewat Definisi (kode 3 lewat induk, kode 9/36), dan sel ekspor `=1+1`.
- Rute dev: tidak terdaftar di `testing`/`production`; controller dev diuji langsung (regex, tipe, header, realpath).
- Koreksi kontrak §7.5: foto/Plt-Plh/Konket dengan path `arsip/…` yang berkasnya ada → 200.
- Test penjaga path lama (`AttachmentServiceTest.php:63`, `:142`; `tests/database/Integrasi/StatServiceTest.php:251`) **tidak diubah** dan tetap hijau. Hanya test Arsip yang memastikan `url_berkas` ada.
- Gate penuh `./check.sh` + regresi lampiran B-18, Arsip Data Umum 37/38, SIASN unggah arsip, `s_stat`, kelengkapan beranda.

### 11.2 Vitest (frontend)
- Route + guard + redirect 2/6/7 (termasuk `/arsip/<nip lain>`); nav per role (role 8 tanpa menu); breadcrumb.
- Folder: urutan, kode 0 dan `tanpa_jenis` di bawah, badge hilang / tidak dapat dibuka.
- Tabel: badge, keadaan kosong/galat.
- Menu ⋮: dibuka lalu item dipilih lewat `data-action` (urutan, `hidden`/`disabled` §9.3).
- "Buka": memanggil `window.open` dengan popup 600×600 + `noopener`, dan tidak dipanggil saat `disabled`. Mode `privat`: jalur blob lewat klien API.
- Tahap 2: Edit, Hubungkan (dua langkah), `ConfirmDialog` Hapus, filter (centang me-reset select; gabungan → kosong), ekspor.
- Header: label "Arsip Data Umum" dengan `btn-arsip`, dan tombol "Arsip".

### 11.3 QA Lapis 1 F-10 (reviewer)
Checklist tersendiri (Fase 7 mengecualikan F-10, `docs/fase7/Checklist_QA_Lapis1_Fase7.md:16`), dijalankan di server dev dengan Nginx (G10-4), viewport 1440 dan 390 px. Termasuk membuka PDF/JPG/PNG di Chrome, Edge, dan Firefox (pratinjau tampil) dan memeriksa header dengan `curl -I`.

**Bukan bug** (sesuai keputusan, ditulis di checklist):
- URL berkas bisa dibuka tanpa login atau dari akun lain (F10-18).
- Lampiran layanan cuti/TB/IB/PMK, termasuk surat cuti ber-TTE, juga bisa dibuka lewat URL `/berkas/…` (R8).
- Role 2/4/5/6/7 melihat lampiran **dan ringkasan** riwayat yang tersembunyi di tab B-18, mis. hukdis, termasuk di opsi Hubungkan (F10-30).
- Pegawai yang muncul di pemilih tetapi di luar lingkup detail role 3 → 403 (§5.1).
- Lampiran riwayat status 10 tetap tampil dan bisa dibuka (F10-16).
- Hapus arsip yatim tidak menghapus berkas fisik; URL lama masih bisa dibuka (F10-09, R7).
- Hubungkan ke Jabatan/Diklat sertifikasi bisa memicu antre SIASN; Hubungkan ke 9/10/11/32 mengubah `s_stat`; Hubungkan ke 11 mengubah kelengkapan beranda (§6.6).
- Role 2/6/7 bisa meng-Hubungkan ke riwayat Disetujui; role 2/3/6/7 bisa mengubah nama arsip kode 37 (F10-31).
- Centang "Belum terhubung" + memilih jenis menghasilkan daftar kosong (§6.1, [K]).
- Berkas selain pdf/gambar terunduh, tidak tampil sebagai pratinjau (F10-32).
- Berkas dibuka di jendela popup kecil; di ponsel terbuka sebagai tab (§5.1).
- Tidak ada token CSRF terpisah (§6.1).
- Endpoint unduh B-18 lama tetap butuh login (§7.5).

MTC-046 (arsip dengan banyak lampiran terindeks; satu berkas hilang ditandai, bukan 500) = **fase Manual Testing tersendiri**, tidak menahan sign-off.

### 11.4 Definition of Done F-10
- Tahap 1 dan Tahap 2 di `main` dengan AC1-01..16 dan AC2-01..13 terpenuhi; quality gate dan regresi hijau.
- Setelan Nginx §7.3 (termasuk hak baca dan uji browser) + README-deploy §1 (contoh server) dan §1l + rute dev + kunci `.env.example`; dokumen usang §7.5 dikoreksi (izin WS-2); kontrak README Kepegawaian diperbarui.
- ADR §7.7 ditulis.
- QA Lapis 1 F-10 lolos.
- Laporan akhir F-10 mencatat deviasi dan cara kembali (Lampiran A).
- Trello QASMTASK-107 (+ butir explorer di QASMTASK-110: dari N/A menjadi dipenuhi lewat folder F-10) dan Notion disinkron.
- Sign-off Horii.

Blocker B10-1..3 adalah syarat cutover/deploy, **bukan** syarat sign-off.

---

## 12. Matriks role × endpoint dan pertanyaan terbuka

### 12.1 Matriks (role 1–8)
| Endpoint | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
|---|---|---|---|---|---|---|---|---|
| `GET pegawai/{nip}/arsip`, `GET …/arsip/berkas` | semua | sendiri | lingkup lihat | semua (lihat) | semua (lihat) | sendiri | sendiri | 403 |
| `GET …/arsip/ekspor` (T2) | semua | sendiri | lingkup lihat | semua | semua | sendiri | sendiri | 403 |
| `GET …/arsip/opsi-hubungkan` (T2) | semua | sendiri | lingkup ubah | 403 | 403 | sendiri | sendiri | 403 |
| `PATCH …/arsip/berkas/{id}` (T2) | semua | sendiri | lingkup ubah | 403 | 403 | sendiri | sendiri | 403 |
| `POST …/arsip/berkas/{id}/hubungkan` (T2, kode 0) | semua | sendiri | lingkup ubah | 403 | 403 | sendiri | sendiri | 403 |
| `DELETE …/arsip/berkas/{id}` (T2, kode 0) | semua | sendiri | lingkup ubah | 403 | 403 | sendiri | sendiri | 403 |
| `GET …/arsip/berkas/{id}/buka` (mode privat saja) | semua | sendiri | lingkup lihat | semua | semua | sendiri | sendiri | 403 |
| URL `/berkas/...` (Nginx) | **tanpa cek izin** (F10-18) | | | | | | | |

Catatan matriks:
- Role di route: baca `Role::filter(1–7)`; tulis `Role::filter(1, 2, 3, 6, 7)`. Filter route **wajib**, karena `PegawaiScope::ROLE_LIHAT_SEMUA` memuat role 8.
- Lingkup di service: "sendiri" = `PegawaiScope` UL_PEGAWAI. "Lingkup lihat/ubah" role 3 = `bolehLihat`/`bolehUbah` jalur detail (fail-closed v2, `PegawaiScope.php:47-51`; service aksi legacy lebih longgar, `Local.php:3527-3544`).
- `opsi-hubungkan` di legacy (`list_arsip_rwy_data`) hanya mengecek login, tanpa cek role/NIP (`Local.php:3272-3285`). v2 memakai role + lingkup ubah [V2, pola keamanan F10-09].

### 12.2 Pertanyaan terbuka (tidak mengubah F10-01..18)
| # | Pertanyaan | Saran reviewer | Menahan |
|---|---|---|---|
| QF10-1 | Baris `assets/upload/...`: dibuka lewat alias publik ketiga seperti legacy (usulan F10-33), atau ditandai "Tidak dapat dibuka"? Jumlah dari D1 blok 4 dilaporkan | Ikut legacy (F10-33) | Tidak menahan koding Tahap 1 |
| QF10-2 | Alias `arsip/` dan `assets/upload/` membuka seluruh subtree, seperti server legacy. Ini konsekuensi F10-18 [K], bukan keputusan baru. Pertanyaan ke Horii **hanya bila** angka manifest (§10) menunjukkan berkas kolom lain berada di subtree itu **dan** Horii ingin mempersempitnya | Hitung dari keluaran `lampiran:manifest` salinan dev (jumlah per `tabel.kolom`), laporkan ke Horii | Tidak menahan koding. Hanya relevan bila Horii ingin mempersempit sebelum rilis |
| QF10-3 | Hanya soal **waktu** menu "Arsip" tampil: ikut gerbang Fase 7 (`ACTIVE_PHASE` ≥ 7) atau lebih awal lewat `rilis`. Menu tetap diaktifkan (F10-06) | Ikut gerbang Fase 7 | MAKE-091 (hanya flag) |
| QF10-4 | Alamat terpisah untuk berkas (penguatan R5). Subdomain = same-site dan tidak menggantikan daftar tipe + `nosniff`; isolasi penuh butuh domain terdaftar berbeda | Dicatat di ADR; keputusan infrastruktur saat deploy | Tidak menahan |
| QF10-5 | Keberadaan baris ber-`id_riwayat` NULL (D1 blok 9). Kode di luar daftar tidak mungkin ada karena FK | F10-29 (folder `tanpa_jenis`, Edit saja) | Tidak menahan |
| QF10-6 | Konfirmasi bawaan F10-19..F10-33 | Ikut bawaan. Horii 12-11-2026: "Yes, decision ini berdasarkan simpeg legacy basecode" | G10-1 |
| QF10-7 | Butir Plan B mana (PB-1..PB-7, §14) yang dipakai, bila ada. Tanpa pilihan = Plan A | Lihat §14.6 | G10-1 |

---

## 13. Pelaksanaan
Pelaksana **belum ditunjuk** (F10-13). Sesudah PRD ini disetujui, reviewer mengajukan kode izin workload (`[#N-…]` + scope) ke Horii, mengikuti aturan "Berbagi Workload".

| Paket | Isi | Berkas milik | Sesudah |
|---|---|---|---|
| **MAKE-090** BE Tahap 1 | Endpoint folder/daftar/`buka` (saklar), `Libraries/Kepegawaian/Arsip/*` (tanpa `AksiArsip`/`EksporArsip`), `Config\Arsip` (+ regex), rute dev, catatan F10-17, kontrak README (bagian baru, aditif), baris KontrakMatriksRoleTest + RoutePegawaiKontrakTest, kunci `.env.example` | `Controllers/Api/Kepegawaian/ArsipController.php`, `Controllers/BerkasDevController.php` (atau setara), `Libraries/Kepegawaian/Arsip/**`, `Config/Arsip.php`; aditif `Config/RoutesPegawai.php`, `backend/.env.example`, `tests/feature/KontrakMatriksRoleTest.php`, `tests/Kepegawaian/RoutePegawaiKontrakTest.php` | G10-1, G10-2 |
| **MAKE-091** FE Tahap 1 | Halaman Arsip di modul Kepegawaian (route, pemilih pegawai, folder, tabel, ⋮ Buka), nav, tombol header + label "Arsip Data Umum" | `frontend/src/features/kepegawaian/views/Arsip*.vue`, `components/arsip/**`, `routes.arsip.ts`; aditif `router/index.ts` (bila perlu), `shared/layouts/nav.config.ts` (+ test menu), `PegawaiHeaderCard.vue`, `ArsipKepegawaianDialog.vue` (label/docblock), `DetailPegawaiPage.vue` (docblock), `DetailPegawaiPage.spec.ts` (label) | Kontrak §8.1 (bisa paralel dengan MAKE-090 memakai mock dengan bentuk yang sama) |
| **MAKE-093** Penyajian | Contoh Nginx + snippet header + README-deploy §1 (contoh server) dan §1l (penegasan `writable/`, hak baca, symlink, regex), verifikasi `curl -I` + uji 3 browser di server dev, koreksi dokumen usang §7.5 (izin WS-2), draf ADR §7.7 | `README-deploy.md`, `backend/app/Controllers/Api/Kepegawaian/README.md` (koreksi non-aditif), komentar `PenugasanService.php:189`, docblock `LocalStorageAdapter.php:12-13`, komentar `ArsipDataUmum.php:13`, test koreksi §7.5 | MAKE-090 (bentuk URL + regex) |
| **MAKE-092** BE+FE Tahap 2 | `AksiArsip`, `EksporArsip` (lewat `EksporTabel`), endpoint T2, filter `belum_terhubung`, dialog Edit/Hubungkan, Hapus, ekspor, test | Berkas MAKE-090/091 yang sama (aditif) | MAKE-090 + MAKE-091 di `main` |
| Penutup | QA Lapis 1 F-10 + laporan + sinkron Trello/Notion | Reviewer | Semua paket |

Urutan: MAKE-090 ∥ MAKE-091 → MAKE-093 → QA Tahap 1 (boleh dilakukan reviewer lebih awal) → MAKE-092 → regresi gate penuh → QA Lapis 1 F-10 → sign-off.

Alur setiap paket: branch `f10/<key>-<slug>` dari `origin/main` → gate cepat → push → CI → gate penuh atas aba-aba reviewer → review → izin Horii → ff ke `main` (hanya `[CR]`).

---

## 14. Plan B — versi penyesuaian (pembanding untuk ditinjau Horii)

### 14.1 Kedudukan
- **Plan A = PRD ini (§3–§13)**, yaitu rencana yang berjalan: persis legacy, dengan pola keamanan v2 (F10-09). Horii 12-11-2026 mengonfirmasi bahwa keputusan F-10 berbasis kode legacy ("Yes, decision ini berdasarkan simpeg legacy basecode").
- **Plan B = versi penyesuaian**, dicatat atas permintaan Horii 12-11-2026 sebagai pembanding. Setiap butir memuat status legacy, status v2 sekarang, gap, serta kelebihan dan kekurangannya.
- Butir Plan B **bisa dipilih satu per satu**; campuran Plan A dan Plan B boleh. Memilih satu butir Plan B = keputusan Horii baru yang mengubah butir F10 terkait lewat revisi PRD (CR). Tanpa keputusan itu, pelaksana mengerjakan Plan A.
- Plan B **tidak** menambah migration. Semua butir masih memakai tabel dan kode `jenis_rwy` yang ada, sehingga tetap tanpa DBV.

### 14.2 Status fitur secara umum
| Sisi | Status |
|---|---|
| Legacy | **Berjalan.** Halaman "Arsip Kepegawaian" per pegawai (`hr/arsip/index/{nip}`): tabel datar semua baris `document_attachment` milik NIP, filter jenis + "belum terhubung", ekspor, aksi Hubungkan/Hapus (kode 0) + Ubah Nama, berkas dibuka lewat URL publik server arsip (`legacy:controllers/hr/Arsip.php`, `legacy:libraries/hr/L_arsip.php:12-19`, `legacy:views/hr/employee/arsip/list.php`, `legacy:helpers/function_helper.php:307-326`) |
| v2 sekarang (`main` `0af9be4f`) | **Belum ada halaman Arsip.** Menu `arsip` masih placeholder (`phase: 7`, tersembunyi). Fondasinya sudah ada: tabel `document_attachment` + `jenis_rwy` (ikut impor tier 10), lampiran per riwayat di tab B-18 (`ArsipTable`, unduh privat lewat `LampiranController` + `OtorisasiLampiran`), dialog Data Umum 37/38 (`ArsipKepegawaianDialog`), penyimpanan lokal privat (`LocalStorageAdapter`), serta alat salin berkas legacy `lampiran:manifest/tarik/periksa` (MAKE-069 opsi A) |
| Plan A | Halaman Arsip v2 yang perilakunya persis legacy (termasuk URL publik), ditambah folder, tanda berkas hilang, dan pola keamanan v2 |
| Plan B | Halaman dan alur yang sama dengan Plan A, tetapi butir yang menyentuh kerahasiaan berkas dan konsistensi aturan B-18 mengikuti pola v2 (§14.3) |

### 14.3 Butir yang berbeda antara Plan A dan Plan B

#### PB-1. Cara membuka berkas (F10-18, F10-08)
- **Legacy:** URL publik langsung `pathToSrc(path)` tanpa sesi. Berkas `arsip/` dari server arsip, path lain dari server aplikasi. Tanpa log unduh (`function_helper.php:307-326`, `list.php:81`).
- **v2 sekarang:** semua lampiran **privat** sejak Fase 3: unduh lewat `GET pegawai/{nip}/lampiran/{id}/unduh` (jwt + izin Definisi + `PegawaiScope`, `attachment`, `nosniff`, `no-store`; `LampiranController.php:79-94`, `OtorisasiLampiran.php:110-125`). Path tidak pernah dikirim ke klien (`AttachmentService.php:29-38`). Dokumen F-09 sudah punya audit unduh + throttle 6/menit per akun (`DokumenService.php:26`, `:336`, `:357`).
- **Plan A:** URL publik lewat alias Nginx `/berkas/` tanpa cek izin (§7). Audit unduh gugur.
- **Plan B:** **privat**. Mode `'privat'` yang di Plan A hanya disiapkan sebagai saklar rollback (F10-24) menjadi bawaan: berkas dibuka lewat `GET pegawai/{nip}/arsip/berkas/{id}/buka` (jwt + lingkup + `no-store`), FE membuka sebagai blob lewat klien API (§9.3). Tidak ada blok Nginx `/berkas/`. Audit + throttle unduh dihidupkan lagi dengan pola F-09 (entity terpisah, mis. `arsip_unduh`).
- **Gap:** legacy publik ↔ v2 privat. Plan A membalik pola v2 untuk berkas yang sama; Plan B mempertahankan pola v2 dan berbeda dari legacy hanya pada cara membuka (popup tetap ada).
- **Kelebihan Plan B:** R1–R5, R7, dan R8 (§7.6) hilang; KTP/NPWP/BPJS (kode 38) tidak terbuka bagi yang sekadar tahu URL; audit unduh bisa dilakukan; invarian "path tidak ke klien" tetap utuh; tidak butuh setelan Nginx, hak baca user Nginx, uji header, maupun ADR pembalikan pola (G10-4 dan B10-3 gugur, MAKE-093 mengecil); satu pola dengan tab B-18.
- **Kekurangan Plan B:** berbeda dari legacy: URL berkas tidak bisa dibagikan ke orang lain atau dibuka tanpa login; berkas melewati PHP, dan `StorageAdapterInterface::baca()` memuat seluruh isi berkas ke memori (`StorageAdapterInterface.php:25`), sehingga berkas besar butuh batas ukuran + `memory_limit` (DEPLOY-002.1) atau CR streaming terpisah; beban PHP-FPM sedikit naik; QR surat cuti lama (F10-14) tetap blocker terpisah, karena alias publik yang bisa dipakai untuk redirect tidak ada.

#### PB-2. Publik hanya untuk berkas legacy (jalan tengah PB-1)
- **Legacy:** semua berkas arsip adalah berkas legacy, dibuka publik.
- **v2 sekarang:** berkas unggahan v2 (kunci `YYYY/MM/<32 hex>`) privat; berkas legacy hasil salinan MAKE-069 dibaca lewat `PathLegacy` oleh endpoint privat.
- **Plan A:** tiga pola publik: kunci v2, `arsip/…`, `assets/upload/…` (§7.2).
- **Plan B:** hanya `arsip/…` dan `assets/upload/…` yang disajikan Nginx (paritas URL lama legacy). Berkas unggahan v2, termasuk lampiran layanan 42–50, dibuka lewat endpoint privat `buka` seperti PB-1. Badge, folder, dan popup sama.
- **Gap:** legacy tidak punya berkas unggahan v2, jadi Plan B tetap paritas penuh untuk semua berkas yang ada di legacy dan mempertahankan pola privat untuk berkas yang lahir di v2.
- **Kelebihan Plan B:** R8 hilang (surat cuti ber-TTE dan lampiran cuti/TB/IB tidak terbuka lewat URL publik); berkas yang diunggah sejak v2 (termasuk KTP/NPWP unggahan baru) tetap privat; URL legacy yang sudah beredar tetap berfungsi; alias `arsip/` tetap membuka opsi redirect QR lama (B10-2).
- **Kekurangan Plan B:** dua jalur buka (publik untuk berkas legacy, privat untuk berkas v2), sehingga FE, test, dan QA lebih banyak; R1, R2, R6, R7 tetap berlaku untuk berkas legacy; setelan Nginx tetap dibutuhkan (MAKE-093 tetap ada, dengan dua pola).

#### PB-3. Izin per jenis riwayat (F10-30)
- **Legacy:** semua baris per NIP tampil bagi role yang boleh membuka halaman, tanpa izin per jenis (`L_arsip.php:12-19`).
- **v2 sekarang:** tab B-18 memakai izin Definisi per kode. Contoh: hukdis hanya boleh dilihat role 1/3 (`Definisi/Hukdis.php:92`); daftar lampiran B-18 ditolak bagi role tanpa izin `lihat` (`OtorisasiLampiran.php:69`).
- **Plan A:** tanpa izin per kode; role 2/4/5/6/7 melihat lampiran + ringkasan hukdis (R9).
- **Plan B:** baris dan folder disaring dengan izin `lihat` Definisi pemilik kode (pola `OtorisasiLampiran::bolehDaftar`). Kode tanpa Definisi riwayat (0, 37, 38, 42–50) memakai aturan tertulis: 0/37/38 = role yang boleh melihat pegawai; 42–50 = role yang boleh melihat layanan itu.
- **Gap:** Plan A = legacy; Plan B = sama dengan B-18.
- **Kelebihan Plan B:** halaman Arsip tidak menjadi jalan pintas ke data yang sengaja disembunyikan di B-18; satu aturan izin di seluruh aplikasi; R9 hilang.
- **Kekurangan Plan B:** pegawai tidak melihat sebagian arsip miliknya sendiri (mis. lampiran hukdis), berbeda dari legacy; aturan kode 42–50 perlu dirinci dan diuji; `jumlah` folder berbeda per role.

#### PB-4. Kunci aksi seperti B-18 (F10-31)
- **Legacy:** Hubungkan dan Ubah Nama tanpa cek kunci baris (`Local.php:3496-3699`, `:3809-3890`).
- **v2 sekarang:** unggah/hapus lampiran ke riwayat **Disetujui** dikunci untuk pegawai bila Definisi menguncinya (`OtorisasiLampiran.php:380-389`); tulis Arsip Data Umum 37 hanya role 1 (`ArsipDataUmum.php:52-56`).
- **Plan A:** tanpa kunci; role 2/6/7 bisa meng-Hubungkan ke riwayat Disetujui, role 2/3/6/7 bisa mengubah nama arsip 37.
- **Plan B:** Hubungkan memakai kunci baris Disetujui yang sama (403 "Data yang sudah disetujui tidak dapat diubah."); Ubah Nama kode 37 memakai `ArsipDataUmum::bolehTulis()`.
- **Gap:** Plan A = legacy; Plan B = sama dengan B-18 dan Data Umum.
- **Kelebihan Plan B:** pegawai tidak bisa menambah berkas ke riwayat yang sudah disahkan admin lewat jalur samping; konsisten dengan B-18.
- **Kekurangan Plan B:** pegawai tidak bisa merapikan arsip yatim miliknya ke riwayat yang sudah disetujui; tugas itu jatuh ke admin (role 1/3).

#### PB-5. Hapus arsip yatim (F10-09)
- **Legacy:** hanya baris DB yang dihapus; berkas fisik tertinggal; tanpa log (`Local.php:3786-3797`).
- **v2 sekarang:** `AttachmentService::hapus()` menghapus baris lalu menjadwalkan penghapusan berkas sesudah transaksi selesai (`AttachmentService.php:120-139`); hapus B-18 juga menjaga lampiran wajib terakhir (`OtorisasiLampiran.php:43-45`).
- **Plan A:** baris saja, berkas tertinggal (R7, retensi = B10-1).
- **Plan B:** memakai `AttachmentService::hapus()`, sehingga berkas fisik ikut terhapus sesudah commit. Sebelum dipakai, pelaksana memastikan tidak ada baris lain yang merujuk path yang sama [I].
- **Gap:** Plan A = legacy; Plan B = pola v2.
- **Kelebihan Plan B:** tidak ada berkas yatim yang menumpuk di disk; R7 hilang; retensi berkas yatim (bagian B10-1) lebih sederhana.
- **Kekurangan Plan B:** berkas tidak bisa dipulihkan dari disk sesudah dihapus (bergantung backup, B10-1); berbeda dari legacy.

#### PB-6. Hubungkan dan antrean SIASN (F10-09, §6.6)
- **Legacy:** tanpa cek; Hubungkan bisa membuat berkas masuk antre unggah SIASN.
- **v2 sekarang:** `AntreArsip` mengantre lampiran kode 9/36/41 (Jabatan) dan 5 (Diklat sertifikasi) berekstensi PDF untuk riwayat yang sudah terkirim ke SIASN (`AntreArsip.php:17-21`); belum ada jalur Hubungkan.
- **Plan A:** tanpa pencegahan, risiko diterima.
- **Plan B:** Hubungkan ke 9/36/5 hanya untuk role 1/3, dengan dialog konfirmasi yang menyebut bahwa berkas bisa ikut terkirim ke SIASN. Role 2/6/7 tetap bisa Hubungkan ke kode lain.
- **Gap:** Plan A = legacy; Plan B = pencegahan ringan khusus v2.
- **Kelebihan Plan B:** berkas yang dikirim ke SIASN atas nama instansi selalu lewat keputusan admin; pengiriman tak sengaja berkurang.
- **Kekurangan Plan B:** pegawai tidak bisa menghubungkan SK Jabatan/sertifikat sendiri; aturan role per kode menambah test.

#### PB-7. Lampiran "menggantung" (F10-16)
- **Legacy:** tetap tampil dan bisa dibuka tanpa syarat status (`L_arsip.php:12`).
- **v2 sekarang:** unduh B-18 untuk riwayat status 10 (Dihapus) → 404 (`OtorisasiLampiran.php:121`).
- **Plan A:** tampil dan bisa dibuka, tanpa badge.
- **Plan B:** tampil dengan badge status riwayat ("Riwayat dihapus" / "Pengajuan" / "Riwayat tidak ditemukan"); buka hanya untuk role 1/3; role lain melihat baris tanpa bisa membuka.
- **Gap:** Plan A = legacy; Plan B = jalan tengah antara legacy (tampil) dan B-18 (404).
- **Kelebihan Plan B:** admin tetap bisa memeriksa dan merapikan arsip; berkas dari riwayat yang sudah dihapus tidak beredar lagi; status baris jelas di layar.
- **Kekurangan Plan B:** pegawai tidak bisa membuka berkas lama miliknya sendiri yang riwayatnya sudah dihapus; butuh satu query status per kode (tetap tanpa N+1).

### 14.4 Butir yang sudah disesuaikan di Plan A (tidak berbeda di Plan B)
| Butir | Legacy | v2 sekarang | Plan A = Plan B | Gap / catatan |
|---|---|---|---|---|
| Keamanan aksi (F10-09) | Aksi lewat GET, `tag` mentah (XSS), `opsi-hubungkan` hanya cek login | Endpoint tulis non-GET, sanitasi, cek lingkup | Non-GET, `tag` dibersihkan, cek role + lingkup + kepemilikan, audit baris | Celah legacy tidak ditiru; perilaku bisnisnya tetap |
| Tipe berkas (F10-32) | Semua ekstensi disajikan apa adanya | Unduh B-18 selalu `attachment` | Semua bisa dibuka; hanya pdf/gambar sebagai pratinjau | Mencegah HTML/SVG berjalan sebagai halaman (R5) |
| Folder (F10-04) | Tabel datar + filter | — | Folder virtual dari `jenis_rwy` + "Semua berkas" (filter legacy tetap) | "Semua berkas" = tampilan legacy |
| Tanda berkas hilang (F10-23) | Tidak ada (link mati) | B-18 404 bila berkas tidak ada | Badge "Berkas hilang" / "Tidak dapat dibuka", tanpa 500 | Fitur baru v2 (DoD) |
| Jejak buka daftar (F10-17) | Tanpa log | Audit F-09 untuk unduhan dokumen | Catat pembukaan daftar pegawai lain oleh 1/3/4/5 | Fitur baru v2 |
| Paginasi, pencarian, CSV (F10-20/21/27) | DataTables di klien; CSV berekstensi `.txt` | Pola `ListQuery`, `EksporTabel` | Paginasi + `q` di server; `.csv` | Perbaikan teknis |
| Role 4/5 dan 8 (F10-07) | 4/5 lihat; 8 ditolak | `PegawaiScope` | Sama | Tidak ada gap |

### 14.5 Dampak bila butir Plan B dipilih
| Butir | Bagian PRD yang direvisi | Paket |
|---|---|---|
| PB-1 | F10-18 (keputusan baru), F10-08 hidup lagi, F10-24 (bawaan `'privat'`), §7 diringkas, R1–R8, ADR §7.7 tidak perlu, G10-4/B10-3 gugur | MAKE-090 (endpoint `buka` + audit/throttle), MAKE-091 (buka via blob), MAKE-093 menyusut ke koreksi dokumen §7.5 |
| PB-2 | F10-18 (dipersempit), §7.2 (dua pola), R8 hilang | MAKE-090 (dua jalur URL), MAKE-091 (dua cara buka), MAKE-093 (dua pola Nginx) |
| PB-3 | F10-30, AC1-05, §12.1, daftar "Bukan bug" §11.3 | MAKE-090 (`OtorisasiArsip` memakai Definisi) |
| PB-4 | F10-31, AC2-13 | MAKE-092 |
| PB-5 | F10-09 (Hapus), AC2-09, R7 | MAKE-092 |
| PB-6 | F10-09 (SIASN), F10-28, §6.6, AC2-07 | MAKE-092 |
| PB-7 | F10-16, AC1-06 | MAKE-090, MAKE-091 |

### 14.6 Catatan reviewer (bukan keputusan)
- PB-1 dan PB-2 saling menggantikan. PB-1 paling aman; PB-2 menjaga paritas URL legacy sambil menutup R8.
- PB-3..PB-7 tidak saling bergantung dan bisa dipilih tersendiri.
- Bila Horii tetap memilih Plan A untuk semua butir, bagian ini tetap disimpan sebagai catatan alternatif dan dasar ADR (§7.7).

---

## Lampiran A — Deviasi dan cara kembali
| # | Legacy | v2 (F-10) | Cara kembali |
|---|---|---|---|
| **F10-18** (membalik pola v2) | URL publik `pathToSrc` tanpa sesi (`function_helper.php:307-326`) | **Sama dengan legacy** untuk halaman Arsip. Pola privat v2 sejak Fase 3 (endpoint jwt + lingkup, path tidak ke klien) **dibalik** untuk berkas yang sama. Endpoint lama tetap privat. Akibat tambahan: lampiran layanan 43–50 (termasuk surat cuti ber-TTE) juga terbuka lewat kunci v2 (R8, [V2]) | **Kembali ke pola privat (rollback)** dalam tiga langkah. (1) Ubah `Config\Arsip::$penyajianBerkas` (atau `.env` `arsip.penyajianBerkas`) ke `'privat'`: respons daftar lalu memberi `url_berkas` = `…/arsip/berkas/{id}/buka` (jwt + lingkup, `no-store`), dan FE membuka berkas lewat klien API sebagai blob (§9.3), tanpa ubah kode/DB. (2) Hapus blok Nginx `location ^~ /berkas/` lalu `nginx -t && reload`, sehingga semua URL publik lama menjadi 404. (3) Opsional lewat CR: audit + throttle unduh ala F-09 (`DokumenService.php:334-360`) dan streaming berkas besar. Kedua mode dijaga test |
| F10-32 ekstensi | Semua ekstensi disajikan apa adanya oleh server web | Semua ekstensi tetap bisa dibuka [K]. Hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; lainnya diunduh sebagai octet-stream [V2 kecil, mitigasi R5] | Tambah ekstensi pratinjau di `Config\Arsip` + `types`/`map` Nginx (hanya tipe yang tidak bisa menjalankan skrip) |
| F10-23 segmen berawalan titik | Disajikan | "Tidak dapat dibuka" [V2 kecil] | Ubah regex di `Config\Arsip` + Nginx bersamaan (test pembanding menjaga) |
| F10-17 | Tanpa log | Catatan pembukaan daftar `arsip_lihat` | CR (hapus pemanggilan pencatatan); baris audit tidak dihapus |
| F10-04 folder | Tabel datar + filter | Folder virtual + "Semua berkas" (filter legacy tetap ada di T2) | Tidak perlu: "Semua berkas" = tampilan legacy |
| F10-15 | "Arsip Kepegawaian" = semua lampiran | Halaman "Arsip"; dialog 37/38 = "Arsip Data Umum" (testid `btn-arsip` tetap) | Ganti label (FE saja) |
| F10-09 keamanan | Aksi lewat GET, `tag` mentah, `opsi-hubungkan` tanpa cek role/NIP (`Local.php:3272-3285`), Hubungkan tanpa cabang `else` untuk kode lain (`Local.php:3561-3690`) | Metode non-GET + cookie `SameSite=Lax`, `tag` dibersihkan, cek role + lingkup di setiap aksi dan di `opsi-hubungkan`, kode di luar F10-28 → 422 | Tidak disarankan (celah CSRF/XSS/IDOR dan data tak konsisten) |
| AC1-03 | NIP lain dialihkan ke arsip sendiri | FE sama (dialihkan); API → 403 [V2] | Tidak perlu |
| F10-19..F10-33 | Lihat register §3.2 | — | CR atas keputusan Horii |

## Lampiran B — Riwayat versi
| Versi | Tanggal | Isi |
|---|---|---|
| v1.0 draf | 12-11-2026 | CR-120: PRD F-10 dari Mapping F-10 + keputusan Horii 12-11-2026 #1–#18 (F10-01..18); bawaan usulan F10-19..33; Tahap 1/2; penyajian URL publik + rollback; tanpa DBV. Sudah memuat hasil review keputusan/legacy dan review teknis, bawaan F10-19..33 menunggu konfirmasi Horii |
| v1.1 draf | 12-11-2026 | Jawaban Horii atas konfirmasi bawaan dicatat (QF10-6); §14 Plan B versi penyesuaian (PB-1..PB-7: status legacy, status v2, gap, kelebihan, kekurangan, dampak paket) + QF10-7 |
