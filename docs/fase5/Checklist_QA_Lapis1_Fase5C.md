# Checklist QA Lapis 1 — Frontend Paket 5C Fase 5 (D-12 sisa)

> **HASIL 29-10-2026: LOLOS.** Dijalankan reviewer CR di app demo (role 1–8 + QA ulang). Semua temuan (§M, T-01..T-15)
> ditutup di `main` lewat CR-077, CR-078, CR-079, CR-081. Ringkasan di `docs/fase5/Laporan_Akhir_Fase5.md`.

Disusun sebagai draf untuk reviewer CR (pelaksana QA Lapis 1 di app demo, Q5C-07 ◻ default, pola Fase 4 C-11). Acuan: PRD 5C v1.0
§5 (DoD QASMTASK-083/084/087), §6 (matriks role × halaman), §7.1–§7.5, §8 (AC-FE5C.1–8, AC-5C.X1); PRD 5B §6–§7 (kontrak
API); README `Controllers/Api/Presensi` (bagian Penyesuaian Tukin, Laporan Tukin, Rekap kehadiran & uang makan) dan
`Libraries/Presensi/Tukin`; AGENTS.md §1 & §3. Disusun dari `main` `3a8c32c` (29-10-2026) **sebelum** halaman 5C dibuat:
nama komponen/testid mengikuti PRD dan diperbarui bila implementasi MAKE-047..053 berbeda. Checklist ini **disepakati
reviewer CR + QA sebelum QA dimulai** (pola Q5A-18 / §6.11.8 butir 17 Fase 4); 5C tetap IN_PROGRESS sampai seluruh butir
lolos (AC-5C.X2).

**Acuan visual:** Figma halaman laporan **belum ada** (QAUI-005). Dinilai terhadap pola halaman 5A/Fase 4 yang sudah lolos
QA: Data Presensi, Kelola Slip Gaji (tab Monitoring), Status Layanan (`UiExportMenu`, filter, paginasi), Daftar Pegawai
(ekspor). Halaman tanpa frame dicatat "Figma belum ada" (AGENTS §3 butir 6). Legacy (tampilan acuan kolom):
`V/list_lt.php`, layar `laporan_tukin_skp`, `V/rekap.php`, `V/rekap_uma.php`.

**Lingkungan:** app demo lokal (DB demo @3307, captcha mock), **data sintetis**, setelah MAKE-053 (`ACTIVE_PHASE = 5`) di
`main`. Akun di `AKUN_DEMO.md` folder demo-seed (password **tidak** ditulis di sini). Data uji tambahan yang perlu disiapkan
sebelum QA (skrip data QA 5C, idempoten, pola `qa_fase4_data.php`) — kode di bawah dipakai di kolom Data:

| Kode | Data | Untuk butir |
|---|---|---|
| Akun | `demo.admin` (1), `demo.pegawai` (2), `demo.satker` (3), `demo.eselon1` (4), `demo.menteri` (5), `demo.ptt` (6), `demo.pppk` (7), `demo.pimpinan` (8) | B, semua |
| U1 | Satu bulan laporan lengkap (presensi harian satker `demo.satker`) dengan setiap kode harian: hadir tepat waktu, TL1–3, PSW1–3, TPM, TPP, TK, cuti (termasuk sakit > 14 hari kerja → CS), konket `affect_tukin = 1`, tugas belajar, libur nasional + akhir pekan, hari di luar masa kerja | D, E, F |
| U2 | Bulan berjalan (periode belum selesai) untuk `demo.pegawai` | D5 |
| U3 | Satu pegawai dengan konfigurasi Tukin tidak lengkap (galat terkendali `konfigurasi_tukin_tidak_lengkap`) di lingkup U1 | D7, F4 |
| U4 | Pegawai Instansi Lain (IL) ≥ 1 | C3, F6, H4 |
| U5 | Satker/unit dengan > 800 pegawai (batas PDF rekap `pdfRekapMaksBaris`) **atau** lingkup `0` Kementerian | F5 |
| U6 | Penyesuaian Tukin: satu baris status 1 dan satu status 2 untuk pegawai U1 | G, D6 |

**Ukuran layar** setiap halaman dan dialog: **1440, 1024, 390 px** (desktop Chrome; 1024/390 lewat emulasi). Bukti =
tangkapan layar berdampingan dengan halaman pembanding. Tema terang **dan** gelap untuk butir warna (E).

Isi kolom Hasil: ✅ lolos / ❌ gagal (+ nomor temuan di §M) / — tidak berlaku.

## A. Umum — semua halaman 5C (AC-FE5C.8, AGENTS §1 & §3)

Halaman: `/presensi/tukin`, `/presensi/tukin/il`, `/presensi/tukin/:nip`, `/presensi/tukin/saya`, `/presensi/tukin/rekap`,
`/presensi/tukin/rekap-il`, `/presensi/tukin/penyesuaian`, `/presensi/rekap-kehadiran`, `/presensi/uang-makan`
(path = ◻ default Q5C-02; disesuaikan bila implementasi berbeda). Akun: `demo.admin` + akun per role bagian B.

| # | Butir | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|
| A1 | AGENTS §3.1, AC-FE5C.8 | Periksa semua kontrol (filter, dialog, menu) | Tanpa elemen bawaan browser: `<select>` terlihat, popup bulan/tanggal native, tooltip `title`, `<datalist>`, `window.confirm/alert/prompt`. Bulan = `UiMonthPicker`, Unit/Satker = `UiSelect` searchable, Jenis Pegawai = pilihan jamak custom | 1440, 390 | ✅ layar + kode: halaman & komponen 5C tanpa `<select>`/`<datalist>`/input tanggal-bulan native/`title`/`window.confirm` (grep MAKE-054); Bulan = `UiMonthPicker` lewat `UiTextField type="month"` (CR-060) |
| A2 | AGENTS §3.4–3.5 | Gulir menu samping, isi halaman, tabel | Navbar atas sticky; menu samping & isi punya area gulir mandiri; scrollbar ramping custom; tanpa scroll horizontal **halaman** (`scrollWidth` = lebar viewport); tabel menggulir di wadahnya | 1440, 1024, 390 | ✅ setelah CR-077/079/081: tanpa gulir horizontal halaman di 1440/1024/423/375 (T-10, T-11, T-13, T-15) |
| A3 | §7.1 | Halaman tabel | `page-fill` + `table-fill` + `UiCard fill`: desktop ≥ 1024 hanya area tabel yang menggulir, judul/filter tetap (pola CR-069); header kolom sticky | 1440, 1024 | ✅ setelah CR-077/079 (T-10, T-12, T-14) |
| A4 | AGENTS §1 | Buka ⋮ tiap tabel | Kolom Aksi paling kanan, sticky dengan bayangan saat tergulir; ⋮ ber-aria-label "Aksi untuk <nama baris>" (nama, **bukan** NIP — pelajaran CR-074); testid `<fitur>-actions-<id>`; menu terbuka di samping tombol (bukan di luar layar, CR-068); label item baku | 1440, 390 | ✅ `tukin-actions-<nip>`, `penyesuaian-actions-<id>`; menu di samping tombol |
| A5 | §7.1 | Filter tanpa data; jaringan lambat; matikan backend | Kosong: "Tidak ada data untuk filter ini"; memuat: skeleton; galat: pesan dari `message` server, 403 "Anda tidak berhak …", 404 "Data tidak ditemukan"; `code` dipetakan ke teks (mis. `konfigurasi_tukin_tidak_lengkap`) | 1440 | ✅ kosong, galat `message`, 403, 404, `code` → teks. Memuat = teks "Memuat…" (pola seluruh modul, tanpa skeleton) — bukan temuan |
| A6 | §7.1 | Ubah filter lalu muat ulang (F5) dan salin URL ke tab baru | Filter (bulan, unit_satker, jenis_pegawai, cari, halaman) tersimpan di query string; tab baru menampilkan data yang sama | 1440 | ✅ setelah CR-078 (T-04: back/forward & menu ke rute yang sama) |
| A7 | §7.1 | Buka halaman pertama kali | Bulan bawaan = bulan laporan berjalan menurut tanggal WIB klien (nilai `YYYY-MM`; cek di sekitar pergantian bulan bila memungkinkan) | 1440 | ✅ setelah CR-078 (T-01, K5C-6): Tukin = periode 16–15, Rekap Kehadiran/Uang Makan = bulan kalender |
| A8 | §7.1 | Baca angka persen di tabel | Persen 2 desimal dengan koma desimal Indonesia (mis. `12,50`); angka rata kanan; **tanpa nominal Tukin** (F5B-8) | 1440 | ✅ koma desimal, rata kanan, tanpa nominal |
| A9 | AGENTS §3.3 | Keyboard saja: Tab ke semua kontrol, Enter/Spasi membuka ⋮/dropdown/picker, panah memilih, Home/End, Esc menutup | Fokus selalu terlihat dan kembali ke pemicu setelah Esc; urutan Tab wajar; label form terbaca pembaca layar | 1440 | ✅ fokus kembali ke ⋮ setelah dialog (T-06 → CR-078); keyboard komponen `shared/ui` teruji Vitest (`UiSelect.interaksi`, `pickers`) |
| A10 | — | DevTools Console & Network selama alur normal | Konsol bersih; tidak ada 4xx/5xx tak terduga (403/422 yang memang diuji dikecualikan) | 1440 | ✅ hanya 403/422 yang diuji |
| A11 | — | Breadcrumb, `h1`, judul tab | Breadcrumb "Home / Presensi / <Halaman>"; `h1` ada; judul tab "<Halaman> · SIMPEG v2"; label Bahasa Indonesia Title Case konsisten (pelajaran CR-072) | 1440 | ✅ |

## B. Menu per role & ACTIVE_PHASE (AC-FE5C.1, AC-5C.X1, §6, MAKE-053)

| # | Akun (role) | Anak grup Presensi yang tampil (tambahan 5C; anak menu 5A tetap) | Hasil |
|---|---|---|---|
| B1 | `demo.admin` (1) | Laporan Tukin, Rekap Tukin, Penyesuaian Tukin, Rekap Kehadiran, Rekap Uang Makan (IL lewat tab/route di halaman masing-masing) | ✅ |
| B2 | `demo.pegawai` (2) | Laporan Tukin Saya | ✅ (+ Presensi Saya, Slip Gaji 5A) |
| B3 | `demo.satker` (3) | Laporan Tukin, Rekap Tukin, Rekap Kehadiran, Rekap Uang Makan (tanpa Penyesuaian Tukin, tanpa IL) | ✅ |
| B4 | `demo.eselon1` (4) | Rekap Kehadiran (titik masuk Laporan Tukin per pegawai dalam lingkup, §6: konfirmasi dengan MAKE-048) | ✅ Rekap Kehadiran; laporan per pegawai lewat route langsung, tanpa menu (K5C-4) |
| B5 | `demo.menteri` (5) | Tanpa anak menu laporan 5C baru (titik masuk Laporan Tukin per pegawai, §6: konfirmasi dengan MAKE-048) | ✅ tanpa anak menu 5C; laporan per pegawai lewat route langsung (K5C-4) |
| B6 | `demo.ptt` (6) | Laporan Tukin Saya | ✅ |
| B7 | `demo.pppk` (7) | Laporan Tukin Saya | ✅ |
| B8 | `demo.pimpinan` (8) | Tanpa halaman laporan Presensi | ✅ |
| B9 | Semua | Grup Presensi tampil karena `ACTIVE_PHASE = 5`; flag `rilis` grup Presensi sudah dihapus (AC-5C.X1) | ✅ `ACTIVE_PHASE = 5` (MAKE-053) |

## C. Akses & 403 (AC-FE5C.1, §6; guard FE hanya UX, server tetap menegakkan)

| # | Akun | Langkah (alamat langsung) | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C1 | `demo.satker` | `/presensi/tukin/penyesuaian`, `/presensi/tukin/il`, `/presensi/tukin/rekap-il` | Halaman 403 bersama; API juga 403 | ✅ FE 403 + API 403 |
| C2 | `demo.eselon1`, `demo.menteri` | `/presensi/tukin`, `/presensi/tukin/rekap`, `/presensi/uang-makan` | 403 | ✅ |
| C3 | `demo.pegawai`, `demo.ptt`, `demo.pppk` | `/presensi/tukin`, `/presensi/tukin/rekap`, `/presensi/rekap-kehadiran`, `/presensi/uang-makan`, `/presensi/tukin/penyesuaian` | 403 | ✅ |
| C4 | `demo.pegawai` | `/presensi/tukin/<NIP pegawai lain>` | Pesan 403 dari server ditampilkan (tidak dialihkan ke laporan sendiri, F5B-7) | ✅ pesan 403 server, tidak dialihkan |
| C5 | `demo.satker` | `/presensi/tukin/<NIP di luar satker>` | 403 (`PegawaiScope`) | ✅ |
| C6 | `demo.admin` | `/presensi/tukin/<NIP tidak ada>` | "Data tidak ditemukan" (404) | ✅ "Pegawai tidak ditemukan." |
| C7 | `demo.pimpinan` | Semua path §A | 403 | ✅ FE + API 403 |

## D. Laporan Tukin — daftar & per pegawai (MAKE-048, §7.3, DoD 083/087)

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| D1 | 083, AC-FE5C.2 | `demo.admin` | U1 | `/presensi/tukin`: ubah Bulan, Unit/Satker (`0` Kementerian, unit, `sat_<id>`), Jenis Pegawai (jamak), Cari | Permintaan `GET presensi/tukin/pegawai` membawa `unit_satker`, `jenis_pegawai[]`, `search`, `page`, `per_page` sesuai filter (cek Network); kolom sesuai `V/list_lt.php` [K] (dicatat di laporan C0); paginasi 10/25/50/100 | 1440, 1024, 390 | ✅ |
| D2 | AC-FE5C.2 | `demo.satker` | U1 | Buka daftar | Filter Unit/Satker **tidak tampil**; data hanya satker sendiri (dipaksa server) | 1440 | ✅ |
| D3 | AGENTS §1 | `demo.admin` | U1 | ⋮ baris | Item "Lihat laporan", "Unduh PDF"; label ⋮ "Aksi untuk <nama>" | 1440, 390 | ✅ |
| D4 | §7.3 | `demo.admin` | U1 | Lihat laporan satu pegawai | Kartu identitas (NIP, nama, gol/ruang, satker, jabatan, kelas jabatan, predikat SKP); periode awal–akhir + sumber (BKN/bawaan); tabel harian (tanggal, status, jam masuk, jam pulang, potongan, keterangan) ber-color-coding (§E); ringkasan `jumlah` (TL1..3, PSW1..3, TPM, TPP, TK, CS), `rincian`, total presensi / SKP / potongan | 1440, 1024, 390 | ✅ setelah CR-078 (T-09: Predikat SKP "-" bila kosong) |
| D5 | §7.3 | `demo.pegawai` | U2 | `/presensi/tukin/saya`, bulan berjalan | Tanpa input NIP; catatan "dihitung sampai hari ini" | 1440, 390 | ✅ "Periode berjalan: dihitung sampai …" |
| D6 | §7.3 | `demo.admin` | U6 | Laporan pegawai ber-penyesuaian | Penyesuaian (persen) + alasan tampil; total potongan tidak diubah penyesuaian (README Penyesuaian Tukin) | 1440 | ✅ |
| D7 | §7.1 | `demo.admin` | U3 | Laporan pegawai konfigurasi tidak lengkap | Pesan galat terkendali dari `code` (bukan layar kosong/galat mentah) | 1440 | ✅ pesan dari `code` (`data_tukin_tidak_sah`) |
| D8 | §7.1, PRD 5B | `demo.admin` | — | Bulan sebelum 2024-04 (bila picker mengizinkan) / sesudah batas | 422 tampil di bawah filter Bulan: "Laporan periode sebelum April 2024 tersedia di arsip SIMPEG lama." (`periode_tidak_didukung`) / `errors.bulan` | 1440 | ✅ 2024-03 → pesan arsip; bulan belum tersedia → pesan |
| D9 | AC-FE5C.5 | `demo.admin`, `demo.pegawai` | U1 | Unduh PDF per pegawai | Berkas `tukin_<nip>_<YYYY-MM>.pdf` (nama dari `Content-Disposition`), PDF valid; tombol nonaktif + indikator saat memproses | 1440 | ✅ |
| D10 | §6 | `demo.admin` | U4 | `/presensi/tukin/il` | Daftar pegawai IL (`GET presensi/tukin/pegawai-il`) dengan Cari & paginasi; ⋮ seperti D3 | 1440 | ✅ |

## E. Color-coding status (MAKE-047, §7.2, AC-FE5C.3, DoD 087 butir 2)

Sumber kode (tidak ditebak): status harian `harian[].status` = `hadir`, `TK`, `TPM`, `TPP`, `cuti`, `konket`,
`tugas_belajar`, `libur`, `di_luar_masa_kerja`; kode kejadian `harian[].kode` (CR-075) = `TL1`–`TL3`, `PSW1`–`PSW3`,
`TPM`, `TPP`, `TK`, `CS` (`Libraries/Presensi/Tukin/StatusHarian.php`, `KalkulasiTukin.php`). **Keputusan reviewer CR
29-10** (verifikasi legacy `views/hr/employee/presensi/laporan_tukin_skp.php:235-262` + CSS :471-495):

- **(a) [K] warna latar baris** tabel harian laporan per pegawai per status, ikut legacy: libur biru, akhir pekan abu-abu,
  konket jingga, cuti ungu, `tugas_belajar` merah muda, `di_luar_masa_kerja` abu-abu; baris `hadir`/`TK`/`TPM`/`TPP`
  **tanpa** warna baris.
- **(b) [V2] chip kode** `TL1`–`TL3`/`PSW1`–`PSW3`/`TPM`/`TPP`/`TK`/`CS` dari `kode[]`, palet Q5C-03 (TL/PSW kuning,
  TK/TPM/TPP merah, CS jingga) + legenda.
- **(c) rekap** (legacy tanpa warna): chip/sel kode saja, tanpa warna latar baris.

| # | Butir | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|
| E1 | (a) [K] | U1 | Tabel harian laporan per pegawai: bandingkan warna baris tiap status dengan legacy | Latar baris: libur biru, akhir pekan abu-abu, konket jingga, cuti ungu, tugas belajar merah muda, di luar masa kerja abu-abu; baris hadir/TK/TPM/TPP tanpa warna baris. Status juga tertulis sebagai teks (tidak bergantung warna saja) | 1440, 390 | ✅ setelah CR-077 (T-02: sel Tanggal sticky ikut warna baris) |
| E2 | (b) [V2], AC-FE5C.3 | U1 | Hari dengan kode kejadian | Chip per kode dari `kode[]` dengan teks kode, warna palet Q5C-03; hari dengan dua kode (mis. `TPM` + `PSW2`, `TL1` + `PSW1`) menampilkan keduanya sesuai urutan; hari `kode: []` tanpa chip | 1440, 390 | ✅ |
| E3 | (b), AC-FE5C.3 | U1 | Legenda | `LegendaStatusPresensi` di dekat tabel: memuat warna baris (a) dan chip kode (b) dengan label Indonesia | 1440, 390 | ✅ |
| E4 | (c) | U1 | Tabel Rekap Tukin & Rekap Kehadiran | Tanpa warna latar baris per status; kode/kolom tampil sebagai chip atau sel kode saja | 1440 | ✅ |
| E5 | AC-FE5C.3 | U1 | Ukur kontras (DevTools / alat kontras) teks di atas setiap warna baris dan chip, tema terang & gelap | Rasio teks ≥ 4.5:1; warna dari token desain (tanpa hex lepas di komponen) | 1440 | ✅ tema terang; tema gelap — (app belum punya tema gelap); kontras token ≥ 4,5:1 di `statusPresensi.spec` |
| E6 | — | U1 | Mode keyboard / pembaca layar pada chip | Chip tidak fokus-able tanpa aksi; teks kode terbaca | 1440 | ✅ chip `role="img"` + `aria-label`, tidak fokus-able |

## F. Rekap Tukin (MAKE-049, §7.4, DoD 083/087 butir 1)

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| F1 | 083, AC-FE5C.2 | `demo.admin` | U1 | `/presensi/tukin/rekap`: filter Bulan, Unit/Satker, Jenis Pegawai | Query `GET presensi/tukin/rekap` sesuai filter; kolom urut XLSX PRD 5B §7.3.4 (subset JSON: NIP, nama, unit es.1, unit es.2, pengurangan TL/PSW/TK/TA/CUTI/TB, total presensi, total SKP, total potongan, keterangan, penyesuaian) | 1440 | ✅ |
| F2 | AC-FE5C.4, 087 | `demo.admin` | U1 | Gulir tabel ke kanan & ke bawah | **Densitas tinggi:** header kolom sticky; kolom identitas (No, Nama, NIP) sticky kiri; gulir horizontal di wadah tabel; angka rata kanan; zebra halus; tetap terbaca | 1440, 1024, 390 | ✅ tanpa zebra (rekap legacy tanpa warna baris; tetap terbaca) — bukan temuan |
| F3 | Q5C-08 | `demo.admin` | U1 | Layar rekap | Blok penanda tangan **tidak** tampil di layar (hanya di berkas) | 1440 | ✅ |
| F4 | §7.1, AC-D08.7 | `demo.admin` | U3 | Rekap lingkup U1 | Baris pegawai galat tetap tampil dengan penanda galat + pesan (`galat {code, message}`); baris lain tidak hilang; `jumlah_galat` terlihat | 1440 | ✅ |
| F5 | AC-FE5C.5 | `demo.admin` | U5 | Ekspor PDF lingkup `0` atau > 800 baris | 422 `errors.unit_satker` tampil sebagai **pesan** (bukan berkas rusak/unduhan kosong) | 1440 | ✅ setelah CR-078 (T-05: pesan server tampil) |
| F6 | AC-FE5C.5 | `demo.admin`, `demo.satker` | U1 | `UiExportMenu` → Excel; PDF lingkup satker | XLSX `laporan_tukin_<lingkup>_<YYYY-MM>.xlsx` valid (dengan blok penanda tangan); PDF valid; nama dari server; tombol nonaktif saat memproses | 1440 | ✅ |
| F7 | §6 | `demo.admin` | U4 | Rekap IL (`/presensi/tukin/rekap-il`) + Excel | `GET presensi/tukin/rekap-il[/xlsx]`; tanpa filter lingkup | 1440 | ✅ |
| F8 | AC-FE5C.2 | `demo.satker` | U1 | Buka rekap | Tanpa filter Unit/Satker; hanya satker sendiri; tanpa tab/route IL | 1440 | ✅ |

## G. Penyesuaian Tukin (MAKE-050, §7.4, AC-FE5C.6) — role 1

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| G1 | §7.4 | `demo.admin` | U6 | `/presensi/tukin/penyesuaian`: filter NIP, Bulan, Status | Query `nip`, `bulan`, `status` diteruskan; bawaan status 1 dan 2; kolom Status hanya badge (Aktif / Tidak Aktif) | 1440, 390 | ✅ |
| G2 | AGENTS §1 | `demo.admin` | U6 | ⋮ baris | Urutan **Edit → Hapus** (Hapus merah, dengan pemisah); label "Aksi untuk <nama>"; testid `<fitur>-actions-<id>` | 1440, 390 | ✅ Edit → Nonaktifkan/Aktifkan → Hapus (status lewat ⋮, AGENTS §1; revisi MAKE-050) |
| G3 | AC-FE5C.6 | `demo.admin` | — | Tambah: kosongkan semua; NIP tidak ada; `bulan_mulai` ≤ 2024-03; mulai > akhir; persen `0`, `150`, `12,345`; alasan > 255 karakter | Galat 422 per kolom di bawah field masing-masing (`nip`, `bulan_mulai`, `bulan_akhir`, `persen`, `alasan`); dialog muat di 390 px | 1440, 390 | ✅ |
| G4 | README | `demo.admin` | U6 | Tambah baris status 1 beririsan dengan baris status 1 milik NIP yang sama | 422 `errors.bulan_mulai` menyebut rentang yang bentrok | 1440 | ✅ |
| G5 | README | `demo.admin` | U6 | Edit: ubah rentang/persen/alasan/status | Tersimpan; NIP tidak bisa diubah (terkunci di form; bila dikirim → 422 `errors.nip`) | 1440 | ✅ |
| G6 | AC-FE5C.6 | `demo.admin` | U6 | ⋮ Hapus → `ConfirmDialog` → Batal, lalu Hapus | Batal tidak mengubah apa pun dan fokus kembali ke ⋮; Hapus → baris hilang dari daftar bawaan (status 10) | 1440, 390 | ✅ setelah CR-078 (T-06) |
| G7 | README | `demo.admin` | — | Dua tab menyimpan penyesuaian NIP yang sama bersamaan (bila bisa direproduksi) | 409 `konflik_data` tampil sebagai pesan "Data sedang diubah oleh permintaan lain, coba lagi." | 1440 | — tidak direproduksi di layar; BE `PenyesuaianTukinKonkurensiTest` (409 `konflik_data`) |

## H. Rekap Kehadiran & Uang Makan (MAKE-051, §7.5, DoD 084/087)

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| H1 | 084, AC-FE5C.2 | `demo.admin`, `demo.satker`, `demo.eselon1` | U1 | `/presensi/rekap-kehadiran`: filter Bulan, Unit/Satker, Jenis Pegawai | Kolom `nama, nip, jabatan, hari_kerja, hadir, tl, psw, tk, sakit, dinas_luar, lain_lain, cuti, tidak_hadir, tb` [K `V/rekap.php:114-133`, `tb` V2]; role 3 tanpa filter Unit/Satker; role 4 sesuai lingkup | 1440, 1024, 390 | ✅ setelah CR-078 (T-07: header "TB") |
| H2 | AC-FE5C.4 | `demo.admin` | U1 | Gulir tabel | Densitas tinggi seperti F2 | 1440, 1024, 390 | ✅ setelah CR-079 (T-13) |
| H3 | AC-FE5C.5 | `demo.admin`, `demo.eselon1` | U1 | `UiExportMenu` → "Excel ringkas", "Excel harian" | `rekap_kehadiran_<lingkup>_<YYYY-MM>.xlsx` dan `rekap_kehadiran_harian_<lingkup>_<YYYY-MM>.xlsx` valid (grid harian kode `TB/CT/DL/SKT/LL/H/TK`, akhir pekan & libur berwarna) | 1440 | ✅ |
| H4 | 084 | `demo.admin` | U1, U4 | `/presensi/uang-makan` + tab IL (role 1) | Dua kolom `NIP \| Tanggal Absen`; tanpa nominal (F5B-20); teks bantuan perbedaan pegawai baru (Q5B-26 vs F5B-2) ◻; tab IL tanpa filter lingkup & jenis pegawai | 1440, 390 | ✅ setelah CR-077/079 (T-11, T-13) |
| H5 | AC-FE5C.5 | `demo.admin`, `demo.satker` | U1 | Ekspor Excel uang makan (dan IL) | `rekap_uang_makan_<lingkup>_<YYYY-MM>.xlsx` valid | 1440 | ✅ |
| H6 | §6 | `demo.satker` | U1 | Uang makan | Tanpa tab IL | 1440 | ✅ |

## I. Map picker = `IndikatorRadius` SVG (Q5C-01, DoD 087 butir 3, §7.5)

| # | Akun | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|
| I1 | `demo.pegawai` | `/presensi` (Presensi Saya 5A), izin lokasi diberikan (HTTPS) | Lingkaran radius lokasi + posisi relatif pegawai tampil (SVG); tanpa peta tile, tanpa permintaan jaringan ke penyedia peta (cek Network), tanpa kunci API | 1440, 390 | ✅ geolokasi disimulasikan di halaman (pengaturan browser tidak diubah): dalam radius / di luar radius + garis posisi; tanpa permintaan ke penyedia peta |
| I2 | `demo.pegawai` | Izin lokasi ditolak / lokasi tidak tersedia | Keadaan ditolak/tidak tersedia ditampilkan dengan pesan jelas; tidak ada galat konsol | 1440, 390 | ✅ |
| I3 | — | Catat gap | Gap tercatat di laporan MAKE-054: tanpa peta jalan/latar [V2] | — | ✅ dicatat di `Laporan_Akhir_Fase5.md` §6 |

## J. Klien HTTP & komponen (AC-FE5C.7, AC-FE5C.8) — diperiksa dari kode/test, bukan layar

| # | Butir | Hasil yang diharapkan | Hasil |
|---|---|---|---|
| J1 | AC-FE5C.7 | Setiap service baru (`laporanTukin`, `penyesuaianTukin`, `rekapKehadiran`) punya `*.amplop.spec.ts` dengan klien HTTP asli (amplop `{data, meta}`); grep `res.data.data` kosong | ✅ `laporanTukin`, `rekapTukin`, `penyesuaianTukin`, `rekapKehadiran` (+ uang makan) `.amplop.spec.ts`; `res.data.data` hanya di komentar test |
| J2 | AC-FE5C.8 | Komponen generik baru (mis. `StatusPresensiChip`, `LegendaStatusPresensi` bila diletakkan di `shared/ui`) diekspor dari `shared/ui/index.ts` dengan test keyboard/ARIA di `shared/ui/__tests__` | ✅ chip & legenda khusus domain di `features/presensi/components` (bukan `shared/ui`), diuji `statusPresensi.spec`; `shared/ui` baru: `pickerControl` (CR-081) + `pickers.spec` |
| J3 | AC-FE5C.2 | Vitest memeriksa query string permintaan per filter | ✅ `useFilterLaporan.spec`, `*.amplop.spec`, spec halaman |

## K. Deviasi yang sah (bukan temuan)

| Sumber | Deviasi |
|---|---|
| Q5C-01 | Map picker = `IndikatorRadius` SVG tanpa peta tile (gap: tanpa peta jalan/latar) |
| F5B-4 / F5B-5 | Hanya varian `skp`; tanpa filter `tukinver`; tanpa "Tukin Lama" |
| F5B-8 / F5B-20 | Tanpa nominal Tukin / uang makan di layar |
| ADR-035 | PDF Rekap Tukin hanya lingkup unit/satker ≤ batas baris; lingkup `0` → 422 (pakai XLSX) |
| Q5C-04 ◻ | Tanpa cetak HTML dari layar; cukup PDF/XLSX server |
| Q5C-08 ◻ | Blok penanda tangan hanya di berkas, tidak di layar |
| QAUI-005 | Figma halaman laporan belum ada: QA lewat checklist ini |

## L. Penutupan

| # | Butir | Hasil |
|---|---|---|
| L1 | Reviewer CR + QA menyepakati checklist ini (termasuk path ◻ Q5C-02 dan warna ◻ Q5C-03 hasil C0) sebelum QA Lapis 1 | ✅ diterima reviewer CR 29-10-2026 (`c8fa1e3`) |
| L2 | Deviasi §K dianggap sah; temuan lain = temuan QA biasa (CR-075 dst.) | ✅ |
| L3 | 5C tetap IN_PROGRESS sampai semua butir ✅ atau — (AC-5C.X2); hasil dicatat di `Laporan_Akhir_Fase5.md` (MAKE-054) | ✅ semua butir ✅ atau — |

## M. Temuan

QA dijalankan di app demo pada `main` `12f2f46` (role 1–8), QA ulang pada `15beef1`, `e9027e5`, `57122dd`, dan
`6c116ac`. Semua temuan ditutup di `main`.

| No | Butir | Halaman | Layar | Temuan | Bukti | CR |
|---|---|---|---|---|---|---|
| T-01 | A7 | Laporan/Rekap Tukin | 1440 | Bulan bawaan = bulan kalender; legacy (tanggal > 15 → bulan berikutnya) | `useFilterLaporan.spec` | CR-078 `7eac943` |
| T-02 | E1 | Laporan per pegawai | 1440, 390 | Sel Tanggal sticky tidak ikut warna baris | `LaporanTukin.spec` | CR-077 `7eac943` |
| T-03 | A3 | Laporan per pegawai | 375 | Pojok header sticky di atas navbar saat digulir | QA ulang | CR-077 `7eac943` |
| T-04 | A6 | Semua laporan | 1440 | Rute yang sama (back/forward, menu) tidak menyinkronkan filter/NIP | `useFilterLaporan.spec`, `LaporanTukin.spec` | CR-078 `7eac943` |
| T-05 | F5 | Semua unduhan | 1440 | Body galat JSON pada unduhan blob tidak diurai (pesan server hilang) | `rekapTukin.amplop.spec` | CR-078 `7eac943` |
| T-06 | G6, A9 | Penyesuaian Tukin, Hari Libur | 1440 | Fokus tidak kembali ke ⋮ setelah `ConfirmDialog` | QA ulang | CR-078 `7eac943` |
| T-07 | H1 | Rekap Kehadiran | 1440 | Header "TB (v2)" menampilkan penanda internal | `RekapKehadiranPage.spec` | CR-078 `7eac943` |
| T-08 | — | — | — | Dibatalkan (tidak terulang) | — | — |
| T-09 | D4 | Laporan per pegawai | 1440 | Predikat SKP hilang bila kosong | `LaporanTukin.spec` | CR-078 `7eac943` |
| T-10 | A3 | Laporan Tukin (role 1) | viewport pendek | Area tabel ±0 px | QA ulang | CR-077 `7eac943` |
| T-11 | H4 | Uang Makan IL, Rekap Kehadiran | 1440 | Teks bantuan menimpa isi tabel | QA ulang | CR-077 `7eac943` |
| T-12 | A3 | Master Data (regresi CR-077) | lg | Panel grid memanjang, halaman tidak menggulir di dalam panel | QA ulang | CR-079 `e9027e5` |
| T-13 | A2, H4 | Uang Makan IL, Rekap Kehadiran | < lg | T-11 masih terjadi di bawah lg | `RekapUangMakanPage.spec`, `RekapKehadiranPage.spec` | CR-079 `e9027e5` |
| T-14 | A3 | Laporan/Rekap Tukin | 1334×630 | Hanya 1 baris tabel terbaca | `LaporanTukin.spec` | CR-079 `e9027e5` |
| T-15 | A2 | Presensi Saya (5A) | 423 | `UiMonthPicker` meluap, halaman bergulir horizontal | `pickers.spec` | CR-081 `6c116ac` |
