# PRD F-10 Arsip Digital v1.3 (disetujui Horii)

| Atribut | Isi |
|---|---|
| Versi | **v1.3, 12-11-2026** (CR-120). Disusun reviewer CR dari dokumen kerja `Mapping_F-10_Arsip_Digital.md` (folder kerja Horii, tidak di repo) dan kode `main` `0af9be4f`. Temuan dua peninjau (lensa keputusan/legacy dan lensa teknis) sudah dimasukkan; temuan tiga peninjau atas v1.2 (lensa keputusan, teknis, kelengkapan) juga sudah dimasukkan (Lampiran B) |
| Status | **DISETUJUI Horii 12-11-2026** ("Setuju, boleh merge PRD F-10"); semua bawaan F10-19..F10-38 sudah dikonfirmasi (QF10-6). Catatan riwayat persetujuan: Keputusan Horii 12-11-2026 (#1–#18) **sudah final** dan dicatat apa adanya (§3.1). Butir F10-19 dst. adalah bawaan usulan reviewer yang masih perlu dikonfirmasi Horii (§3.2). Untuk bawaan §3.2, reviewer condong ke perilaku legacy bila tidak menyentuh pola keamanan v2 (F10-09), tetapi semuanya tetap usulan yang menunggu konfirmasi Horii (QF10-6). Horii 12-11-2026 menjawab permintaan konfirmasi bawaan (isi v1.1), R8, dan prioritas pratinjau atas CSP dengan "Yes, decision ini berdasarkan simpeg legacy basecode" (R8 kemudian hilang lewat PB-2 = B, v1.2), lalu meminta **Plan B versi penyesuaian** sebagai pembanding (§14) untuk ditinjau. Horii 12-11-2026 sudah memilih per butir Plan B: PB-1 = B, PB-2 = B, PB-3 = A, PB-4 = B, PB-5 = A, PB-6 = A, PB-7 = A (§14.1). Karena PB-1 dan PB-2 di v1.1 saling menggantikan (§14.6), reviewer menanyakan maksudnya; Horii memilih opsi **"PB-2 + audit PB-1"**: PB-2 sebagai dasar (berkas legacy publik, berkas unggahan v2 privat), ditambah audit + throttle dari PB-1 untuk jalur privat. PB-4 diterapkan sebagai kunci aksi seperti B-18. Bawaan teknis baru F10-34..F10-38 dikonfirmasi Horii 12-11-2026 ("Setuju semua F10-34..38, kode 38 ikut legacy") |
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

Berkas dibuka di jendela popup dengan **dua jalur**. Horii 12-11-2026 memilih PB-1 = B dan PB-2 = B. Karena kedua butir di v1.1 saling menggantikan (§14.6), reviewer menanyakan maksudnya, dan Horii memilih opsi "PB-2 + audit PB-1": PB-2 sebagai dasar, ditambah audit + throttle dari PB-1:
- **Berkas legacy** (hasil salinan MAKE-069, path `arsip/...` dan `assets/upload/...`): **persis legacy**, lewat alamat publik langsung yang disajikan server web (Nginx), tanpa pemeriksaan izin per berkas. Ini keputusan sadar Horii; risikonya dicatat di §7 dan Lampiran A.
- **Berkas unggahan v2** (lampiran riwayat, Arsip Data Umum 37/38, dan lampiran layanan 42–50 yang diunggah atau dibuat di v2, termasuk surat cuti ber-TTE kode 45): **privat**, dibuka lewat endpoint aplikasi yang memeriksa login dan lingkup pegawai, dengan catatan audit dan batas jumlah buka per menit seperti unduhan Dokumen F-09. Berkas ini tidak bisa dibuka lewat alamat publik.

Tahap 1 hanya untuk melihat. Tahap 2 menambah aksi legacy: Hubungkan, Ubah nama, Hapus arsip yatim, Ekspor, dan filter "belum terhubung". Aturan aksinya ikut legacy, dengan pola keamanan v2, kecuali satu penyesuaian pilihan Horii (PB-4): pegawai (role 2/6/7) tidak bisa meng-Hubungkan arsip ke riwayat yang sudah **Disetujui**, dan Ubah Nama arsip kode 37 hanya untuk role 1, sama dengan aturan tab lampiran B-18 dan Arsip Data Umum. Akibatnya, karena tujuan Hubungkan selalu riwayat Disetujui [K] dan semua jenis riwayat saat ini mengunci baris Disetujui (`RiwayatDefinisi.php:99-102`), pegawai praktis hanya bisa meng-Hubungkan arsip yatim ke Arsip Data Umum (kode 38). Menghubungkan ke riwayat lain menjadi tugas admin (role 1/3), dan risiko antrean SIASN lewat Hubungkan kini hanya terbuka bagi role 1/3 (F10-31, §6.6). Unggah tetap lewat form riwayat/Data Umum. Skema database tidak berubah, jadi DB Validator tidak diperlukan.

**Plan B (§14).** Atas permintaan Horii, PRD ini memuat versi penyesuaian sebagai pembanding. Horii sudah memilih per butir (§14.1): Plan B untuk PB-1, PB-2, dan PB-4; Plan A untuk PB-3, PB-5, PB-6, PB-7. PB-1 dan PB-2 digabung sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1". Bagian utama PRD sudah direvisi sesuai pilihan itu.

## Cara pakai (WAJIB dibaca)
1. Label perilaku:
   - **[K]**: ikut kode legacy. Rujukan `legacy:` = folder `application/` repo legacy.
   - **[V2]**: berbeda dari legacy, alasannya ada di register.
   - **[I]**: inferensi yang belum terbukti, dicek saat D1/QA.
2. Rujukan kode v2 memakai path relatif repo `main` `0af9be4f`.
3. Pertanyaan baru diajukan ke reviewer, lalu reviewer meneruskannya ke Horii. Jangan menebak keputusan bisnis. Keputusan F10-01..F10-18 tidak dibuka ulang, kecuali lewat keputusan Horii baru (v1.2: F10-08 dan F10-18 diubah oleh PB-1/PB-2; F10-09 diberi pengecualian oleh PB-4 = B; pilihan Plan B Horii 12-11-2026, §14.1).
4. Repo ini publik. Jangan menulis NIP, IP, host, atau kredensial di kode, test, log, maupun dokumen. Contoh URL memakai `<nip>`/`<kunci>`/`<id>`.

---

## 0. Gerbang & prasyarat
| ID | Prasyarat | Menahan |
|---|---|---|
| G10-1 | PRD ini sudah ada di `main` (izin Horii) | Semua koding F-10 |
| G10-2 | Kode izin workload untuk pelaksana (F10-13) | Mulai koding paket MAKE-090..093 |
| G10-3 | **D1 F-10**: Horii menjalankan paket `Downloads/MenParekRaf/D1_f10` (`BACA_DULU_permintaan_D1_f10.md`) di **salinan dev legacy (simpegdev)**, bukan produksi. Angkanya diverifikasi ulang di produksi saat cutover | Angka di §10 dan pelaporan QF10-2. **Tidak** menahan koding Tahap 1 |
| G10-4 | Setelan Nginx `/berkas/` (§7.3, dua pola berkas legacy) terpasang di server dev dan sudah diverifikasi: user Nginx bisa membaca berkas legacy, `curl -I` memberi header yang benar, kunci v2 `/berkas/YYYY/MM/<kunci>` → 404, serta pratinjau PDF/JPG/PNG tampil di Chrome, Edge, dan Firefox, baik lewat Nginx (berkas legacy) maupun lewat endpoint `buka` (berkas unggahan v2, popup + blob, serta navigasi langsung ke `buka` dengan CSP F10-34 (4)), termasuk tombol unduh/cetak penampil PDF pada popup blob sebelum dan sesudah pencabutan object URL (F10-37 (5)). Hasilnya dicatat di README-deploy §1l | QA Lapis 1 F-10 |
| B10-1 | **Blocker DEPLOY/cutover** (F10-03): penanggung jawab disk, kapasitas + pemantauan, backup folder berkas, retensi berkas yatim (sudah dikomentari di Trello DEPLOY-002). Tambahan syarat teknis: tidak boleh ada symlink di dalam akar berkas. Bila disk dipindah, mount langsung ke path akar, bukan lewat symlink (§7.3) | Cutover. Tidak menahan koding/QA |
| B10-2 | **Blocker cutover di luar F-10** (F10-14): QR surat cuti ber-TTE legacy yang menunjuk server arsip lama (Trello DEPLOY-002, belum diputus). Catatan teknis: alias `/berkas/arsip/…` (§7.3) secara teknis membuka opsi redirect QR lama ke v2 | Mematikan server arsip legacy |
| B10-3 | Setelan Nginx `/berkas/` (dua pola berkas legacy) di server produksi + README-deploy §1l (§7) | Rilis F-10 ke produksi |

## 1. Scope
### 1.1 Di dalam scope
| Bagian | Isi | Tahap | Key usulan |
|---|---|---|---|
| Indeks arsip (BE) | Folder agregat per `jenis_rwy` + daftar berkas berhalaman + penanda berkas hilang / tidak dapat dibuka + URL berkas + `akses_berkas` + catatan pembukaan daftar (F10-17) + endpoint privat `buka` untuk berkas unggahan v2 dengan audit + throttle (F10-08, F10-34..36) + rute khusus mesin dev (dua pola legacy, §7.4) | 1 | MAKE-090 |
| Halaman Arsip (FE, modul Kepegawaian) | Route, menu `arsip`, pemilih pegawai (admin), breadcrumb, folder, tabel berkas, ⋮ "Buka" (publik = popup URL; privat = popup + blob, F10-37), tautan dari Detail Pegawai, label tombol lama → "Arsip Data Umum" | 1 | MAKE-091 |
| Aksi legacy | Hubungkan (dengan kunci baris Disetujui B-18, F10-31), Ubah nama (Edit; kode 37 role 1 saja), Hapus arsip yatim, Ekspor Excel/CSV, filter "Belum terhubung" + jenis di tampilan "Semua berkas" | 2 | MAKE-092 |
| Penyajian berkas legacy | Alias Nginx `/berkas/` hanya untuk dua pola berkas legacy (`arsip/...` dan `assets/upload/...` F10-33), README-deploy + test pembanding regex README §1l vs `Config\Arsip`, koreksi dokumen usang (rute dev ada di MAKE-090) | 1 | MAKE-093 |
| Penutup | QA Lapis 1 F-10 + catatan sign-off | — | Reviewer |

### 1.2 Di luar scope
| Hal | Alasan / milik |
|---|---|
| QR surat cuti ber-TTE legacy ke server arsip lama | F10-14: blocker cutover di luar F-10 (DEPLOY-002) |
| Berkas yang disimpan di kolom tabel lain (foto pegawai, surat cuti `file_*`, karpeg `file_1..5`, SKP periodik `f_arsip_*`, bukti izin presensi, dll.) | F10-05: tidak ditampilkan di halaman Arsip (legacy pun tidak, `legacy:libraries/hr/L_arsip.php:12`). Catatan: berkas kolom lain yang kebetulan berada di subtree `arsip/` atau `assets/upload/` tetap bisa dibuka lewat URL publik, seperti di legacy (R6) |
| Unggah dari halaman Arsip | F10-11: unggah tetap lewat form riwayat, Data Umum (`ArsipKepegawaianDialog`), dan layanan |
| Kuota per pegawai, versi berkas, "unduh semua (ZIP)" | Tidak ada di legacy (Mapping §8.7) |
| Entitas/tabel folder baru | F10-04: folder = tampilan dari `jenis_rwy` |
| Audit + throttle unduh untuk berkas **legacy** | F10-08 (v1.2): berkas legacy dibuka lewat URL publik Nginx, tidak lewat aplikasi, sehingga tidak bisa diaudit. Audit + throttle **berlaku** untuk berkas unggahan v2 lewat jalur privat (di dalam scope, F10-35/36) |
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
Catatan v1.2: F10-08 dan F10-18 diubah oleh pilihan Plan B Horii 12-11-2026 (PB-1 = B, PB-2 = B; digabung sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1", §14). F10-09 diberi pengecualian oleh PB-4 = B (F10-31). Teks lama ada di v1.1 (Lampiran B).

| # | Keputusan | Label | Sumber |
|---|---|---|---|
| F10-01 🔒 | F-10 dibuka di atas **penyimpanan lokal v2** (MAKE-069 opsi A: berkas legacy disalin ke v2 saat cutover dengan struktur folder relatif yang sama). Tidak ada server arsip terpisah (`docs/migrasi/MAKE-069-rencana.md:150-158`) | [V2] | Horii 12-11-2026 |
| F10-02 🔒 | J3.06 PRD 01-10 ("v2 terhubung ke server Arsip yang sama") **gugur**, digantikan MAKE-069 opsi A. Entri `arsip` di `Config\Integrations` tidak dibuat | [V2] | Horii 12-11-2026 |
| F10-03 🔒 | Prasyarat server (penanggung jawab disk, kapasitas + pemantauan, backup folder berkas, retensi berkas yatim) = **blocker deploy/cutover** (DEPLOY-002). Tidak menghambat koding | — | Horii 12-11-2026 |
| F10-04 🔒 | Cakupan **bertahap**. Tahap 1: lihat daftar + buka berkas + tanda "berkas hilang" + tampilan folder (Pegawai → Jenis Riwayat → berkas; folder virtual dari `jenis_rwy`, tanpa entitas folder baru). Tahap 2: Hubungkan (kode 0 → riwayat), Ubah nama (`tag`), Hapus arsip yatim (kode 0), Ekspor Excel/CSV, filter "belum terhubung" | [K] aksi / [V2] folder | Horii 12-11-2026 |
| F10-05 🔒 | Yang tampil: `document_attachment` kode riwayat + Data Umum 37/38 + belum terhubung (0) + lampiran layanan cuti/TB/IB/PMK (42–50). Berkas di kolom lain **tidak** tampil | [K] (42 PMK = kode legacy `ARSIP_RWY`, `CreateJenisRwy.php:20`, `:84`); 43–50 [V2]: kode layanan v2 (`2026-10-26-100800_SeedJenisRwyLayananFase4.php:36-45`) | Horii 12-11-2026 |
| F10-06 🔒 | Endpoint & halaman berada di **modul Kepegawaian** (dekat `OtorisasiLampiran`), baik backend maupun frontend (`frontend/src/features/kepegawaian/`). Menu nav `arsip` diaktifkan. Pegawai (2/6/7) melihat arsip sendiri lewat menu; admin lewat menu + tautan dari Detail Pegawai | [K] pintu masuk / [V2] menu admin | Horii 12-11-2026 |
| F10-07 🔒 | Role 4 (Admin View/Eselon I) dan 5 (Menteri): **hanya lihat**, dengan lingkup = `PegawaiScope` role itu (saat ini semua pegawai, `PegawaiScope.php:65`). Role 8 **tidak boleh**. Role 3 dalam lingkupnya. Role 1 semua. Role 2/6/7 hanya NIP sendiri | [K] (`legacy:controllers/hr/Arsip.php:12-46`) | Horii 12-11-2026 |
| F10-08 🔒 | Audit + throttle unduh **berlaku untuk berkas unggahan v2** (jalur privat, endpoint `buka`), dengan pola F-09 (`backend/app/Libraries/Dashboard/Dokumen/DokumenService.php:334-344` audit, `:353-360` throttle). Berkas legacy yang dibuka lewat URL publik tetap **tanpa** audit unduh (tidak lewat aplikasi). Rincian = bawaan F10-35/F10-36. (v1.1: "gugur akibat F10-18") | [V2] | Horii 12-11-2026 (PB-1 = B, digabung dengan PB-2 sesuai klarifikasi "PB-2 + audit PB-1") |
| F10-09 🔒 | Aturan aksi Tahap 2 **ikut legacy persis**: Hapus hanya menghapus baris DB (berkas fisik tertinggal) dan hanya untuk arsip kode 0; Hubungkan/Ubah nama **tanpa** cek antrean SIASN. Hubungkan ke kode 9/36/41/5 bisa membuat berkas memenuhi syarat antre unggah SIASN; ini **risiko yang diterima** (§6.6). Pola keamanan v2 tetap dipakai: tanpa aksi pengubah lewat GET (POST/PATCH/DELETE + perlindungan standar, §6.1), `tag` disanitasi (anti-XSS), cek kepemilikan/lingkup di setiap aksi (anti-IDOR), dan audit perubahan baris lewat model auditable (event `update`/`delete`). Pengecualian v1.2: kunci aksi Hubungkan/Ubah Nama kode 37 seperti B-18 (F10-31, PB-4 = B) | [K] aturan / [V2] keamanan | Horii 12-11-2026 (Hapus dan antrean SIASN dikonfirmasi lewat PB-5 = A, PB-6 = A; pengecualian kunci aksi lewat PB-4 = B) |
| F10-10 🔒 | Berkas dibuka sebagai **pratinjau di browser** (PDF/gambar), seperti legacy (`legacy:views/hr/employee/arsip/list.php:81`) | [K] | Horii 12-11-2026 |
| F10-11 🔒 | **Tidak ada unggah** dari halaman Arsip | [K] | Horii 12-11-2026 |
| F10-12 🔒 | Tampilan memakai pola komponen v2 yang sudah ada (breadcrumb + daftar folder + tabel berkas, ⋮ AGENTS.md §1), tanpa menunggu Figma | [V2] | Horii 12-11-2026 |
| F10-13 🔒 | Pelaksana ditunjuk **sesudah** PRD disetujui, lewat kode izin workload (§13) | — | Horii 12-11-2026 |
| F10-14 🔒 | QR surat cuti ber-TTE legacy yang menunjuk server arsip lama = blocker cutover **di luar F-10** (DEPLOY-002: URL/server lama read-only vs migrasi/redirect QR ke v2, belum diputus) | — | Horii 12-11-2026 |
| F10-15 🔒 | Halaman **baru** bernama **"Arsip"**. Tombol lama "Arsip Kepegawaian" (dialog Data Umum 37/38, MAKE-014) diganti label menjadi **"Arsip Data Umum"** | [V2] | Horii 12-11-2026 |
| F10-16 🔒 | Lampiran "menggantung" (riwayat status 10 / pengajuan / `id_entri` tidak valid) **ikut legacy**: tetap tampil dan bisa dibuka oleh role yang boleh melihat pegawai itu (`legacy:libraries/hr/L_arsip.php:12`; `legacy:helpers/function_helper.php:2260-2383` tanpa syarat status) | [K] | Horii 12-11-2026 (dikonfirmasi lewat PB-7 = A) |
| F10-17 🔒 | Pembukaan **daftar** arsip pegawai lain oleh role 1/3/4/5 **dicatat** (siapa membuka arsip siapa, tanpa isi berkas). Pegawai yang membuka arsip sendiri tidak dicatat | [V2] (legacy tanpa log) | Horii 12-11-2026 |
| F10-18 🔒 | Dua jalur buka berkas (PB-1 = B dan PB-2 = B, digabung sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1"). (a) **Berkas legacy** (path cocok pola `arsip/...` atau `assets/upload/...`, F10-33): dibuka **persis legacy**, lewat **URL publik langsung** (disajikan Nginx dari folder berkas), **tanpa** cek izin per berkas. Horii memilih ini **sesudah** diberi tahu risikonya: URL bisa ditebak (path legacy memuat NIP); KTP/NPWP legacy terbuka bagi yang tahu URL; audit unduh tidak bisa dilakukan; dan ini **membalik pola privat lampiran sejak Fase 3** untuk berkas legacy. Alias `arsip/` tetap membuka opsi redirect QR lama (B10-2). (b) **Berkas unggahan v2** (kunci `YYYY/MM/<32 hex>.<ext>`, termasuk lampiran riwayat unggahan v2, Data Umum 37/38 unggahan v2, dan layanan 42–50 termasuk surat cuti ber-TTE kode 45): **privat**, dibuka lewat `GET pegawai/{nip}/arsip/berkas/{id}/buka` (jwt + `Role::filter(1–7)` + `PegawaiScope` lihat; izin sama dengan daftar arsip, F10-30), `inline` untuk pdf/jpg/jpeg/png/gif, `attachment` + `application/octet-stream` untuk lainnya, `nosniff`, `Cache-Control: private, no-store`. Pola kunci v2 **tidak** ada di blok Nginx. Rincian di §7 dan Lampiran A; rekomendasi ADR (khusus berkas legacy) di §7.7. (v1.1: semua berkas lewat URL publik) | (a) [K] (membalik pola v2) / (b) [V2] | Horii 12-11-2026 (PB-2 = B + audit PB-1, klarifikasi) |

### 3.2 Bawaan usulan reviewer (◻ menunggu konfirmasi Horii)
Catatan v1.2: F10-31 kini keputusan Horii (PB-4 = B, 🔒). F10-30 dikonfirmasi lewat PB-3 = A. F10-23/24/25/32 disesuaikan dengan F10-18 baru. F10-34..F10-38 adalah bawaan baru untuk rincian teknis pilihan Plan B dan sudah **dikonfirmasi Horii 12-11-2026** (QF10-6).

| # | Usulan bawaan | Label | Alasan |
|---|---|---|---|
| F10-19 | Nama arsip = `tag`; bila kosong `display_name`; bila kosong `filename`. Ditampilkan sebagai teks biasa. Untuk baris berkunci v2 (regex kunci v2 §7.2), `filename` **tidak** dipakai sebagai cadangan, karena `filename` = basename kunci (`<32 hex>.<ext>`); bila `tag` dan `display_name` kosong, `nama_arsip` = "Berkas <id_attachment>" | [V2] kecil | Legacy memakai `tag` → `filename` (`list.php:79`). Berkas unggahan v2 ber-`filename` acak (`AttachmentService.php:65`, `:75`), jadi tanpa `display_name` namanya tidak terbaca, dan basename kunci tidak boleh sampai ke klien (AC1-10). Dalam praktik `display_name` unggahan v2 selalu terisi (`ValidasiBerkas.php:170-181`), jadi cadangan ini jarang terpakai |
| F10-20 | Daftar berkas berhalaman di server (bawaan 25, maks 100), urut bawaan `id_attachment` menurun | [K] urutan / [V2] paginasi | Legacy urut id DESC (`L_arsip.php:12`), DataTables di klien tanpa paginasi server |
| F10-21 | Pencarian `q` atas `tag`/`display_name`/`filename` (LIKE literal, `%`/`_` di-escape). Ringkasan riwayat tidak ikut dicari | [V2] | Legacy mencari di semua kolom tabel klien |
| F10-22 | "Data Riwayat" = ringkasan seperti `previewRwy` legacy untuk kode 1, 3, 5, 7, 9, **36**, 10, 11, 13, 14, 20, 22, 23, 32 (kode 36 memakai ringkasan yang sama dengan 9: jabatan + satker). Kode lain (37–50) tampil "—" | [K] | `legacy:helpers/function_helper.php:2260-2383` (`:2305` `9 \|\| 36`) |
| F10-23 | `url_berkas` hanya diisi bila (a) path cocok dengan salah satu dari tiga regex penyajian di `Config\Arsip` (§7.2: kunci v2 + dua pola legacy; dua pola legacy sama persis dengan blok Nginx), dan (b) berkasnya ada di disk. Kasus (a) gagal → `null` + badge "Tidak dapat dibuka". Kasus (b) gagal → `null` + badge "Berkas hilang". Regex kunci v2 tetap dipakai di `PenyajiUrlBerkas` walaupun tidak ada di Nginx | [V2] | DoD "berkas hilang ditandai, bukan 500". Backend tidak boleh memberi URL yang ternyata 404 di Nginx atau di endpoint `buka` |
| F10-24 | Saklar rollback **khusus berkas legacy** `Config\Arsip::$penyajianBerkasLegacy` (`.env` `arsip.penyajianBerkasLegacy`) = `'publik'` (bawaan, F10-18 (a)) / `'privat'` (rollback: berkas legacy juga dibuka lewat endpoint `buka`, Lampiran A). Berkas unggahan v2 **selalu** privat dan tidak dipengaruhi saklar. Kedua mode dijaga test | [V2] | Rollback cukup lewat konfigurasi (pola Q7-36) |
| F10-25 | Penguatan Nginx `/berkas/` untuk berkas legacy (§7.3): hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; tipe lain selalu diunduh sebagai `application/octet-stream`; `nosniff`; CSP; tanpa listing folder; tanpa PHP; tanpa symlink di dalam akar; `Cache-Control: no-store`; tanpa log akses/not-found. Ini **bukan** cek izin, sehingga F10-18 (a) tetap utuh | [V2] | Mencegah berkas legacy (mis. HTML/SVG) berjalan sebagai halaman di alamat aplikasi |
| F10-26 | Catatan F10-17 memakai `audit_logs` entity `arsip_lihat`, event `create`, dengan dedup satu catatan per (aktor, NIP) per 15 menit untuk folder/daftar. Ekspor arsip pegawai lain dicatat **setiap kali** (tanpa dedup, `endpoint: "ekspor"`) | [V2] | §8.2 |
| F10-27 | Ekspor CSV berekstensi `.csv` | [V2] | Bug legacy: CSV terunduh sebagai `.txt` (`list.php:221-253`) |
| F10-28 | Daftar jenis tujuan Hubungkan = 1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, ditambah 38 (tanpa data riwayat). Kode 37/39/40/41/42 dan 43–50 tidak ditawarkan. (v1.2) Daftar ini dipersempit per aktor oleh kunci F10-31; untuk role 2/6/7 lihat `aksi.jenis_hubungkan` (F10-38) | [K] daftar / [V2] penolakan server | Legacy tidak punya cabang data untuk 37/39–42, sehingga dropdown kosong (`Local.php:3475-3722`); 43–50 tidak ada di legacy. Penolakan server untuk kode lain = [V2] (legacy tanpa cabang `else`, `Local.php:3561-3690`) sesuai pola keamanan F10-09 |
| F10-29 | Penempatan folder: "Belum Terhubung" (kode 0) paling bawah; folder dengan 0 berkas tidak ditampilkan [V2 tampilan; legacy tabel datar]. Baris ber-`id_riwayat` NULL, bila ada (D1 blok 9), masuk folder **"Tanpa Jenis"** (kunci `tanpa_jenis`) dan hanya bisa di-Edit [K aksi: legacy menawarkan Hubungkan/Hapus hanya bila `id_riwayat == '0'`, `list.php:125-134`]. Keberadaan baris NULL [I] sampai D1. Kode di luar `jenis_rwy` tidak mungkin ada karena FK (`CreateDocumentAttachment.php:70`): baris seperti itu tertolak saat impor tier 10, bukan tampil di F-10 | [V2] tampilan / [K] aksi / [I] | `jenis_rwy.order` 0 = sentinel (`CreateJenisRwy.php:26-28`) |
| F10-30 | Halaman Arsip **tidak** memakai izin Definisi per kode, karena legacy menampilkan semua baris per NIP. Akibatnya, untuk role yang boleh melihat pegawai itu (termasuk pemiliknya), tampil juga: (a) lampiran, (b) **ringkasan Data Riwayat**, dan (c) calon tujuan Hubungkan dari jenis yang tersembunyi di tab B-18. Contoh: hukdis di B-18 hanya boleh dilihat role 1/3 (`Definisi/Hukdis.php:87-97`), tetapi di Arsip role 2/4/5/6/7 melihat lampiran dan ringkasannya (TMT + tingkat hukuman), dan opsi Hubungkan kode 7 memuat riwayat hukdis milik NIP itu. Aturan yang sama berlaku untuk endpoint `buka` (berkas unggahan v2): izinnya = izin daftar arsip, bukan izin Definisi atau izin endpoint layanan. Dikonfirmasi Horii 12-11-2026 lewat PB-3 = A | [K] | `legacy:libraries/hr/L_arsip.php:12-19`; `legacy:helpers/function_helper.php:2297-2302`; `legacy:controllers/hr/services/Local.php:3272-3285`, `:3339` |
| F10-31 🔒 (v1.2) | **Keputusan Horii 12-11-2026 (PB-4 = B), bukan lagi bawaan.** Aksi F-10 memakai sebagian aturan tulis B-18. (a) **Hubungkan**: baris tujuan milik Definisi yang `kunciBarisDisetujui()` (`backend/app/Libraries/Kepegawaian/Riwayat/RiwayatDefinisi.php:99-102`, bawaan `true`; saat ini tidak ada Definisi yang meng-override) **terkunci** untuk `Role::UL_PEGAWAI` (2/6/7, `backend/app/Constants/Role.php:30`) → 403 "Data yang sudah disetujui tidak dapat diubah." (pesan `OtorisasiLampiran.php:387`, pola `bukanBarisTerkunci` `:382-389`). Dicek di jalan cepat, lalu diulang sesudah baris tujuan dibaca ulang `FOR UPDATE` di dalam transaksi (pola `kunciUnggah`, `OtorisasiLampiran.php:158-171`). Role 1/3 tidak terkena kunci. Karena tujuan Hubungkan selalu berstatus Disetujui [K], akibatnya role 2/6/7 praktis hanya bisa meng-Hubungkan ke kode 38 (tanpa Definisi, tanpa kunci baris). (b) **Ubah Nama kode 37**: `ArsipDataUmum::bolehTulis()` (`ArsipDataUmum.php:52-56`: role 1 saja) → selain itu 403 "Anda tidak berhak mengubah arsip data umum pegawai ini." (`OtorisasiLampiran.php:254`). (c) **Ubah Nama kode lain** (termasuk baris riwayat Disetujui) tetap [K] untuk semua baris. Kode 38: ikut legacy, sama dengan (c), tanpa `bolehTulis()` (Horii 12-11-2026, QF10-8). Kunci B-18 tetap berlaku di B-18 | [V2] (Hubungkan, Ubah Nama 37) / [K] (Ubah Nama kode lain) | Horii 12-11-2026 (PB-4 = B). Legacy tanpa cek kunci (`Local.php:3496-3699`, `:3809-3890`). Rincian teknis = F10-38 |
| F10-32 | Semua ekstensi bisa dibuka seperti legacy (`pathToSrc` tanpa saring ekstensi) [K]. Hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; ekstensi lain (doc, xls, rar, zip, tif, svg, html, dll.) selalu **diunduh** sebagai `application/octet-stream` [V2 kecil]. Ekstensi diambil dari segmen terakhir path ternormalisasi (huruf kecil), **bukan** dari kolom `file_ext` (legacy CI3 menyimpan dengan titik/huruf besar, `legacy:libraries/hr/L_employee.php:301`; v2 tanpa titik, `AttachmentService.php:84`). Aturan yang sama dipakai di dua jalur: Nginx (berkas legacy) dan endpoint `buka` (berkas unggahan v2, F10-34) | [K] / [V2] kecil | Di legacy, berkas `arsip/` disajikan dari server arsip (alamat dasar `ARSIP`, terpisah dari aplikasi [I]). Di v2 berkas disajikan dari alamat aplikasi, sehingga HTML/SVG yang dibuka inline bisa menjadi XSS (R5) |
| F10-33 | Baris `document_attachment` berpath `assets/upload/...` juga dibuka lewat URL publik (alias kedua `/berkas/assets/upload/...`, di samping `arsip/`), seperti legacy yang menyajikannya publik dari server aplikasi (`legacy:helpers/function_helper.php:314-326`). Berkasnya ikut disalin MAKE-069 dengan struktur yang sama (`MAKE-069-rencana.md:150`, `:177-181`). Jumlah baris dari D1 blok 4 dilaporkan, tetapi tidak menahan | [K] | Usulan reviewer (paritas legacy, konsekuensi F10-18); menunggu konfirmasi Horii |
| F10-34 🔒 (v1.2) | **Jalur privat `buka`** (F10-18 (b)). (1) Setiap item daftar memuat `akses_berkas` = `publik` (berkas legacy, saklar F10-24 `'publik'`) / `privat` (kunci v2, atau berkas legacy saat saklar `'privat'`) / `null` (bila `url_berkas` null). (2) Untuk `privat`, `url_berkas` = path API relatif `pegawai/<nip>/arsip/berkas/<id>/buka` (memuat id, bukan path). (3) Endpoint `buka` hanya melayani baris yang saat itu berakses `privat`; baris legacy saat saklar `'publik'` → 404 (FE memakai URL publik). (4) `Content-Type` dari daftar tertutup menurut ekstensi path ternormalisasi (pdf → `application/pdf`, jpg/jpeg → `image/jpeg`, png → `image/png`, gif → `image/gif`; lainnya `application/octet-stream`), bukan dari kolom `file_type`. `Content-Disposition` `inline`/`attachment` dengan nama = nama arsip (F10-19) aman-ASCII + `filename*` (pola `LampiranController.php:84-92`); bila nama arsip tidak berakhiran ekstensi path ternormalisasi, ekstensi itu ditambahkan (mis. `tag` "SK Jabatan" → "SK Jabatan.docx"), agar berkas jenis `unduh` tidak tersimpan tanpa ekstensi. Header lain: `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, ditambah `Referrer-Policy: no-referrer` dan `Content-Security-Policy` yang sama dengan snippet Nginx §7.3 (`default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'`), karena endpoint ini bisa dibuka langsung sebagai navigasi tingkat atas (cookie akses ber-path `/` dan `SameSite=Lax` ikut terkirim, `Config/Jwt.php:49`, `:60`). Kedua header tambahan ini tidak berpengaruh pada jalur blob FE; bila CSP mematahkan pratinjau, berlaku aturan yang sama dengan §7.3 butir 3 (pratinjau didahulukan). (5) Isi dibaca lewat `StorageAdapterInterface::baca()` (seluruh isi ke memori, `StorageAdapterInterface.php:25`), **tanpa streaming** | [V2] | Semua berkas unggahan v2 lahir lewat `AttachmentService::simpan()` (`AttachmentService.php:64-66`), yang menegakkan batas unggah `ValidasiBerkas` (`ValidasiBerkas.php:55`) dari `AturanLampiran` maks 5 MB (`AturanLampiran.php:20`, `:67-70`). Ini termasuk surat cuti ber-TTE (`SuratCutiService.php:28`, `:162`: 5 MB), surat IB/TB (`BerkasStudi.php:30`: 5 MB), PMK (`PmkPengajuanService.php:41`: 2 MB), dan Data Umum (`ArsipDataUmum.php:42-47`: 5 MB). Jadi `baca()` cukup. Pengecualian: saat rollback `'privat'`, berkas legacy (tanpa batas unggah v2) juga lewat PHP; ukuran maksimumnya dicek dari D1/manifest sebelum rollback dipakai [I] |
| F10-35 🔒 (v1.2) | **Audit buka berkas privat** (F10-08), pola F-09 (`DokumenService.php:334-344`): `AuditLogModel::record()` dengan `entity` = `arsip_unduh`, `entity_id` = NIP sasaran (dikonfirmasi Horii 12-11-2026; konsisten dengan `arsip_lihat`; **berbeda** dari F-09 yang mengisi `entity_id` dengan objek yang diunduh, yaitu jenis dokumen, `DokumenService.php:336`; alternatif setara F-09: `entity_id` = `id_attachment`), `event` = `create`, `before` = null, `after` = `{ "id_attachment": …, "id_riwayat": …, "jenis_buka": "pratinjau"/"unduh" }`, aktor = `idPengguna` + NIP aktor; **tanpa** nama berkas, `tag`, atau path. Dicatat **setiap** pembukaan yang berhasil lewat endpoint `buka`, oleh **semua** role termasuk pemilik yang membuka berkasnya sendiri, tanpa dedup (F-09 mencatat setiap unduhan tanpa pengecualian aktor, `DokumenService.php:178`, `:221`). Ditulis sesudah berkas terbaca, sebelum respons dikirim (pola `:176-180`). Hasil `record()` tidak diperiksa (pola F-09, `DokumenService.php:336-343`): `record()` yang mengembalikan 0 tidak menggagalkan respons. Galat DB yang dilempar (DBDebug = true, `backend/app/Config/Database.php:47`; `insert` tanpa try/catch, `AuditLogModel.php:44-55`) tidak ditangkap dan menjadi 500, sama dengan F-09. Berkas legacy lewat URL publik tidak tercatat | [V2] | Mengikuti pola F-09 (`record()` tanpa cek hasil, aktor, tanpa dedup), kecuali `entity_id`. Catatan: berbeda dengan catatan pembukaan daftar F10-17 (§8.2) yang fail-closed penuh. Bila Horii ingin fail-closed penuh juga (hasil ≤ 0 → 500), cukup ubah butir ini. Bila Horii ingin audit yang sama sekali tidak menahan respons (galat DB ditangkap), itu bukan lagi pola F-09 dan perlu keputusan tersendiri |
| F10-36 🔒 (v1.2) | **Throttle buka berkas privat** (F10-08), pola F-09 (`DokumenService.php:353-360`): `Services::throttler()->check('arsip_unduh_<id_pengguna>', <batas>, MINUTE, 1)`. Batas diambil dari konstanta `Config\Arsip` (bawaan 6, sama dengan `DokumenService::UNDUH_PER_MENIT`, `:47`, tetapi tidak merujuk konstanta itu). Throttler CI4 adalah token bucket (`system/Throttle/Throttler.php` `check()`: `$rate = $capacity / $seconds`): maksimum 6 pembukaan beruntun, lalu terisi 1 jatah tiap 10 detik. Jadi "6 per menit" bukan jendela tetap satu menit: permintaan ke-7 yang dikirim segera sesudah 6 permintaan sukses → 429, tetapi sekitar 10 detik kemudian satu berkas bisa dibuka lagi. Ember terpisah dari unduhan Dokumen F-09 (`dok_unduh_…`). Lewat batas → 429 `TooManyRequestsException` (`backend/app/Exceptions/TooManyRequestsException.php:14`) dengan pesan "Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.". **Urutan:** throttle dicek **sesudah** semua penolakan yang pasti (401, 403 role, 403 lingkup, 404 NIP, 404 baris, 404 bukan akses privat / tidak cocok regex, 404 berkas hilang lewat `ada()`) dan **sebelum** `baca()`, sesuai pola F-09 "ditolak sebelum throttle agar permintaan yang pasti gagal tidak memakan jatah" (`:152-153`). Berlaku juga untuk berkas legacy saat saklar `'privat'`. Test memakai `setTestTime` (`Throttler.php:169`, pola `tests/database/Integrasi/SStatKontrakTest.php:41`) atau waktu tetap | [V2] | Pola F-09 apa adanya. Angka 6 ikut F-09 dan bisa terasa ketat saat membuka banyak berkas berturut-turut; bisa disesuaikan Horii lewat `Config\Arsip` |
| F10-37 🔒 (v1.2) | **FE buka berkas privat.** (1) Hanya untuk `jenis_buka = pratinjau`: popup dibuka **sinkron** di handler klik, `window.open('', 'arsip-popup', 'width=600,height=600')` tanpa `noopener` (dengan `noopener`, `window.open` mengembalikan `null` sehingga lokasi popup tidak bisa diisi), lalu segera `popup.opener = null`, dan popup menampilkan "Memuat berkas…". Membuka popup sesudah `await` akan diblokir pemblokir popup. (2) Berkas diambil lewat `ambilBerkas(url_berkas, …)` (`frontend/src/features/kepegawaian/services/berkasUnduhan.ts:59-66`), yang memakai klien `api` (interceptor refresh token ikut, `frontend/src/lib/axios.ts:185`) dan mengurai body galat Blob menjadi `message`/`errors` (`urainGalatBlob`, `:45-56`). (3) Untuk pratinjau, blob dibuat ulang dengan tipe dari daftar tertutup (pdf/jpeg/png/gif sesuai ekstensi; lainnya `application/octet-stream`), **bukan** dari header respons mentah, karena object URL `blob:` mewarisi origin aplikasi; lalu `popup.location.href = objectUrl`. (4) `jenis_buka = unduh` → **tanpa** popup: berkas diambil lewat `ambilBerkas` lalu disimpan lewat `simpanBerkas` (`berkasUnduhan.ts:79-88`, pola `ArsipTable.vue:194-202`), dengan nama dari `Content-Disposition` (F10-34 (4), nama arsip + ekstensi). (5) Object URL pratinjau dicabut (`URL.revokeObjectURL`) sesudah penundaan tetap (bawaan 60 detik) dan saat komponen di-unmount (pola `UlangTahunCard.vue:45-46`), tanpa deteksi selesai-muat (event load penampil PDF di popup tidak bisa diamati dengan andal dari opener). (6) Semua galat (termasuk 401 dan 403 `password_change_required`) → popup ditutup di blok `catch`. 429/404/403 → pesan tampil di halaman (`UiNotice`) dari `message` galat yang dilempar `ambilBerkas`, bukan JSON mentah; cadangan 429 bila body tidak terbaca = "Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.", didefinisikan di modul arsip. `frontend/src/features/dokumen/dokumen.service.ts` (`pesanGalatUnduhan`, cadangan 429 khusus dokumen `:57`) tidak dipakai dan tidak diubah. 401 sesudah refresh gagal → halaman dialihkan ke `/login` oleh interceptor (`axios.ts:198-201`, `:207`), dan 403 `password_change_required` memicu handler ganti password (`:215-217`), tanpa pesan tambahan. (7) Bila popup pratinjau diblokir (`window.open` = `null`), tampilkan pesan "Popup diblokir browser. Izinkan popup untuk situs ini." tanpa memanggil API. `akses_berkas = publik` tetap `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')` | [V2] | Mencegah JSON 401 mentah sesudah token akses kedaluwarsa (`Jwt.php:27`), menghindari pemblokir popup, dan mencegah blob HTML/SVG berjalan di origin aplikasi (R5) |
| F10-38 🔒 (v1.2) | **Rincian kunci aksi F10-31.** (1) Urutan galat Hubungkan: 403 role/lingkup → 404 baris arsip → 422 bukan kode 0 / jenis di luar F10-28 → 404 riwayat tujuan (bukan milik NIP, bukan Disetujui, tidak ada) → 403 kunci (role 2/6/7 + Definisi terkunci). Ini pola `bolehUnggah` (`OtorisasiLampiran.php:100`: `entriMilik` 404 lebih dulu, lalu kunci 403). Di dalam transaksi, urutan kunci sama dengan B-18 (docblock `OtorisasiLampiran.php:47-50`; `kunciUnggah` `:163`, `:169`; `kunciHapus` `:181-182`): (a) baris `pegawai` {nip} `FOR UPDATE` (pola `OtorisasiLampiran::kunciPegawai`, `:418-422`; metode itu `private`, jadi ditulis ulang di `AksiArsip` tanpa mengubah `OtorisasiLampiran`), (b) baris `document_attachment` {id} `FOR UPDATE`, (c) baris riwayat tujuan `FOR UPDATE`, lalu cek kode 0 dan kunci Disetujui diulang. Hapus dan Ubah Nama memakai urutan (a)–(b) yang sama. (2) `OtorisasiArsip` memanggil `RiwayatDefinisi::kunciBarisDisetujui()` + `Role::UL_PEGAWAI` dengan pesan yang sama, **tanpa** mengubah `OtorisasiLampiran` (`bukanBarisTerkunci` bersifat `private`, `:382`). (3) Respons folder memuat `aksi.jenis_hubungkan` = kode tujuan F10-28 yang boleh bagi aktor itu (untuk role 2/6/7 saat ini hanya 38). FE hanya menawarkan kode itu di dialog Hubungkan. `opsi-hubungkan` untuk kode terkunci oleh role 2/6/7 → 403 dengan pesan kunci. (4) Ubah Nama kode 37: `aksi.edit = false` (item Edit `hidden`) bila aktor bukan role 1; urutan galat 404 baris → 403 `bolehTulis` → 422 `errors.tag` | [V2] | Mengikuti B-18 dan Arsip Data Umum, tanpa mengubah berkas milik B-18 |

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
- **Buka** (⋮), menurut `akses_berkas` item (F10-34):
  - `publik` (berkas legacy): `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')` [K].
  - `privat` (berkas unggahan v2): untuk pratinjau, popup yang sama dibuka sinkron saat klik, berkas diambil lewat klien API sebagai blob, lalu popup diarahkan ke object URL; untuk jenis `unduh`, berkas diambil sebagai blob dan langsung diunduh tanpa popup (F10-37) [V2]. Pembukaan dicatat (F10-35) dan dibatasi (6 beruntun, lalu 1 tiap 10 detik per akun, F10-36); galat tampil sebagai pesan di halaman.
  - Jendela popup 600×600 = [K] (`list.php:81`); `noopener` / `opener = null` = [V2 keamanan]. Di browser ponsel, popup biasanya terbuka sebagai tab.
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
| AC1-10 | Respons memakai daftar kolom tertutup (§8.1). `path`, `url`, `basename`, dan `document_id` **tidak pernah** dikirim (test: kunci-kunci itu tidak ada di JSON). Satu-satunya turunan path adalah `url_berkas` untuk item `akses_berkas: "publik"` (berkas legacy). Item `akses_berkas: "privat"` memakai `url_berkas` = `pegawai/<nip>/arsip/berkas/<id>/buka`, tanpa path dan tanpa kunci berkas (test: kunci v2, termasuk basename `<32 hex>.<ext>`, tidak muncul di JSON; termasuk kasus baris kunci v2 dengan `tag` dan `display_name` kosong → `nama_arsip` = "Berkas <id_attachment>", F10-19) |
| AC1-11 | `q` diperlakukan literal (input `%`, `_` tidak menjadi wildcard); `per_page` > 100 dipotong menjadi 100 |
| AC1-12 | Role 1/3/4/5 membuka daftar pegawai **lain** → tercatat di `audit_logs` (`entity` `arsip_lihat`, `event` `create`) tanpa nama/isi berkas dan tanpa teks pencarian. Bila F10-26 disetujui: permintaan **berurutan** dalam 15 menit untuk (aktor, NIP) yang sama → satu baris (permintaan bersamaan boleh menghasilkan lebih dari satu baris). Bila F10-26 tidak disetujui: setiap pembukaan dicatat. Membuka arsip sendiri (role apa pun) → tidak dicatat |
| AC1-13 | Ringkasan "Data Riwayat" untuk kode di F10-22 (termasuk 36 = bentuk ringkasan 9) sesuai bentuk `previewRwy`; kode lain tampil "—". Tanpa N+1 query (satu query per kode per halaman) |
| AC1-14 | Tombol lama di header Detail Pegawai berlabel "Arsip Data Umum", **tetap** ber-testid `btn-arsip`, dan tetap membuka dialog 37/38 lama. Tombol baru "Arsip" (`pegawai-header-arsip`) menuju `/arsip/<nip>` (role 1–7) |
| AC1-15 | Pada viewport 1440 px dan 390 px tidak ada scroll horizontal halaman. Tabel boleh scroll di dalam kartu dengan kolom Aksi **sticky** di kanan (pelajaran QAUI-003, Mapping §8.6) |
| AC1-16 | `url_berkas` terisi **jika dan hanya jika** path cocok salah satu regex `Config\Arsip` dan berkasnya ada (mis. `….PDF` → terisi; segmen `.foo` → null). Ekstensi non-pratinjau → `jenis_buka: "unduh"`. `akses_berkas`: kunci v2 → `privat` (selalu); `arsip/…` dan `assets/upload/…` → `publik` saat saklar `'publik'`, `privat` saat `'privat'`; `null` bila `url_berkas` null (F10-24, F10-34) |
| AC1-17 | `GET …/arsip/berkas/{id}/buka` (F10-34..36): izin = izin daftar (role 1–7 + `PegawaiScope` lihat; role 8 → 403; 2/6/7 NIP lain → 403; `{id}` milik NIP lain → 404). Header: pdf/jpg/jpeg/png/gif → `Content-Type` sesuai daftar tertutup + `inline`; lainnya → `application/octet-stream` + `attachment`; selalu `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, dan `Content-Security-Policy` yang sama dengan snippet Nginx §7.3. Nama berkas di `Content-Disposition` berakhiran ekstensi path (F10-34 (4)). Baris legacy saat saklar `'publik'` → 404; berkas hilang / path tak cocok regex → 404 (tanpa path di log). Saklar `'privat'`: baris `arsip/…` dan `assets/upload/…` → 200 lewat `buka` (dibaca lewat `PathLegacy`, `LocalStorageAdapter.php:104-116`), dengan header, audit, dan throttle yang sama. Baris menggantung (riwayat status 10, pengajuan, `id_entri` tak ada) → 200 (F10-16, PB-7 = A), berbeda dengan unduh B-18 yang 404 (`OtorisasiLampiran.php:121-122`). Setiap 200 menulis satu baris `audit_logs` `arsip_unduh` tanpa nama berkas/path, termasuk saat pemilik membuka berkasnya sendiri. Throttle token bucket (F10-36): 7 permintaan beruntun tanpa jeda dari akun yang sama → yang ke-7 429 (test memakai `setTestTime` atau waktu tetap); permintaan yang ditolak 403/404 tidak memakan jatah |
| AC1-18 | FE item `privat` (F10-37): untuk `pratinjau`, popup dibuka sinkron di handler klik (test: `window.open` dipanggil sebelum permintaan API selesai); untuk `unduh`, tanpa `window.open` (unduh lewat `simpanBerkas`); berkas diambil lewat `ambilBerkas` (klien API, `responseType: 'blob'`); tipe blob pratinjau dari daftar tertutup; object URL dicabut (`revokeObjectURL`) sesudah penundaan tetap dan saat unmount; semua galat menutup popup; 429/404/403 menampilkan pesan di halaman, bukan JSON mentah; 401 sesudah refresh gagal → dialihkan ke `/login` oleh interceptor tanpa pesan tambahan (test: popup tertutup pada 401); popup terblokir → pesan, tanpa panggilan API. Item `publik` tetap memakai `window.open(url, 'arsip-popup', 'width=600,height=600,noopener')` |

---

## 6. Tahap 2 — Aksi legacy

### 6.1 Perilaku
- **Perlindungan standar** (F10-09) sama dengan endpoint tulis v2 lain: cookie httpOnly `SameSite=Lax` (`Jwt.php:42`, `:60`) + metode non-GET. Tidak ada token CSRF terpisah (`Config/Filters.php:91`) [V2 yang berlaku]. QA tidak perlu mengharapkan token CSRF.
- **Izin aksi** = role + lingkup `PegawaiScope` (ubah) + kepemilikan baris, ditambah dua aturan tulis B-18 pilihan Horii (F10-31, PB-4 = B): kunci baris Disetujui untuk Hubungkan dan `ArsipDataUmum::bolehTulis()` untuk Ubah Nama kode 37. Izin Definisi per kode tetap **tidak** dipakai (F10-30).
- **Edit (Ubah nama)**:
  - Berlaku untuk semua baris, terhubung maupun yatim, termasuk 38 dan baris riwayat yang sudah disetujui [K].
  - Role yang boleh: 1, 3 (lingkup ubah), 2/6/7 (NIP sendiri) [K].
  - **Kecuali kode 37**: hanya role 1 (`ArsipDataUmum::bolehTulis()`, `ArsipDataUmum.php:52-56`); role lain → 403 dan item Edit `hidden` (F10-31, F10-38) [V2].
  - Dialog "Ubah Nama Arsip" dengan satu isian. Nama wajib diisi [K `Local.php:3829`].
- **Hubungkan**:
  - Hanya untuk baris kode 0. Pengguna memilih jenis (F10-28), lalu memilih data riwayat. Untuk kode 38 tidak ada pilihan data (`id_entri` NULL) [K].
  - Baris tujuan dicari lewat **Definisi pemilik kode** (`RiwayatRegistry`), dengan pola `OtorisasiLampiran::entriMilik` (`OtorisasiLampiran.php:351-377`): milik NIP (kode 3 lewat induk, `entriLampiran`), status terpetakan = `StatusRiwayat::Disetujui` [K "status 1"], dibaca ulang `FOR UPDATE` di dalam transaksi.
  - **Kunci baris Disetujui** (F10-31, PB-4 = B) [V2]: untuk role 2/6/7, tujuan milik Definisi yang `kunciBarisDisetujui()` → 403 "Data yang sudah disetujui tidak dapat diubah.", diulang sesudah baca ulang `FOR UPDATE`. Karena semua Definisi saat ini mengunci (`RiwayatDefinisi.php:99-102`), role 2/6/7 praktis hanya bisa meng-Hubungkan ke kode 38; dialog Hubungkan hanya menawarkan `aksi.jenis_hubungkan` (F10-38). Role 1/3 tidak terkena kunci.
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
| AC2-13 | (F10-31, PB-4 = B, [V2]) Role 2/6/7 meng-Hubungkan ke riwayat Disetujui milik Definisi yang `kunciBarisDisetujui()` → 403 "Data yang sudah disetujui tidak dapat diubah." (test: juga saat status baris tujuan berubah di antara jalan cepat dan baca ulang `FOR UPDATE`); role 2/6/7 ke kode 38 → sukses; role 1/3 ke riwayat Disetujui → sukses. `opsi-hubungkan` kode terkunci oleh role 2/6/7 → 403; `aksi.jenis_hubungkan` role 2/6/7 = kode yang tidak terkunci saja. Ubah Nama kode 37 oleh role 2/3/6/7 → 403 "Anda tidak berhak mengubah arsip data umum pegawai ini." dan `aksi.edit = false`; oleh role 1 → sukses. Ubah Nama baris riwayat Disetujui oleh role 2/6/7 (NIP sendiri) tetap sukses [K] |

### 6.6 Risiko dan efek yang diterima (F10-09)
- **Antrean SIASN.** Hubungkan ke kode 9/36/41 (Jabatan) atau 5 (Diklat sertifikasi) bisa membuat baris itu memenuhi syarat antre unggah SIASN pada jalannya `AntreArsip` berikutnya (`backend/app/Libraries/Siasn/Arsip/AntreArsip.php:17-20`: berkas PDF + riwayat yang sudah terkirim). Kode 41 tidak ada di daftar tujuan Hubungkan (F10-28), jadi dalam praktik risikonya hanya untuk 9/36/5. Tidak ada pencegahan, sama seperti legacy (dikonfirmasi PB-6 = A). Catatan v1.2: akibat kunci baris Disetujui (F10-31), role 2/6/7 tidak bisa meng-Hubungkan ke 9/36/5, sehingga jalur ini dalam praktik hanya terbuka bagi role 1/3.
- **Efek data lain yang ikut diterima [K]:**
  - Berkas yang dihubungkan ke 9/10/11/32 ikut tampil di respons `services/s_stat` untuk klien eksternal (`backend/app/Libraries/Integrasi/Stat/PenyajiStat.php:24-28`). Kode 46/47/49/50 juga dipetakan di sana, tetapi tidak ditawarkan untuk Hubungkan.
  - Hubungkan ke 11 mengubah kelengkapan beranda (`backend/app/Libraries/Dashboard/Beranda/KelengkapanData.php:470-480`, tanpa cache).
- QA mencatat semua perilaku ini sebagai **sesuai keputusan**, bukan bug.

---

## 7. Penyajian berkas: legacy lewat URL publik, unggahan v2 lewat jalur privat (F10-18)

### 7.1 Inti
- **Berkas legacy** (`arsip/…`, `assets/upload/…`): server web (Nginx) menyajikan berkas langsung dari akar penyimpanan lampiran v2 `writable/uploads/lampiran` (`LocalStorageAdapter.php:29`). Di server, `writable` adalah symlink ke `shared/writable` (`deploy/post-receive:160-162`, `README-deploy.md:67-68`). Alias Nginx menunjuk langsung ke `shared/writable/uploads/lampiran`.
  - **Tidak ada** pemeriksaan login atau izin saat URL dibuka. Siapa pun yang tahu URL bisa membuka berkasnya, seperti di legacy.
  - Backend hanya **membentuk** URL (`url_berkas`) di respons daftar yang sudah terautentikasi dan tersaring lingkup.
- **Berkas unggahan v2** (kunci `YYYY/MM/<32 hex>.<ext>`): **tidak** disajikan Nginx. Dibuka lewat endpoint aplikasi `GET pegawai/{nip}/arsip/berkas/{id}/buka` (jwt + `Role::filter(1–7)` + `PegawaiScope` lihat, izin sama dengan daftar arsip), dengan header aman, audit, dan throttle (F10-18 (b), F10-34..36). URL publik `/berkas/YYYY/MM/<kunci>` → 404.
- **Yang terjangkau web hanya dua pola legacy di §7.2 di bawah `uploads/lampiran`.** Kunci v2, folder `writable/cache` (berisi token OAuth FCM), `logs`, `uploads/konten`, `uploads/slip-impor`, serta `kolom/` dan `form-digital/` di bawah akar lampiran tetap tidak terjangkau. README-deploy §1l menegaskan hal ini, karena README saat ini menyatakan `writable/` tidak boleh terjangkau web (`README-deploy.md:250`). Pengecualiannya sempit dan disengaja.

### 7.2 Bentuk URL dan pola yang disajikan
Dasar URL publik: `Config\Arsip::$dasarUrlBerkas`, bawaan `/berkas` (relatif ke host aplikasi). Nilai ini bisa diganti lewat `.env` `arsip.dasarUrlBerkas` (mis. untuk subdomain berkas, §7.7). Host tidak pernah ditulis di repo.

Ketiga regex didefinisikan **satu kali** sebagai konstanta di `Config\Arsip` dan dipakai `PenyajiUrlBerkas` (badge "Tidak dapat dibuka" dan penentuan `akses_berkas`). Hanya **dua pola legacy** yang ada di blok Nginx. Contoh Nginx di README §1l memuat dua regex legacy yang sama persis, dan test membandingkan keduanya sebagai string. Regex kunci v2 tidak ada di README/Nginx dan tidak ikut dibandingkan.

| Sumber baris | Path di DB | Regex (atas path ternormalisasi) | `url_berkas` | `akses_berkas` | Label |
|---|---|---|---|---|---|
| Unggahan v2 (riwayat, 37/38, layanan 42–50 termasuk surat ber-TTE 45) | `YYYY/MM/<32 hex>.<ext>` (`AttachmentService.php:65-66`) | `^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}\.[a-z0-9]{1,10}$` (hanya di `Config\Arsip`) | `pegawai/<nip>/arsip/berkas/<id>/buka` bila berkas ada (tidak dipengaruhi saklar); berkas hilang → `null` (F10-23) | `privat` (`null` bila berkas hilang) | [V2] (F10-18 (b)) |
| Impor legacy (server arsip) | `arsip/<NIP>/<sub>/<nama>` (bisa diawali `/`, berspasi, `\`) | `^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$` (`Config\Arsip` + Nginx) | dasar + `/` + `PathLegacy::normalisasi(path)`, **setiap segmen** di-`rawurlencode`. Saat saklar `'privat'`: URL `buka` | `publik` (saklar `'privat'` → `privat`) | [K] |
| Impor legacy (server aplikasi) | `assets/upload/...` | `^assets/upload/(?:[^/.][^/]*/)*[^/.][^/]*$` (`Config\Arsip` + Nginx) | sama dengan baris di atas | sama dengan baris di atas | [K] (F10-33) |
| Lainnya (`kolom/...`, `form-digital/...`, segmen berawalan `.`, path tak sah) | — | tidak cocok | `null` ("Tidak dapat dibuka") | `null` | [V2] kecil untuk segmen berawalan `.` |

`jenis_buka` = `pratinjau` bila ekstensi segmen terakhir (huruf kecil) ∈ pdf/jpg/jpeg/png/gif; selain itu `unduh` (F10-32). Kolom `file_ext` tidak dipakai.

### 7.3 Kebutuhan teknis Nginx (MAKE-093; contoh, final diuji di server dev)
Wajib:
1. `location ^~ /berkas/` (prefix `^~` supaya blok regex PHP tidak pernah terpakai) **tanpa** `fastcgi_pass`, dengan `autoindex off` dan `disable_symlinks on from=<akar lampiran>`. Symlink hanya diperiksa di bawah akar, sehingga symlink `writable` di atasnya tidak berpengaruh. Prasyarat (README §1l, B10-1): di dalam akar tidak boleh ada symlink, dan disk yang dipindah di-mount langsung ke path akar. Alasannya: PHP (`realpath`, `LocalStorageAdapter.php:118-129`) mengizinkan symlink di dalam akar. Kalau ada symlink, badge `berkas_ada` di halaman bisa berbeda dengan kenyataan di Nginx.
2. Hanya **dua pola legacy** §7.2 (`arsip/…`, `assets/upload/…`) yang lolos (regex sama persis dengan `Config\Arsip`); selain itu 404, termasuk kunci v2 `YYYY/MM/<kunci>` (F10-18 (b)), `kolom/` (`KolomBerkas.php:51`), dan `form-digital/` (`IsianFormDigital.php:117`).
3. Tipe dari daftar tertutup: hanya `application/pdf`, `image/jpeg`, `image/png`, `image/gif`, dengan `default_type application/octet-stream`. `Content-Disposition` lewat `map` di konteks `http`: `inline` untuk pdf/jpg/jpeg/png/gif, `attachment` untuk lainnya. Header lain: `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, dan `Content-Security-Policy` untuk respons berkas. CSP **tanpa** direktif `sandbox` dan tidak boleh menghalangi penampil PDF bawaan browser. Contoh awal: `default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'`. Bila nilai CSP apa pun mematahkan pratinjau di salah satu browser uji, pratinjau (F10-10) didahulukan dan CSP disesuaikan; `nosniff` dan daftar tipe tetap dipasang. Karena `add_header` di blok dalam menimpa header blok luar, semua header ditulis lewat satu `include` snippet di **setiap** blok dalam.
4. Log: `access_log off;` dan `log_not_found off;` di `location ^~ /berkas/`, karena URL dan path berkas memuat NIP. `error_log` server tetap dibatasi aksesnya, karena bisa berisi path.
5. **Hak baca.** Berkas ditulis PHP dengan hak `0640` dan folder `0750` (`LocalStorageAdapter.php:43`, `:59`). Begitu juga hasil `lampiran:tarik`. User worker Nginx wajib bisa membaca subtree `arsip/` dan `assets/upload/` di bawah akar (kunci v2 dibaca PHP, bukan Nginx): masukkan user Nginx ke grup pemilik berkas (user PHP-FPM/deploy), atau jalankan keduanya dengan user yang sama. Hak `0640`/`0750` tidak diubah. Verifikasi: `sudo -u <user-nginx> test -r <berkas contoh>` dan `curl -I` → 200. Hasilnya dicatat di README §1l.
6. Blok `/berkas/` ditambahkan ke contoh server di README-deploy §1 (`README-deploy.md:100-117`), sebelum blok regex PHP.

Contoh indikatif (path server mengikuti README-deploy):
```nginx
# konteks http (sekali)
map $uri $disposisi_berkas {
    ~*\.(pdf|jpe?g|png|gif)$  inline;
    default                   attachment;
}

# F-10 (PRD F-10 §7, F10-18 (a)): berkas arsip LEGACY dibuka lewat URL publik, persis legacy. Tanpa PHP, tanpa listing.
# Berkas unggahan v2 (kunci YYYY/MM/<32 hex>) SENGAJA tidak ada di sini: privat lewat endpoint aplikasi `buka` (F10-18 (b)).
location ^~ /berkas/ {
    autoindex off;
    access_log off;
    log_not_found off;
    disable_symlinks on from=/var/www/simpeg-v2/shared/writable/uploads/lampiran;
    types { application/pdf pdf; image/jpeg jpg jpeg; image/png png; image/gif gif; }
    default_type application/octet-stream;

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
Catatan coder: header (termasuk `Content-Disposition` per tipe dan pencocokan tipe untuk ekstensi huruf besar seperti `.PDF`) wajib diverifikasi dengan `curl -I`, dan pratinjau PDF/JPG/PNG wajib dibuka di Chrome, Edge, dan Firefox di server dev. `curl -I /berkas/YYYY/MM/<kunci>.pdf` untuk kunci v2 yang ada di disk wajib 404. Hasilnya dicatat di README-deploy §1l (baru). Ini syarat G10-4.

### 7.4 Perilaku di mesin dev (tanpa Nginx)
- Rute khusus dev `GET berkas/(:any)` (`BerkasDevController`) didaftarkan di `RoutesPegawai.php` **di luar** grup `api/v1` (atau di berkas route baru yang didaftarkan di `Routing::$routeFiles`, `Config/Routing.php:33-58`). Rute ini **hanya** didaftarkan bila `ENVIRONMENT === 'development'` **dan** `.env` `arsip.sajiBerkasDev = true`.
- Rute ini hanya melayani **dua pola legacy** `Config\Arsip` (sama dengan Nginx; kunci v2 → 404), dengan daftar tipe dan header yang sama, serta membaca lewat `LocalStorageAdapter::baca()` (realpath di dalam akar, `LocalStorageAdapter.php:104-130`). Tidak ada cek izin, meniru Nginx. Berkas unggahan v2 di mesin dev dibuka lewat endpoint `buka` biasa (tanpa rute khusus).
- `.env` dev: `arsip.dasarUrlBerkas = http://localhost:<port backend>/berkas`. FE Vite tidak punya proxy (`frontend/vite.config.ts`), jadi URL-nya absolut ke backend dev dan dibuka di popup (tanpa CORS).
- Batasan yang diterima: nama berkas dengan karakter di luar `permittedURIChars` CI4 (`a-z 0-9~%.:_\-`, `Config/App.php:86`), mis. `( ) , ' & +`, tidak bisa dibuka lewat rute dev. `permittedURIChars` **tidak** dilonggarkan. QA memakai Nginx.
- Test penjaga: rute tidak terdaftar di `testing`/`production`. Tidak ada penjaga konfigurasi produksi terpisah untuk `sajiBerkasDev`, karena rute hanya didaftarkan di `development`.
- Kunci `arsip.penyajianBerkasLegacy`, `arsip.dasarUrlBerkas`, dan `arsip.sajiBerkasDev` ditambahkan ke `backend/.env.example` dengan nilai bawaan.
- QA Lapis 1 memakai server dev dengan Nginx (G10-4), bukan rute dev.

### 7.5 Dampak ke endpoint dan invarian lama
| Hal | Dampak |
|---|---|
| `GET pegawai/{nip}/lampiran/{id}/unduh` (B-18) | **Tetap privat dan tidak berubah**: jwt + Definisi + `PegawaiScope`, `attachment`, `nosniff`, `no-store` (`LampiranController.php:79-94`, `OtorisasiLampiran.php:110-125`). Berkas **legacy** yang sama kini **juga** bisa dibuka lewat URL publik. Berkas unggahan v2 yang sama juga bisa dibuka lewat endpoint privat `buka` Arsip, dengan izin daftar arsip (tanpa izin Definisi per kode, F10-30). Jalur-jalur ini sengaja berbeda |
| Endpoint berkas layanan cuti/TB/IB/PMK | **Endpoint tetap privat dan tidak berubah.** Berkas `document_attachment` kode 42–50 yang diunggah atau dibuat di v2 (lampiran pengajuan, SK/surat cuti/TB/IB, termasuk surat ber-TTE kode 45; `CutiService.php:747`, `SuratCutiService.php:162`) berkunci `YYYY/MM/<kunci>` (`AttachmentService.php:65-66`), sehingga privat dan **tidak** disajikan lewat URL publik (v1.2, F10-18 (b)); `/berkas/YYYY/MM/<kunci>` → 404. Berkas ini tampil di Arsip (F10-05) dan dibuka lewat endpoint `buka` dengan izin daftar arsip (role yang boleh melihat pegawai itu, F10-30), bukan izin endpoint layanan; setiap pembukaan diaudit dan di-throttle (F10-35/36). Untuk 43–50 [V2]: legacy menyimpan berkas ini di kolom `riwayat_cuti`/`riwayat_tb`, bukan di `document_attachment`. Baris PMK kode 42 **hasil impor legacy** berpath legacy dan mengikuti F10-18 (a) (publik bila cocok pola §7.2) [K]; jumlahnya dari D1 [I] |
| Foto, Konket, Plt/Plh, berkas `kolom/...` dan `form-digital/...` | Tidak berubah, tetap privat (tidak cocok regex §7.2). Pengecualian: berkas kolom lain yang path-nya berada di subtree `arsip/` atau `assets/upload/` (R6) |
| Invarian "path tidak pernah ke klien" (`AttachmentService.php:29-38`) | Untuk berkas **legacy** (`akses_berkas: "publik"`), endpoint Arsip mengirim `url_berkas` yang **memuat path** (memuat NIP lama). Ini deviasi sadar F10-18 (a). Untuk berkas unggahan v2 (dan berkas legacy saat saklar `'privat'`), `url_berkas` = URL endpoint `buka` yang memuat `id`, **bukan** path, sehingga invarian tetap utuh. **[I]**: baris impor legacy membawa kolom `url` yang berisi nilai yang sama dengan `path` (`legacy:libraries/hr/L_employee.php:296-297`), dan `untukKlien()` B-18 hanya membuang `path` (`AttachmentService.php:38`, `:175-177`), sehingga `url` mungkin sudah terkirim di B-18. Dicek di salinan dev. Bila benar, dibuat CR terpisah di luar F-10 untuk menambah `url` ke `KOLOM_INTERNAL`. Endpoint Arsip sendiri memakai daftar kolom tertutup (AC1-10) |
| SIASN unggah arsip, `s_stat`, kelengkapan beranda | Kodenya tidak berubah (membaca storage lewat adapter). **Datanya** bisa berubah akibat Hubungkan (§6.6) |
| Dokumen usang "path arsip legacy → 404" | Koreksi **non-aditif** kontrak beku "Kontrak API Modul B" (`backend/app/Controllers/Api/Kepegawaian/README.md:7-10`, `:154`, `:204`, `:217`). Perilakunya sudah berubah sejak adapter MAKE-069 (baca legacy lewat `PathLegacy`, `KolomBerkas.php:77-80` → `LocalStorageAdapter.php:104-116`), **bukan** karena F-10. Butuh izin pemilik WS-2 lewat reviewer. Disertai test: foto/Plt-Plh/Konket dengan path `arsip/…` yang berkasnya sudah disalin → 200 (bukan 404). Ikut dikoreksi: komentar `PenugasanService.php:189` dan docblock `LocalStorageAdapter.php:12-13` ("adapter remote menyusul di Fase 6", bertentangan dengan F10-02) |
| Komentar "Arsip Kepegawaian" di backend | `backend/app/Libraries/Kepegawaian/Lampiran/ArsipDataUmum.php:13` diganti menjadi "Arsip Data Umum" (komentar saja) |

### 7.6 Risiko (dicatat sebagai keputusan sadar Horii)
Sejak v1.2, R1, R2, R6, dan R7 hanya berlaku untuk **berkas legacy** yang disajikan publik. Berkas unggahan v2 privat (F10-18 (b)).

| # | Risiko | Mitigasi yang tetap dipasang (tanpa cek izin) |
|---|---|---|
| R1 | URL berkas legacy bisa ditebak (memuat NIP + nama berkas) | Tanpa listing folder; log akses/not-found dimatikan. Berkas unggahan v2 tidak punya URL publik |
| R2 | KTP/NPWP/BPJS **legacy** (kode 38 hasil salinan MAKE-069) terbuka bagi yang tahu URL (mis. URL yang dibagikan atau tersimpan di riwayat browser). Kode 38 yang diunggah di v2 privat | `Cache-Control: no-store`, `Referrer-Policy: no-referrer` |
| R3 | Berlaku sebagian: audit unduh tidak mungkin untuk berkas legacy yang dibuka lewat URL publik; audit hanya ada di jalur privat (F10-08) | Pembukaan daftar dicatat (F10-17, fail-closed); pembukaan berkas lewat jalur privat dicatat (tidak fail-closed penuh, pola F-09, F10-35) + di-throttle (F10-36) |
| R4 | Membalik pola privat Fase 3, **hanya untuk berkas legacy** | Endpoint lama tetap privat (§7.5); berkas unggahan v2 tetap privat; saklar rollback berkas legacy (F10-24, Lampiran A) |
| R5 | Berkas legacy disajikan dari alamat aplikasi (cookie sesi `SameSite=Lax`, `Jwt.php:60`), sehingga berkas HTML/SVG legacy bisa menjadi XSS. Berkas unggahan v2 tidak lagi disajikan publik; di jalur privat, object URL `blob:` berada di origin aplikasi | Nginx: hanya pdf/gambar yang inline; lainnya diunduh sebagai octet-stream; `nosniff`; tanpa PHP; CSP (§7.3, F10-25, F10-32); rekomendasi domain terpisah (§7.7). Jalur privat: daftar tipe tertutup + `nosniff` + CSP + `Referrer-Policy` di endpoint `buka` (berlaku saat endpoint dibuka langsung), dan tipe blob dari daftar tertutup di FE (F10-34, F10-37) |
| R6 | Alias `arsip/` dan `assets/upload/` membuka **seluruh** subtree hasil salinan MAKE-069, termasuk berkas kolom lain yang mungkin berada di sana (surat cuti, `esign_hist.file_pdf_to_sign`, bukti izin presensi, foto) | [K]: sama dengan legacy, yang menyajikan subtree itu publik. Keberadaan berkas kolom lain di sana [I]: dihitung dari manifest (QF10-2) |
| R7 | Berkas legacy yang dihapus dari DB tetap bisa dibuka lewat URL lama (Hapus = baris saja, F10-09, PB-5 = A). Berkas unggahan v2 yang barisnya terhapus tidak bisa dibuka lagi (endpoint `buka` → 404) | Retensi berkas yatim = blocker B10-1 |
| ~~R8~~ | **Hilang di v1.2** (PB-2 = B). v1.1: lampiran layanan kode 43–50 (termasuk surat cuti ber-TTE, kode 45) bisa dibuka lewat URL publik. Kini berkas itu privat (F10-18 (b)) | — |
| R9 | Lampiran + ringkasan riwayat yang tersembunyi di B-18 (mis. hukdis) tampil di Arsip untuk role yang boleh melihat pegawai itu, termasuk pemiliknya (F10-30) | Tidak ada, sesuai legacy |
| R10 (v1.2) | Saat rollback `'privat'` (F10-24), berkas legacy yang tidak dibatasi ukuran unggah v2 dimuat seluruhnya ke memori PHP lewat `baca()` (F10-34 (5)) [I] | Cek ukuran maksimum dari D1/manifest sebelum rollback dipakai; streaming = CR terpisah bila perlu (Lampiran A) |

### 7.7 Rekomendasi ADR
Reviewer menulis **ADR baru** "Penyajian berkas arsip legacy lewat URL publik (F-10)". Cakupannya **hanya berkas legacy** (`arsip/…`, `assets/upload/…`); berkas unggahan v2 tetap mengikuti pola privat Fase 3 dan cukup disebut sebagai batas cakupan. Isinya:
- konteks: legacy `pathToSrc`, pola privat sejak Fase 3;
- keputusan F10-18 (a) dan (b) beserta tanggal dan pengambilnya (termasuk pilihan Plan B 12-11-2026, §14.1);
- risiko R1–R7, R9, dan R10 (R8 hilang di v1.2);
- penguatan Nginx (dua pola) dan hak baca;
- saklar rollback berkas legacy (F10-24);
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
| `GET pegawai/{nip}/arsip/berkas/{id}/buka` | 1 | **Selalu aktif untuk berkas unggahan v2** (F10-18 (b)); berkas legacy hanya saat saklar `penyajianBerkasLegacy = 'privat'` (rollback), selain itu 404 (F10-34). `Role::filter(1–7)` + `PegawaiScope` lihat (izin sama dengan daftar, F10-30). `inline` + tipe dari daftar tertutup untuk pdf/jpg/jpeg/png/gif, `attachment` + `application/octet-stream` untuk lainnya (nama berkas berekstensi, F10-34 (4)), `nosniff`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, CSP sama dengan §7.3. Audit `arsip_unduh` (F10-35) + throttle per akun (token bucket: 6 beruntun, lalu 1 tiap 10 detik, F10-36) |

Urutan galat: 401 → 403 role (`Role::filter`) → 403 lingkup (NIP tak ada bagi role ber-lingkup = 403, sama dengan `GET pegawai/{nip}`) → 404 NIP → 404 baris → 422. Khusus `buka`: … → 404 baris → 404 bukan akses privat / tidak cocok regex / berkas hilang → 429 throttle → baca → audit → 200 (F10-36). Khusus Hubungkan: urutan F10-38.

**Bentuk respons** (envelope sukses v2, daftar kolom tertutup; `path`, `url`, `basename`, `document_id` tidak pernah dikirim):
```json
// GET pegawai/{nip}/arsip
{ "pegawai": { "nip": "<nip>", "nama": "…", "glr_depan": "…", "glr_belakang": "…" },
  "folder": [ { "id_jenis_rwy": 9, "kode": "jabatan", "label": "Jabatan", "urutan": 6, "jumlah": 12, "jumlah_hilang": 1, "jumlah_tak_dapat_dibuka": 0 },
              { "id_jenis_rwy": 0, "kode": "belum_terhubung", "label": "Belum Terhubung", "urutan": 0, "jumlah": 3, "jumlah_hilang": 0, "jumlah_tak_dapat_dibuka": 0 },
              { "id_jenis_rwy": null, "kode": "tanpa_jenis", "label": "Tanpa Jenis", "urutan": null, "jumlah": 0, "jumlah_hilang": 0, "jumlah_tak_dapat_dibuka": 0 } ],
  "total": 40, "total_hilang": 2, "total_tak_dapat_dibuka": 0, "penyajian_berkas_legacy": "publik",
  "aksi": { "tulis": false, "jenis_hubungkan": [] } }
// GET pegawai/{nip}/arsip/berkas
{ "items": [ { "id_attachment": 123, "nama_arsip": "SK Jabatan", "tag": "SK Jabatan", "display_name": "…",
               "id_riwayat": 9, "jenis_riwayat": "Jabatan", "id_entri": "456", "ringkasan_riwayat": "…",
               "file_type": "application/pdf", "created_at": "…",
               "berkas_ada": true, "status_berkas": "ada", "akses_berkas": "privat",
               "url_berkas": "pegawai/<nip>/arsip/berkas/123/buka", "jenis_buka": "pratinjau",
               "aksi": { "edit": false, "hubungkan": false, "hapus": false } },
             { "id_attachment": 77, "nama_arsip": "SK CPNS", "tag": "SK CPNS", "display_name": null,
               "id_riwayat": 0, "jenis_riwayat": "Belum Terhubung", "id_entri": null, "ringkasan_riwayat": "—",
               "file_type": "application/pdf", "created_at": "…",
               "berkas_ada": true, "status_berkas": "ada", "akses_berkas": "publik",
               "url_berkas": "/berkas/arsip/<nip>/<sub>/<nama>.pdf", "jenis_buka": "pratinjau",
               "aksi": { "edit": false, "hubungkan": false, "hapus": false } } ],
  "total": 40, "page": 1, "per_page": 25 }
```
Catatan bentuk respons:
- Folder `tanpa_jenis` hanya dikirim bila `jumlah` > 0. Contoh di atas hanya memperlihatkan bentuknya.
- `status_berkas` ∈ `ada` / `hilang` / `tak_dapat_dibuka`.
- `akses_berkas` ∈ `publik` / `privat` / `null` (F10-34, §7.2). Item `privat` memakai `url_berkas` relatif terhadap dasar API (tanpa path, tanpa kunci berkas); item `publik` memakai URL publik berkas legacy. FE memilih cara buka dari field ini (§9.3).
- `penyajian_berkas_legacy` = nilai saklar F10-24 (informasi saja; FE tetap memakai `akses_berkas` per item).
- `aksi.*` di Tahap 1 selalu `false` dan `aksi.jenis_hubungkan` kosong. Di Tahap 2 dihitung server per baris (role, lingkup ubah, kode 0, kode 37 untuk Edit) dan per aktor (`jenis_hubungkan`, F10-38). FE memakai `aksi.*` untuk `hidden`; server tetap menegakkan aturan.
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
- **Fail-closed:** insert yang gagal bisa melempar exception (DBDebug = true, `backend/app/Config/Database.php:47`) atau mengembalikan 0 (`AuditLogModel.php:44-55`, `getInsertID()`), jadi hasilnya wajib diperiksa. Hasil ≤ 0 → 500 envelope (pola `PenyimpanKlien.php:252`); exception juga berakhir 500. Dicek QA.

**Audit + throttle pembukaan berkas privat (F10-08, v1.2).** Berbeda dari catatan pembukaan daftar di atas:
- Hanya endpoint `buka` (berkas unggahan v2, dan berkas legacy saat saklar `'privat'`). Berkas legacy lewat URL publik tidak tercatat.
- Audit `entity` = `arsip_unduh`, dicatat setiap pembukaan yang berhasil oleh semua role (termasuk pemilik), tanpa dedup, tanpa nama berkas/path. Hasil `record()` tidak diperiksa (pola F-09, F10-35): hasil 0 tetap 200, sedangkan exception DB tetap menjadi 500.
- Throttle `arsip_unduh_<id_pengguna>` (token bucket CI4, batas dari `Config\Arsip`, bawaan 6 beruntun lalu 1 tiap 10 detik) → 429, dicek sesudah semua 403/404 dan sebelum `baca()` (F10-36).
- `entity` `arsip_unduh` juga `VARCHAR(100)` bebas, tanpa migrasi (§8.4).

### 8.3 Struktur kode (usulan, pemilik Kepegawaian)
- `Controllers/Api/Kepegawaian/ArsipController.php` (`castNumericParams = false`, pola `LampiranController.php:37`).
- `Libraries/Kepegawaian/Arsip/`:
  - `ArsipService`: folder, daftar, filter, `berkas_ada` lewat `StorageAdapterInterface::ada()` dalam try/catch.
  - `OtorisasiArsip`: role + `PegawaiScope` lihat/ubah. Memakai ulang `PegawaiScopeInterface` dan **tidak** memakai izin Definisi per kode (F10-30). Untuk Tahap 2 memakai **sebagian** aturan tulis B-18 (F10-31, F10-38): `RiwayatDefinisi::kunciBarisDisetujui()` + `Role::UL_PEGAWAI` dengan pesan yang sama dengan `OtorisasiLampiran` (`OtorisasiLampiran.php:382-389`; metode itu `private`, sehingga `OtorisasiLampiran` tidak diubah), dan `ArsipDataUmum::bolehTulis()` (`ArsipDataUmum.php:52-64`, statis publik) untuk Ubah Nama kode 37.
  - `PenyajiUrlBerkas`: tiga regex §7.2 (kunci v2 + dua pola legacy) + saklar berkas legacy → `url_berkas`, `akses_berkas`, `jenis_buka`.
  - `BukaBerkasArsip` (atau metode `ArsipService::buka`): endpoint `buka` (F10-34): baca lewat `StorageAdapterInterface::baca()`, header daftar tertutup, audit `arsip_unduh` (F10-35), throttle (F10-36).
  - `RingkasanRiwayat`: F10-22.
  - `AksiArsip` (Tahap 2): dalam transaksi, urutan kunci sama dengan B-18 (F10-38 (1)): baris `pegawai` {nip} `FOR UPDATE` lebih dulu (pola `OtorisasiLampiran::kunciPegawai`, `OtorisasiLampiran.php:418-422`, ditulis ulang karena `private`), lalu baris `document_attachment` {id} `FOR UPDATE` (pola `kunciHapus`, `:181-182`), lalu baris riwayat tujuan (Hubungkan saja). Hapus dan Ubah Nama memakai dua langkah pertama yang sama. Baris tujuan Hubungkan lewat `RiwayatRegistry` + pola `entriMilik` (§6.1), lalu kunci baris Disetujui diulang sesudah baca ulang `FOR UPDATE` (pola `kunciUnggah`, `OtorisasiLampiran.php:158-171`).
  - `EksporArsip` (Tahap 2): menulis lewat `EksporTabel`.
- `Config/Arsip.php` (baru): `$penyajianBerkasLegacy`, `$dasarUrlBerkas`, `$sajiBerkasDev`, konstanta regex penyajian (§7.2: kunci v2 + dua pola legacy, yang terakhir sama persis dengan Nginx), daftar ekstensi pratinjau + peta tipe tertutup, daftar kode tujuan Hubungkan (F10-28), entity + batas throttle (F10-35/36).
- Perubahan baris lewat `DocumentAttachmentModel` (`BaseAuditableModel`, audit otomatis di `BaseAuditableModel.php:116`, `:143`).
- Kontrak didokumentasikan di `backend/app/Controllers/Api/Kepegawaian/README.md`, bagian baru "Arsip F-10" (aditif).

### 8.4 Skema dan DBV
**Tidak perlu DBV** untuk Tahap 1 maupun Tahap 2:
- Data dibaca dari `document_attachment` (`2026-09-30-130900_CreateDocumentAttachment.php:47-72`, indeks NIP `:67`, FK `id_riwayat` → `jenis_rwy` `:70`) dan `jenis_rwy` (`2026-09-30-130000_CreateJenisRwy.php:63-85`; `2026-10-26-100800_SeedJenisRwyLayananFase4.php:36-45`). Tidak ada kolom atau kode baru.
- Aksi Tahap 2 hanya meng-update `id_riwayat`/`nama_riwayat`/`id_entri`/`tag` atau menghapus baris. Kode 0 ada di `jenis_rwy` (FK aman). `siasn_upload_arsip.id_da` tanpa FK, jadi Hapus aman.
- Audit F10-17 dan F10-35: `audit_logs.entity` bertipe `VARCHAR(100)` bebas (`2026-09-16-000001_CreateAuditLogs.php:21`), jadi `arsip_lihat` dan `arsip_unduh` tidak butuh migrasi. Throttle F10-36 memakai cache throttler CI4, bukan tabel. Event `create`/`update`/`delete` sudah ada di ENUM (`CreateAuditLogs.php:23`; nilai saat ini `create, update, delete, login, logout, warning, import`, `2026-10-27-100100_AlterAuditLogsEventPresensi.php:27`, `:30`).
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
| 1 | `edit` | Edit | 2 | role 4/5 atau `aksi.edit = false` (termasuk kode 37 bagi selain role 1, F10-31) | sedang memproses |
| 2 | `buka` | Buka | 1 | — | `url_berkas = null` (hilang / tak dapat dibuka), atau berkas privat sedang dimuat |
| 3 | `hubungkan` | Hubungkan | 2 | bukan kode 0, role 4/5, atau `aksi.jenis_hubungkan` kosong | sedang memproses |
| 4 | `hapus` | Hapus (`danger: true`, garis pemisah otomatis) | 2 | bukan kode 0, atau role 4/5 | sedang memproses |

- Tombol ⋮: `label` = "Aksi untuk <nama arsip>", `testid` = `arsip-actions-<id_attachment>`.
- Testid lain: `arsip-folder-<id_jenis_rwy|semua|tanpa_jenis>`, `arsip-berkas-table`, `arsip-berkas-row-<id>`, `arsip-badge-hilang-<id>`, `arsip-badge-tak-dapat-dibuka-<id>`, `arsip-filter-jenis`, `arsip-filter-belum-terhubung`, `arsip-ekspor`, `arsip-pemilih-pegawai`, `pegawai-header-arsip`. Tombol Arsip Data Umum tetap `btn-arsip`.
- Prefiks tidak bertabrakan dengan `ArsipTable.vue` lama (`lampiran-actions-<id>`, `ArsipTable.vue:326`).
- "Buka" memilih jalur dari `akses_berkas` item (§5.1, F10-34), bukan dari saklar global:
  - `publik` (berkas legacy): `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')`.
  - `privat` (berkas unggahan v2, atau berkas legacy saat rollback): alur F10-37. Untuk `pratinjau`, popup `arsip-popup` dibuka **sinkron** di handler klik (tanpa `noopener`, lalu `opener = null`), berkas diambil lewat `ambilBerkas` (`features/kepegawaian/services/berkasUnduhan.ts:59-66`, klien API sehingga interceptor refresh token ikut, `frontend/src/lib/axios.ts:185`, endpoint refresh `:46`), tipe blob dari daftar tertutup, popup diarahkan ke object URL, lalu object URL dicabut sesudah penundaan tetap (bawaan 60 detik) dan saat unmount. Tipe `unduh` diambil lewat `ambilBerkas` lalu disimpan lewat `simpanBerkas` (`:79-88`), **tanpa** popup. Tujuannya mencegah JSON 401 mentah sesudah token akses kedaluwarsa (`Jwt.php:27`) dan pemblokiran popup.
  - Semua galat → popup ditutup di blok `catch`. 429 (throttle), 404, 403 → pesan tampil di halaman (`UiNotice`) dari `message` galat yang sudah diurai `ambilBerkas` (cadangan 429 khusus arsip di modul arsip; `dokumen.service.ts` tidak diubah), bukan JSON mentah. 401 sesudah refresh gagal → dialihkan ke `/login` oleh interceptor (`axios.ts:198-201`, `:207`); 403 `password_change_required` → handler ganti password (`:215-217`).

---

## 10. Impor dan cutover
| Langkah | Isi | Rujukan |
|---|---|---|
| Data baris | `document_attachment` ikut impor tier 10, path disalin apa adanya sesudah `stripslashes` (DBV-029). Kode di luar `jenis_rwy` tertolak FK saat impor | `backend/app/Libraries/Impor/DaftarTabel.php:410-438`; `docs/migrasi/MAKE-069-rencana.md:173-174` |
| Berkas fisik | `php spark lampiran:manifest` → `lampiran:tarik` (alamat dasar diisi operator lewat opsi/env, tidak di repo/log) → `lampiran:periksa`. Mencakup `arsip/…` dan `assets/upload/…` | `MAKE-069-rencana.md:176-195`; `backend/app/Commands/LampiranTarik.php` |
| Hak baca | Sesudah tarik, user Nginx bisa membaca subtree `arsip/` dan `assets/upload/` (§7.3 butir 5). Berkas unggahan v2 dibaca PHP | README-deploy §1l |
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
  - path legacy (awalan `/`, spasi, `\`) → URL publik ter-encode per segmen + `akses_berkas: publik`; `assets/upload/` → terisi (F10-33); kunci v2 → URL `buka` + `akses_berkas: privat` di kedua mode saklar; `kolom/`, `form-digital/`, segmen berawalan `.` → null; `.PDF` → terisi + `pratinjau`; ekstensi lain → `unduh`;
  - saklar `penyajianBerkasLegacy = 'privat'` → berkas legacy juga URL `buka` + `privat`;
  - **teks dua regex legacy di README-deploy §1l sama persis dengan konstanta `Config\Arsip`** (pembanding string); regex kunci v2 **tidak** ada di README (test: README tidak memuat pola kunci v2). Test pembanding ini milik MAKE-093 (§13), karena README §1l baru ditulis di paket itu.
- Endpoint `buka` (AC1-17): matriks role; header per tipe (`inline`/`attachment`, daftar tipe tertutup, `nosniff`, `private, no-store`, `Referrer-Policy`, CSP); nama berkas berekstensi (mis. `tag` "SK Jabatan" + path `.docx` → "SK Jabatan.docx"); baris legacy saat `'publik'` → 404; saklar `'privat'` → baris `arsip/…` dan `assets/upload/…` 200 lewat `PathLegacy` dengan audit + throttle; baris menggantung (status 10, pengajuan, `id_entri` tak ada) → 200; berkas hilang → 404; audit `arsip_unduh` per 200 (termasuk pemilik, tanpa nama/path); throttle (token bucket, `setTestTime`): permintaan ke-7 segera sesudah 6 sukses → 429, ember terpisah dari F-09; 403/404 tidak memakan jatah; `record()` mengembalikan 0 → tetap 200 (F10-35).
- Catatan F10-17: dicatat/tidak per role dan per NIP sendiri; dedup (berurutan); isi tanpa nama berkas dan tanpa `q`; `record()` ≤ 0 → 500.
- Tahap 2: AC2-01..AC2-13, termasuk berkas fisik tetap ada sesudah Hapus, `siasn_upload_arsip` tidak tersentuh, Hubungkan lewat Definisi (kode 3 lewat induk, kode 9/36), kunci baris Disetujui untuk role 2/6/7 (termasuk ulang sesudah `FOR UPDATE`), `aksi.jenis_hubungkan`, Ubah Nama kode 37 role 1 saja, dan sel ekspor `=1+1`.
- Rute dev: tidak terdaftar di `testing`/`production`; controller dev diuji langsung (dua pola legacy saja, kunci v2 → 404, tipe, header, realpath).
- Koreksi kontrak §7.5: foto/Plt-Plh/Konket dengan path `arsip/…` yang berkasnya ada → 200.
- Test penjaga path lama (`AttachmentServiceTest.php:63`, `:142`; `tests/database/Integrasi/StatServiceTest.php:251`) **tidak diubah** dan tetap hijau. Hanya test Arsip yang memastikan `url_berkas` ada.
- Gate penuh `./check.sh` + regresi lampiran B-18, Arsip Data Umum 37/38, SIASN unggah arsip, `s_stat`, kelengkapan beranda.

### 11.2 Vitest (frontend)
- Route + guard + redirect 2/6/7 (termasuk `/arsip/<nip lain>`); nav per role (role 8 tanpa menu); breadcrumb.
- Folder: urutan, kode 0 dan `tanpa_jenis` di bawah, badge hilang / tidak dapat dibuka.
- Tabel: badge, keadaan kosong/galat.
- Menu ⋮: dibuka lalu item dipilih lewat `data-action` (urutan, `hidden`/`disabled` §9.3).
- "Buka" `publik`: memanggil `window.open` dengan popup 600×600 + `noopener`, dan tidak dipanggil saat `disabled`.
- "Buka" `privat` (AC1-18): untuk `pratinjau`, `window.open` dipanggil sinkron sebelum permintaan selesai; untuk `unduh`, `window.open` **tidak** dipanggil dan berkas disimpan lewat `simpanBerkas` dengan nama berekstensi; jalur blob lewat `ambilBerkas`; tipe blob dari daftar tertutup (respons `text/html` tetap menjadi `application/octet-stream`); `revokeObjectURL` dipanggil sesudah penundaan tetap dan saat unmount (fake timer); 429/404/403 → popup ditutup + pesan di halaman; 401 → popup ditutup tanpa pesan tambahan (pengalihan ke `/login` oleh interceptor); popup terblokir → pesan tanpa panggilan API.
- Tahap 2 PB-4: item Edit `hidden` untuk kode 37 bagi selain role 1; dialog Hubungkan hanya menawarkan `aksi.jenis_hubungkan`; pesan 403 kunci tampil di dialog.
- Tahap 2: Edit, Hubungkan (dua langkah), `ConfirmDialog` Hapus, filter (centang me-reset select; gabungan → kosong), ekspor.
- Header: label "Arsip Data Umum" dengan `btn-arsip`, dan tombol "Arsip".

### 11.3 QA Lapis 1 F-10 (reviewer)
Checklist tersendiri (Fase 7 mengecualikan F-10, `docs/fase7/Checklist_QA_Lapis1_Fase7.md:16`), dijalankan di server dev dengan Nginx (G10-4), viewport 1440 dan 390 px. Termasuk membuka PDF/JPG/PNG di Chrome, Edge, dan Firefox (pratinjau tampil) lewat kedua jalur (URL publik berkas legacy dan popup blob berkas unggahan v2), memeriksa header dengan `curl -I`, dan memastikan `/berkas/YYYY/MM/<kunci>` → 404. Untuk jalur privat, QA juga memeriksa: baris audit `arsip_unduh` muncul; pesan 429 tampil di halaman; perilaku saat popup diblokir; membuka berkas privat sesudah token akses kedaluwarsa (refresh berhasil, tanpa JSON mentah); dan, di Chrome, Edge, dan Firefox, tombol unduh dan cetak penampil PDF pada popup blob sebelum dan sesudah penundaan pencabutan object URL (hasilnya dicatat di G10-4).

**Bukan bug** (sesuai keputusan, ditulis di checklist):
- URL berkas **legacy** bisa dibuka tanpa login atau dari akun lain (F10-18 (a)).
- Berkas unggahan v2 (termasuk lampiran cuti/TB/IB/PMK dan surat cuti ber-TTE) tidak punya URL publik dan tidak bisa dibagikan ke orang yang tidak berhak; membukanya butuh login dengan izin melihat pegawai itu (F10-18 (b)).
- Membuka lebih dari 6 berkas unggahan v2 berturut-turut dengan cepat dari akun yang sama menampilkan pesan batas; sesudah sekitar 10 detik satu berkas bisa dibuka lagi (token bucket, F10-36). Berkas legacy tidak dibatasi.
- Pembukaan berkas unggahan v2 oleh pemiliknya sendiri juga tercatat di audit (F10-35).
- Pembukaan berkas legacy lewat URL publik tidak tercatat di audit (F10-08).
- Lampiran cuti/TB/IB/PMK unggahan v2 (termasuk surat ber-TTE) bisa dibuka di Arsip oleh role yang boleh melihat pegawai itu, walaupun halaman/endpoint layanan bisa menolak role tersebut (F10-30, §7.5). Baris PMK hasil impor legacy dibuka lewat URL publik (§7.5).
- Role 2/4/5/6/7 melihat lampiran **dan ringkasan** riwayat yang tersembunyi di tab B-18, mis. hukdis, termasuk di opsi Hubungkan (F10-30).
- Pegawai yang muncul di pemilih tetapi di luar lingkup detail role 3 → 403 (§5.1).
- Lampiran riwayat status 10 tetap tampil dan bisa dibuka (F10-16).
- Hapus arsip yatim tidak menghapus berkas fisik; URL lama berkas legacy masih bisa dibuka (F10-09, R7).
- Hubungkan ke Jabatan/Diklat sertifikasi bisa memicu antre SIASN; Hubungkan ke 9/10/11/32 mengubah `s_stat`; Hubungkan ke 11 mengubah kelengkapan beranda (§6.6).
- Role 2/6/7 tidak bisa meng-Hubungkan ke riwayat Disetujui (403 "Data yang sudah disetujui tidak dapat diubah."), sehingga dialog Hubungkan mereka praktis hanya menawarkan Arsip Data Umum (kode 38); role 2/3/6/7 tidak bisa mengubah nama arsip kode 37 (F10-31, PB-4 = B).
- Role 2/6/7 tetap bisa mengubah nama arsip yang terhubung ke riwayat Disetujui (kode selain 37, F10-31 (c), [K]).
- Centang "Belum terhubung" + memilih jenis menghasilkan daftar kosong (§6.1, [K]).
- Berkas selain pdf/gambar terunduh, tidak tampil sebagai pratinjau (F10-32).
- Berkas dibuka di jendela popup kecil; di ponsel terbuka sebagai tab (§5.1).
- Tidak ada token CSRF terpisah (§6.1).
- Endpoint unduh B-18 lama tetap butuh login (§7.5).

MTC-046 (arsip dengan banyak lampiran terindeks; satu berkas hilang ditandai, bukan 500) = **fase Manual Testing tersendiri**, tidak menahan sign-off.

### 11.4 Definition of Done F-10
- Tahap 1 dan Tahap 2 di `main` dengan AC1-01..18 dan AC2-01..13 terpenuhi; quality gate dan regresi hijau.
- Setelan Nginx §7.3 dua pola legacy (termasuk hak baca, kunci v2 → 404, dan uji browser kedua jalur) + README-deploy §1 (contoh server) dan §1l + rute dev + kunci `.env.example`; dokumen usang §7.5 dikoreksi (izin WS-2); kontrak README Kepegawaian diperbarui.
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
| `PATCH …/arsip/berkas/{id}` (T2; kode 37 role 1 saja, F10-31) | semua | sendiri (kecuali 37) | lingkup ubah (kecuali 37) | 403 | 403 | sendiri (kecuali 37) | sendiri (kecuali 37) | 403 |
| `POST …/arsip/berkas/{id}/hubungkan` (T2, kode 0; kunci Disetujui F10-31) | semua | sendiri, tujuan tidak terkunci (saat ini 38) | lingkup ubah | 403 | 403 | sendiri, tujuan tidak terkunci | sendiri, tujuan tidak terkunci | 403 |
| `DELETE …/arsip/berkas/{id}` (T2, kode 0) | semua | sendiri | lingkup ubah | 403 | 403 | sendiri | sendiri | 403 |
| `GET …/arsip/berkas/{id}/buka` (berkas unggahan v2; berkas legacy saat saklar `'privat'`) | semua | sendiri | lingkup lihat | semua | semua | sendiri | sendiri | 403 |
| URL `/berkas/...` (Nginx, **hanya berkas legacy**) | **tanpa cek izin** (F10-18 (a)) | | | | | | | |

Catatan matriks:
- Role di route: baca `Role::filter(1–7)`; tulis `Role::filter(1, 2, 3, 6, 7)`. Filter route **wajib**, karena `PegawaiScope::ROLE_LIHAT_SEMUA` memuat role 8.
- Lingkup di service: "sendiri" = `PegawaiScope` UL_PEGAWAI. "Lingkup lihat/ubah" role 3 = `bolehLihat`/`bolehUbah` jalur detail (fail-closed v2, `PegawaiScope.php:47-51`; service aksi legacy lebih longgar, `Local.php:3527-3544`).
- `opsi-hubungkan` di legacy (`list_arsip_rwy_data`) hanya mengecek login, tanpa cek role/NIP (`Local.php:3272-3285`). v2 memakai role + lingkup ubah [V2, pola keamanan F10-09]; untuk role 2/6/7, kode tujuan yang terkunci → 403 (F10-38).
- Endpoint `buka`: semua 200 diaudit (F10-35) dan di-throttle per akun (token bucket: 6 beruntun, lalu 1 tiap 10 detik, F10-36).

### 12.2 Pertanyaan terbuka (tidak mengubah F10-01..18)
| # | Pertanyaan | Saran reviewer | Menahan |
|---|---|---|---|
| QF10-1 | Baris `assets/upload/...`: dibuka lewat alias publik kedua `assets/upload/` seperti legacy (usulan F10-33), atau ditandai "Tidak dapat dibuka"? Jumlah dari D1 blok 4 dilaporkan | Ikut legacy (F10-33) | Tidak menahan koding Tahap 1 |
| QF10-2 | Alias `arsip/` dan `assets/upload/` membuka seluruh subtree, seperti server legacy. Ini konsekuensi F10-18 [K], bukan keputusan baru. Pertanyaan ke Horii **hanya bila** angka manifest (§10) menunjukkan berkas kolom lain berada di subtree itu **dan** Horii ingin mempersempitnya | Hitung dari keluaran `lampiran:manifest` salinan dev (jumlah per `tabel.kolom`), laporkan ke Horii | Tidak menahan koding. Hanya relevan bila Horii ingin mempersempit sebelum rilis |
| QF10-3 | Hanya soal **waktu** menu "Arsip" tampil: ikut gerbang Fase 7 (`ACTIVE_PHASE` ≥ 7) atau lebih awal lewat `rilis`. Menu tetap diaktifkan (F10-06) | Ikut gerbang Fase 7 | MAKE-091 (hanya flag) |
| QF10-4 | Alamat terpisah untuk berkas legacy publik (penguatan R5). Subdomain = same-site dan tidak menggantikan daftar tipe + `nosniff`; isolasi penuh butuh domain terdaftar berbeda | Dicatat di ADR; keputusan infrastruktur saat deploy | Tidak menahan |
| QF10-5 | Keberadaan baris ber-`id_riwayat` NULL (D1 blok 9). Kode di luar daftar tidak mungkin ada karena FK | F10-29 (folder `tanpa_jenis`, Edit saja) | Tidak menahan |
| QF10-6 ✔ | Konfirmasi bawaan F10-19..F10-33, dan (v1.2) bawaan baru F10-34..F10-38 (jalur privat, audit, throttle, FE blob, rincian kunci aksi) | F10-19..33: Horii 12-11-2026 "Yes, decision ini berdasarkan simpeg legacy basecode" atas isi **v1.1**. Sesudah itu: F10-30 dikonfirmasi lewat PB-3 = A; F10-31 kini keputusan PB-4 = B; F10-19, F10-23, F10-24, F10-25, F10-28, F10-32, F10-33 disesuaikan di v1.2 sebagai akibat PB-2/PB-4 (dan temuan peninjau) dan perlu dikonfirmasi ulang bersama F10-34..38. F10-34..38: **dikonfirmasi Horii 12-11-2026** ("Setuju semua F10-34..38"). Penyesuaian v1.2 atas F10-19, F10-23, F10-24, F10-25, F10-28, F10-32, F10-33: **dikonfirmasi Horii 12-11-2026** ("Setuju") | ✔ |
| QF10-7 ✔ | Butir Plan B mana (PB-1..PB-7, §14) yang dipakai, bila ada. Tanpa pilihan = Plan A | **Terjawab Horii 12-11-2026:** PB-1 = B, PB-2 = B, PB-3 = A, PB-4 = B, PB-5 = A, PB-6 = A, PB-7 = A (§14.1). Atas klarifikasi reviewer, Horii memilih gabungan "PB-2 + audit PB-1" | — |
| QF10-8 ✔ (v1.2) | PB-4 menyebut `ArsipDataUmum::bolehTulis()` hanya untuk Ubah Nama **kode 37** (role 1 saja). Aturan `bolehTulis()` untuk **kode 38** berbeda: role 1, 3, dan 2/6/7 untuk NIP sendiri (`ArsipDataUmum.php:35`, `:58-63`). Apakah Ubah Nama kode 38 (dan Hubungkan ke kode 38) juga lewat `bolehTulis()`? Hasil izinnya sama dengan aturan [K] F10-31 (c) yang berlaku sekarang (1, 3 lingkup ubah, 2/6/7 NIP sendiri; 4/5 ditolak), jadi pilihan ini hanya menentukan jalur kode dan pesan 403, bukan siapa yang boleh. Reviewer tidak memutuskan sendiri | **Terjawab Horii 12-11-2026: kode 38 ikut legacy.** Ubah Nama dan Hubungkan ke kode 38 mengikuti F10-31 (c) [K] (role 1, 3 lingkup ubah, 2/6/7 NIP sendiri; 4/5 ditolak), **tanpa** jalur `ArsipDataUmum::bolehTulis()`; pesan 403 = pesan lingkup F-10 | MAKE-092 (hanya pesan 403 dan jalur kode). Tidak menahan Tahap 1 |

---

## 13. Pelaksanaan
Pelaksana **belum ditunjuk** (F10-13). Sesudah PRD ini disetujui, reviewer mengajukan kode izin workload (`[#N-…]` + scope) ke Horii, mengikuti aturan "Berbagi Workload".

| Paket | Isi | Berkas milik | Sesudah |
|---|---|---|---|
| **MAKE-090** BE Tahap 1 | Endpoint folder/daftar (+ `akses_berkas`), endpoint `buka` jalur privat (F10-34) + audit `arsip_unduh` (F10-35) + throttle (F10-36) + saklar berkas legacy (F10-24), `Libraries/Kepegawaian/Arsip/*` (tanpa `AksiArsip`/`EksporArsip`), `Config\Arsip` (+ tiga regex), rute dev (dua pola legacy), catatan F10-17, kontrak README (bagian baru, aditif), baris KontrakMatriksRoleTest + RoutePegawaiKontrakTest, kunci `.env.example` | `Controllers/Api/Kepegawaian/ArsipController.php`, `Controllers/BerkasDevController.php` (atau setara), `Libraries/Kepegawaian/Arsip/**`, `Config/Arsip.php`; aditif `Config/RoutesPegawai.php`, `backend/.env.example`, `tests/feature/KontrakMatriksRoleTest.php`, `tests/Kepegawaian/RoutePegawaiKontrakTest.php` | G10-1, G10-2 |
| **MAKE-091** FE Tahap 1 | Halaman Arsip di modul Kepegawaian (route, pemilih pegawai, folder, tabel, ⋮ Buka dengan dua cara: URL publik dan popup + blob, F10-37), nav, tombol header + label "Arsip Data Umum" | `frontend/src/features/kepegawaian/views/Arsip*.vue`, `components/arsip/**`, `routes.arsip.ts`; aditif `router/index.ts` (bila perlu), `shared/layouts/nav.config.ts` (+ test menu), `PegawaiHeaderCard.vue`, `ArsipKepegawaianDialog.vue` (label/docblock), `DetailPegawaiPage.vue` (docblock), `DetailPegawaiPage.spec.ts` (label) | Kontrak §8.1 (bisa paralel dengan MAKE-090 memakai mock dengan bentuk yang sama) |
| **MAKE-093** Penyajian berkas legacy | Contoh Nginx (dua pola legacy) + snippet header + README-deploy §1 (contoh server) dan §1l (penegasan `writable/`, hak baca, symlink, dua regex legacy, kunci v2 tidak disajikan), verifikasi `curl -I` (termasuk kunci v2 → 404) + uji 3 browser di server dev untuk kedua jalur, test pembanding regex README §1l vs `Config\Arsip` (dua pola legacy + asersi tanpa pola kunci v2, §11.1), koreksi dokumen usang §7.5 (izin WS-2), draf ADR §7.7 | `README-deploy.md`, `backend/app/Controllers/Api/Kepegawaian/README.md` (koreksi non-aditif), komentar `PenugasanService.php:189`, docblock `LocalStorageAdapter.php:12-13`, komentar `ArsipDataUmum.php:13`, test koreksi §7.5, berkas test pembanding regex README (baru, di `backend/tests/`, nama ditentukan pelaksana) | MAKE-090 (bentuk URL + regex) |
| **MAKE-092** BE+FE Tahap 2 | `AksiArsip`, `EksporArsip` (lewat `EksporTabel`), endpoint T2, kunci aksi F10-31/F10-38 (kunci baris Disetujui, `bolehTulis()` kode 37, `aksi.jenis_hubungkan`), filter `belum_terhubung`, dialog Edit/Hubungkan, Hapus, ekspor, test | Berkas MAKE-090/091 yang sama (aditif) | MAKE-090 + MAKE-091 di `main` |
| Penutup | QA Lapis 1 F-10 + laporan + sinkron Trello/Notion | Reviewer | Semua paket |

Urutan: MAKE-090 ∥ MAKE-091 → MAKE-093 → QA Tahap 1 (boleh dilakukan reviewer lebih awal) → MAKE-092 → regresi gate penuh → QA Lapis 1 F-10 → sign-off.

Alur setiap paket: branch `f10/<key>-<slug>` dari `origin/main` → gate cepat → push → CI → gate penuh atas aba-aba reviewer → review → izin Horii → ff ke `main` (hanya `[CR]`).

---

## 14. Plan B — versi penyesuaian (catatan keputusan Horii 12-11-2026)

### 14.1 Keputusan dan kedudukan
**Keputusan Horii 12-11-2026 (final)**, dicatat apa adanya:

| Butir | Pilihan Horii | Akibat di PRD v1.2 |
|---|---|---|
| PB-1 | B | Digabung dengan PB-2 sesuai klarifikasi Horii ("PB-2 + audit PB-1"): audit + throttle unduh dihidupkan untuk jalur privat (F10-08, F10-35, F10-36) |
| PB-2 | B | Berkas legacy tetap publik lewat Nginx (dua pola); berkas unggahan v2 privat lewat endpoint `buka` (F10-18, F10-24, F10-34, F10-37, §7) |
| PB-3 | A | Tidak berubah: F10-30 (tanpa izin Definisi per kode) |
| PB-4 | B | F10-31 berubah: kunci baris Disetujui seperti B-18 untuk Hubungkan, `ArsipDataUmum::bolehTulis()` untuk Ubah Nama kode 37 (F10-38, AC2-13, §6.1, §8.3) |
| PB-5 | A | Tidak berubah: Hapus = baris saja (F10-09) |
| PB-6 | A | Tidak berubah: tanpa pencegahan antrean SIASN (F10-09, §6.6) |
| PB-7 | A | Tidak berubah: lampiran menggantung tampil dan bisa dibuka (F10-16) |

- **Plan A berlaku untuk PB-3, PB-5, PB-6, PB-7.** Untuk PB-1/PB-2 dan PB-4, bagian utama PRD (§0–§13, Lampiran A) **sudah direvisi** di v1.2. Pelaksana mengikuti bagian utama PRD; §14.3 di bawah disimpan sebagai catatan perbandingan dan dasar ADR (§7.7).
- Horii 12-11-2026 sebelumnya mengonfirmasi bahwa keputusan F-10 berbasis kode legacy ("Yes, decision ini berdasarkan simpeg legacy basecode"), lalu meminta Plan B sebagai pembanding.
- **PB-1 = B bersama PB-2 = B.** Di v1.1 kedua butir saling menggantikan (§14.6). Atas pertanyaan klarifikasi reviewer, Horii 12-11-2026 memilih opsi "PB-2 + audit PB-1": PB-2 sebagai dasar, ditambah audit + throttle dari PB-1 untuk jalur privat.
- Detail teknis PB-1/PB-2 dan PB-4 yang belum diputus Horii ditulis sebagai bawaan usulan reviewer F10-34..F10-38 (◻, QF10-6). QF10-8 (kode 38) terjawab: ikut legacy. F10-34..38 dikonfirmasi Horii 12-11-2026.
- Pilihan ini **tidak** menambah migration. Semua butir masih memakai tabel dan kode `jenis_rwy` yang ada, sehingga tetap tanpa DBV.

### 14.2 Status fitur secara umum
| Sisi | Status |
|---|---|
| Legacy | **Berjalan.** Halaman "Arsip Kepegawaian" per pegawai (`hr/arsip/index/{nip}`): tabel datar semua baris `document_attachment` milik NIP, filter jenis + "belum terhubung", ekspor, aksi Hubungkan/Hapus (kode 0) + Ubah Nama, berkas dibuka lewat URL publik server arsip (`legacy:controllers/hr/Arsip.php`, `legacy:libraries/hr/L_arsip.php:12-19`, `legacy:views/hr/employee/arsip/list.php`, `legacy:helpers/function_helper.php:307-326`) |
| v2 sekarang (`main` `0af9be4f`) | **Belum ada halaman Arsip.** Menu `arsip` masih placeholder (`phase: 7`, tersembunyi). Fondasinya sudah ada: tabel `document_attachment` + `jenis_rwy` (ikut impor tier 10), lampiran per riwayat di tab B-18 (`ArsipTable`, unduh privat lewat `LampiranController` + `OtorisasiLampiran`), dialog Data Umum 37/38 (`ArsipKepegawaianDialog`), penyimpanan lokal privat (`LocalStorageAdapter`), serta alat salin berkas legacy `lampiran:manifest/tarik/periksa` (MAKE-069 opsi A) |
| Plan A | Halaman Arsip v2 yang perilakunya persis legacy (termasuk URL publik), ditambah folder, tanda berkas hilang, dan pola keamanan v2 |
| Plan B | Halaman dan alur yang sama dengan Plan A, tetapi butir yang menyentuh kerahasiaan berkas dan konsistensi aturan B-18 mengikuti pola v2 (§14.3) |
| **Dipakai (v1.2)** | Campuran pilihan Horii 12-11-2026 (§14.1): Plan A untuk PB-3/5/6/7, PB-1 = B dan PB-2 = B (digabung sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1"), PB-4 = B |

### 14.3 Butir yang berbeda antara Plan A dan Plan B

#### PB-1. Cara membuka berkas (F10-18, F10-08)
- **Keputusan Horii 12-11-2026: B.** Digabung dengan PB-2 sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1": yang dipakai dari PB-1 hanya audit + throttle unduh untuk jalur privat; berkas legacy tetap publik (PB-2). Diterapkan di F10-08, F10-35, F10-36.
- **Legacy:** URL publik langsung `pathToSrc(path)` tanpa sesi. Berkas `arsip/` dari server arsip, path lain dari server aplikasi. Tanpa log unduh (`function_helper.php:307-326`, `list.php:81`).
- **v2 sekarang:** semua lampiran **privat** sejak Fase 3: unduh lewat `GET pegawai/{nip}/lampiran/{id}/unduh` (jwt + izin Definisi + `PegawaiScope`, `attachment`, `nosniff`, `no-store`; `LampiranController.php:79-94`, `OtorisasiLampiran.php:110-125`). Path tidak pernah dikirim ke klien (`AttachmentService.php:29-38`). Dokumen F-09 sudah punya audit unduh + throttle 6/menit per akun (`DokumenService.php:26`, `:336`, `:357`).
- **Plan A (v1.1):** URL publik lewat alias Nginx `/berkas/` tanpa cek izin (§7 v1.1, diganti di v1.2). Audit unduh gugur.
- **Plan B:** **privat**. Mode `'privat'` yang di Plan A hanya disiapkan sebagai saklar rollback (F10-24) menjadi bawaan: berkas dibuka lewat `GET pegawai/{nip}/arsip/berkas/{id}/buka` (jwt + lingkup + `no-store`), FE membuka sebagai blob lewat klien API (§9.3). Tidak ada blok Nginx `/berkas/`. Audit + throttle unduh dihidupkan lagi dengan pola F-09 (entity terpisah, mis. `arsip_unduh`).
- **Gap:** legacy publik ↔ v2 privat. Plan A membalik pola v2 untuk berkas yang sama; Plan B mempertahankan pola v2 dan berbeda dari legacy hanya pada cara membuka (popup tetap ada).
- **Kelebihan Plan B:** R1–R5, R7, dan R8 (§7.6) hilang; KTP/NPWP/BPJS (kode 38) tidak terbuka bagi yang sekadar tahu URL; audit unduh bisa dilakukan; invarian "path tidak ke klien" tetap utuh; tidak butuh setelan Nginx, hak baca user Nginx, uji header, maupun ADR pembalikan pola (G10-4 dan B10-3 gugur, MAKE-093 mengecil); satu pola dengan tab B-18.
- **Kekurangan Plan B:** berbeda dari legacy: URL berkas tidak bisa dibagikan ke orang lain atau dibuka tanpa login; berkas melewati PHP, dan `StorageAdapterInterface::baca()` memuat seluruh isi berkas ke memori (`StorageAdapterInterface.php:25`), sehingga berkas besar butuh batas ukuran + `memory_limit` (DEPLOY-002.1) atau CR streaming terpisah; beban PHP-FPM sedikit naik; QR surat cuti lama (F10-14) tetap blocker terpisah, karena alias publik yang bisa dipakai untuk redirect tidak ada.

#### PB-2. Publik hanya untuk berkas legacy (jalan tengah PB-1)
- **Keputusan Horii 12-11-2026: B.** Ditambah audit + throttle dari PB-1 sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1". Diterapkan di F10-18, F10-24, F10-34, F10-37, §7, §8.1, §9.3.
- **Legacy:** semua berkas arsip adalah berkas legacy, dibuka publik.
- **v2 sekarang:** berkas unggahan v2 (kunci `YYYY/MM/<32 hex>`) privat; berkas legacy hasil salinan MAKE-069 dibaca lewat `PathLegacy` oleh endpoint privat.
- **Plan A (v1.1):** tiga pola publik: kunci v2, `arsip/…`, `assets/upload/…` (§7.2 v1.1, diganti di v1.2).
- **Plan B:** hanya `arsip/…` dan `assets/upload/…` yang disajikan Nginx (paritas URL lama legacy). Berkas unggahan v2, termasuk lampiran layanan 42–50, dibuka lewat endpoint privat `buka` seperti PB-1. Badge, folder, dan popup sama.
- **Gap:** legacy tidak punya berkas unggahan v2, jadi Plan B tetap paritas penuh untuk semua berkas yang ada di legacy dan mempertahankan pola privat untuk berkas yang lahir di v2.
- **Kelebihan Plan B:** R8 hilang (surat cuti ber-TTE dan lampiran cuti/TB/IB tidak terbuka lewat URL publik); berkas yang diunggah sejak v2 (termasuk KTP/NPWP unggahan baru) tetap privat; URL legacy yang sudah beredar tetap berfungsi; alias `arsip/` tetap membuka opsi redirect QR lama (B10-2).
- **Kekurangan Plan B:** dua jalur buka (publik untuk berkas legacy, privat untuk berkas v2), sehingga FE, test, dan QA lebih banyak; R1, R2, R6, R7 tetap berlaku untuk berkas legacy; setelan Nginx tetap dibutuhkan (MAKE-093 tetap ada, dengan dua pola).

#### PB-3. Izin per jenis riwayat (F10-30)
- **Keputusan Horii 12-11-2026: A.** F10-30 tetap (tanpa izin Definisi per kode), termasuk untuk endpoint `buka`.
- **Legacy:** semua baris per NIP tampil bagi role yang boleh membuka halaman, tanpa izin per jenis (`L_arsip.php:12-19`).
- **v2 sekarang:** tab B-18 memakai izin Definisi per kode. Contoh: hukdis hanya boleh dilihat role 1/3 (`Definisi/Hukdis.php:92`); daftar lampiran B-18 ditolak bagi role tanpa izin `lihat` (`OtorisasiLampiran.php:69`).
- **Plan A:** tanpa izin per kode; role 2/4/5/6/7 melihat lampiran + ringkasan hukdis (R9).
- **Plan B:** baris dan folder disaring dengan izin `lihat` Definisi pemilik kode (pola `OtorisasiLampiran::bolehDaftar`). Kode tanpa Definisi riwayat (0, 37, 38, 42–50) memakai aturan tertulis: 0/37/38 = role yang boleh melihat pegawai; 42–50 = role yang boleh melihat layanan itu.
- **Gap:** Plan A = legacy; Plan B = sama dengan B-18.
- **Kelebihan Plan B:** halaman Arsip tidak menjadi jalan pintas ke data yang sengaja disembunyikan di B-18; satu aturan izin di seluruh aplikasi; R9 hilang.
- **Kekurangan Plan B:** pegawai tidak melihat sebagian arsip miliknya sendiri (mis. lampiran hukdis), berbeda dari legacy; aturan kode 42–50 perlu dirinci dan diuji; `jumlah` folder berbeda per role.

#### PB-4. Kunci aksi seperti B-18 (F10-31)
- **Keputusan Horii 12-11-2026: B.** Diterapkan di F10-31, F10-38, §6.1, AC2-13, §8.3, §11.3, §12.1. Kode 38: ikut legacy (QF10-8 terjawab).
- **Legacy:** Hubungkan dan Ubah Nama tanpa cek kunci baris (`Local.php:3496-3699`, `:3809-3890`).
- **v2 sekarang:** unggah/hapus lampiran ke riwayat **Disetujui** dikunci untuk pegawai bila Definisi menguncinya (`OtorisasiLampiran.php:380-389`); tulis Arsip Data Umum 37 hanya role 1 (`ArsipDataUmum.php:52-56`).
- **Plan A:** tanpa kunci; role 2/6/7 bisa meng-Hubungkan ke riwayat Disetujui, role 2/3/6/7 bisa mengubah nama arsip 37.
- **Plan B:** Hubungkan memakai kunci baris Disetujui yang sama (403 "Data yang sudah disetujui tidak dapat diubah."); Ubah Nama kode 37 memakai `ArsipDataUmum::bolehTulis()`.
- **Gap:** Plan A = legacy; Plan B = sama dengan B-18 dan Data Umum.
- **Kelebihan Plan B:** pegawai tidak bisa menambah berkas ke riwayat yang sudah disahkan admin lewat jalur samping; konsisten dengan B-18.
- **Kekurangan Plan B:** pegawai tidak bisa merapikan arsip yatim miliknya ke riwayat yang sudah disetujui; tugas itu jatuh ke admin (role 1/3).

#### PB-5. Hapus arsip yatim (F10-09)
- **Keputusan Horii 12-11-2026: A.** Hapus tetap baris saja (F10-09).
- **Legacy:** hanya baris DB yang dihapus; berkas fisik tertinggal; tanpa log (`Local.php:3786-3797`).
- **v2 sekarang:** `AttachmentService::hapus()` menghapus baris lalu menjadwalkan penghapusan berkas sesudah transaksi selesai (`AttachmentService.php:120-139`); hapus B-18 juga menjaga lampiran wajib terakhir (`OtorisasiLampiran.php:43-45`).
- **Plan A:** baris saja, berkas tertinggal (R7, retensi = B10-1).
- **Plan B:** memakai `AttachmentService::hapus()`, sehingga berkas fisik ikut terhapus sesudah commit. Sebelum dipakai, pelaksana memastikan tidak ada baris lain yang merujuk path yang sama [I].
- **Gap:** Plan A = legacy; Plan B = pola v2.
- **Kelebihan Plan B:** tidak ada berkas yatim yang menumpuk di disk; R7 hilang; retensi berkas yatim (bagian B10-1) lebih sederhana.
- **Kekurangan Plan B:** berkas tidak bisa dipulihkan dari disk sesudah dihapus (bergantung backup, B10-1); berbeda dari legacy.

#### PB-6. Hubungkan dan antrean SIASN (F10-09, §6.6)
- **Keputusan Horii 12-11-2026: A.** Tanpa pencegahan (F10-09, §6.6). Catatan: akibat PB-4 = B, role 2/6/7 praktis sudah tidak bisa meng-Hubungkan ke 9/36/5 (riwayat tujuan selalu Disetujui dan terkunci).
- **Legacy:** tanpa cek; Hubungkan bisa membuat berkas masuk antre unggah SIASN.
- **v2 sekarang:** `AntreArsip` mengantre lampiran kode 9/36/41 (Jabatan) dan 5 (Diklat sertifikasi) berekstensi PDF untuk riwayat yang sudah terkirim ke SIASN (`AntreArsip.php:17-21`); belum ada jalur Hubungkan.
- **Plan A:** tanpa pencegahan, risiko diterima.
- **Plan B:** Hubungkan ke 9/36/5 hanya untuk role 1/3, dengan dialog konfirmasi yang menyebut bahwa berkas bisa ikut terkirim ke SIASN. Role 2/6/7 tetap bisa Hubungkan ke kode lain.
- **Gap:** Plan A = legacy; Plan B = pencegahan ringan khusus v2.
- **Kelebihan Plan B:** berkas yang dikirim ke SIASN atas nama instansi selalu lewat keputusan admin; pengiriman tak sengaja berkurang.
- **Kekurangan Plan B:** pegawai tidak bisa menghubungkan SK Jabatan/sertifikat sendiri; aturan role per kode menambah test.

#### PB-7. Lampiran "menggantung" (F10-16)
- **Keputusan Horii 12-11-2026: A.** Tetap tampil dan bisa dibuka tanpa badge (F10-16), termasuk lewat endpoint `buka`.
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
| Butir | Bagian PRD yang direvisi | Paket | Status v1.2 |
|---|---|---|---|
| PB-1 | F10-18 (keputusan baru), F10-08 hidup lagi, F10-24 (bawaan `'privat'`), §7 diringkas, R1–R8, ADR §7.7 tidak perlu, G10-4/B10-3 gugur | MAKE-090 (endpoint `buka` + audit/throttle), MAKE-091 (buka via blob), MAKE-093 menyusut ke koreksi dokumen §7.5 | **Dipilih (B)**, digabung dengan PB-2 sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1": hanya F10-08 (audit + throttle jalur privat) yang diambil; F10-24 tetap bawaan `'publik'` untuk berkas legacy; ADR §7.7, G10-4, B10-3, MAKE-093 tetap ada |
| PB-2 | F10-18 (dipersempit), §7.2 (dua pola), R8 hilang | MAKE-090 (dua jalur URL), MAKE-091 (dua cara buka), MAKE-093 (dua pola Nginx) | **Diterapkan** |
| PB-3 | F10-30, AC1-05, §12.1, daftar "Bukan bug" §11.3 | MAKE-090 (`OtorisasiArsip` memakai Definisi) | Tidak dipilih (A) |
| PB-4 | F10-31, AC2-13 | MAKE-092 | **Diterapkan** (+ F10-38, §6.1, §8.3, §11.3, §12.1; kode 38 ikut legacy, QF10-8) |
| PB-5 | F10-09 (Hapus), AC2-09, R7 | MAKE-092 | Tidak dipilih (A) |
| PB-6 | F10-09 (SIASN), F10-28, §6.6, AC2-07 | MAKE-092 | Tidak dipilih (A) |
| PB-7 | F10-16, AC1-06 | MAKE-090, MAKE-091 | Tidak dipilih (A) |

### 14.6 Catatan reviewer (bukan keputusan)
- (v1.1) PB-1 dan PB-2 saling menggantikan. PB-1 paling aman; PB-2 menjaga paritas URL legacy sambil menutup R8. (v1.2) Horii memilih PB-1 = B dan PB-2 = B. Karena keduanya saling menggantikan, reviewer menanyakan maksudnya; Horii memilih opsi "PB-2 + audit PB-1": PB-2 sebagai dasar, ditambah audit + throttle dari PB-1.
- PB-3..PB-7 tidak saling bergantung dan bisa dipilih tersendiri.
- Bagian ini tetap disimpan sebagai catatan alternatif dan dasar ADR (§7.7).

---

## Lampiran A — Deviasi dan cara kembali
| # | Legacy | v2 (F-10) | Cara kembali |
|---|---|---|---|
| **F10-18 (a)** berkas legacy (membalik pola v2) | URL publik `pathToSrc` tanpa sesi (`function_helper.php:307-326`) | **Sama dengan legacy** untuk berkas legacy (`arsip/…`, `assets/upload/…`). Pola privat v2 sejak Fase 3 (endpoint jwt + lingkup, path tidak ke klien) **dibalik** untuk berkas legacy. Endpoint lama tetap privat. Berkas unggahan v2 tidak terdampak (tetap privat, F10-18 (b)); R8 hilang di v1.2 | **Kembali ke pola privat (rollback) untuk berkas legacy** dalam dua langkah. (1) Ubah `Config\Arsip::$penyajianBerkasLegacy` (atau `.env` `arsip.penyajianBerkasLegacy`) ke `'privat'`: respons daftar lalu memberi `url_berkas` = `pegawai/<nip>/arsip/berkas/<id>/buka` + `akses_berkas: "privat"` untuk berkas legacy juga, dan FE membuka berkas lewat klien API sebagai blob (§9.3, F10-37), dengan audit + throttle yang sama (F10-35/36), tanpa ubah kode/DB. (2) Hapus blok Nginx `location ^~ /berkas/` lalu `nginx -t && reload`, sehingga semua URL publik lama menjadi 404. Catatan: berkas legacy tidak dibatasi ukuran unggah v2; sebelum rollback, cek ukuran maksimum dari D1/manifest, karena `baca()` memuat seluruh isi ke memori (F10-34). Streaming berkas besar = CR terpisah bila perlu. Kedua mode dijaga test |
| F10-18 (b) + F10-08 berkas unggahan v2 | Semua lampiran `document_attachment` disajikan lewat URL publik tanpa log; berkas layanan 43–50 disimpan di kolom tabel layanan, bukan `document_attachment` | Privat lewat endpoint `buka` dengan izin daftar arsip, header aman, audit `arsip_unduh`, throttle per akun (bawaan 6 beruntun, lalu 1 tiap 10 detik) [V2] | Tidak disarankan membuka publik. Mengubah perilaku audit/throttle = ubah `Config\Arsip` (entity/batas) atau CR |
| F10-31 (PB-4) | Hubungkan dan Ubah Nama tanpa cek kunci (`Local.php:3496-3699`, `:3809-3890`) | Hubungkan ke riwayat Disetujui terkunci untuk 2/6/7; Ubah Nama kode 37 role 1 saja [V2] | CR atas keputusan Horii (hapus pemeriksaan kunci di `OtorisasiArsip`); tanpa ubah DB |
| F10-32 ekstensi | Semua ekstensi disajikan apa adanya oleh server web | Semua ekstensi tetap bisa dibuka [K]. Hanya pdf/jpg/jpeg/png/gif yang tampil sebagai pratinjau; lainnya diunduh sebagai octet-stream [V2 kecil, mitigasi R5] | Tambah ekstensi pratinjau di `Config\Arsip` + `types`/`map` Nginx (hanya tipe yang tidak bisa menjalankan skrip) |
| F10-23 segmen berawalan titik | Disajikan | "Tidak dapat dibuka" [V2 kecil] | Ubah dua regex legacy di `Config\Arsip` + Nginx bersamaan (test pembanding menjaga) |
| F10-17 | Tanpa log | Catatan pembukaan daftar `arsip_lihat` | CR (hapus pemanggilan pencatatan); baris audit tidak dihapus |
| F10-04 folder | Tabel datar + filter | Folder virtual + "Semua berkas" (filter legacy tetap ada di T2) | Tidak perlu: "Semua berkas" = tampilan legacy |
| F10-15 | "Arsip Kepegawaian" = semua lampiran | Halaman "Arsip"; dialog 37/38 = "Arsip Data Umum" (testid `btn-arsip` tetap) | Ganti label (FE saja) |
| F10-09 keamanan | Aksi lewat GET, `tag` mentah, `opsi-hubungkan` tanpa cek role/NIP (`Local.php:3272-3285`), Hubungkan tanpa cabang `else` untuk kode lain (`Local.php:3561-3690`) | Metode non-GET + cookie `SameSite=Lax`, `tag` dibersihkan, cek role + lingkup di setiap aksi dan di `opsi-hubungkan`, kode di luar F10-28 → 422 | Tidak disarankan (celah CSRF/XSS/IDOR dan data tak konsisten) |
| AC1-03 | NIP lain dialihkan ke arsip sendiri | FE sama (dialihkan); API → 403 [V2] | Tidak perlu |
| F10-19..F10-38 | Lihat register §3.2 | — | CR atas keputusan Horii |

## Lampiran B — Riwayat versi
| Versi | Tanggal | Isi |
|---|---|---|
| v1.0 draf | 12-11-2026 | CR-120: PRD F-10 dari Mapping F-10 + keputusan Horii 12-11-2026 #1–#18 (F10-01..18); bawaan usulan F10-19..33; Tahap 1/2; penyajian URL publik + rollback; tanpa DBV. Sudah memuat hasil review keputusan/legacy dan review teknis, bawaan F10-19..33 menunggu konfirmasi Horii |
| v1.1 draf | 12-11-2026 | Jawaban Horii atas konfirmasi bawaan dicatat (QF10-6); §14 Plan B versi penyesuaian (PB-1..PB-7: status legacy, status v2, gap, kelebihan, kekurangan, dampak paket) + QF10-7 |
| v1.2 draf | 12-11-2026 | Keputusan Horii 12-11-2026 atas Plan B dicatat apa adanya: PB-1 = B, PB-2 = B, PB-3 = A, PB-4 = B, PB-5 = A, PB-6 = A, PB-7 = A (§14.1, QF10-7 terjawab). PB-1 = B dan PB-2 = B digabung sesuai klarifikasi Horii 12-11-2026: "PB-2 + audit PB-1" (pilihan atas pertanyaan klarifikasi reviewer): berkas legacy tetap publik lewat Nginx (dua pola), berkas unggahan v2 (termasuk layanan 42–50 dan surat ber-TTE 45) privat lewat endpoint `buka` dengan audit + throttle pola F-09; F10-08 dan F10-18 diubah; F10-23/24/25/32 disesuaikan; saklar F10-24 menjadi `penyajianBerkasLegacy`; field `akses_berkas`; R8 hilang, R1–R7 dipersempit; contoh Nginx tanpa blok kunci v2; rute dev dua pola; AC1-17/18 baru. PB-4: F10-31 menjadi keputusan (kunci baris Disetujui + `bolehTulis()` kode 37), AC2-13, §6.1, §8.3, §11.3, §12.1. Bawaan usulan baru F10-34..F10-38 (menunggu konfirmasi) dan pertanyaan baru QF10-8 (kode 38). F10-09 diberi pengecualian PB-4 (F10-31); F10-28 dipersempit per aktor; G10-4/B10-3 dua pola; QF10-4 dipersempit ke berkas legacy. Hasil tinjauan tiga peninjau atas v1.2: atribusi penggabungan PB-1/PB-2 ditulis sesuai jawaban klarifikasi Horii; PMK 42 hasil impor tetap publik (§7.5); ringkasan memuat akibat PB-4; F10-19 tanpa cadangan basename kunci v2; F10-34 nama berkas berekstensi + CSP/`Referrer-Policy` di `buka`; F10-35 `entity_id` disebut sebagai penyimpangan dari F-09 dan perilaku galat `record()` diluruskan (DBDebug); F10-36 throttle token bucket + batas di `Config\Arsip`; F10-37 memakai `ambilBerkas`/`simpanBerkas`, `unduh` tanpa popup, 401 dialihkan interceptor, revoke dengan penundaan tetap; F10-38 urutan kunci `pegawai` lebih dulu; AC1-17 saklar `'privat'` + baris menggantung; R10 baru; daftar "Bukan bug" + checklist QA jalur privat; test pembanding regex README pindah ke MAKE-093 |
| v1.3 draf | 12-11-2026 | Horii: "Setuju semua F10-34..38, kode 38 ikut legacy" → F10-34..F10-38 🔒 (QF10-6), QF10-8 terjawab (kode 38 mengikuti F10-31 (c) [K], tanpa `bolehTulis()`) |
| v1.3 | 12-11-2026 | Horii: "Setuju, boleh merge PRD F-10" → penyesuaian v1.2 atas F10-19/23/24/25/28/32/33 dikonfirmasi (QF10-6 ✔); PRD disetujui dan di-merge ke `main` (G10-1) |
