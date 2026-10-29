# PRD Fase 5C — FE Laporan Tukin & Rekap, Regression Suite D-13, Penutup Fase 5 (Modul D, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase5/PRD_Fase5C_FE-Laporan-Regresi-SignOff.md` — **acuan utama** coder/agen paket **5C** Fase 5 (kartu Trello DEV-006). Pasangan kerja: `docs/fase5/TaskList_Fase5C.md` (pembagian paket untuk beberapa coder) |
| Versi | **v1.1 — 29-10-2026** (v0.1 draf reviewer CR; v0.2 keputusan Horii atas Q5C-01 dan Q5C-06; v1.0 disetujui Horii; v1.1 mencatat keputusan pelaksanaan 5C, hasil QA Lapis 1, dan UP-1 sebagai tahap tersendiri — §5.1, §12) |
| Status | **BERLAKU** — disetujui Horii 29-10-2026. Semua paket koding 5C (MAKE-047..053, CR-075..078) sudah di `main`; sisa: QA ulang + MAKE-054 + sign-off Fase 5 |
| Basis kode | `origin/main` = **`7eac943`** (29-10-2026). 5A sign-off 28-10; 5B sign-off 29-10 (MAKE-043 `87f77c8`); MAKE-047..053 + CR-075..078 di `main` |
| Acuan legacy | Salinan kerja legacy CI3 HEAD **`0cf7e67`** (sama dengan PRD 5B). Tampilan acuan: `views/hr/employee/presensi/` (`V/list_lt.php`, `V/rekap.php`, `V/rekap_uma.php`, layar `laporan_tukin_skp`, cetak `print/laporan_tukin_us_skp.php`) |
| Scope | **5C** = D-12 sisa (FE Laporan Tukin, Rekap Tukin, Penyesuaian Tukin, Rekap Kehadiran, Rekap Uang Makan, map picker), D-13 lengkap (12 skenario regression suite terpisah), QA Lapis 1 Fase 5, `ACTIVE_PHASE` 4→5 + hapus flag `rilis`, laporan akhir & **sign-off Horii Fase 5**. **Uji paritas UP-1 data nyata dipindah ke Tahap UP-1** (antara Fase 5 dan Fase 6, keputusan Horii 29-10; tidak menahan sign-off Fase 5, menjadi syarat masuk Fase 6 — `TaskList_Fase5_Paralel.md` §6–§7) |
| Key | Pekerjaan baru **MAKE-047..054** (§10); perbaikan review/QA **CR-075** dst. Tidak ada skema baru (bila ternyata butuh → DBV-025, eskalasi dulu) |
| Sumber | PRD 5A v1.1 (§7.8, §9, §11), PRD 5B v1.0 (§1.2, §6, §7.3, §7.4, §9, §10 M7), `backend/docs/progress/05-Presensi.md`, README `Controllers/Api/Presensi` + `Libraries/Presensi*`, `frontend/src/features/presensi/README.md`, AGENTS.md §1 & §3, Trello DEV-006, QASMTASK-087/088, QAUI-005, QAFUNC-006 |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh dokumen ini + TaskList_Fase5C.md sebelum menulis kode.** Laporan C0 (§10) membuktikan pemahaman.
2. Bila bertentangan dengan sumber lain, **PRD ini + keputusan Horii (§11) MENANG** untuk lingkup 5C. Keputusan PRD 5A
   (P-1..P-4, F5-1..F5-24) dan PRD 5B (F5B-1..F5B-23, Q5B-xx) **tetap berlaku**; PRD ini hanya menambah.
3. Hal yang tidak tercakup/ambigu → **BERHENTI dan eskalasi ke reviewer CR. DILARANG MENEBAK.**
4. **⛔** = titik tunggu / keputusan wajib sebelum batas yang disebut. **◻ default** = berlaku bila tidak ditolak saat review C0.
5. Label: **[K]** ikut legacy (berkas:baris), **[V2]** deviasi disengaja (alasan + keputusan dicatat), **[I]** inferensi.
6. **DILARANG membuka `application/config/constants.php` legacy** (berisi kredensial produksi), termasuk lewat subagen/grep.
   Pencarian di folder legacy wajib `--exclude=constants.php`. Butuh nilai/template dari berkas itu → minta reviewer CR.

---

## 1. Scope 5C

### 1.1 Di dalam scope

| Task | Isi | Trello |
|---|---|---|
| D-12 Laporan Tukin | Halaman daftar pegawai (1, 3), pegawai IL (1), laporan per pegawai (1–7; 2/6/7 hanya diri sendiri) + unduh PDF; tabel harian ber-**color-coding** | 087, 083 (FE) |
| D-12 Rekap Tukin | Tabel rekap densitas tinggi (1, 3) + unduh XLSX / PDF berbatas; rekap IL (1) | 087, 083 (FE) |
| D-12 Penyesuaian Tukin | CRUD role 1 (F5B-14) dengan menu ⋮ | 083 (FE) |
| D-12 Rekap Kehadiran & Uang Makan | Rekap kehadiran (1, 3, 4) + XLSX ringkas/harian; rekap uang makan (1, 3) + XLSX; IL (1) | 087, 084 (FE) |
| D-12 Map picker | **Keputusan Horii 29-10 (Q5C-01): tetap `IndikatorRadius` SVG 5A tanpa peta tile** — tidak ada paket koding; diverifikasi di MAKE-054 | 087 |
| D-13 lengkap | 12 skenario sebagai **regression suite terpisah** (§7.6) | 088 |
| Penutup Fase 5 | `ACTIVE_PHASE` 4→5, hapus `rilis` grup Presensi, QA Lapis 1, paritas UP-1, laporan akhir, sign-off Horii | DEV-006 |

### 1.2 DI LUAR scope 5C

| Butir | Milik | Catatan |
|---|---|---|
| Perubahan API/backend laporan (kecuali perbaikan bug lewat CR yang disetujui) | 5B | Temuan API → lapor reviewer; dikerjakan sebagai CR-### oleh pemilik 5B |
| Laporan Tukin Lama (`laporan_tukin_us`), varian `tukinver` selain `skp` | — | F5B-4/F5B-5, tidak dibuat |
| FCM presensi, cron status TB/CLTN | Fase 6 | — |
| Manual Testing (MT-005, MTC-034) | Fase MT tersendiri | Bukan syarat exit 5C |
| Impor produksi / cutover | Cutover | 5C hanya menyiapkan & menjalankan prosedur paritas di data yang disetujui (Q5C-06) |

## 2. Aturan proyek WAJIB

- **Commit & key:** Bahasa Indonesia `feat(presensi): … (MAKE-0xx)` / `fix(presensi): … (CR-0xx)`; tanpa penyebutan
  AI/asisten/tool dan tanpa trailer `Co-Authored-By`. Satu key per paket; branch `fase5c/make-0xx-<ringkas>` dari
  `origin/main` terbaru; **tanpa force push**; merge `origin/main` (bukan rebase) bila tertinggal.
- **Gate:** gate cepat lokal per paket (ESLint + vue-tsc + Vitest untuk FE; PHPStan + CS + PHPUnit modul untuk BE) → push →
  CI hijau → lapor SHA + run CI ke reviewer (lapor juga bila CI masih berjalan) → ⛔ tunggu merge reviewer (izin Horii per paket).
  Satu `./check.sh` penuh per mesin pada satu waktu.
- **Rahasia & repo publik:** jangan commit `.env`, kredensial, IP/host internal, NIP/nama/nominal nyata. Data uji sintetis.
- **DB:** test BE memakai DB scratch eksplisit port 3307 (satu DB per paket, dipakai ulang). Jangan sentuh DB dev/testing
  bersama atau produksi. Jangan menyalin `.env` dev ke worktree.
- **UI (AGENTS.md §1 & §3):** aksi baris lewat `RowActionsMenu` (⋮) dengan label "Aksi untuk <nama baris>" & testid
  `<fitur>-actions-<id>`; komponen custom `shared/ui` (tanpa `<select>`/date picker/`title` native, tanpa
  `window.confirm/alert/prompt`); halaman tabel memakai `page-fill` + `table-fill` + `UiCard fill`; ekspor lewat `UiExportMenu`;
  komponen baru yang generik dibuat di `shared/ui` + test keyboard/ARIA. Figma halaman laporan **belum ada** (QAUI-005) →
  ikuti pola halaman 5A/Fase 4 dan catat di laporan (§3.6 AGENTS).
- **Pelajaran amplop axios:** setiap service diuji dengan klien HTTP asli (`*.amplop.spec.ts`), bukan hanya mock; grep
  `res.data.data` saat review.

## 3. Aturan berkas & tabrakan

### 3.1 Berkas milik 5C (dibuat baru)
`frontend/src/features/presensi/` — `views/{LaporanTukin*,RekapTukin*,PenyesuaianTukin*,RekapKehadiran*,RekapUangMakan*}Page.vue`,
`components/` pendukung laporan, `services/{laporanTukin,penyesuaianTukin,rekapKehadiran}.service.ts`, `types.laporan.ts`,
`composables/useFilterLaporan.ts`, test di `__tests__/`; `backend/tests/Presensi/RegresiD13/**`;
`docs/fase5/Checklist_QA_Lapis1_Fase5C.md`, `docs/fase5/Laporan_Akhir_Fase5.md`.

### 3.2 Berkas BERSAMA — hanya ADITIF, commit terpisah
`features/presensi/routes.presensi.ts`, `roles.ts`, `README.md`; `shared/layouts/nav.config.ts` (+ test);
`shared/ui/index.ts` (bila menambah komponen); `backend/composer.json` (script `test:d13`);
`backend/docs/progress/05-Presensi.md`. Paket yang merge belakangan **merge `origin/main`** dan menyelesaikan konflik secara aditif.

### 3.3 DILARANG disentuh
Backend `app/**` (kecuali CR yang disetujui), halaman 5A yang sudah ada (selain menambah tautan bila disebut), modul fase lain,
`ACTIVE_PHASE`/`rilis` **kecuali** paket penutup MAKE-053.

## 4. Glosarium
Istilah PRD 5B §4 berlaku (Rekap Tukin, Periode Tukin, Bulan laporan `bulan=YYYY-MM`, IL, Lingkup laporan `unit_satker` =
`0` / `<id_unit>` / `sat_<id_satker>`). **Densitas tinggi** = tabel banyak kolom (≥ 15) & banyak baris yang tetap terbaca:
header kolom sticky, kolom identitas (No, Nama, NIP) sticky kiri, gulir horizontal di dalam wadah tabel, angka rata kanan,
baris bergaris (zebra) halus. **Color-coding** = warna latar/penanda baris atau sel per kode status, **selalu disertai teks
kode** (tidak bergantung warna saja) + legenda.

## 5. Keputusan & pemetaan DoD

| DoD Trello | Arti di 5C | Tempat |
|---|---|---|
| 087 "QA Lapis 1: tabel densitas tinggi tetap terbaca" | Rekap Tukin, rekap kehadiran, tabel harian laporan per pegawai lolos checklist QA Lapis 1 di 1440 / 1024 / 390 px | §7.1, §8 |
| 087 "Color-coding baris (TL/PSW/TK)" | Tabel harian laporan per pegawai & grid status: kode TL1–3, PSW1–3, TK, TPM, TPP, CS, cuti, TB, libur berwarna + legenda; warna per AC-FE5C.3 | §7.2 |
| 087 "Map picker presensi" | Dipenuhi `IndikatorRadius` SVG 5A (lingkaran radius + posisi relatif, tanpa peta tile) [V2] — Q5C-01 diputus Horii 29-10; gap: tanpa peta jalan/latar | §7.5 |
| 087 / 083 / 084 "Lolos MTC-0xx" | MT fase tersendiri, **bukan** syarat exit 5C | — |
| 083 "Filter: month, unit_satker, jenis_pegawai, tukinver, format" | Filter Bulan (`UiMonthPicker`), Unit/Satker (`UiSelect` searchable, role 1 saja; role 3 tidak ditampilkan karena dipaksa server), Jenis Pegawai (multi), ekspor `UiExportMenu`; `tukinver` tidak ada (hanya `skp`, F5B-4) | §7.1 |
| 088 seluruh butir | 12 skenario §7.6 | §7.6 |

### 5.1 Keputusan pelaksanaan 5C (29-10-2026, reviewer CR + Horii)

| ID | Keputusan | Asal |
|---|---|---|
| K5C-1 | **Warna baris** tabel harian laporan per pegawai ikut legacy [K] (`laporan_tukin_skp.php:235-262`, CSS :471-495): libur biru, akhir pekan abu-abu, konket jingga, cuti ungu, `tugas_belajar` merah muda, `di_luar_masa_kerja` abu-abu; hadir/TK/TPM/TPP tanpa warna baris. **Chip kode** `TL1`–`TL3`/`PSW1`–`PSW3`/`TPM`/`TPP`/`TK`/`CS` dari `kode[]` [V2] palet Q5C-03 (TL/PSW kuning, TK/TPM/TPP merah, CS jingga). Rekap (legacy tanpa warna): chip/sel kode saja. Token `status.*` di `tailwind.config.js`; PDF memakai palet soft yang sama | Horii "warna ikut legacy" |
| K5C-2 | **CR-075**: `harian[].kode` = kode kejadian (kategori) di JSON laporan per pegawai | Review MAKE-047 |
| K5C-3 | **CR-076**: `harian[]` JSON & PDF memuat **setiap tanggal** periode, ikut legacy: `di_luar_masa_kerja`, `libur` "(Libur) <nama>", status baru `akhir_pekan` ("Sabtu"/"Minggu") | Horii "ikut perilaku legacy" |
| K5C-4 | Role 4/5 **tanpa menu** Laporan Tukin per pegawai (ikut legacy); route `/presensi/tukin/:nip` tetap terbuka (lingkup ditegakkan server). Role 4 memilih Unit/Satker di Rekap Kehadiran, semua lingkup (F5B-10) | Review MAKE-048/051 |
| K5C-5 | Deviasi diterima: PUT penyesuaian mengirim body lengkap (MAKE-050); helper `RegresiD13` di `tests/_support` (MAKE-052) | Review |
| K5C-6 | **CR-078 (T-01)**: bulan bawaan halaman **Tukin** (daftar, rekap, per pegawai, Saya) = bulan periode Tukin berjalan: tanggal WIB > 15 → bulan berikutnya [K `Presensi.php:110-119`]. Rekap Kehadiran & Uang Makan tetap **bulan kalender** WIB | QA Lapis 1 |
| K5C-7 | **CR-078 (T-04)**: filter & laporan per pegawai mengikuti URL saat komponen yang sama dipakai ulang (back/forward, menu, NIP lain) lewat `onBeforeRouteUpdate`; penulisan balik ke URL hanya selama rute milik halaman aktif | QA Lapis 1 |
| K5C-8 | **CR-077 (T-10/T-11)**: `.page-fill` menggulir sendiri bila viewport pendek (window tetap tanpa gulir); `.table-fill` tinggi minimum `18rem` (`--table-fill-min`); kartu filter tidak `fill`; sel sticky kiri ikut warna baris (T-02); pojok header sticky di bawah navbar (T-03) | QA Lapis 1 (temuan Horii) |
| K5C-9 | **CR-078 (T-05/T-06)**: unduhan blob mengurai body galat JSON (pesan 422/`code` server tampil); fokus kembali ke ⋮ setelah `ConfirmDialog` (pola bersama) | QA Lapis 1 |
| K5C-10 | **UP-1 = tahap tersendiri** antara Fase 5 dan Fase 6; tidak menahan sign-off Fase 5; Fase 6 terkunci sampai UP-1 selesai. Sumber data = DB server dev (salinan produksi, tetap data pribadi), DBA = DBV, jalur impor = alat impor resmi MAKE-056 (gladi cutover) | Horii 29-10 |

## 6. Matriks role × halaman 5C (v2)

Guard FE hanya UX (ADR-024); server tetap menegakkan role + lingkup. Role: 1 Super Admin, 2 PNS, 3 Admin Satker,
4 Admin View Eselon I, 5 Menteri, 6 PTT, 7 PPPK, 8 Pimpinan. Path di bawah = **◻ default Q5C-02**.

| Halaman (path ◻) | Role | API (PRD 5B §6) |
|---|---|---|
| Laporan Tukin — daftar pegawai `/presensi/tukin` | 1, 3 | `GET presensi/tukin/pegawai` |
| Laporan Tukin — pegawai IL `/presensi/tukin/il` | 1 | `GET presensi/tukin/pegawai-il` |
| Laporan Tukin per pegawai `/presensi/tukin/:nip` | 1, 3, 4, 5 (siapa pun dalam lingkup); 2, 6, 7 hanya NIP sendiri | `GET presensi/tukin/laporan/{nip}` + `/pdf` |
| Laporan Tukin Saya `/presensi/tukin/saya` | 2, 6, 7 | sama, `{nip}` = NIP akun |
| Rekap Tukin `/presensi/tukin/rekap` | 1, 3 | `GET presensi/tukin/rekap` + `/xlsx` + `/pdf` |
| Rekap Tukin IL `/presensi/tukin/rekap-il` | 1 | `GET presensi/tukin/rekap-il[/xlsx]` |
| Penyesuaian Tukin `/presensi/tukin/penyesuaian` | 1 | CRUD `presensi/tukin/penyesuaian` |
| Rekap Kehadiran `/presensi/rekap-kehadiran` | 1, 3, 4 | `GET presensi/rekap-kehadiran` + `/xlsx` + `/xlsx-harian` |
| Rekap Uang Makan `/presensi/uang-makan` (+ tab IL role 1) | 1, 3 | `GET presensi/uang-makan/rekap[-il]` + `/xlsx` |

Role 8 tidak punya halaman laporan Presensi. Halaman yang tidak boleh diakses → halaman 403 bersama (pola Fase 3/4).

## 7. Spesifikasi per task

### 7.1 Pola umum halaman laporan (semua paket FE)
- **Filter bar** (`useFilterLaporan`): Bulan wajib (bawaan = bulan laporan berjalan menurut tanggal WIB klien — halaman
  Tukin: periode 16–15, tanggal > 15 → bulan berikutnya; Rekap Kehadiran/Uang Makan: bulan kalender, K5C-6; nilai
  `YYYY-MM`, tanpa `toISOString()`), Unit/Satker (role 1: opsi `0` Kementerian + unit + `sat_<id>`; role 3/4: lihat §6),
  Jenis Pegawai multi, Cari (bila API mendukung). Filter disimpan di query string URL agar halaman bisa dibagikan/di-refresh.
- **Tabel:** `page-fill` + `table-fill`; header sticky; kolom identitas sticky kiri; paginasi server (`per_page` 10/25/50/100);
  state memuat (skeleton), kosong ("Tidak ada data untuk filter ini"), galat (pesan dari `message`, 422 per kolom di bawah
  filter, `code` dipetakan ke teks bila ada — mis. `konfigurasi_tukin_tidak_lengkap`).
- **Ekspor:** `UiExportMenu` (Excel / PDF sesuai endpoint); unduh lewat blob dengan nama berkas dari `Content-Disposition`;
  tombol nonaktif + indikator saat memproses; 422 batas baris PDF ditampilkan sebagai pesan (bukan unduhan rusak).
- **Galat baris rekap:** baris pegawai yang galat (AC-D08.7) ditampilkan dengan penanda galat + pesan, tidak menghilangkan baris lain.
- **Angka:** persen 2 desimal dengan koma desimal Indonesia; tanpa nominal Tukin (F5B-8).

### 7.2 Color-coding status (MAKE-047, dipakai MAKE-048/049/051)
- Sumber kode = respons API (`harian[].status` + kode kejadian; kolom rekap). **Daftar kode final diambil dari README
  `Controllers/Api/Presensi` + `Libraries/Presensi/Tukin` (`StatusHarian`, `HasilHarian`) pada laporan C0 — DILARANG menebak.**
- Satu peta kode → {label, token warna, teks singkat} di `features/presensi/statusPresensi.ts`; komponen `StatusPresensiChip`
  + `LegendaStatusPresensi`. Warna memakai token desain proyek (tanpa hex lepas); kontras teks ≥ 4.5:1 di tema terang & gelap.
- Warna mengikuti legacy bila tampilan legacy memberi warna [K] (diperiksa di C0); bila tidak → ◻ default Q5C-03.

### 7.3 Laporan Tukin (MAKE-048) — menunggu MAKE-041 di `main`
- Daftar pegawai: kolom sesuai `V/list_lt.php` [K] (diperiksa C0) + aksi baris ⋮ "Lihat laporan", "Unduh PDF".
- Per pegawai: kartu identitas (nip, nama, gol/ruang, satker, jabatan, kelas jabatan, predikat SKP), periode (awal–akhir,
  sumber BKN/bawaan), tabel harian ber-color-coding (tanggal, status, jam masuk, jam pulang, potongan, keterangan), ringkasan
  `jumlah` (TL1..3, PSW1..3, TPM, TPP, TK, CS), `rincian`, total presensi / SKP / potongan, penyesuaian (bila ada) + alasan;
  tombol Unduh PDF. Periode berjalan diberi catatan "dihitung sampai hari ini".
- 2/6/7: halaman "Laporan Tukin Saya" tanpa input NIP; membuka `/presensi/tukin/:nip` NIP lain → 403 dari server ditampilkan.

### 7.4 Rekap Tukin, Penyesuaian (MAKE-049, MAKE-050)
- Rekap: kolom persis urutan XLSX PRD 5B §7.3.4 (subset yang ada di JSON); densitas tinggi; blok penanda tangan **tidak**
  ditampilkan di layar (hanya di berkas) ◻; tab/route IL role 1.
- Penyesuaian Tukin (role 1): daftar (filter nip, bulan, status), tambah/ubah lewat dialog form (validasi 422 per kolom,
  aturan PRD 5B §7.3.10 — mis. `bulan` ≤ 2024-03 ditolak), menu ⋮ urutan **Edit → Hapus** (Hapus `danger`, `ConfirmDialog`).
  Kolom Status hanya badge.

### 7.5 Rekap Kehadiran, Uang Makan (MAKE-051), Map picker
- Rekap kehadiran: kolom ringkas `nama, nip, jabatan, hari_kerja, hadir, tl, psw, tk, sakit, dinas_luar, lain_lain, cuti,
  tidak_hadir, tb` [K `V/rekap.php:114-133`, `tb` V2]; ekspor "Excel ringkas" dan "Excel harian" (role 1, 3, 4).
- Rekap uang makan: dua kolom `NIP | Tanggal Absen` + ekspor; tab IL role 1. Catatan perbedaan pegawai baru (Q5B-26 vs F5B-2)
  ditampilkan sebagai teks bantuan ◻.
- Map picker (Q5C-01, keputusan Horii 29-10): **tetap `IndikatorRadius` SVG** di halaman Presensi Saya 5A; tanpa peta tile
  pihak ketiga, tanpa kunci API, tanpa ketergantungan jaringan. Tidak ada paket koding; MAKE-054 memverifikasi DoD 087 butir 3
  (radius & posisi relatif tampil, keadaan izin lokasi ditolak/tidak tersedia) dan mencatat gap.

### 7.6 D-13 regression suite (MAKE-052, backend saja)
- Lokasi `backend/tests/Presensi/RegresiD13/`, **satu kelas test per skenario**, atribut `#[Group('d13')]`, `extends
  DatabaseTestCase`, data sintetis, waktu disuntik, melewati jalur nyata (`TukinDataService` + `SumberCutiTbFase4` +
  `KalkulasiTukin`, slip: service impor/akses 5A) — bukan hanya library murni. Script `composer test:d13`
  (`phpunit --group d13`); suite tetap ikut `./check.sh` penuh.
- 12 skenario (QASMTASK-088 / QAFUNC-006):

| No | Skenario | Inti assert |
|---|---|---|
| S01 | Hari libur + akhir pekan | Tidak masuk hari kerja, tanpa potongan |
| S02 | Jam kerja normal vs puasa (Jumat beda jam) | TL/PSW memakai jam `web_config` + `rentang_puasa` |
| S03 | Cuti tahunan / alasan penting / melahirkan | Tidak dipotong harian |
| S04 | Cuti sakit > 14 hari kerja berurutan | Hari ke-15+ dipotong `CUTI_SAKIT` |
| S05 | Cuti besar / melahirkan | Potongan 40/70/80 % sesuai bulan |
| S06 | Konket `affect_tukin = 1` | Hadir tanpa potongan |
| S07 | Tugas belajar penuh | Bebas presensi total |
| S08 | TL/PSW boundary 30 & 60 menit | Tepat di batas & ±1 menit |
| S09 | TPM / TPP | Penalti TA + denda selisih jam |
| S10 | Kombinasi bertumpuk (libur + cuti + konket + TB) | Precedence sesuai rekap legacy |
| S11 | Slip gaji: impor (nama-NIP mismatch di-log, duplikat NIP+periode ditolak di pratinjau, pelanggaran UNIQUE → pesan jelas) + gerbang tgl 4 10:00 WIB (sebelum/sesudah) + pegawai hanya slip sendiri | |
| S12 | Ubah tarif `web_config` (regression Fase 2) | Rekap & laporan langsung berubah |

- Setiap skenario mencantumkan rujukan legacy [K] di docblock dan test lama 5A/5B yang dicakup (tidak menghapus test lama).

### 7.7 Penutup (MAKE-053) dan verifikasi (MAKE-054)
- MAKE-053: `ACTIVE_PHASE` 4→5, hapus `rilis` grup Presensi + sesuaikan test nav; semua anak menu laporan terdaftar per role;
  README FE/BE + progres diperbarui. **Hanya setelah MAKE-047..051 di `main`.**
- MAKE-054: verifikasi AC 5A/5B/5C, matriks halaman × role, keterlacakan DoD 076–088, checklist QA Lapis 1 5C (+ QA ulang
  CR-077/078), status Tahap UP-1 (dicatat, **bukan** syarat — K5C-10), daftar blocker go-live (QC-1..5, Q5A-14, Q5A-25, UP-1),
  `Laporan_Akhir_Fase5.md` → ⛔ sign-off Horii.

## 8. Acceptance criteria 5C

- **AC-FE5C.1** Setiap halaman §6 tampil hanya untuk role-nya (guard + menu); role lain yang membuka URL → halaman 403.
- **AC-FE5C.2** Filter mengubah permintaan API sesuai PRD 5B §6 (Vitest memeriksa query); role 3 tidak melihat filter
  Unit/Satker lain; 422 tampil per kolom.
- **AC-FE5C.3** Color-coding: setiap kode punya warna + teks + legenda; kontras ≥ 4.5:1; tidak bergantung warna saja.
- **AC-FE5C.4** Densitas tinggi: header & kolom identitas sticky, gulir horizontal di wadah tabel, tetap terbaca di
  1440/1024/390 px (QA Lapis 1).
- **AC-FE5C.5** Ekspor: berkas terunduh dengan nama dari server; 422 batas baris tampil sebagai pesan.
- **AC-FE5C.6** Penyesuaian Tukin: CRUD lewat ⋮ + `ConfirmDialog`; validasi server tampil; hanya role 1.
- **AC-FE5C.7** Service diuji dengan klien HTTP asli (amplop `{data, meta}`); tidak ada `res.data.data` ganda.
- **AC-FE5C.8** Komponen custom `shared/ui` saja (pemindai/elemen native tidak tampil); keyboard & ARIA teruji untuk komponen baru.
- **AC-D13.1** 12 kelas skenario ada, bergrup `d13`, lulus lewat `composer test:d13` dan `./check.sh`.
- **AC-5C.X1** `ACTIVE_PHASE = 5`, tidak ada `rilis` tersisa di grup Presensi, test nav diperbarui.
- **AC-5C.X2** QA Lapis 1 5C lolos; laporan akhir Fase 5 memuat blocker go-live dan status Tahap UP-1 (UP-1 bukan syarat
  sign-off Fase 5, K5C-10).

## 9. Ketergantungan

| Pihak | Butir | Status 29-10 (v1.1) |
|---|---|---|
| 5B MAKE-041 | API Laporan & Rekap Tukin | ✅ Di `main` `9968dc0` |
| 5B MAKE-042 / MAKE-045 | API rekap kehadiran/uang makan, CRUD penyesuaian | ✅ Di `main` |
| 5B MAKE-043 | Verifikasi 5B + prosedur paritas UP-1 | ✅ Di `main` `87f77c8` (5B sign-off 29-10) |
| Horii | Q5C-01 (map picker), Q5C-06 (paritas data nyata), Q5C-09 (UP-1 tahap tersendiri) | ✅ diputus 29-10 |
| Tahap UP-1 | Paritas Tukin data nyata (MAKE-055 runbook, MAKE-056 alat impor) | Di luar 5C; syarat masuk Fase 6 |
| Cutover | QC-1..5, Q5A-14, Q5A-25 | Dicatat sebagai blocker go-live, bukan blocker 5C |

## 10. Urutan kerja (ringkas — rinci di TaskList_Fase5C.md)

C0 (baca + laporan pemahaman, tanpa commit) → **MAKE-047** fondasi → paralel **MAKE-048 ∥ 049** (setelah 041 di main),
**MAKE-050 ∥ 051 ∥ 052** (boleh langsung) → **MAKE-053** penutup → **MAKE-054** verifikasi →
⛔ sign-off Horii Fase 5.

**Isi laporan C0:** ringkasan per paket (≤ 3 baris); daftar kode status final dari README; kolom tampilan legacy per halaman
(`V/list_lt.php`, `V/rekap.php`, `V/rekap_uma.php`, layar `laporan_tukin_skp`) + ada/tidaknya warna legacy; komponen
`shared/ui` yang dipakai ulang / perlu dibuat; konfirmasi status MAKE-041/042/043/045 di `main`; pertanyaan terbuka.

## 11. Pertanyaan terbuka (untuk Horii / reviewer CR)

| ID | Pertanyaan | Default usulan | Sifat |
|---|---|---|---|
| Q5C-01 ✅ | "Map picker presensi" (DoD 087): (a) tetap `IndikatorRadius` SVG tanpa peta (5A), (b) peta tile pihak ketiga (mis. OpenStreetMap) di layar presensi pegawai, atau (c) peta untuk admin memilih titik di master Lokasi Presensi | (a) + catat gap; (b)/(c) perlu keputusan privasi, CSP & ketergantungan internet | **DIPUTUS Horii 29-10: (a) SVG** |
| Q5C-02 | Letak menu & path halaman laporan | Anak grup **Presensi**: Laporan Tukin, Rekap Tukin, Penyesuaian Tukin, Rekap Kehadiran, Rekap Uang Makan (path §6) | ◻ |
| Q5C-03 ✅ | Warna color-coding bila legacy tanpa warna | TL/PSW kuning, TK/TPM/TPP merah, CS jingga, cuti/TB biru, libur abu-abu — dari token desain | **Diputus 29-10**: warna baris ikut legacy, chip kode palet ini (K5C-1) |
| Q5C-04 | Cetak dari layar (pengganti cetak HTML legacy) | Tidak dibuat; cukup PDF/XLSX server | ◻ |
| Q5C-05 | Lokasi & cara jalan suite D-13 | §7.6 (`RegresiD13`, grup `d13`, `composer test:d13`) | ◻ |
| Q5C-06 ✅ | Uji paritas UP-1 data nyata: periode mana, siapa menjalankan, akses data | 2 periode terakhir, dijalankan reviewer bersama DBA di salinan data yang disetujui; hasil tanpa data pribadi | **DIPUTUS Horii 29-10: sesuai default** |
| Q5C-07 | Pelaksana QA Lapis 1 5C | Reviewer CR di app demo (pola Fase 4 C-11) | ◻ |
| Q5C-08 | Blok penanda tangan di layar Rekap Tukin | Tidak ditampilkan (hanya di berkas) | ◻ |
| Q5C-09 ✅ | Paritas UP-1: bagian 5C atau tahap tersendiri? | — | **DIPUTUS Horii 29-10: tahap tersendiri** antara Fase 5 dan Fase 6 (K5C-10) |

## 12. Riwayat
- v0.1 (29-10-2026): draf awal reviewer CR.
- v0.2 (29-10-2026): Q5C-01 = SVG `IndikatorRadius` (paket map picker dihapus, key digeser: D-13 = MAKE-052, penutup = MAKE-053, verifikasi = MAKE-054); Q5C-06 = default.
- v1.0 (29-10-2026): disetujui Horii, berlaku.
- v1.1 (29-10-2026): §5.1 keputusan pelaksanaan K5C-1..10 (warna legacy, CR-075/076, role 4/5, deviasi MAKE-050/052, hasil QA Lapis 1 → CR-077/078); UP-1 dipindah ke Tahap UP-1 (Q5C-09); §7.1, §7.7, §8 AC-5C.X2, §9 diperbarui.
