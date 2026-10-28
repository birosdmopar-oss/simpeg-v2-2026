# PRD Fase 5B — Cuti/TB Tukin, Laporan Tukin, Rekap Kehadiran & Uang Makan (Modul D, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase5/PRD_Fase5B_Cuti-TB-LaporanTukin-UangMakan.md` — **acuan utama** coder/agen AI paket **5B** Fase 5 (kartu Trello DEV-006, sub-card DEV-006.2; nama berkas sesuai DEV-006.2) |
| Versi | **v1.0 — 28-10-2026** (v0.1 draf 28-10-2026 `a5f5254`; v1.0 memuat keputusan Horii 28-10-2026 atas semua butir ⛔ dan ◻, disampaikan reviewer CR; riwayat perubahan §14 Lampiran D) |
| Status | **BERLAKU setelah review reviewer CR** (keputusan F5B-1..F5B-21 dikunci di §5.3; Q5B-08 diputus ulang Horii 28-10-2026 setelah temuan verifikasi). Koding 5B mulai setelah dokumen ini di `main` dan FE 5A selesai |
| Basis kode | `origin/main` = **`43023e6`** (rujukan `path:baris` repo dari commit ini). 5A M2–M6 sudah di `main` (`3a12909` dst.); Fase 4 M3–M6 (cuti, e-sign/notifikasi, TB/IB/PMK, dashboard/PIC) sudah di `main` |
| Acuan legacy | Salinan kerja legacy CI3 **HEAD `0cf7e67`** (26-10-2026). `L_presensi.php`, `Presensi.php`, `L_employee.php` **identik** dengan `771f8b4` (acuan 5A), sehingga nomor baris 5A tetap berlaku; `Lsl_gaji.php` berubah di `1c3142f` (aturan snapshot uang makan — sudah sama dengan PRD 5A §7.3.7) dan `0cf7e67` (baris Pajak di slip, §9 catatan). Rujukan baris bercatatan AS-09 (belum dicocokkan checksum produksi, Q5A-14) |
| Scope | **5B** = D-06 (sumber cuti/TB nyata untuk Tukin), D-08 (Laporan Tukin, API + ekspor), D-09 (rekap kehadiran, rekap uang makan, **nilai uang makan slip**), bahan cross-check rumus Q5A-13. **Bukan** 5A/5C (§1.2). Backend saja; FE laporan = 5C (F5-21) |
| Dikerjakan | Setelah Fase 4 M3/M5 di `main` (P-1, DEV-006.2 — terpenuhi 28-10-2026). Pelaksana: sesi WS-2 setelah FE 5A selesai (keputusan reviewer CR 28-10-2026) |
| Key | Pekerjaan baru **MAKE-039..045** (§10; MAKE-044 TB massal Fase 4, MAKE-045 CRUD penyesuaian — dikonfirmasi reviewer CR 28-10-2026). Skema: **DBV-024** = tabel penyesuaian Tukin per NIP (F5B-14, §7.6). Perbaikan review/QA: CR-### berikutnya (CR-067 dst., dikonfirmasi reviewer CR) |
| Sumber | PRD 5A v1.1 (§1.2, §7.3.7, §7.7, §9, §13, Lampiran C); `backend/docs/progress/05-Presensi.md` (status 5A, UP-1, QC-1..5); README `Libraries/Presensi`, `Presensi/Tukin`, `Presensi/TukinData`; PRD Fase 4 v1.4 (§6.7.5, §8, §14.6); `Bahan Baku SIMPEG/05-Presensi.md` (D-06, D-08, D-09, D-12, D-13, exit criteria); Matriks Role × Endpoint v2 Modul D; ADR-013/029 (`SIMPEG_v2_ADR`), ADR-032/033/034 repo; Trello DEV-006, DEV-006.2, QASMTASK-079..088, QAUI-005, QAFUNC-006; kode legacy `0cf7e67` (Lampiran A) |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh dokumen ini sebelum menulis satu baris kode.** Laporan M0 (§10) membuktikan pemahaman.
2. Bila dokumen ini bertentangan dengan sumber lain (Trello, `05-Presensi.md`, Matriks, TS/FSD/SRS, README modul, PRD 5A),
   **PRD ini + keputusan user (§5) MENANG** untuk lingkup 5B. Keputusan PRD 5A (P-1..P-4, F5-1..F5-24, SG-1..SG-3, K-1)
   **tetap berlaku**; PRD ini hanya menambah.
3. Bila ada hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG MENEBAK.** Catat
   di laporan serah dan di `backend/docs/progress/05-Presensi.md`.
4. **⛔** = titik tunggu: jangan lanjut sebelum pihak yang disebut menyetujui. Di §13: butir **⛔** WAJIB dijawab sebelum
   batas yang disebut; butir **◻ default** = default PRD berlaku bila reviewer CR tidak menolaknya saat review laporan M0.
5. Label: **[K]** = ikut legacy apa adanya (rujukan berkas:baris), **[V2]** = deviasi v2 disengaja (alasan + keputusan
   dicatat), **[I]** = inferensi dari kode bila DDL/kode tidak cukup.
6. Rujukan legacy relatif ke `application/` salinan legacy: `L_presensi.php` = `libraries/hr/L_presensi.php`,
   `Presensi.php` = `controllers/hr/Presensi.php`, `L_employee.php` = `libraries/hr/L_employee.php`, `Lsl_gaji.php` =
   `libraries/hr/Lsl_gaji.php`, `MYC` = `core/MY_Controller.php`, `V/` = `views/hr/employee/presensi/`. Bila tidak disebut
   berkasnya, nomor baris `:nnnn` = `L_presensi.php`. Rujukan repo relatif ke root repo.
7. Aturan dalam dokumen ini bersifat **LOCKED** setelah v1.0: setiap "WAJIB", "DILARANG", dan AC harus dipenuhi apa adanya.
   Deviasi hanya dengan persetujuan tertulis reviewer CR (dicatat di progres + laporan).

---

## 1. Scope 5B

### 1.1 Di dalam scope 5B

| Task | Judul | Inti 5B | QASMTASK |
|---|---|---|---|
| D-06 | Precedence status khusus — sumber cuti & TB nyata | `SumberCutiTbFase4` (implementasi `SumberCutiTbInterface`) dari `riwayat_cuti` (status 1, jenis 1..6) + `StatusTugasBelajarInterface::rentangBebasPresensi` (Fase 4 M5); mengganti `SumberCutiTbBelumTersedia` lewat `Config\Presensi::$sumberCutiTb`; varian massal untuk laporan; test precedence & kombinasi bertumpuk per skenario. Library murni `Presensi/Tukin` **tidak diubah** (precedence sudah di CR-037/039) | 081 |
| D-09a | Nilai uang makan slip (blocker go-live F5-1) | `UangMakanSlipPresensi` (implementasi `UangMakanSlipInterface`) port `Lsl_gaji.php:260-414` [K]; mengganti `UangMakanBelumTersedia` lewat `Config\Presensi::$sumberUangMakan`; snapshot 5A tidak berubah | 084 (bagian tarif), 086 (uang makan slip) |
| D-08 | Laporan Tukin | API daftar pegawai (`list_lt`, `list_lt_il`), laporan per pegawai (`laporan_tukin/{nip}`: JSON + PDF), rekap (`laporan_tukin_us_skp` / `_il`: JSON + XLSX + PDF terbatas); lingkup role ditegakkan **server**; adapter massal + uji volume; ekspor sinkron (ADR-029/034) | 083 (backend) |
| D-09b | Rekap kehadiran & rekap uang makan | API rekap kehadiran per unit/satker (`rekap`, + XLSX ringkas & harian `xls-full`; role 1, 3, 4) dan rekap uang makan format legacy **`NIP \| TGL_ABSEN`** tanpa nominal (F5B-20) dengan hari dibayar menurut aturan slip (F5B-2) (+ XLSX); role 1, 3 | 084 |
| D-08/DBV-024 | Penyesuaian Tukin per NIP | Tabel admin pengganti daftar NIP hard-code (`:11755-11806`) + CRUD role 1 + efek keterangan/kolom di laporan & rekap tanpa mengubah rumus murni (F5B-14) | 083 |
| Q5A-13 | Bahan cross-check rumus Tukin | Tabel aturan rekap vs teks DoD/spek + butir yang perlu dicek ke aturan resmi (§7.5) untuk keputusan Horii | 079 (butir prasyarat) |

### 1.2 DI LUAR scope 5B

| Di luar scope | Milik | Catatan |
|---|---|---|
| FE Laporan Tukin, rekap kehadiran, rekap uang makan (tabel densitas tinggi, color-coding TL/PSW/TK/TPM/TPP, filter laporan) | **5C** (F5-21) | 5B hanya API + berkas ekspor; respons API memuat kode status per hari agar 5C dapat mewarnai baris |
| D-13 lengkap (12 skenario sebagai regression suite terpisah, QASMTASK-088), QA Lapis 1, uji paritas UP-1 **pada data nyata**, **sign-off Horii**, `ACTIVE_PHASE` 4→5, penghapusan flag `rilis` | **5C** | 5B menyediakan test per skenario backend (§7.7) yang menjadi bagian suite |
| Map picker tile pihak ketiga (Q5A-10) | 5C | — |
| FE 5A (M7) dan verifikasi 5A (M8) | **5A** | 5B **DILARANG** menyentuh `frontend/src/features/presensi/**` |
| Perubahan rumus di `Libraries/Presensi/Tukin/**` | Butuh keputusan Q5B-01 + CR | 5B tidak mengubah rumus; bila cross-check Q5A-13 memutus perubahan → CR terpisah setelah 5B |
| Varian tukin `original`/`perbaikan` dan "Tukin Lama" (`laporan_tukin_us`) | Tidak dibuat (F5B-4, F5B-5) | — |
| Isi daftar NIP penyesuaian tukin hard-code legacy (`:11755-11806`, `:6526-6560`) | Tidak di-commit (data pribadi) | Mekanismenya **masuk 5B** lewat tabel DBV-024 (F5B-14); pengisian riwayat dilakukan admin saat cutover (F5B-21) |
| Penghapusan baris "Pajak" & "Tunjangan Pajak" bagian TK slip (legacy `0cf7e67`) | **CR 5A terpisah** (keputusan Horii 28-10-2026; dibuat reviewer CR) | Acuan Slip Gaji kini `0cf7e67` (§9) |
| Aktivasi otomatis status TB/CLTN (cron) | Fase 6 H.4 (PRD Fase 4 v1.4 §14.6 #2) | Tukin membaca rentang tanggal `riwayat_tb`, bukan status pegawai, sehingga tidak bergantung cron |
| Notifikasi FCM presensi | Fase 6 | — |
| Modul C (cuti/TB/IB/PMK) | Fase 4 | **DILARANG** mengubah berkas Fase 4 (§3.2) **kecuali** metode massal aditif TB (F5B-18, milestone M1a) |
| MT-00x / MTC-0xx | Fase tersendiri (MT) | DILARANG dimasukkan ke task list, prasyarat, status, atau ringkasan 5B |

---

## 2. Aturan proyek WAJIB

Seluruh PRD 5A §2 (2.1–2.8) **berlaku apa adanya** untuk 5B, dengan penyesuaian berikut.

### 2.1 Commit, key, branch
- Branch `fase5b/<key>-<ringkas>` dari **`origin/main` terbaru**; satu key per branch; commit Bahasa Indonesia
  `feat(presensi): MAKE-039 …`; **DILARANG** menyebut AI/assistant/tool apa pun dan **DILARANG** trailer `Co-Authored-By`.
- Coder **tidak** push ke `main`; lapor SHA + URL run CI `quality-gate` hijau; merge oleh reviewer CR (izin Horii).

### 2.2 Kemurnian (lanjutan PRD 5A §2.8)
- `Libraries/Presensi/Tukin/**` dan `Libraries/Presensi/SlipGaji/**` **tidak diubah** di 5B (AC-5B.X1).
- Logika murni baru 5B (aturan hari uang makan, klasifikasi rekap kehadiran, penyesuaian Tukin, penanda tangan) ditaruh di folder
  murni baru **`Libraries/Presensi/Rekap/`** dan **ikut dipindai** `PemindaiKemurnian` (tambah pemindaian, aditif, tanpa
  melonggarkan larangan). Kode ber-DB/berkas tetap di `TukinData/`, `Slip/`, dan folder baru `Laporan/` (§2.3).

### 2.3 Tempat kode 5B (milik 5B, dibuat baru kecuali disebut)

| Folder / berkas | Isi |
|---|---|
| `backend/app/Libraries/Presensi/TukinData/` | `SumberCutiTbFase4` (baru), `SumberCutiTbMassalInterface` (baru), `TukinDataService::siapkanBanyak()` (aditif) |
| `backend/app/Libraries/Presensi/Slip/` | `UangMakanSlipPresensi` (baru), `TarifUangMakan` (ber-DB, baru) |
| `backend/app/Libraries/Presensi/Rekap/` | **Murni**: `HariUangMakan`, `KlasifikasiKehadiran`, `PenyesuaianTukin` (penerapan keterangan/kolom penyesuaian), `PenandaTangan` (penentuan kasus blok tanda tangan dari data yang sudah dimuat), VO hasil |
| `backend/app/Libraries/Presensi/Laporan/` | `DaftarPegawaiTukinService`, `LaporanTukinService` (per pegawai), `RekapTukinService`, `RekapKehadiranService`, `RekapUangMakanService`, `CakupanLaporan` (lingkup role), `PejabatPenandaTangan` (ber-DB), `PenyesuaianTukinService` (CRUD), `EksporTukinXlsx`, `EksporRekapXlsx`, `PdfLaporanTukin`, `PdfRekapTukin` |
| `backend/app/Models/Presensi/` | `PenyesuaianTukinModel` (`extends BaseAuditableModel`, DBV-024) |
| `backend/app/Controllers/Api/Presensi/` | `LaporanTukinController`, `RekapController`, `PenyesuaianTukinController` (baru) + bagian baru di `README.md` |
| `backend/app/Database/Migrations/<tanggal>_CreatePenyesuaianTukin.php`, `backend/docs/db-review/DBV-024-*.md`, `backend/docs/db-review/sql/DBV-024-*.sql` | DBV-024 (§7.6) |
| `backend/app/Config/RoutesPresensiLaporan.php` | Route 5B (berkas baru, pola M5/M6 Fase 4: satu berkas per blok) |
| `backend/app/Views/pdf/` | `laporan_tukin_pegawai.php`, `rekap_tukin.php` (baru) |
| `backend/tests/Presensi/{TukinData,Slip,Laporan,Rekap}/`, `backend/tests/unit/Presensi/Rekap/` | Test 5B |

---

## 3. Aturan berkas & tabrakan

### 3.1 Berkas MILIK 5B
Semua berkas baru di §2.3, `backend/docs/progress/05-Presensi.md` (bagian 5B), bagian 5B di
`backend/app/Libraries/Presensi/README.md` dan `TukinData/README.md`.

### 3.2 DILARANG disentuh
- Berkas Fase 4 (PRD 5A §3.2 + seluruh `Libraries/Layanan/**`, `Models/Layanan/**`, `Controllers/Api/Layanan/**`,
  `Config/RoutesLayanan*.php`, `Config/Cuti.php`, `Config/Layanan.php`, `Interfaces/Layanan/**`, `tests/Layanan/**`,
  `docs/fase4/**`). 5B hanya **memanggil** `service('statusTugasBelajar')` dan **membaca** tabel `riwayat_cuti`,
  `riwayat_tb`, `riwayat_tb_perpan`. **Satu pengecualian (F5B-18):** milestone M1a menambah metode massal **aditif** di
  `Libraries/Layanan/Tb/BebasPresensiTb.php`, `Libraries/Layanan/Tb/StatusTugasBelajar.php`, dan
  `Interfaces/Layanan/StatusTugasBelajarInterface.php` (+ test di `tests/Layanan/**`), tanpa mengubah perilaku metode
  tunggal; dikerjakan pemilik M5 Fase 4.
- `Libraries/Presensi/Tukin/**`, `Libraries/Presensi/SlipGaji/**` (§2.2).
- `frontend/**` (FE = 5C; FE 5A milik sesi 5A).

### 3.3 Berkas BERSAMA — hanya ADITIF, commit TERPISAH

| Berkas | Perubahan 5B yang diizinkan |
|---|---|
| `backend/app/Config/Presensi.php` | Ganti nilai `$sumberCutiTb` → `SumberCutiTbFase4::class` (M1) dan `$sumberUangMakan` → `UangMakanSlipPresensi::class` (M2), masing-masing di commit tersendiri; tambah konstanta batas ekspor (§7.3.8) |
| `backend/app/Config/Routing.php` | Tambah satu entri `RoutesPresensiLaporan.php` |
| `backend/tests/feature/KontrakMatriksRoleTest.php` | Baris kontrak route 5B (prefiks `presensi` sudah tercakup) di commit yang sama dengan route-nya |
| `tests/_support/Libraries/PemindaiKemurnian.php` atau test pemanggilnya | Tambah pemindaian `Libraries/Presensi/Rekap` |
| `backend/app/Libraries/Presensi/TukinData/TukinDataService.php` | Metode baru `siapkanBanyak()`; `siapkan()` tidak berubah perilaku |
| `backend/app/Libraries/Presensi/Slip/SlipGajiService.php` | **Tidak diubah** (aturan snapshot 5A sudah final); hanya bila test memaksa — BERHENTI & lapor |
| `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`, `NipReferenceRegistryTest`, `TabelAnakNipTest` (+ cakupan `NipCascadeService`) | Klasifikasi kolom `penyesuaian_tukin.nip` (FK, ikut koreksi NIP) di commit migration DBV-024; `NipCascadeService::FASE` tetap 5 |
| Berkas Fase 4 TB (§3.2 pengecualian) | Hanya metode massal aditif M1a (F5B-18), commit tersendiri |

---

## 4. Glosarium & label

| Istilah | Arti di 5B |
|---|---|
| **Rekap Tukin** | Perhitungan potongan Tukin per pegawai per periode = `KalkulasiTukin::hitung()` (port rekap `laporan_tukin_us_skp`, K-1) |
| **Periode Tukin** | `PeriodeTukin::dariBulan(T, B, periodeBkn)`: baris `bkn_periode_ekinper` atau 16 bulan lalu – 15 bulan B; Januari mulai 16 Desember [K `:11852-11876`] |
| **Bulan laporan** | Kunci API `bulan=YYYY-MM` = bulan B periode Tukin (bukan bulan kalender data) |
| **Bulan kehadiran (uang makan)** | Bulan kalender 1..akhir bulan, dipotong hari ini [K `Lsl_gaji.php:273-279`]; slip periode N memakai bulan N−1 (F5-1) |
| **Hari uang makan** | Hari kerja (bukan libur/akhir pekan) yang dibayar uang makan menurut aturan §7.2.2 |
| **IL (Instansi Lain)** | Pegawai yang mutasi jabatan berlakunya `pegawai_mutasi_jabatan.jenis_jabatan = 2` (dipekerjakan/diperbantukan) [K `L_employee.php:6630-6636`] |
| **Lingkup laporan** | `unit_satker` = `0` (kementerian) / `<id_unit>` / `sat_<id_satker>` [K `V/list_lt.php:26-47`]; role 3 dipaksa ke satkernya oleh **server** [V2] |
| **Massal** | Satu permintaan laporan untuk banyak pegawai; WAJIB dimuat per batch dengan jumlah kueri tetap (bukan N+1) |
| **Sumber cuti/TB** | `SumberCutiTbInterface` 5A; implementasi 5B membaca data Fase 4 |

Label [K]/[V2]/[I] dan istilah 5A (PRD 5A §4) tetap berlaku.

---

## 5. Keputusan & pemetaan DoD

### 5.1 Pola umum (dari PRD 5A §5.1, tetap)
Hak **LIHAT** ikut Matriks v2; alur **hitung** ikut legacy rekap [K] (K-1); deviasi disengaja [V2]; DoD Trello dipenuhi lewat
pemetaan §5.3; keputusan final lama tetap berlaku. Hal di luar ini → eskalasi.

### 5.2 Keputusan yang SUDAH berlaku (diwarisi, tidak dibuka ulang)

| ID | Isi | Dipakai di |
|---|---|---|
| K-1 | Tukin ikut **rekap** `laporan_tukin_us_skp` bila berbeda dengan laporan per pegawai `laporan_tukin_skp` (user 02-10-2026) | §7.1, §7.3 |
| F5-1 | Uang makan slip = bulan N−1, snapshot saat slip dibuka, acuan `Lsl_gaji.php` legacy terbaru; **WAJIB tersambung sebelum go-live** | §7.2 |
| F5-16 | Jam kerja & rentang puasa dari `web_config` grup "Jam Kerja & Puasa" | §7.4 |
| F5-21 | FE Laporan Tukin dkk. di **5C** | §1.2 |
| F5-24 | PPPK dikenali lewat `id_jenis_pegawai = 6` (`Jabatan::JENIS_PEGAWAI_PPPK`), bukan nama | §7.2.3 |
| SG-2 | Akses slip (tidak berubah di 5B) | — |
| Fase 4 §8 | `riwayat_cuti.status = 1` final, `id_jenis_cuti` 1..6; status 3/4 **bukan** cuti untuk presensi; `StatusTugasBelajarInterface` | §7.1 |
| ADR-029 / ADR-034 | Ekspor laporan besar **sinkron** (tanpa queue); PDF dibatasi baris, XLSX seluruh lingkup | §7.3.8 |

### 5.3 Keputusan 5B (LOCKED — Horii 28-10-2026, lewat reviewer CR)

Nomor F5B-n = nomor butir Q5B-nn di §13. **[CATAT]** = Horii meminta tabel "v2 dipakai | legacy [K] | gap" di bagian
spesifikasinya (dirujuk kolom terakhir).

| ID | Keputusan (mengikat) | Dipakai di |
|---|---|---|
| F5B-1 | Rumus Tukin **tetap** K-1 (rekap). Biro SDM/Keuangan mengecek X-1, X-5, X-6, X-10, X-12 (§7.5) **sebelum go-live**; selisih → CR setelah 5B. **Blocker go-live** | §7.5, §11.2 |
| F5B-2 | **Satu aturan hari uang makan = aturan slip (F5-1, §7.2.2)** untuk slip dan rekap uang makan. **[CATAT]** | §7.2.2, §7.4.2 |
| F5B-3 | Penanda TB melekat uang makan **ikut legacy [K]**; konfirmasi Biro Keuangan sebelum go-live | §7.2.2, §11.2 |
| F5B-4 | Hanya varian `skp`; `bulan` ≤ 2024-03 → 422 `periode_tidak_didukung` | §7.3.1 |
| F5B-5 | "Tukin Lama" (`laporan_tukin_us`) **tidak** di-port | §6.3 |
| F5B-6 | Laporan IL (`list_lt_il`, rekap IL, `rekap_uma_il`) **hanya role 1** [V2] | §6 |
| F5B-7 | Laporan per pegawai ikut legacy: role 1/4/5 semua NIP; role 3 satker akun (unit bila akun tanpa satker) dicek **server**; role 2/6/7 hanya NIP sendiri (NIP lain → 403, tidak dialihkan); role 8 → 403 | §6.1, §7.3.6 |
| F5B-8 | Layar laporan per pegawai hanya persen. PDF per pegawai = **render legacy persis [K]**: tanpa kolom & tanpa baris total uang makan; "Rp." hanya di baris konket yang dibayar (sel ke-6), nilainya dengan aturan slip (F5B-2) per bulan kalender. Nominal Tukin tidak ditampilkan; `gaji_pegawai` di DoD 083 = teks DoD lebih luas dari legacy. **[CATAT]** | §7.3.2 |
| F5B-9 | Uji paritas UP-1 data nyata oleh QA (5C/cutover). **Blocker go-live** | §10, §11.2 |
| F5B-10 | Rekap kehadiran role **1, 3, 4** [K]: role 3 dipaksa satkernya, role 4 semua lingkup; TB didahulukan dan jam dari `web_config` [V2] | §6.2, §7.4.1 |
| F5B-11 | Ekspor **tidak** diaudit; target uji volume §7.3.7. Opsi tindak lanjut: audit setiap unduhan rekap Tukin/uang makan (data keuangan) | §8 |
| F5B-12 | Daftar NIP tester legacy tidak di-port | §7.3.1 |
| F5B-13 | Blok tanda tangan XLSX rekap di-port [K] dengan data jabatan v2; bila pejabat tidak ditemukan, blok **tetap ada** dan menampilkan status kasus (daftar eksplisit + test). **[CATAT]** | §7.3.4 |
| F5B-14 | Tabel **penyesuaian Tukin per NIP** dikelola admin, pengganti hard-code `:11755-11806`; skema **DBV-024**; efek lewat adapter/presenter tanpa mengubah rumus murni. **[CATAT]** | §7.3.10, §7.6 |
| F5B-15 | Checksum legacy produksi (lanjutan Q5A-14). **Blocker go-live** | §11.2 |
| F5B-16 | PDF rekap per unit/satker dengan batas baris (ADR-034); lingkup besar → 422. **[CATAT]** | §7.3.5 |
| F5B-17 | `id_jenis_cuti` di luar 1..6 → `DataTukinTidakSah`; cuti tumpang tindih → baris `id_riwayat_cuti` terbesar. **[CATAT]** | §7.1.2 |
| F5B-18 | Fase 4 menambah **metode massal aditif** di `BebasPresensiTb`/`StatusTugasBelajar` (tanpa mengubah perilaku tunggal) = prasyarat D-06 massal (M1a; pelaksana: pemilik M5 Fase 4) | §7.1.3, §10 |
| F5B-19 | Uang makan PTT / golongan di luar I–IV = 0 [K/I]; konfirmasi Biro Keuangan sebelum go-live | §7.2.3, §11.2 |
| F5B-20 | Rekap uang makan = format legacy **`NIP \| TGL_ABSEN`** saja (tanpa nominal, tanpa ringkasan golongan; dikonfirmasi Horii); hari dibayar = aturan slip (F5B-2). **[CATAT]** | §7.4.2 |
| F5B-22 | Sumber data Plt v2 untuk blok tanda tangan dicek di M0 (laporan M0) | §7.3.4, §10 |
| F5B-23 | Key **MAKE-044** (M1a, TB massal Fase 4) dan **MAKE-045** (M4, CRUD penyesuaian) | §10 |
| F5B-21 | Riwayat penyesuaian Tukin legacy (periode ≥ 2024-04) diisi admin lewat CRUD saat cutover; data **tidak** di-commit (data pribadi) | §7.3.10 |
| — | Slip Gaji: acuan legacy kini **`0cf7e67`** (hapus baris "Pajak" & "Tunjangan Pajak" bagian TK) lewat **CR 5A terpisah**; di luar 5B | §9 |

### 5.4 Pemetaan teks DoD Trello → arti v2 (acuan penilaian QA)

| Teks DoD / Trello | Arti v2 (yang diuji) | Dasar |
|---|---|---|
| 081 "Tugas Belajar penuh → bebas total" | Tanggal di `rentangBebasPresensi` → `tugas_belajar = true` → hari bebas; **pemutihan TB**: bila hari kerja terakhir yang diproses TB, total potongan = 0 [K `:12726-12735`, `:13026-13030`] | CR-039, Fase 4 §6.7.5 |
| 081 "Cuti Tahunan/Alasan Penting/Melahirkan → tidak dipotong" | Hari cuti jenis 1/4/5 bebas potongan harian [K `:12807-12823`]. Cuti melahirkan tetap dapat memicu potongan **periode** 40/70/80 bila jumlah anak > 3 (baris berikut) | CR-039 |
| 081 "Cuti Sakit > 14 hari → potongan bertahap" | Mulai hari kerja cuti sakit **berurutan ke-15**: 1% × 0,2 per hari; urutan hanya direset baris presensi; lookback 1 periode [K `:12796-12804`, `:12686-12697`] | CR-039 |
| 081 "Cuti Besar/Melahirkan → potongan 40/70/80% per bulan" | **Arti v2 = rekap (K-1):** cuti **besar** 5 / 5 / 5 per periode (ke-2 bila `lama_cuti` > 35, ke-3 bila > 65); cuti **melahirkan** 40 / 70 / 80 **hanya bila jumlah anak > 3**. Teks DoD menyamakan keduanya → keliru terhadap rekap. **Termasuk bahan cross-check F5B-1 (X-5, X-6)** | K-1, `:12470-12575` |
| 081 "Konket `affect_tukin=1` → hadir tanpa potongan" | Hari konket `status = 'V'`, `affect_tukin = 1` bebas potongan (5A adapter) [K `:12343-12348`, `:12828-12846`] | 5A AC-TK.5 |
| 081 "kombinasi bertumpuk (libur+cuti+konket) — tidak ada potongan dobel" | Satu status per hari menurut precedence masa kerja → libur/akhir pekan → TB → cuti → konket → presensi [K `:12739-12849`]; diuji per kombinasi (§7.1.5) | CR-037/039 |
| 083 "Filter: month, unit_satker, jenis_pegawai, tukinver, format (print/pdf/xls-full)" | `bulan`, `unit_satker`, `jenis_pegawai[]`, `format`: JSON (pengganti print HTML, dicetak FE 5C), `pdf`, `xlsx`. **`tukinver` hanya `skp`** (F5B-4). "xls-full" = XLSX rekap lengkap | §7.3 |
| 083 "list_lt/list_lt_il hanya role 1,3" | `list_lt` route role 1, 3; role 3 **server** dibatasi satkernya (legacy hanya JS, celah L5B-02); `list_lt_il` hanya role 1 (F5B-6) | Matriks, §6 |
| 083 "laporan_tukin: non-admin dibatasi NIP sendiri; role 3 satker sendiri" | Role 2/6/7 hanya NIP sendiri (NIP lain → **403**, bukan redirect); role 3 lewat `PegawaiScope`; role 1/4/5 semua; role 8 → 403 | Matriks, `Presensi.php:246-298` |
| 083 "Query gabungan dh_online + riwayat_* + gaji_pegawai" | Data = `dh_online`, `absen_ijin`, `riwayat_cuti`, `riwayat_tb(_perpan)`, `riwayat_skp_periodik`, `riwayat_keluarga`/`detail_anak`, `riwayat_kp`, `riwayat_mutasi_jabatan`, `bkn_periode_ekinper`, `hari_libur`, `penyesuaian_tukin` (DBV-024). **`gaji_pegawai` tidak dipakai** — rekap legacy tidak membacanya; teks DoD lebih luas dari legacy (F5B-8) | `:12169-12397` |
| 083 "Export PDF besar SYNCHRONOUS (bukan background queue)" | Sinkron; PDF rekap per unit/satker dengan batas baris (pola ADR-034); XLSX seluruh lingkup; uji volume | ADR-029, ADR-034 |
| 083 "Tabel tetap terbaca & color-coding baris (MTC-034)" | **5C** (FE). 5B menyertakan kode status per hari/per pegawai di JSON | F5-21 |
| 084 "Rekap per unit/satker" | Rekap kehadiran & uang makan dengan `unit_satker` | §7.4 |
| 084 "Rekap per golongan PPPK" | **Tidak dibuat** (F5B-20): rekap uang makan legacy hanya `NIP \| TGL_ABSEN`; filter `jenis_pegawai[]` (termasuk PPPK) tetap ada. Teks DoD lebih luas dari legacy — dicatat sebagai gap, disetujui Horii | §7.4.2 |
| 084 "Tarif uang makan dari web_config" | Tarif dipakai **nilai uang makan slip** (D-09a): PNS `uang_makan_gol_1..4` (`WebConfigService`), PPPK `gol_pppk.uang_makan` [K `Lsl_gaji.php:390-414`]; rekap uang makan tidak bernominal (F5B-20) | §7.2.3 |
| 084 "Akses terbatas role 1,3" | Rekap uang makan: route role 1, 3 (role 3 dipaksa satkernya); rekap kehadiran: 1, 3, 4 [K] (F5B-10); IL hanya 1 (F5B-6) | §6.2 |
| 079 "cross-check rumus ke aturan resmi [TBD — blocker]" | Bahan §7.5 + keputusan F5B-1 (blocker go-live) | Q5A-13 |

---

## 6. Matriks role × endpoint 5B (v2)

Pola PRD 5A §6: prefix `api/v1/`, `jwt` di grup, `Role::filter(...)` per route, aturan rinci di service. Semua route di
**`Config/RoutesPresensiLaporan.php`**. Role: 1 Super Admin, 2 PNS, 3 Admin Satker, 4 Admin View Eselon I, 5 Menteri,
6 PTT, 7 PPPK, 8 Pimpinan. Urutan pemeriksaan: 401 → 403 role route → 403 role aksi (mis. NIP orang lain) → 404 pegawai
tidak ada → **403** target di luar cakupan role 3 → 422 validasi.

### 6.1 Laporan Tukin (D-08)

| Method & path | Route | Service | Legacy |
|---|---|---|---|
| `GET presensi/tukin/pegawai?bulan=&unit_satker=&jenis_pegawai[]=&search=&page=&per_page=` | 1, 3 | Role 3: `unit_satker` diabaikan, dipaksa `sat_<id_satker akun>` (tanpa satker → unit akun; tanpa keduanya → 403 fail-closed) | `list_lt` `Presensi.php:76`, `:3535-3587` |
| `GET presensi/tukin/pegawai-il?bulan=&search=&page=&per_page=` | **1** (F5B-6) | — | `list_lt_il` `Presensi.php:160`, `:3588-3627` |
| `GET presensi/tukin/laporan/{nip}?bulan=` | 1–7 | 2/6/7: `{nip}` ≠ NIP akun → 403; 3: satker akun (unit bila akun tanpa satker), target tanpa satker/unit **ditolak** (fail-closed); 1/4/5: semua (F5B-7) | `laporan_tukin/{nip}` `Presensi.php:244-401` |
| `GET presensi/tukin/laporan/{nip}/pdf?bulan=` | 1–7 | Sama | `:6476-6505` |
| `GET presensi/tukin/rekap?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=` | 1, 3 | Role 3 dipaksa satkernya | `laporan_tukin_us_skp` `Presensi.php:488` |
| `GET presensi/tukin/rekap/xlsx?…` | 1, 3 | Sama; seluruh lingkup | `:13139-13486` |
| `GET presensi/tukin/rekap/pdf?…` | 1, 3 | [V2] hanya `unit_satker` unit/satker + batas baris (F5B-16) | (legacy: cetak HTML) |
| `GET presensi/tukin/rekap-il[/xlsx]?bulan=` | **1** (F5B-6) | — | `laporan_tukin_us_skp_il` `Presensi.php:569` |
| `GET presensi/tukin/penyesuaian?nip=&bulan=&status=&page=&per_page=` | 1 | Daftar penyesuaian (F5B-14) | (legacy: hard-code `:11755-11806`) |
| `POST presensi/tukin/penyesuaian` | 1 | Tambah; validasi §7.3.10 | — |
| `PUT presensi/tukin/penyesuaian/{id}` | 1 | Ubah | — |
| `DELETE presensi/tukin/penyesuaian/{id}` | 1 | Hapus = status 10 (pola master) + audit | — |

### 6.2 Rekap kehadiran & uang makan (D-09b)

| Method & path | Route | Service | Legacy |
|---|---|---|---|
| `GET presensi/rekap-kehadiran?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=` | 1, 3, 4 (F5B-10) | Role 3 dipaksa satkernya; role 1/4 semua lingkup | `rekap` `Presensi.php:735-774`, `L_presensi.php:415-810` |
| `GET presensi/rekap-kehadiran/xlsx?…` | 1, 3, 4 | Ringkas (13 kolom + TB) | `V/rekap.php:90-133` |
| `GET presensi/rekap-kehadiran/xlsx-harian?…` | 1, 3, 4 | Grid harian (`xls-full`) | `L_presensi.php:824-1424` |
| `GET presensi/uang-makan/rekap?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=` | 1, 3 | Role 3 dipaksa satkernya; baris `{nip, tgl_absen}` (F5B-20) | `rekap_uma` `Presensi.php:845` (aturan hari F5B-2) |
| `GET presensi/uang-makan/rekap/xlsx?…` | 1, 3 | Dua kolom `NIP \| TGL_ABSEN` | `V/rekap_uma.php:89-110` |
| `GET presensi/uang-makan/rekap-il[/xlsx]?bulan=` | **1** (F5B-6) | — | `rekap_uma_il` `Presensi.php:944` |

### 6.3 Endpoint/route yang TIDAK dibuat di 5B
`laporan_tukin_us` (Tukin Lama, F5B-5), varian `tukinver=original|perbaikan` (F5B-4), method bertanggal/`_2`
legacy (Lampiran B L5B-10), route FE. Satu-satunya endpoint tulis 5B = CRUD penyesuaian Tukin (F5B-14); lainnya hanya baca.

### 6.4 Bentuk respons (pola Fase 3/4)
Envelope standar `{data, meta}`; daftar memakai `ListQuery` (`page`, `per_page` maks 100, 422 untuk param array tidak sah);
`bulan` wajib `YYYY-MM` (422 `errors.bulan`); `unit_satker` divalidasi ada di master (422 `errors.unit_satker`);
`jenis_pegawai[]` id yang ada di master (422). Berkas: `Content-Disposition: attachment` dengan nama tersanitasi, tanpa
data pribadi di nama berkas kecuali NIP pada PDF per pegawai (pola `FormatSlip::namaBerkasPdf`).

---

## 7. Spesifikasi per task

### 7.1 D-06 — Sumber cuti & TB nyata (`SumberCutiTbFase4`, MAKE-039)

**Tujuan.** Mengisi kunci harian `cuti` dan `tugas_belajar` yang diharapkan `KalkulasiTukin::hitung()` (README Tukin
"Kontrak data") dari data Fase 4, mengganti `SumberCutiTbBelumTersedia` (fail-closed 5A). Precedence sudah ada di library
murni; 5B **tidak** menulis ulang precedence.

#### 7.1.1 Kontrak
```php
namespace App\Libraries\Presensi\TukinData;

final class SumberCutiTbFase4 implements SumberCutiTbInterface, SumberCutiTbMassalInterface
{
    public function untuk(string $nip, string $awal, string $akhir): array;          // kontrak 5A, tidak berubah

    /** @param list<string> $nip @return array<string, array<string, array{tugas_belajar?: true, cuti?: array{jenis: string, mulai: string, akhir: string, lama: int}}>> per NIP lalu per tanggal */
    public function untukBanyak(array $nip, string $awal, string $akhir): array;      // baru, untuk laporan massal
}
```
`untuk()` WAJIB = `untukBanyak([$nip], …)[$nip] ?? []` (satu jalur aturan).

#### 7.1.2 Cuti [K `:12201-12267`]
1. Baris `riwayat_cuti` dengan `status = 1` **saja** (0/2/3/4 diabaikan, Fase 4 §8) yang beririsan `[awal, akhir]`
   (`tgl_mulai ≤ akhir AND tgl_akhir ≥ awal`), urut `nip`, `tgl_mulai` naik.
2. Setiap tanggal kalender `tgl_mulai..tgl_akhir` (termasuk akhir pekan/libur — library yang melewati) yang jatuh di
   `[awal, akhir]` mendapat `cuti = {jenis, mulai: tgl_mulai, akhir: tgl_akhir, lama: lama_cuti}`.
3. `jenis` = kebalikan `StatusHarian::JENIS_CUTI` dari `id_jenis_cuti` (1 tahunan … 6 cltn). `id_jenis_cuti` di luar 1..6 →
   `DataTukinTidakSah` dengan NIP + id (F5B-17).
4. Dua cuti atau lebih pada tanggal yang sama → baris dengan **`id_riwayat_cuti` terbesar** menang (F5B-17).
5. Tidak ada cuti setengah hari (legacy & DDL `riwayat_cuti` tidak punya kolomnya; `D1:4396-4463`) — dicatat, bukan fitur.

**[CATAT] F5B-17 — jenis cuti tak dikenal & tumpang tindih**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| `id_jenis_cuti` ∉ 1..6 → `DataTukinTidakSah` (pegawai itu galat terkendali, tampil di rekap dengan `galat`) | Tidak ada cabang untuk id lain; hari itu tetap `continue` setelah blok cuti → **bebas tanpa potongan** (`L_presensi.php:12796-12823`) | Legacy diam-diam membebaskan; v2 menolak. Dampak praktis kecil: `jenis_cuti` v2 hanya berisi id 1..6 (seed Fase 4 [K]) dan `riwayat_cuti.id_jenis_cuti` NOT NULL |
| Tumpang tindih pada tanggal sama → `id_riwayat_cuti` terbesar | Baris diurut `nip, tgl_mulai ASC` lalu diekspansi per hari; baris belakangan **menimpa** → `tgl_mulai` terbesar menang; seri `tgl_mulai` tanpa urutan pasti (`L_presensi.php:12206-12211`, `:12230-12262`) | Kriteria berbeda bila cuti yang dibuat belakangan punya `tgl_mulai` lebih awal (id terbesar ≠ `tgl_mulai` terbesar). Fase 4 v2 menolak cuti tumpang tindih saat pengajuan (`CutiService`, PRD Fase 4 §6.2), sehingga kasus ini praktis hanya ada di data impor legacy |

#### 7.1.3 Tugas belajar
- Tanggal di `service('statusTugasBelajar')->rentangBebasPresensi($nip, $awal, $akhir)` → `tugas_belajar = true`
  (tanggal kalender, termasuk akhir pekan; library menangani pemutihan TB). Aturan TB = Fase 4 `BebasPresensiTb`
  (status 1, rentang TB + rentang perpanjangan, celah tidak bebas) = rekap [K `:12271-12333`].
- Varian massal WAJIB memuat TB banyak NIP dengan kueri tetap. **Prasyarat M1a (F5B-18):** Fase 4 menambah metode massal
  **aditif** — usulan kontrak (nama final ditetapkan pelaksana M1a dan dicatat di README Fase 4):
  ```php
  // App\Interfaces\Layanan\StatusTugasBelajarInterface (aditif)
  /** @param list<string> $nip @return array<string, list<string>> NIP → tanggal bebas presensi di [$dari, $sampai] */
  public function rentangBebasPresensiBanyak(array $nip, string $dari, string $sampai): array;
  ```
  Implementasi `StatusTugasBelajar` memuat `riwayat_tb` status 1 + `riwayat_tb_perpan` untuk semua NIP batch dalam satu
  kueri per tabel, lalu memakai aturan `BebasPresensiTb` yang sama (metode murni baru bila perlu). Metode tunggal tidak
  berubah perilaku; test baru membuktikan `rentangBebasPresensiBanyak([$a, $b], …)` = gabungan pemanggilan tunggal.
  5B memanggil metode ini lewat `service('statusTugasBelajar')`; DILARANG menyalin aturan TB ke kode Presensi.
- Potongan TB 25% dan `isPegTB` legacy **tidak** dipakai (rekap tidak menerapkannya, README Tukin).

#### 7.1.4 Konfigurasi & massal
- `Config\Presensi::$sumberCutiTb = SumberCutiTbFase4::class` (commit tersendiri). Setelah itu `TukinDataService::siapkan()`
  tidak lagi melempar `DataTukinBelumTersedia`.
- `TukinDataService::siapkanBanyak(list<string> $nip, int $tahun, int $bulan, ?string $hariIni)` →
  `array<string, ArgumenTukin|GalatDataTukin>`: satu kueri per sumber per batch (≤ 500 NIP/batch, konstanta), galat per
  pegawai **tidak** menggagalkan seluruh laporan (dilaporkan per baris, §7.3.5). `siapkan()` = jalur yang sama untuk satu NIP.

#### 7.1.5 Acceptance criteria D-06 (QASMTASK-081)
- **AC-D06.1** TB penuh: semua hari kerja periode dalam rentang TB → potongan 0, `alasan = pemutihan_tb`; TB berakhir di
  tengah periode lalu presensi normal → hari setelah TB dinilai presensi; celah antara TB dan perpanjangan **tidak** bebas.
- **AC-D06.2** Cuti tahunan / alasan penting / melahirkan / CLTN (masing-masing test terpisah) → hari cuti tanpa potongan harian.
- **AC-D06.3** Cuti sakit: 14 hari kerja berurutan → 0; hari ke-15 dan seterusnya → 1% × 0,2 per hari (test tepat 14/15);
  lookback lintas periode (14 hari di periode lalu + hari pertama periode ini) → hari pertama = ke-15; cuti lain/konket/
  libur di tengah urutan **tidak** memutus; baris presensi memutus.
- **AC-D06.4** Cuti besar: `lama_cuti` 35 / 36 / 65 / 66 → 1 / 2 / 2 / 3 kali potongan 5 pada periode yang sesuai.
- **AC-D06.5** Cuti melahirkan: anak 3 → tanpa potongan periode; anak 4 → 40 / 70 / 80 menurut periode ke-1/2/3.
- **AC-D06.6** Kombinasi bertumpuk (test terpisah per baris): libur + cuti; akhir pekan + TB; TB + cuti; cuti + konket;
  konket + presensi; cuti + presensi tercatat; libur + cuti + konket dalam satu rentang → **satu** status per hari sesuai
  precedence, tanpa potongan ganda.
- **AC-D06.7** Hanya `status = 1`: baris status 0/2/3/4 pada tanggal yang sama tidak mengubah hasil.
- **AC-D06.8** `id_jenis_cuti` di luar 1..6 → `DataTukinTidakSah` (tidak jatuh diam-diam ke "bebas").
- **AC-D06.9** `untukBanyak` = gabungan `untuk` per NIP (test properti pada data sintetis 3 NIP) dan jumlah kueri tetap
  terhadap jumlah NIP (hitung kueri lewat `DB::getConnectStats`/listener, bukan perkiraan).
- **AC-D06.10** `git diff origin/main -- backend/app/Libraries/Presensi/Tukin/` kosong; perubahan `backend/app/Libraries/Layanan/`
  hanya metode massal aditif M1a (commit tersendiri, test Fase 4 lama tetap hijau).

**Test wajib.** `tests/Presensi/TukinData/SumberCutiTbFase4Test.php`, `PrecedenceKombinasiTest.php` (dataProvider →
`#[DataProvider]`, satu kasus per skenario), `CutiSakitLookbackTest.php`, `CutiBesarMelahirkanPeriodeTest.php`,
`TukinDataMassalTest.php`.

**DILARANG (D-06):** mengubah library `Tukin`; membaca `pegawai.id_jenis_status` sebagai pengganti rentang TB/CLTN;
memakai status cuti selain 1; menyalin aturan `BebasPresensiTb` ke kode Presensi; mengubah perilaku metode tunggal Fase 4.

**Milestone M1a (prasyarat, F5B-18).** Metode massal aditif Fase 4 di atas + test di `tests/Layanan/Tb/` (kesetaraan dengan
metode tunggal, jumlah kueri tetap, batas `MAKS_HARI_RENTANG` tetap berlaku). Tidak ada perubahan skema.

---

### 7.2 D-09a — Nilai uang makan slip (`UangMakanSlipPresensi`, MAKE-040)

**Tujuan.** Menutup blocker go-live F5-1: implementasi asli `UangMakanSlipInterface::hitung(nip, periodeKehadiran)`.
Aturan snapshot/penulisan **sudah** di 5A `SlipGajiService` (PRD 5A §7.3.7; sama dengan legacy `1c3142f`
`Lsl_gaji.php:156-205`) dan **tidak diubah**.

#### 7.2.1 Kontrak
Port `Lsl_gaji::getUangMakan` (`:260-383`) + `_getTarifUangMakan` (`:390-414`) [K]. Hasil `UangMakanSlip(totalSen, hari,
tarifSen)`; `null` **hanya** bila pegawai tidak ada. Tarif ≤ 0 → `UangMakanSlip(0, 0, 0)` (legacy mengembalikan total 0;
aturan snapshot 5A lalu mempertahankan nilai lama bila ada).

#### 7.2.2 Aturan hari (murni, `Rekap\HariUangMakan`) [K `Lsl_gaji.php:273-378`]
Untuk setiap tanggal bulan kehadiran 1..akhir bulan, dipotong `hariIni` (WIB, disuntik):
1. Libur (`hari_libur` aktif yang beririsan bulan) atau Sabtu/Minggu → lewati.
2. Tanggal TB → lewati; bila potongan TB bulan itu > 0, set penanda `isPegTB` (**melekat sampai akhir bulan** [K], F5B-3).
   Potongan TB per (TB, bulan) = 25, atau 0 bila TB itu mulai setelah tanggal 1 di bulan tersebut; bulan perpanjangan
   memakai `tgl_mulai` TB induk [K `:311-344`].
3. `isPegTB` aktif atau tanggal cuti (`riwayat_cuti` status 1, **jenis apa pun**) → lewati.
4. Konket `status = 'V'` dengan `affect_tukin = 1`: **dibayar hanya bila `kategori = 13`**, selain itu lewati [K `:370-373`].
5. Selain itu dibayar bila `dh_online` tanggal itu punya `jam_masuk` **atau** `jam_pulang` [K `:375-377`].

Hari dihitung; `totalSen = hari × tarifSen` (integer; tanpa pajak, tanpa pembulatan — legacy bruto `:381`).
**Sumber TB untuk uang makan** = baris `riwayat_tb` (status 1, `tgl_mulai ≤` akhir bulan, `tgl_akhir ≥` awal bulan atau
`tgl_akhir_perpan ≥` awal bulan) + `riwayat_tb_perpan` [K `Lsl_gaji.php:328-344`], bukan `rentangBebasPresensi`, karena
aturan 25%/0 butuh `tgl_mulai` per TB. Pembaca baris ini milik 5B (`Slip/`), hanya membaca tabel Fase 4.
Aturan yang sama dipakai rekap uang makan (F5B-2); perbedaannya dengan `rekap_uma` legacy ada di tabel [CATAT] §7.4.2.

#### 7.2.3 Tarif [K `Lsl_gaji.php:390-414`] (`TarifUangMakan`, ber-DB)
- PPPK (`id_jenis_pegawai = 6`, F5-24): `riwayat_mutasi_jabatan` `status = 1`, `jenis_jabatan = 1`, `tmtsk ≤` tanggal 1
  bulan kehadiran, urut `tmtsk DESC` → `id_gol_pppk` → `gol_pppk.uang_makan`.
- Lainnya: `riwayat_kp` `status = 1`, `tmtsk ≤` tanggal 1 bulan kehadiran, urut `tmtsk DESC` → `trim(gol)` ∈
  {I, II, III, IV} → `web_config` `uang_makan_gol_1..4` lewat `WebConfigService`.
- Golongan lain/tanpa baris/tarif belum diatur → 0 (PTT praktis 0 [K/I], F5B-19; konfirmasi Biro Keuangan sebelum go-live).
- Rupiah → sen secara eksak (`web_config` tipe rupiah bulat; `gol_pppk.uang_makan` DOUBLE: nilai tak bulat sampai sen →
  `DataTukinTidakSah`-setara di namespace Slip, dicatat log tanpa nominal).

#### 7.2.4 Acceptance criteria D-09a
- **AC-D09a.1** `Config\Presensi::$sumberUangMakan = UangMakanSlipPresensi::class`; slip dibuka → snapshot terisi
  (`uang_makan`, `uang_makan_hari`, `uang_makan_periode`) sesuai tiga cabang PRD 5A §7.3.7 (test ulang dengan sumber asli).
- **AC-D09a.2** Aturan hari (satu test per butir §7.2.2): libur/akhir pekan; TB 25% + penanda melekat; TB mulai setelah
  tanggal 1 → penanda tidak aktif; cuti jenis apa pun; konket kategori 13 dibayar vs kategori lain tidak; presensi hanya
  masuk / hanya pulang dibayar; tanpa jam tidak dibayar; dipotong hari ini.
- **AC-D09a.3** Tarif PNS gol I–IV dari `web_config` (ubah nilai → hasil berubah, regression pola AC-TK.4); PPPK dari
  `gol_pppk` menurut mutasi terakhir ≤ tanggal 1; golongan "IV/a"-style tidak cocok → 0 [K, `trim(gol)` harus tepat].
- **AC-D09a.4** Slip periode Januari memakai Desember tahun lalu (lintas tahun).
- **AC-D09a.5** Progres: blocker go-live "nilai uang makan slip" ditandai **selesai** di `05-Presensi.md` setelah merge.

**Test wajib.** `tests/unit/Presensi/Rekap/HariUangMakanTest.php`, `tests/Presensi/Slip/UangMakanSlipPresensiTest.php`,
`TarifUangMakanTest.php`, `UangMakanSnapshotAsliTest.php`.

---

### 7.3 D-08 — Laporan Tukin (MAKE-041)

#### 7.3.1 Daftar pegawai (`list_lt`) [K `:3535-3587`, `V/list_lt.php`]
- Pegawai `status = 1` (saat ini) dengan jabatan berlaku `jenis_jabatan = 1`; filter `unit_satker`, `jenis_pegawai[]`
  (PNS 1, PTT 2, PPPK 6 — id dari master), `search` (nama/NIP); urut `kelas_jabatan DESC, gol_ruang DESC,
  tmtsk_pangkat ASC, id_satker ASC` [K `L_employee.php:6605-6611`]. **Paginasi server** [V2] (legacy memuat semua ke
  DataTable).
- Kolom item: `nip, nama, gol_ruang, pangkat, jabatan, eselon, unit, satker, pendidikan {strata, jurusan, institusi},
  jenis_pegawai, aksi {lihat_laporan: bool}`.
- Pilihan bulan: 2020-07 … bulan berjalan; setelah tanggal 15 termasuk bulan depan [K `Presensi.php:85-139`] — dikirim di
  `meta.bulan_tersedia` mulai **2024-04** (F5B-4); `bulan` ≤ 2024-03 → 422 `periode_tidak_didukung` "Laporan periode
  sebelum April 2024 tersedia di arsip SIMPEG lama." pada semua endpoint D-08.
- Daftar NIP tester legacy tidak di-port [V2, F5B-12].

#### 7.3.2 Laporan per pegawai (`laporan_tukin/{nip}`)
- Hitung `KalkulasiTukin::hitung(...TukinDataService::siapkan(nip, T, B, hariIni))` dengan `hariIni` = tanggal WIB server
  (laporan periode berjalan dipotong hari ini [K `:11881-11885`]).
- **[V2] (K-1):** legacy per pegawai memakai `laporan_tukin_skp` (`:6510`) yang berbeda dengan rekap pada 4 aturan (README
  Tukin "Perbedaan rekap…"); v2 memakai **satu** kalkulasi rekap untuk layar per pegawai dan rekap, sehingga angka
  keduanya selalu sama. Dicatat sebagai deviasi.
- Respons: identitas (`nip, nama, gol_ruang, satker, jabatan, kelas_jabatan, predikat_skp`), `periode {awal, akhir,
  sumber: bkn|bawaan}`, `harian[] {tanggal, status, jam_masuk, jam_pulang, potongan_bp, keterangan}` (status = kode
  `StatusHarian`, dipakai FE 5C untuk color-coding), `jumlah {TL1..3, PSW1..3, TPM, TPP, TK, CS}`, `rincian {TL, PSW, TK, TA,
  CUTI}`, `total_presensi, total_skp, total_potongan` (persen 2 desimal dari basis poin), `alasan`.
- Layar (JSON) per pegawai: **persen saja** [K] — tanpa uang makan, tanpa nominal Tukin (F5B-8).
- PDF (TCPDF lewat `Pdf::renderer()`, ADR-033): A4 potret; identitas + tabel harian (tanggal, jam masuk, jam pulang, ket.,
  potongan) + total pengurangan Tukin (+ penyesuaian, §7.3.10) [K isi `print/laporan_tukin_skp_pdf.php:55-257`]; nama
  berkas `tukin_<nip>_<YYYY-MM>.pdf` tanpa angka acak.
- **Uang makan di PDF (F5B-8 = render legacy persis [K]).** PDF **tidak** punya kolom "UANG MAKAN" dan **tidak** punya
  baris "TOTAL UANG MAKAN". Satu-satunya tampilan uang makan = teks "Rp. <nilai>" pada **baris konket** yang dibayar uang
  makan, di sel ke-6 tanpa header (baris konket: tanggal + keterangan `colspan="4"` + sel Rp) [K `:190-197`]. Baris
  "TOTAL PENGURANGAN TUKIN" tetap. Nilai Rp memakai **aturan slip** (F5B-2):
  1. Untuk setiap tanggal konket di periode Tukin, kelompokkan menurut **bulan kalender** tanggal itu (periode 16–15 terbelah
     dua bulan kalender). Untuk setiap bulan kalender yang tersentuh, jalankan `Rekap\HariUangMakan` §7.2.2 atas bulan kalender
     itu (mulai tanggal 1, sehingga penanda TB melekat dan urutan hari sama dengan slip), dipotong `hariIni`.
  2. Tanggal konket dicetak "Rp." **hanya** bila tanggal itu termasuk hari dibayar hasil langkah 1 (praktis: konket `V`,
     `affect_tukin = 1`, kategori 13, bukan TB/penanda/cuti); nilai = `TarifUangMakan` bulan kalender tanggal itu.
  3. Hari presensi biasa **tidak** dicetak nominalnya (sel legacy dikomentari); tidak ada penjumlahan.

**[CATAT] F5B-8 — uang makan & nominal di laporan per pegawai**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Layar JSON hanya persen | Layar `laporan_tukin_skp.php` hanya persen; kolom uang makan dikomentari | Sama |
| PDF: tanpa kolom "UANG MAKAN", tanpa baris "TOTAL UANG MAKAN"; "Rp." hanya di baris konket yang dibayar (sel ke-6 tanpa header) | Header kolom "UANG MAKAN" (`print/laporan_tukin_skp_pdf.php:169`), sel uang makan baris presensi (`:228`), dan baris "TOTAL UANG MAKAN" (`:256-259`) berada **di dalam komentar HTML `<!--tr> … <tr-->`**; TCPDF memanggil `strip_tags()` yang membuang komentar, sehingga **tidak tercetak** (diverifikasi penyusun & reviewer CR 28-10-2026). Yang tercetak hanya "Rp." pada baris konket dengan `uang_makan > 0` (sel ke-6 tanpa header, `:190-197`) | Tampilan **sama** |
| Nilai "Rp." baris konket = aturan slip (F5B-2), dihitung per bulan kalender tanggal itu | Nilai dari `laporan_tukin_skp`: konket kategori 13 = tarif pegawai (`:7089-7101`, `:7435-7444`), tanpa penanda TB melekat | Hanya **sumber nilai** (aturan slip): tanggal konket setelah penanda TB aktif di bulan kalender yang sama tidak dicetak Rp; tarif per bulan kalender tanggal itu |
| Nominal Tukin tidak ditampilkan | Nominal tidak tampil (kelas jabatan hanya identitas) | Sama |
| Data `gaji_pegawai` tidak dipakai | Laporan/rekap tidak membaca `gaji_pegawai` | DoD 083 "query gabungan … gaji_pegawai" lebih luas dari legacy |
- Galat terkendali adapter (`KonfigurasiTukinTidakLengkap`, `RentangPuasaGanda`, `DataTukinTidakSah`) → 422 dengan `code`
  snake_case (keputusan field `code` 27-10) dan pesan siap tampil; tidak pernah 500.

#### 7.3.3 Rekap (`laporan_tukin_us_skp`) [K `:11752-13492`]
- Populasi [K `L_employee.php:6398-6615`]: `status = 1`, atau `status = 2 AND tmt_status >` awal periode; `DATE(created_at)
  ≤` akhir periode; `jenis_pegawai[]`; atribut pangkat/jabatan/pendidikan berlaku pada periode (aturan F5-23 untuk TMT).
  Lingkup: `0` = semua; unit/satker = jabatan berlaku `jenis_jabatan = 1`.
- Per pegawai: `TukinDataService::siapkanBanyak` + `KalkulasiTukin::hitung`. Baris: `nip, nama, unit_es1, unit_es2,
  pengurangan {TL, PSW, TK, TA, CUTI, TB}, total_presensi, total_skp, total_potongan, keterangan` (= kolom XLSX legacy
  `:13306-13392`). Kolom TB: rekap legacy selalu 0 (TB tidak memotong) — tetap disertakan [K].
- `keterangan` dari `alasan` + status pegawai (mis. "Cuti di Luar Tanggungan Negara" bila `id_jenis_status = 3`
  [K `:12660`]) + keterangan penyesuaian dari tabel DBV-024 (§7.3.10, F5B-14).
- Pegawai yang gagal dihitung (galat terkendali) **tetap** tampil dengan `galat {code, message}` dan nilai kosong; ringkasan
  `meta.jumlah_galat`. Rekap tidak gagal total karena satu pegawai [V2].

#### 7.3.4 XLSX rekap [K `:13139-13486`] (`EksporTukinXlsx`, PhpSpreadsheet ADR-032)
- Satu sheet "Laporan Tunjangan Kinerja"; judul A1:Q6 "LAPORAN TUNJANGAN KINERJA [PNS/PTT/…]", nama lingkup, "Periode:
  <bulan>"; header baris 7–8: `NO | NAMA | NIP | UNIT ES.I | UNIT ES.II | JUMLAH PENGURANGAN (%) [TL|PSW|TK|TA|CUTI|TB] |
  TOTAL PENGURANGAN PRESENSI (%) | TOTAL PENGURANGAN SKP (%) | TOTAL POTONGAN (%) (PRESENSI + SKP) | KETERANGAN`
  (salah ketik "POTONGGAN" legacy diperbaiki [V2]); data mulai baris 9; legenda TL/PSW/TK/TA; blok penanda tangan (F5B-13,
  di bawah); A4 lanskap, fit to width.
- **Blok penanda tangan (F5B-13)** di kolom M baris data terakhir +2 (jabatan), +9 (nama), +10 (NIP) [K `:13426-13439`].
  Penentuan pejabat (`Laporan\PejabatPenandaTangan` ber-DB → `Rekap\PenandaTangan` murni) [K `:12018-12140`]:
  1. Lingkup `0` dan IL → jabatan & nama menteri dari `web_config` (`jabatan_menteri_full`, `nama_menteri`) [K `:12074-12080`].
  2. Lingkup satker/unit → jabatan struktural `struktur_jabatan.level_struktur = 3` (Inspektorat: 2) pada unit/satker itu,
     dengan aturan khusus satker kepegawaian untuk unit kepegawaian dan dua unit tertentu [K `:12019-12020`, `:12106-12107`]
     (id unit yang di-hard-code legacy dipindah ke konstanta bernama + dicek saat cutover); pejabat definitif =
     `pegawai_mutasi_jabatan` jabatan itu, pegawai `status = 1`, `tmtsk` terbaru; tidak ada → Plt pada tanggal cetak
     (port `getPLT`, `function_helper.php:1946`; memakai data/pola Plt v2 yang sudah ada — diverifikasi di M0).
  3. Setiap hasil memiliki `kasus` (enum) dan teks blok yang **selalu dicetak**:

| Kasus | Jabatan dicetak | Nama / NIP dicetak |
|---|---|---|
| `definitif` | jabatan struktural | nama bergelar / NIP pejabat |
| `plt` | "Plt. <jabatan>" | "Plt: <nama bergelar>" / NIP Plt |
| `jabatan_kosong` | jabatan struktural | "Jabatan kosong (belum ada pejabat definitif/Plt)" / kosong |
| `pejabat_tidak_ditemukan` | "Pejabat tidak ditemukan" | kosong / kosong |
| `menteri` | `jabatan_menteri_full` | `nama_menteri` / kosong |
| `menteri_belum_diatur` | "Data menteri belum diatur di Web Config" | kosong / kosong |

**[CATAT] F5B-13 — blok tanda tangan**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Blok selalu dicetak dengan teks kasus (tabel di atas) | Variabel `$pym` diisi bila jabatan & pejabat/Plt ditemukan; bila tidak, sel jabatan/nama/NIP **kosong** tanpa keterangan (`L_presensi.php:12018-12050`, `:12101-12140`) | v2 memberi status kasus agar pembaca tahu alasannya (keputusan Horii) |
| Plt pada tanggal cetak | `getPLT($idJabatan, false, date("Y-m-d"))` — tanggal hari ini, bukan periode (`:12041`) | Sama [K]; pejabat bisa berbeda dengan pejabat saat periode berlangsung |
| Menteri dari `web_config` | Sama (`:12074-12080`) | v2 menambah kasus `menteri_belum_diatur` |
- Nama berkas `laporan_tukin_<lingkup>_<YYYY-MM>.xlsx` (ekstensi tunggal, content type xlsx benar [V2]; legacy
  `.xls.xlsx`).
- Varian IL: populasi IL, tanpa filter unit/jenis pegawai [K `:13787-13811`], penanda tangan menteri dari `web_config`.

#### 7.3.5 PDF rekap [V2, F5B-16]
PDF lanskap A4 kolom sama dengan XLSX, **hanya** `unit_satker` unit/satker, maksimal `Config\Presensi::$pdfRekapMaksBaris`
(bawaan mengikuti uji volume, awal 1.700 = ADR-034); di atas batas / lingkup `0` / IL → 422 `errors.unit_satker` "Persempit
lingkup atau unduh XLSX.". Penolakan dihitung dengan `COUNT` sebelum kalkulasi. Blok tanda tangan sama dengan XLSX.

**[CATAT] F5B-16 — format rekap**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| JSON (dicetak FE 5C), XLSX seluruh lingkup, PDF per unit/satker berbatas | `format=xls` → XLSX PhpSpreadsheet; selain itu **cetak HTML** `print/laporan_tukin_us_skp.php` (`L_presensi.php:13139-13486`, `Presensi.php:488-568`); tidak ada PDF rekap | PDF rekap = fitur baru [V2] (DoD 083 meminta pdf); cetak HTML legacy digantikan cetak FE dari JSON |

#### 7.3.6 Lingkup role (`CakupanLaporan`) — semua endpoint D-08/D-09b
- Role 1: semua lingkup. Role 3: `unit_satker` dipaksa satker akun (tanpa satker → unit akun; tanpa keduanya → 403) —
  menutup celah legacy L5B-02. Role 4/5 (hanya `laporan/{nip}`): semua NIP [K Matriks]. Role 2/6/7: hanya NIP sendiri.
- Per pegawai role 3 memakai `PegawaiScope::bolehLihat` (sama dengan modul lain); rekap role 3 memakai lingkup satker.

#### 7.3.7 Kinerja (WAJIB)
- Satu permintaan rekap = jumlah kueri **tetap** terhadap jumlah pegawai (batch 500); tidak ada kueri per pegawai per hari.
- Uji volume `#[Group('volume')]` (pola ADR-034, skip kecuali `RUN_VOLUME_TEST=1`): data sintetis 5.000 pegawai × 2 periode
  presensi. Target (F5B-11): rekap JSON halaman 1 (100 baris) ≤ 5 s; XLSX kementerian ≤ 60 s & ≤ 256 MB; PDF satker
  1.700 baris ≤ 30 s & ≤ 256 MB. Hasil dicatat di ADR baru **ADR-035 "Ekspor Laporan Tukin sinkron"** (diusulkan M5).
- Paginasi JSON rekap: kalkulasi hanya untuk pegawai di halaman yang diminta (urutan populasi stabil dari SQL).

#### 7.3.8 Konstanta `Config\Presensi` (aditif)
`$tukinBatchNip = 500`, `$pdfRekapMaksBaris = 1700`, `$bulanLaporanMulai = '2020-07'` (dipakai bila Q5B-04 memilih
alternatif), override `.env` tervalidasi (pola `angkaPositifDariEnv`).

#### 7.3.9 Acceptance criteria D-08 (QASMTASK-083)
- **AC-D08.1** Route × role 1–8 sesuai §6.1 (403 role lain); role 3 dengan `unit_satker` satker lain/`0` → tetap satkernya
  (tidak bocor); role 3 tanpa satker & unit → 403; 2/6/7 NIP lain → 403; NIP tidak ada → 404.
- **AC-D08.2** Angka laporan per pegawai = baris pegawai yang sama di rekap (bulan & data sama).
- **AC-D08.3** Filter `bulan`, `unit_satker`, `jenis_pegawai[]` (kombinasi) mengubah populasi sesuai §7.3.3; validasi 422.
- **AC-D08.4** Periode berjalan dipotong hari ini (`hariIni` disuntik); periode BKN tidak standar dipakai.
- **AC-D08.5** XLSX: header & urutan kolom persis §7.3.4 (dibaca ulang PhpSpreadsheet di test), nama berkas & content type.
- **AC-D08.6** PDF per pegawai dan PDF rekap (batas baris 422, lingkup `0` 422). PDF per pegawai: teks tidak memuat
  "UANG MAKAN" sebagai header/total; "Rp." hanya pada baris konket kategori 13 yang dibayar (test: konket kategori 13 →
  ada Rp; konket kategori lain → tidak; konket kategori 13 setelah penanda TB aktif → tidak; konket di periode 16–15 yang
  melintasi dua bulan kalender dengan tarif berbeda → tarif masing-masing bulan).
- **AC-D08.7** Galat satu pegawai tidak menggagalkan rekap; tarif `web_config` belum lengkap → semua baris galat
  `konfigurasi_tukin_tidak_lengkap` (bukan 0).
- **AC-D08.8** Ubah tarif `TL1/PSW1` lewat `WebConfigService` → rekap & laporan langsung berubah (regression Fase 2).
- **AC-D08.9** Uji volume ada dan terdokumentasi (ADR-035), jumlah kueri tetap terhadap jumlah pegawai.

- **AC-D08.10** Blok tanda tangan: satu test per kasus §7.3.4 (definitif, Plt, jabatan kosong, pejabat tidak ditemukan,
  menteri, menteri belum diatur); blok selalu ada di XLSX & PDF rekap.
- **AC-D08.11** Penyesuaian (§7.3.10): baris aktif yang mencakup bulan laporan → `penyesuaian` + keterangan di laporan,
  PDF, rekap, XLSX; `total_potongan` hasil `KalkulasiTukin` **tidak** berubah; status 2/10 diabaikan; CRUD role 1 saja
  (403 role lain), validasi & audit; `bulan` ≤ 2024-03 → 422.

**Test wajib.** `tests/Presensi/Laporan/{DaftarPegawaiTukin, LaporanTukinPegawai, LaporanTukinPdf, RekapTukin,
RekapTukinXlsx, RekapTukinPdf, RekapTukinIl, CakupanLaporan, RoleMatriksLaporan, PenandaTanganRekap, PenyesuaianTukinCrud,
PenyesuaianTukinEfek, RekapTukinVolume (group volume)}Test.php`; `tests/unit/Presensi/Rekap/{PenandaTangan,
PenyesuaianTukin}Test.php`.

#### 7.3.10 Penyesuaian Tukin per NIP (F5B-14, DBV-024)

**Pengganti** daftar NIP hard-code legacy. Dikelola **role 1 (Super Admin)** lewat CRUD §6.1; setiap create/update/delete
diaudit (`BaseAuditableModel` → `audit_logs` before/after, ADR-011).

- **Data per baris:** NIP, rentang bulan Tukin berlaku (`bulan_mulai`..`bulan_akhir`, `YYYY-MM`), persen penyesuaian
  (negatif = pengurangan, mis. −20 / −10), alasan (teks, mis. "Predikat Kinerja Sangat Kurang"), status 1/2/10.
- **Validasi (422 per field):** NIP ada di `pegawai`; `bulan_mulai ≤ bulan_akhir`, keduanya ≥ 2024-04 (F5B-4); persen
  desimal maks 2 angka di belakang koma, ≠ 0, −100..100; alasan wajib ≤ 255 karakter; **tidak boleh tumpang tindih** dengan
  baris aktif lain milik NIP yang sama (diperiksa di bawah kunci baris `FOR UPDATE` per NIP).
- **Efek (adapter/presenter, rumus murni tidak berubah):** setelah `KalkulasiTukin::hitung()`, `Rekap\PenyesuaianTukin`
  menambahkan ke baris laporan/rekap `penyesuaian {persen, alasan}` dan awalan keterangan
  "Penyesuaian Tukin <alasan> <persen>% (sebelum potongan lain)." [K bentuk teks `:13064-13120`]; PDF per pegawai
  mencetak total sebagai "<total>%<penyesuaian>" [K `print/laporan_tukin_skp_pdf.php:254`]. **`total_potongan` tidak
  diubah** dan tidak ada nominal yang dihitung.

**[CATAT] F5B-14 — penyesuaian Tukin**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Tabel `penyesuaian_tukin` dikelola role 1 + audit | Array NIP & daftar periode di-hard-code di kode (`L_presensi.php:11755-11806`; per pegawai `:6526-6560`); diubah developer lewat rilis kode | Data pribadi keluar dari kode; perubahan tercatat |
| Efek = keterangan + kolom `penyesuaian`; total potongan tetap | Hanya **teks keterangan** "Tukin disesuaikan −20% (sebelum potongan lain)" / "Penyesuaian Tukin – Predikat Kinerja … −20%/−10%" (`:13064-13120`); angka potongan tidak diubah (penyesuaian diterapkan di luar SIMPEG) | Sama secara angka; v2 menambah kolom terstruktur `penyesuaian` |
| Persen bebas (−100..100), alasan bebas | Hanya −20 dan −10, teks per periode di-hard-code | v2 lebih umum; nilai lain sah bila diisi admin |
| Riwayat periode ≥ 2024-04 diisi admin saat cutover (F5B-21) | Daftar terakhir di kode mencakup 2-2024..6-2024 | Data legacy periode 2024-04..06 **tidak** di-commit (data pribadi); diisi lewat CRUD/skrip cutover di luar repo |

---

### 7.4 D-09b — Rekap kehadiran & rekap uang makan (MAKE-042)

#### 7.4.1 Rekap kehadiran [K `L_presensi.php:415-810`, `V/rekap.php`]
- Bulan **kalender** (1..akhir, dipotong hari ini) [K `:444-455`]; hari kerja = Senin–Jumat tanpa `hari_libur`.
- Klasifikasi per hari kerja, **urutan pertama yang cocok** (murni `Rekap\KlasifikasiKehadiran`):
  1. [V2] **TB** (`rentangBebasPresensi`) → `TB` (legacy HTML tidak menangani TB sehingga hari TB jatuh ke TK; ekspor
     harian legacy memeriksa TB lebih dulu `:1083-1144`) — F5B-10.
  2. Cuti (`riwayat_cuti` status 1, jenis apa pun) → `C` + tidak hadir [K `:549`].
  3. Konket `status = 'V'` (affect_tukin apa pun) kategori 8 → `D` (dinas luar), 9 → `C`, 10 → `S` (sakit), 13 → `L` +
     hadir; kategori lain → tidak masuk kolom mana pun [K `:652-675`].
  4. Tanpa jam masuk & pulang → `TK`.
  5. Selain itu hadir (`HD`); TL/PSW dihitung dengan jam kerja **dari `web_config` F5-16 + `rentang_puasa`** [V2]
     (legacy hard-code Ramadan 2026 `:621-622`) memakai aturan kompensasi yang sama dengan Tukin (`StatusHarian`,
     TL dihitung bila > 60, atau 31–60 dengan PSW > −60, atau 1–30 dengan PSW > −30 [K `:722-740`]).
- Kolom ringkas [K `V/rekap.php:114-133`]: `nama, nip, jabatan, hari_kerja, hadir, tl, psw, tk, sakit, dinas_luar,
  lain_lain, cuti, tidak_hadir` (+ `tb` [V2]).
- XLSX harian (`xls-full`) [K `:824-1424`]: `NO, NAMA, NIP, UNIT ES.I, UNIT ES.II, <satu kolom per tanggal>, KETERANGAN`, kode
  TB/CT/DL/SKT/LL/H/TK.
- Populasi = rekap Tukin §7.3.3 dengan batas bulan kalender [K `L_employee.php:6412-6416`].

#### 7.4.2 Rekap uang makan (F5B-2, F5B-20)
- **Bentuk [K]:** baris `{nip, tgl_absen}` satu per hari dibayar, urut NIP lalu tanggal; XLSX dua kolom `NIP | TGL_ABSEN`
  [K `V/rekap_uma.php:89-110`]. **Tanpa** nominal, tarif, nama, atau ringkasan golongan (F5B-20).
- **Hari dibayar = aturan slip §7.2.2** (`Rekap\HariUangMakan`, F5B-2), bulan kalender `bulan`, dipotong hari ini.
  Konsekuensi: jumlah baris pegawai X bulan M = `uang_makan_hari` snapshot slip X periode M+1 (bila slip dibuka setelah
  bulan M selesai).
- Populasi: pegawai `status = 1` atau `status = 2 AND tmt_status >` awal bulan; jenis pegawai dari `jenis_pegawai[]`
  [K `L_employee.php:6412-6416`]; lingkup `unit_satker`. Varian IL hanya role 1 (F5B-6).

**[CATAT] F5B-2 — aturan hari rekap uang makan (v2 = aturan slip) vs `rekap_uma` legacy**

| v2 dipakai (aturan slip, `Lsl_gaji.php:260-383`) | Legacy `rekap_uma` [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| Konket `V` + `affect_tukin = 1` kategori **13 dibayar**, kategori lain tidak | Konket `V` + `affect_tukin = 1` kategori **apa pun** (termasuk 13) → tidak dibayar (`L_presensi.php:2885`, `:2933`) | Hari dinas luar kategori 13 kini muncul di rekap |
| TB: setiap baris `riwayat_tb` + `riwayat_tb_perpan`; penanda TB 25% **melekat** sampai akhir bulan (F5B-3) | TB = satu rentang per NIP `tgl_mulai`..maks(`tgl_akhir`, kolom `tgl_akhir_perpan`), baris belakangan menimpa; tanpa tabel perpanjangan, tanpa penanda (`:2862-2877`, `:2939-2950`) | Hari setelah TB berakhir di bulan yang sama tidak dibayar di v2; TB ganda/perpanjangan terbaca lengkap |
| Pegawai baru dalam bulan itu tetap dihitung dari presensinya | Kueri presensi mensyaratkan `pegawai.created_at <` awal bulan → pegawai baru 0 hari (`:2914`) | Pegawai baru kini mendapat hari dibayar |
| Urutan: libur/akhir pekan → TB → penanda/cuti → konket → presensi | Urutan: cuti/konket → TB → presensi (`:2931-2969`) | Tidak mengubah hasil kecuali butir di atas |
| Cuti status 1 jenis apa pun; presensi masuk **atau** pulang | Sama (`:2834`, `:2958`) | Sama |

**[CATAT] F5B-20 — bentuk rekap uang makan**

| v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
|---|---|---|
| `NIP \| TGL_ABSEN` (JSON + XLSX), tanpa nominal & ringkasan golongan | Tabel dua kolom `NIP \| TGL_ABSEN`, ekspor Excel/CSV di klien; tanpa tarif/pajak/nominal (`V/rekap_uma.php:89-110`, `L_presensi.php:2931-2979`) | Sama. DoD 084 "rekap per golongan PPPK" lebih luas dari legacy — tidak dibuat (dikonfirmasi Horii); nominal uang makan hanya di slip (D-09a) |
| XLSX dibangun server | Ekspor oleh DataTables di browser | Format berkas setara |

#### 7.4.4 Acceptance criteria D-09b (QASMTASK-084)
- **AC-D09b.1** Rekap per unit/satker: lingkup role 3 dipaksa; role 1 (dan role 4 untuk rekap kehadiran) lingkup
  `0`/unit/satker.
- **AC-D09b.2** Rekap uang makan: satu test per baris tabel [CATAT] F5B-2 (kategori 13 dibayar, TB + penanda melekat,
  perpanjangan TB, pegawai baru dalam bulan) — hasil mengikuti aturan slip.
- **AC-D09b.3** Bentuk `NIP | TGL_ABSEN` (JSON & XLSX) tanpa kolom nominal.
- **AC-D09b.4** Jumlah baris rekap uang makan pegawai X bulan M = `uang_makan_hari` snapshot slip X periode M+1.
- **AC-D09b.5** Klasifikasi kehadiran: satu test per cabang §7.4.1 + boundary TL/PSW 30/60 dengan jam `web_config`
  (normal & puasa, Jumat & bukan Jumat).
- **AC-D09b.6** Route × role 1–8 (403 role lain); XLSX ringkas, harian, uang makan dibaca ulang di test.

**Test wajib.** `tests/unit/Presensi/Rekap/KlasifikasiKehadiranTest.php`,
`tests/Presensi/Rekap/{RekapKehadiran, RekapKehadiranXlsx, RekapUangMakan, RekapUangMakanXlsx, RoleMatriksRekap}Test.php`.

---

### 7.5 Q5A-13 — Bahan cross-check rumus Tukin ke aturan resmi (untuk keputusan Horii)

Status saat ini: K-1 (02-10-2026) menetapkan v2 ikut rekap; README Tukin menyatakan "tidak ada pertanyaan terbuka untuk Biro
SDM/Keuangan" karena semua dijawab dari **kode**. Prasyarat DEV-006/`05-Presensi.md` meminta cross-check ke **aturan resmi**
(bukan kode). Tabel berikut = bahan untuk Biro SDM/Keuangan; penyusun **tidak** menilai kebenaran hukum (nomor peraturan
diisi pihak yang mengecek). Kolom "Risiko" = alasan butir itu perlu dicek.

| # | Aturan rekap (v2 saat ini) | Rujukan legacy | Teks spek/DoD | Risiko / yang perlu dicek |
|---|---|---|---|---|
| X-1 | Potongan presensi harian memakai **faktor 0,2** × tarif `web_config` (TL/PSW/TA/TK) | `:12854`, `:12889-13015` | DoD: "potongan sesuai tarif" | Apakah tarif di aturan sudah termasuk bobot presensi (mis. komponen presensi = sebagian tukin)? Faktor ganda = potongan terlalu kecil |
| X-2 | TL/PSW 1–30 / 31–60 / > 60 menit → golongan 1/2/3; kompensasi TL bila pulang lebih lambat (TL2 gugur bila PSW ≤ −60, TL1 bila ≤ −30) | `:12888-12931` | DoD sama (tanpa kompensasi) | Kompensasi tidak disebut DoD; cek apakah aturan mengenal penggantian jam |
| X-3 | TPM = TA + denda PSW; TPP = TA + denda TL; tanpa keduanya = TK | `:12853-12859` | DoD sama | — (cek nilai TA) |
| X-4 | Cuti sakit: 1% × 0,2 per hari mulai hari kerja ke-15 berurutan; urutan hanya direset presensi | `:12796-12804` | DoD "> 14 hari bertahap" | Besaran "bertahap" (1%/hari?) dan apakah cuti lain/libur memutus urutan |
| X-5 | Cuti besar: 5% per periode, hingga 3 periode (lama > 35 / > 65 hari) | `:12470-12523` | DoD "40/70/80% per bulan" | **Bertentangan dengan DoD.** Cek besaran resmi cuti besar |
| X-6 | Cuti melahirkan: 40/70/80% **hanya bila jumlah anak > 3** | `:12527-12575` | DoD "40/70/80% per bulan" (tanpa syarat anak) | Syarat anak ke-4 dst. dan besaran |
| X-7 | Cuti alasan penting **tidak** dipotong (jadwal 50% dihitung tetapi tidak dipakai) | `:12415-12466` | DoD "tidak dipotong" | Legacy pernah menyiapkan potongan > 14 hari — cek aturan |
| X-8 | CLTN tanpa potongan harian (pegawai CLTN umumnya tidak menerima tukin) | `:12807-12823`, `:12660` | — | Apakah pegawai CLTN dikeluarkan dari rekap? |
| X-9 | Tugas belajar **tanpa potongan 25%** + pemutihan bila hari kerja terakhir TB | `:12296-12330`, `:12726-12735` | DoD "TB penuh bebas total" | Legacy menghitung 25% tetapi tidak memakainya; uang makan slip memakai 25% sebagai penanda |
| X-10 | SKP periodik: baik/sangat baik/butuh perbaikan 0, kurang 16, lainnya/tidak ada 32 | `:12394-12410`, `:12636` | — | Besaran & kategori predikat (PermenPAN-RB kinerja) |
| X-11 | Tanpa potongan LKH | `:12654` | `web_config` punya tarif LKH | Apakah LKH memotong tukin |
| X-12 | Total potongan **tanpa batas 100%** | `:13053-13057` | — | Batas maksimum potongan |
| X-13 | Cuti sepanjang periode → SKP 0 | `:12664-12680` | — | — |
| X-14 | Pegawai baru (CPNS/PPPK) hari sebelum TMT tanpa potongan; pegawai keluar hari ≥ TMT dikecualikan [V2] | `:12699-12716` | — | — |

**Keputusan (F5B-1, Horii 28-10-2026 = opsi a):** rumus tetap K-1; Biro SDM/Keuangan mengecek **X-1, X-5, X-6, X-10, X-12**
sebelum go-live; selisih → CR terpisah setelah 5B (library murni + test). 5B tidak menunggu hasil cek. Hasil cek = **blocker
go-live** (§11.2), dicatat di progres per butir X.

---

### 7.6 Skema
- Data kalkulasi memakai tabel yang sudah ada (`dh_online`, `gaji_pegawai` DBV-023; `riwayat_cuti`, `riwayat_tb*` DBV-022;
  `absen_ijin`, `riwayat_kp`, `riwayat_mutasi_jabatan`, `gol_pppk`, `bkn_periode_ekinper`, `hari_libur`, `web_config`,
  `riwayat_skp_periodik`, `riwayat_keluarga`/`detail_anak` Fase 2/3).
- **DBV-024 = tabel baru `penyesuaian_tukin` (F5B-14).** Usulan skema untuk DB Validator (**tanpa** draf migration di PRD;
  DDL final + kueri audit + verifikasi ada di dokumen `backend/docs/db-review/DBV-024-penyesuaian-tukin-schema.md`):

| Kolom | Tipe (usulan) | Aturan |
|---|---|---|
| `id_penyesuaian_tukin` | INT UNSIGNED AUTO_INCREMENT | PK |
| `nip` | VARCHAR(30) NOT NULL | FK → `pegawai.nip` ON DELETE RESTRICT ON UPDATE RESTRICT; diklasifikasi di `TabelAnakNip` + cakupan `NipCascadeService` (ikut koreksi NIP) |
| `bulan_mulai` | DATE NOT NULL | Tanggal 1 bulan Tukin pertama; CHECK hari = 1 |
| `bulan_akhir` | DATE NOT NULL | Tanggal 1 bulan Tukin terakhir; CHECK `bulan_akhir ≥ bulan_mulai`, hari = 1 |
| `persen` | DECIMAL(5,2) NOT NULL | CHECK `persen <> 0 AND persen BETWEEN -100 AND 100` |
| `alasan` | VARCHAR(255) NOT NULL | Teks keterangan |
| `status` | TINYINT NOT NULL DEFAULT 1 | CHECK `status IN (1, 2, 10)` (aktif / tidak aktif / dihapus, pola master) |
| `created_at`, `updated_at` | DATETIME NULL | Stempel UTC oleh aplikasi (AS-12) |
| `created_by`, `updated_by` | INT NULL | `pengguna.id` pelaku (pola tabel admin v2; DBV memutus FK atau tidak) |

  Indeks: `KEY (nip, status, bulan_mulai)`; tumpang tindih rentang per NIP ditolak **aplikasi** (§7.3.10) karena tidak dapat
  dijaga UNIQUE. Collation `utf8mb4_unicode_ci`. Tidak ada impor dari legacy (sumber legacy berupa kode, bukan tabel);
  pengisian riwayat oleh admin saat cutover (F5B-21). Pertanyaan untuk DBV: tipe `bulan_*` (DATE vs `CHAR(7)`), FK
  `created_by`, ENUM `audit_logs.event` tidak perlu nilai baru (create/update/delete sudah ada).
- **Indeks tambahan** untuk kueri massal hanya bila uji volume M5 membuktikan perlu (kandidat: `riwayat_cuti (status,
  tgl_mulai, tgl_akhir)`, `absen_ijin (status, affect_tukin, date_start)`, `riwayat_tb (status, tgl_mulai)`) → amendemen
  DBV-024 atau DBV berikutnya, dengan `EXPLAIN` + angka uji volume.

### 7.7 Ringkasan test wajib 5B
D-06 §7.1.5, D-09a §7.2.4, D-08 §7.3.9, D-09b §7.4.4; ditambah `RoutePresensiLaporanKontrakTest` (route × role 1–8),
pemindai kemurnian `Rekap`, dan penjaga AC-5B.X1 (diff library murni kosong). Semua test ber-DB `extends
DatabaseTestCase`, data sintetis, waktu disuntik, DB scratch port 3307.

---

## 8. Aturan lintas modul

- **Tulis terbatas.** 5B hanya menulis (1) snapshot uang makan slip lewat jalur 5A yang sudah ada dan (2) tabel
  `penyesuaian_tukin` lewat CRUD role 1 dengan audit. Ekspor laporan **tidak** diaudit (pola ADR-034, F5B-11); opsi tindak
  lanjut tercatat: audit setiap unduhan rekap Tukin/uang makan (data keuangan).
- **Waktu.** "Hari ini" = tanggal Asia/Jakarta server (disuntik di test); tanpa zonasi satker (rekap legacy memakai jam
  server) [K].
- **Uang.** Integer sen untuk uang makan; integer basis poin untuk potongan Tukin; tampilan persen 2 desimal.
- **Keamanan.** Semua parameter lewat Query Builder + binding (menutup L5B-01); keluaran JSON di-escape FE; tidak ada
  `display_errors`; nama berkas tersanitasi.
- **Kontrak beku** (PRD 5A §2.7) tetap: `PegawaiScope`, `WebConfigService`, `HariLiburService`, `Pdf`, `KalkulasiTukin`,
  `StatusTugasBelajarInterface` hanya dipakai, tidak diubah.

---

## 9. Ketergantungan ke fase/paket lain

| Pihak | Butir | WAJIB disediakan 5B | DILARANG dikerjakan sekarang |
|---|---|---|---|
| **5A** | `SumberCutiTbInterface`, `UangMakanSlipInterface`, `TukinDataService`, `Config\Presensi` | Implementasi asli keduanya + `siapkanBanyak` | FE 5A, verifikasi 5A, mengubah aturan snapshot |
| **5C** | FE laporan/rekap (color-coding), D-13 suite lengkap, QA Lapis 1, uji paritas UP-1 data nyata, sign-off Horii, `ACTIVE_PHASE` 4→5 | API stabil + README endpoint + kode status harian; test per skenario 5B; prosedur paritas (§10 M7) | FE, menaikkan `ACTIVE_PHASE` |
| **Fase 4** | `StatusTugasBelajarInterface`, `BebasPresensiTb`, data `riwayat_cuti`/`riwayat_tb*` | Metode massal TB aditif (M1a, F5B-18); selain itu hanya dibaca | Mengubah perilaku metode Fase 4 yang ada |
| **Fase 6** | Cron status TB/CLTN (H.4) | — | — |
| **Cutover** | Paritas UP-1, checksum legacy (Q5A-14), nama id 6 (Q5A-25), tarif uang makan & `gol_pppk` terisi | Butir QC 5B di progres | Menjalankan impor/produksi |

**Acuan Slip Gaji baru (di luar 5B):** legacy `0cf7e67` (26-10-2026) menghapus baris "Pajak" dan "Tunjangan Pajak" dari
bagian TK slip (layar + PDF). Keputusan Horii 28-10-2026: slip v2 **ikut `0cf7e67`** lewat **CR 5A terpisah** (dibuat dan
diserahkan reviewer CR); acuan Slip Gaji kini `0cf7e67`, bukan `771f8b4`. 5B tidak mengubah slip. Perubahan `1c3142f`
(snapshot uang makan) sudah sama dengan PRD 5A §7.3.7.

---

## 10. Urutan kerja (runbook) dengan ⛔ TITIK TUNGGU

Aturan milestone = PRD 5A §10 (branch dari `origin/main` terbaru, satu key per milestone, berkas bersama commit terpisah,
gate cepat lokal, push, CI hijau, lapor, ⛔ tunggu merge reviewer CR).

| No | Milestone | Task | Key | Branch | Menunggu | Membuka jalan |
|---|---|---|---|---|---|---|
| M0 | Baca PRD v1.0 + inspeksi repo + laporan pemahaman | — | (tanpa commit) | — | PRD 5B v1.0 di `main`; FE 5A selesai | ⛔ reviewer CR menerima laporan M0 (termasuk sumber data Plt v2, F5B-22) |
| M1a | Metode massal TB aditif di Fase 4 (F5B-18) + test kesetaraan | D-06 (prasyarat) | MAKE-044 | `fase5b/make-044-tb-massal` | M0 | M1 |
| M1 | Sumber cuti/TB + massal + switch `$sumberCutiTb` + test precedence/kombinasi | D-06 | MAKE-039 | `fase5b/make-039-sumber-cuti-tb` | M1a merged | M5, M6 |
| M2 | Uang makan slip asli + tarif + switch `$sumberUangMakan` | D-09a | MAKE-040 | `fase5b/make-040-uang-makan-slip` | M0 | M5 (PDF uang makan), M6; **menutup blocker go-live F5-1** |
| M3 | Dokumen DBV-024 + kueri audit + draf migration → setelah disetujui: migration + registry NIP + test skema | DBV-024 | DBV-024 | `fase5b/dbv-024-penyesuaian-tukin` (PR `[DBV]`) | M0 | ⛔ **DB Validator approve DBV-024**, merge PR → M4 |
| M4 | CRUD penyesuaian Tukin (model, service, controller, route, audit, validasi) | D-08 (F5B-14) | MAKE-045 | `fase5b/make-045-penyesuaian-tukin` | M3 merged | M5 |
| M5 | Laporan Tukin: daftar, per pegawai (+PDF), rekap (+XLSX, PDF terbatas), IL, cakupan role, tanda tangan, efek penyesuaian, uji volume, ADR-035 | D-08 | MAKE-041 | `fase5b/make-041-laporan-tukin` | M1 + M2 + M4 merged | 5C FE laporan |
| M6 | Rekap kehadiran (+XLSX ringkas/harian) + rekap uang makan `NIP \| TGL_ABSEN` (+XLSX) | D-09b | MAKE-042 | `fase5b/make-042-rekap-kehadiran-uang-makan` | M1 + M2 merged | 5C FE rekap |
| M7 | Verifikasi 5B: AC, matriks 403, keterlacakan, README, progres, prosedur paritas UP-1 (membandingkan XLSX v2 vs ekspor legacy per periode), laporan akhir | semua 5B | MAKE-043 | `fase5b/make-043-verifikasi-5b` | M1–M6 merged | ⛔ sign-off reviewer CR **5B** (bukan Fase 5) |

**Boleh paralel:** M1a ∥ M2 ∥ M3 setelah M0; M5 ∥ M6 setelah syaratnya. Berkas milik 5B yang sama (`RoutesPresensiLaporan.php`,
`Config/Presensi.php`, README controller) → milestone yang merge belakangan rebase dan menambah aditif. **Tidak boleh:** M4
sebelum DBV-024 di `main`; M5 di-merge sebelum uji volume tercatat; perubahan Fase 4 di luar metode massal aditif.

**Isi laporan M0:** ringkasan per task (≤ 3 baris); berkas repo yang dipakai ulang; konfirmasi `Config\Presensi` masih
memakai implementasi fail-closed 5A; status 5A M7/M8 & Fase 4 di `main`; konfirmasi key MAKE-039..045/DBV-024; sumber data Plt
v2 untuk blok tanda tangan (§7.3.4); risiko kinerja yang terlihat.

---

## 11. Definition of Done 5B & exit criteria

### 11.1 5B DONE hanya bila SEMUA berikut terpenuhi
1. Seluruh AC §7 (AC-D06.*, AC-D09a.*, AC-D08.*, AC-D09b.*, AC-5B.X1) lulus dengan bukti test/run CI; setiap butir DoD
   backend QASMTASK-081/083/084 punya AC (Lampiran C).
2. CI `quality-gate` hijau dan gate penuh sekali sebelum merge terakhir; test Fase 0–4 + 5A tetap hijau.
3. `Config\Presensi::$sumberCutiTb` dan `$sumberUangMakan` menunjuk implementasi asli; blocker go-live F5-1 ditutup di
   progres.
4. Matriks §6: role tak berhak = 403 untuk setiap route 5B (test route × role 1–8).
5. Dokumentasi endpoint di `Controllers/Api/Presensi/README.md`; README `Libraries/Presensi` (+ `TukinData`, `Rekap`);
   ADR-035 di repo.
6. Semua butir ⛔ §13 yang jatuh tempo di 5B terjawab & tercatat; deviasi [V2] tercatat.
7. Sign-off reviewer CR untuk 5B setelah M7.
8. DBV-024 disetujui DB Validator (tanggal tercatat) sebelum migration di-merge; registry NIP hijau.

### 11.2 5B DONE ≠ Fase 5 DONE
Fase 5 DONE hanya setelah 5C (FE laporan, D-13 suite lengkap, QA Lapis 1, paritas UP-1 data nyata) dan **sign-off Horii**.
Laporan 5B **DILARANG** menyatakan "Fase 5 DONE" atau "siap go-live". Blocker go-live yang tersisa setelah 5B dicantumkan:
Q5A-08 (AS-06), Q5A-14/Q5B-15 (checksum), Q5A-25 (nama id 6), **Q5B-01** (hasil cek Biro SDM/Keuangan X-1, X-5, X-6,
X-10, X-12), **Q5B-03** (konfirmasi penanda TB uang makan), **Q5B-09** (paritas UP-1 data nyata), **Q5B-19** (uang makan
PTT/golongan di luar I–IV), serta isi tabel penyesuaian Tukin dan tarif uang makan/`gol_pppk` di produksi.

### 11.3 Status & progres
Aturan IN_PROGRESS dan format progres = PRD 5A §11.3; bagian baru "5B M0..M7" di `backend/docs/progress/05-Presensi.md`.
D-04, D-05, D-07 berpindah ke **DONE (backend)** hanya setelah 5B M1 merged + review (kalkulasi lengkap dengan cuti/TB).

### 11.4 Format laporan per milestone
```
Status: D-06 …, D-08 …, D-09a …, D-09b …
Branch/SHA/CI: <branch> <sha> <url run> (hijau/merah)
File utama: <daftar path>
Test & validasi: <berkas test + hasil>; precedence/kombinasi: <bukti>; uang makan aturan hari/tarif: <bukti>;
                 laporan = rekap: <bukti>; cakupan role 3: <bukti>; 403 per role: <bukti>; volume: <angka>
Berkas bersama: <berkas + SHA commit terpisah>, rebase terhadap <sha main>
Deviasi [V2] & keputusan: <daftar>
Blocker / pertanyaan terbuka: <daftar + rujukan §13>; blocker go-live: <daftar>
```

---

## 12. Checklist verifikasi sebelum serah

- [ ] `Libraries/Presensi/Tukin/**` dan `SlipGaji/**` tidak berubah; berkas Fase 4 hanya metode massal aditif M1a (commit tersendiri)
- [ ] Cuti hanya `status = 1`; jenis 1..6; di luar 1..6 fail-closed; tumpang tindih deterministik
- [ ] TB lewat aturan Fase 4 (`BebasPresensiTb`), tanpa salinan aturan; celah perpanjangan tidak bebas
- [ ] Massal: jumlah kueri tetap terhadap jumlah pegawai; galat per pegawai tidak menggagalkan rekap
- [ ] Uang makan: bulan N−1, aturan hari §7.2.2, tarif PNS `web_config` / PPPK `gol_pppk`, integer sen; snapshot 5A tidak berubah
- [ ] Laporan per pegawai = baris rekap; periode berjalan dipotong hari ini; galat adapter → 422 ber-`code`
- [ ] Role 3 dipaksa satkernya di server untuk semua laporan/rekap; 2/6/7 hanya NIP sendiri; 8 → 403
- [ ] XLSX header persis; nama berkas & content type benar; PDF batas baris & lingkup
- [ ] Uji volume tercatat (ADR-035)
- [ ] Blok tanda tangan selalu ada dengan teks kasus; satu test per kasus
- [ ] DBV-024 disetujui DB Validator; `penyesuaian_tukin.nip` di registry NIP; CRUD role 1 + audit; `total_potongan` tidak berubah oleh penyesuaian
- [ ] Rekap uang makan `NIP | TGL_ABSEN` dengan aturan hari slip; tanpa nominal
- [ ] Semua parameter via binding; tidak ada `display_errors`, tidak ada `transException(true)`
- [ ] Test DB `extends DatabaseTestCase`; waktu disuntik; data sintetis; DB scratch 3307; `.env` dev tidak disalin
- [ ] Commit Bahasa Indonesia `feat|fix(scope): KEY …`, tanpa trailer & tanpa penyebutan AI
- [ ] Tidak ada rahasia/IP/host internal/data pribadi nyata/detail celah di diff
- [ ] README endpoint, README modul, progres, ADR-035 diperbarui; tidak ada klaim "Fase 5 DONE"

---

## 13. Register keputusan & pertanyaan (v1.0)

Status 28-10-2026: Horii memutus **semua** butir ⛔ dan ◻ v0.1 (disampaikan reviewer CR); keputusan mengikat = §5.3 (F5B-n).
Tabel ini menyimpan riwayat pertanyaan + keputusan. Q5B-08 sempat dibuka ulang (temuan verifikasi setelah keputusan pertama, §7.3.2) dan
diputus ulang Horii 28-10-2026 = render legacy persis. Kolom **[CATAT]** menunjuk tabel "v2 dipakai | legacy [K] | gap". Hal baru di luar tabel →
BERHENTI dan eskalasi.

| ID | Pertanyaan (v0.1) | Keputusan Horii 28-10-2026 | [CATAT] | Tindak lanjut / batas |
|---|---|---|---|---|
| Q5B-01 | Cross-check rumus Tukin ke aturan resmi (Q5A-13) | **(a)** rumus tetap K-1; Biro SDM/Keuangan cek X-1, X-5, X-6, X-10, X-12; selisih → CR setelah 5B | — | ⛔ **Blocker go-live** (hasil cek per butir X dicatat di progres) |
| Q5B-02 | Aturan hari rekap uang makan ≠ slip | Satu aturan = aturan slip (F5-1) untuk slip & rekap; bagian "rekap bernominal + per golongan" **dibatalkan** (lihat Q5B-20) | §7.4.2 (F5B-2) | — |
| Q5B-03 | Penanda TB melekat uang makan | Ikut legacy [K] | — | ⛔ Konfirmasi Biro Keuangan sebelum go-live |
| Q5B-04 | Varian `original`/`perbaikan`, periode ≤ Maret 2024 | Hanya `skp`; ≤ 2024-03 → 422 `periode_tidak_didukung` | — | — |
| Q5B-05 | "Tukin Lama" `laporan_tukin_us` | Tidak di-port | — | — |
| Q5B-06 | Hak laporan IL | Hanya role 1 [V2] | — | — |
| Q5B-07 | Role pada laporan per pegawai | Ikut legacy: 1/4/5 semua NIP; 3 satker (unit bila akun tanpa satker) dicek server; 2/6/7 NIP sendiri (NIP lain → 403); 8 → 403 | — | — |
| Q5B-08 | Uang makan & nominal di laporan per pegawai | Putusan awal (b) "PDF memuat uang makan per hari + TOTAL" didasarkan pembacaan keliru; **putus ulang 28-10-2026 = render legacy persis [K]**: layar persen saja; PDF tanpa kolom/total uang makan, "Rp." hanya di baris konket yang dibayar (nilai aturan slip per bulan kalender); nominal Tukin tidak tampil; `gaji_pegawai` DoD = teks lebih luas | §7.3.2 (F5B-8) | AC-D08.6 |
| Q5B-09 | Uji paritas UP-1 data nyata | Tetap (QA 5C/cutover, prosedur M7) | — | ⛔ **Blocker go-live** |
| Q5B-10 | Hak & aturan rekap kehadiran | Role 1, 3, 4 [K] (3 dipaksa satker, 4 semua lingkup); TB dulu & jam `web_config` [V2] | — | — |
| Q5B-11 | Audit unduhan & target volume | Tidak diaudit; target §7.3.7 | — | Opsi tindak lanjut: audit setiap unduhan rekap Tukin/uang makan (data keuangan) |
| Q5B-12 | NIP tester legacy | Tidak di-port | — | — |
| Q5B-13 | Blok tanda tangan XLSX rekap | Port [K] dengan data jabatan v2; pejabat tidak ditemukan → blok **tetap ada** dengan teks kasus | §7.3.4 (F5B-13) | Daftar kasus + test AC-D08.10 |
| Q5B-14 | Penyesuaian Tukin per NIP | (b) tabel admin, DBV-024, efek lewat adapter/presenter | §7.3.10 (F5B-14) | Milestone M3 (DBV-024) + M4 (CRUD); kartu Trello terpisah dibuat reviewer CR |
| Q5B-15 | Checksum legacy produksi | Tetap | — | ⛔ **Blocker go-live** |
| Q5B-16 | PDF rekap | Per unit/satker, batas baris; lingkup besar → 422 | §7.3.5 (F5B-16) | — |
| Q5B-17 | Jenis cuti tak dikenal & tumpang tindih | Fail-closed `DataTukinTidakSah`; tumpang tindih → id terbesar | §7.1.2 (F5B-17) | — |
| Q5B-18 | TB massal | Fase 4 menambah metode massal aditif (prasyarat D-06 massal) | — | Milestone M1a; pelaksana pemilik M5 Fase 4 |
| Q5B-19 | Uang makan PTT / golongan di luar I–IV | 0 [K/I] | — | ⛔ Konfirmasi Biro Keuangan sebelum go-live |
| Q5B-20 | Bentuk rekap uang makan | Format legacy `NIP \| TGL_ABSEN` saja (tanpa nominal & ringkasan golongan); hari = aturan slip | §7.4.2 (F5B-20) | — |
| Q5B-21 | (baru v1.0) Riwayat penyesuaian periode ≥ 2024-04 dari daftar legacy | Diisi admin lewat CRUD saat cutover; tidak di-commit (data pribadi) | — | F5B-21; runbook cutover |
| Q5B-22 | (baru v1.0) Sumber data Plt v2 untuk blok tanda tangan | Dicek di M0 | — | F5B-22; tidak ada sumber setara → BERHENTI & eskalasi |
| Q5B-23 | (baru v1.0) Key milestone tambahan | MAKE-044 (M1a), MAKE-045 (M4) dipakai | — | F5B-23 |

## 14. Lampiran

### Lampiran A — Rujukan sumber

| Kode | Berkas | Bagian |
|---|---|---|
| Legacy (`0cf7e67`) | `controllers/hr/Presensi.php` (`list_lt` 76-159, `list_lt_il` 160-243, `laporan_tukin` 244-404, `laporan_tukin_us` 405-487, `laporan_tukin_us_skp` 488-568, `_il` 569-636, `rekap` 735-844, `rekap_uma` 845-943, `rekap_uma_il` 944-1012); `libraries/hr/L_presensi.php` (`rekap` 415-1424, `rekap_uma` ±2780-2983, `rekap_uma_il` 2984-3212, `list_lt` 3535-3587, `list_lt_il` 3588-3627, `laporan_tukin` 3628, `laporan_tukin_skp` 6510-7752, `laporan_tukin_us_skp` 11752-13492, `_il` 13494-15452); `libraries/hr/L_employee.php` (6398-6676); `libraries/hr/Lsl_gaji.php` (156-230, 244-414, 416-446); `views/hr/employee/presensi/{list_lt, list_lt_il, rekap, rekap_uma, rekap_uma_il}.php`, `print/laporan_tukin_skp_pdf.php`; `core/MY_Controller.php` 649-662; `views/template/sidebar.php` 10-31 | Constants legacy **tidak dibaca** (berisi kredensial) |
| DDL D1 | `simpeg01_struktur_lengkap_20261001.sql` | `riwayat_cuti` 4396-4463 |
| Repo v2 (`43023e6`) | `backend/app/Libraries/Presensi/{README.md, Tukin/*, TukinData/*, Slip/*}`; `backend/app/Config/Presensi.php`; `backend/app/Interfaces/Layanan/StatusTugasBelajarInterface.php`; `backend/app/Libraries/Layanan/Tb/{BebasPresensiTb, StatusTugasBelajar}.php`; migration `2026-10-26-100100_CreateRiwayatCutiFase4`, `2026-10-26-100200_CreateRiwayatTbIbFase4`, `2026-10-27-100000_CreatePresensiGaji`, `2026-09-25-110000_CreateMasterKenaikanPangkat`; `backend/app/Config/WebConfig.php` (uang makan gol 1–4); `backend/app/Libraries/Dashboard/Dokumen/DokumenService.php` (`PDF_MAKS_BARIS`); `docs/adr/ADR-032/033/034`; `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`; `docs/fase4/PRD_Fase4.md` v1.4; `backend/docs/progress/05-Presensi.md` | |
| Spek & Trello | `Bahan Baku SIMPEG/05-Presensi.md` (D-06, D-08, D-09, D-12, D-13), Matriks Role × Endpoint v2 Modul D, `SIMPEG_v2_ADR` (ADR-013, ADR-029); Trello DEV-006, DEV-006.2, QASMTASK-079..088, QAUI-005, QAFUNC-006 | |

### Lampiran B — Anomali legacy & perlakuan 5B

Butir keamanan ditulis **kode + jenis** saja; **rincian tidak ditulis di repo publik**.

| ID | Temuan legacy (ringkas) | Rujukan | Perlakuan 5B |
|---|---|---|---|
| L5B-01 | SQL dirangkai string (jenis: injection) pada filter laporan/rekap | `Presensi.php:272`, `:12014`, `:12062-12103`, `L_employee.php:6416` | [V2] Query Builder + binding |
| L5B-02 | Lingkup role 3 pada ekspor rekap hanya di klien (jenis: otorisasi hanya di klien) | `Presensi.php:405-636`, `V/list_lt.php:105` | [V2] `CakupanLaporan` server |
| L5B-03 | Laporan IL tanpa pembatasan role 3; menu ≠ route | `Presensi.php:160-243`, `:569-636`, `rekap_uma_il` | [V2] hanya role 1 (F5B-6) |
| L5B-04 | Cek satker role 3 pada laporan per pegawai lolos bila satker target kosong | `Presensi.php:285-298` | [V2] `PegawaiScope::bolehLihat` (fail-closed) |
| L5B-05 | Keluaran tanpa escape & `display_errors` di produksi (jenis: XSS tersimpan, kebocoran informasi) | `V/rekap.php:86-116`, `:3570`, `:3589` | [V2] JSON + FE escape; tanpa `display_errors` |
| L5B-06 | Kerja berat lewat GET tanpa batas waktu/memori (jenis: DoS) | `Presensi.php:488`, `:569` | [V2] batch, paginasi, batas PDF, uji volume |
| L5B-07 | Data pribadi (NIP) hard-code di kode untuk penyesuaian tukin | `:6526-6560`, `:11755-11806` | [V2] tabel DBV-024 (F5B-14) |
| L5B-08 | Ekstensi berkas ganda `.xls.xlsx` & content type keliru | `:13479-13481` | [V2] `.xlsx` + content type benar |
| L5B-09 | Indeks `$param` tidak terdefinisi di ekspor IL | `:15102-15109` | [V2] parameter tervalidasi |
| L5B-10 | Method bertanggal/`_2` & berkas cadangan tidak dipanggil controller aktif (`list_lt_20230818`, `rekap_20230818` masih dapat dirute, `laporan_tukin_skp_2`, `laporan_tukin_us_skp_2`, `_20240820`, `laporan_tukin_us_20230707`, `laporan_tukin_skp_20240509`, `rekap_full_xls_2222`) | `Presensi.php:11`, `:637`; L_presensi 1438, 3483, 5056, 9669, 15453, 17496, 18888 | Tidak di-port |
| L5B-11 | Laporan per pegawai (`laporan_tukin_skp`) ≠ rekap pada 4 aturan | README Tukin | [V2] K-1: satu kalkulasi rekap |
| L5B-12 | Rekap uang makan ≠ uang makan slip (kategori 13, TB, pegawai baru dibuat dalam bulan) | `:2834-2969` vs `Lsl_gaji.php:260-383` | [V2] satu aturan = aturan slip (F5B-2) |
| L5B-13 | Penanda TB melekat uang makan sampai akhir bulan | `Lsl_gaji.php:357-368` | [K] (F5B-3) + konfirmasi Biro Keuangan |
| L5B-14 | Rekap kehadiran HTML tidak menangani TB (hari TB → TK); Ramadan hard-code 2026 | `:621-622`, `:642-810` | [V2] TB dulu; `rentang_puasa` (F5B-10) |
| L5B-15 | Cuti alasan penting: jadwal potongan dihitung tetapi tidak dipakai; `$listHK` tak terdefinisi | `:12415-12466` | [K] tidak dipotong; bahan X-7 |
| L5B-16 | `markViewed` tanpa transaksi (riwayat QR ditulis walau update gagal) | `Lsl_gaji.php:203-217` | Sudah [V2] di 5A (transaksi) |

### Lampiran C — Matriks keterlacakan

#### C.1 DoD Trello → AC
| DoD | AC | | DoD | AC |
|---|---|---|---|---|
| 081-1 TB penuh bebas total | AC-D06.1 | | 083-1 filter month/unit_satker/jenis_pegawai/tukinver/format | AC-D08.3, F5B-4, §6.1 |
| 081-2 cuti tahunan/AP/melahirkan tidak dipotong | AC-D06.2 | | 083-2 list_lt/list_lt_il role 1,3 | AC-D08.1, F5B-6 |
| 081-3 cuti sakit > 14 bertahap | AC-D06.3 | | 083-3 laporan_tukin NIP sendiri / role 3 satker | AC-D08.1 |
| 081-4 cuti besar/melahirkan 40/70/80 | AC-D06.4, AC-D06.5, §5.4, F5B-1 (X-5, X-6) | | 083-4 query gabungan | §5.4, F5B-8 [CATAT] |
| 081-5 konket affect_tukin=1 | AC-D06.6 (5A AC-TK.5) | | 083-5 export PDF besar synchronous | AC-D08.6, AC-D08.9, ADR-035 |
| 081-6 unit test terpisah per skenario | §7.1.5 test wajib | | 083-6 tabel terbaca & color-coding | 5C (kode status di JSON §7.3.2) |
| 081-7 kombinasi bertumpuk | AC-D06.6 | | 084-1 rekap per unit/satker | AC-D09b.1 |
| 081-8 MTC-033 & D-13 | N/A (MT) / 5C | | 084-2 rekap per golongan PPPK | Tidak dibuat — gap DoD vs legacy (F5B-20 [CATAT]) |
| 079 prasyarat cross-check | §7.5, F5B-1 (blocker go-live) | | 084-3 tarif uang makan web_config | AC-D09a.3 (slip; rekap tanpa nominal F5B-20) |
| 086 uang makan slip (F5-1) | AC-D09a.1–D09a.5 | | 084-4 akses role 1,3 | AC-D09b.6 (+ role 4 rekap kehadiran F5B-10) |
| 087, 088 | 5C | | QAFUNC-006 "laporan_tukin pegawai NIP sendiri, role 3 satker" | AC-D08.1 |
| QAFUNC-006 "regression ubah tarif" | AC-D08.8, AC-D09a.3 | | QAUI-005 tabel/color-coding/filter laporan | 5C |
| (F5B-13) blok tanda tangan | AC-D08.10 | | (F5B-14) penyesuaian Tukin + DBV-024 | AC-D08.11, §7.6, §11.1 #8 |

#### C.2 Ketergantungan PRD 5A → 5B
| Rujukan PRD 5A | Tercakup di 5B |
|---|---|
| §1.2 baris D-06 / D-08 / D-09 | §1.1 |
| §7.3.7 `UangMakanSlipInterface` + `$sumberUangMakan` | §7.2 |
| §7.7.6 `SumberCutiTbInterface` + `SumberCutiTbBelumTersedia` | §7.1 |
| §9 baris 5B | §9 |
| Q5A-13 | §7.5, F5B-1 |
| Q5A-23 (slip uji tanpa snapshot) | Setelah M2: buka ulang admin menghitung snapshot (aturan 5A: hanya bila `uang_makan_periode` kosong) |
| C.2 baris 1220 "export tukin besar synchronous" | §7.3.5, §7.3.7 (ADR-029/034/035) |
| C.2 baris 1222–1223 precedence, kombinasi, cuti/TB | §7.1.5 |
| UP-1 (`05-Presensi.md`) | F5B-9, M7 |

#### C.3 Batas 5B vs 5C
| 5C | QASMTASK |
|---|---|
| FE Laporan Tukin (daftar, per pegawai, rekap, IL) + color-coding TL/PSW/TK/TPM/TPP + filter | 083 (UI), 087, QAUI-005 |
| FE rekap kehadiran & uang makan | 084 (UI), 087 |
| D-13 regression suite lengkap (12 skenario terpisah, termasuk test 5A & 5B) | 088 |
| Map picker tile (Q5A-10) | 087 |
| QA Lapis 1 (Figma belum ada — QAUI-005 "BLOKER: Figma belum ada") | 087 |
| Uji paritas UP-1 data nyata (F5B-9) | 079/081/083 (verifikasi) |
| FE CRUD penyesuaian Tukin (F5B-14) | 083 (UI), 087 |
| Sign-off Horii, `ACTIVE_PHASE` 4→5, hapus flag `rilis` | Exit criteria Fase 5 |

#### C.4 Hasil pemeriksaan kelengkapan (penyusun, 28-10-2026)
- Struktur 0–14 mengikuti PRD 5A.
- Setiap DoD backend QASMTASK-081/083/084 → AC (C.1); bagian UI → 5C (C.3); prasyarat 079 → §7.5.
- Setiap rujukan 5B di PRD 5A (§1.2, §7.3.7, §7.7.6, §9, Q5A-13, Q5A-23, C.2) → bagian 5B (C.2).
- Hal yang ditemukan saat riset dan dijadikan pertanyaan (bukan ditebak): Q5B-02 (aturan rekap uang makan ≠ slip), Q5B-03
  (penanda TB melekat), Q5B-04/05 (versi tukin lama), Q5B-06 (IL), X-5/X-6 (DoD 40/70/80 vs rekap), catatan Pajak slip §9.
- Tidak ada IP/host internal, kredensial, data pribadi nyata, atau rincian eksploit; `config/constants.php` legacy tidak dibuka.
- v1.0: setiap butir [CATAT] (F5B-2, F5B-8, F5B-13, F5B-14, F5B-16, F5B-17, F5B-20) punya tabel "v2 dipakai | legacy [K] |
  gap" di bagian spesifikasinya dan kolom [CATAT] di register §13.

### Lampiran D — Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| v0.1 | 28-10-2026 | Draf awal (`a5f5254`): scope D-06/D-08/D-09, bahan Q5A-13, 20 pertanyaan (6 ⛔) |
| v1.0 | 28-10-2026 | Keputusan Horii atas semua butir → §5.3 F5B-1..F5B-20 (+ F5B-21..F5B-23). Perubahan isi: rekap uang makan menjadi format legacy `NIP \| TGL_ABSEN` tanpa nominal/golongan (F5B-20, §7.4.2; `Rekap\RingkasanGolongan` dihapus); rekap kehadiran + role 4 (F5B-10); IL hanya role 1 (F5B-6); laporan per pegawai role 3 fail-closed (F5B-7); PDF per pegawai = render legacy persis: "Rp." hanya di baris konket, tanpa kolom/total (F5B-8, diputus ulang setelah temuan komentar HTML); blok tanda tangan dengan 6 kasus (F5B-13); tabel penyesuaian Tukin + DBV-024 + CRUD role 1 (F5B-14, §7.3.10, §7.6); PDF rekap berbatas (F5B-16); cuti tak dikenal fail-closed & tumpang tindih id terbesar (F5B-17); metode massal TB aditif di Fase 4 sebagai prasyarat M1a (F5B-18); runbook M0–M7 (+ M1a MAKE-044, DBV-024 M3, CRUD M4 MAKE-045); blocker go-live diperluas (Q5B-01/03/09/15/19); acuan Slip Gaji `0cf7e67` lewat CR 5A terpisah; tabel [CATAT] per butir |

— Akhir dokumen —
