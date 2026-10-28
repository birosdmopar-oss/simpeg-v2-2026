# Checklist QA Lapis 1 — Frontend Paket 5A (Q5A-18)

Untuk coder 2 / QA. Acuan: PRD 5A §7.8 (AC-FE.1–FE.9), AGENTS.md §1 & §3, QAUI-005 bagian 5A. Butir ⛔ Q5A-18: checklist
ini **disepakati reviewer CR/QA sebelum QA dimulai**; FE 5A (D-12) tetap **IN_PROGRESS** sampai seluruh butir lolos
(PRD 5A §11.1 #7, AC-FE.9).

**Acuan visual:** hanya Slip Gaji yang punya frame Figma — PNG `Figma - UI Slip Gaji.png` (layar) dan
`UI Preview Slip Gaji (Export PDF).png` (PDF). Layar lain tanpa Figma (Kelola Slip Gaji, Impor, Presensi Saya, Data
Presensi, Verifikasi) dinilai terhadap **pola komponen Fase 3** (AGENTS §3.6): `RedesignShell`, kartu `rounded-2xl`,
`UiTabs`, `UiSelect`, `UiSearchInput`, `UiStatTile`, `UiBadge`, `RowActionsMenu`, `ConfirmDialog`, tabel dengan kolom Aksi
sticky.

**Lingkungan:** server uji (bukan dev) dengan **HTTPS** (wajib untuk geolokasi, Q5A-22), data sintetis. Siapkan akun
per role (1, 2, 3, 4, 5, 6, 7, 8) dengan `must_change_password = 0`, satu akun role 1 yang terdaftar sebagai akun
presensi manual (`presensi.akunPresensiManual`) dan satu role 1 yang tidak. Data: slip periode berjalan (sebelum &
sesudah tanggal 4 pukul 10:00 WIB), slip sudah dibuka & belum dibuka, berkas impor Excel valid + berisi baris galat +
duplikat, satu lokasi presensi dengan aturan yang mencakup akun role 2, satu pegawai tanpa aturan.

**Ukuran layar** setiap halaman: 1440, 600, 390 px. **Browser:** Chrome & Firefox desktop; Chrome Android (presensi).

Isi kolom Hasil: ✅ lolos / ❌ gagal (+ nomor temuan) / — tidak berlaku.

## A. Umum (semua halaman 5A)

| # | Butir | Hasil |
|---|---|---|
| A1 | Navbar atas sticky; menu samping & isi halaman punya area gulir mandiri; scrollbar ramping custom | |
| A2 | Tidak ada elemen bawaan browser yang terlihat: `<select>` native, popup tanggal/jam bawaan, tooltip `title`, `alert/confirm` | |
| A3 | Tidak ada scroll horizontal halaman di 390/600 px; tabel tergulir di wadahnya sendiri | |
| A4 | Setelah CR-069 di `main`: hanya area tabel yang bergulir, header/filter tetap (pola baru) | |
| A5 | Semua aksi per baris lewat ⋮ di kolom Aksi paling kanan; menu terbuka **di samping tombol** (bukan di luar layar) di desktop & 390 px | |
| A6 | Label menu ⋮ persis baku; aksi destruktif merah paling bawah dengan pemisah; aksi yang tidak berlaku tidak tampil | |
| A7 | Aksi berisiko memakai `ConfirmDialog`; tombol batal & konfirmasi jelas | |
| A8 | Keyboard: Tab mencapai semua kontrol, Enter/Spasi membuka, Esc menutup menu & dialog, fokus selalu terlihat | |
| A9 | Pesan galat server tampil apa adanya (403 "tidak berhak", 404, 422 per kolom, 429) | |
| A10 | Breadcrumb & judul halaman benar; judul tab browser "<Halaman> · SIMPEG v2" | |

## B. Menu per role (AC-FE.6)

| # | Role | Anak grup Presensi yang tampil | Hasil |
|---|---|---|---|
| B1 | 1 Super Admin | Kelola Slip Gaji, Data Presensi | |
| B2 | 2 Pegawai | Presensi Saya, Slip Gaji | |
| B3 | 3 Admin Satker | Kelola Slip Gaji, Data Presensi | |
| B4 | 4 Admin View Eselon I | Data Presensi | |
| B5 | 5 Menteri | Data Presensi | |
| B6 | 6 PTT | Presensi Saya, Slip Gaji | |
| B7 | 7 PPPK | Presensi Saya, Slip Gaji | |
| B8 | 8 Pimpinan | Grup Presensi tidak tampil | |
| B9 | Membuka URL halaman yang tidak berhak lewat alamat langsung → halaman 403 | |

## C. Slip Gaji pegawai — vs Figma (AC-FE.3)

| # | Butir | Hasil |
|---|---|---|
| C1 | Tata letak, warna, tipografi, radius, bayangan kartu slip sesuai `Figma - UI Slip Gaji.png` | |
| C2 | Peringatan "Data pada slip gaji berasal dari Biro Perencanaan Keuangan…" tampil | |
| C3 | Empat keadaan: tanpa periode; data belum tersedia; tersedia belum dibuka (tombol "Lihat Slip Gaji"); sudah dibuka pada kunjungan berikutnya (waktu WIB, "hubungi Admin SIMPEG", **tanpa nominal & tanpa Cetak PDF**) | |
| C4 | Sebelum tanggal 4 pukul 10:00 WIB periode berjalan belum bisa dibuka | |
| C5 | Modal "Slip gaji hanya dapat dibuka satu kali": "Lanjutkan" nonaktif sampai centang | |
| C6 | Setelah buka: identitas, A. GPP (Penghasilan/Potongan berkelompok), B. TK **tanpa baris Pajak & Tunjangan Pajak** (CR-067), C. Uang Makan, ringkasan total | |
| C7 | Nominal format rupiah tanpa desimal; potongan bertanda "-" | |
| C8 | Muat ulang halaman setelah buka → keadaan "sudah dibuka" (nominal tidak tersimpan di browser) | |
| C9 | Cetak PDF mengunduh berkas `Slip_Gaji_<NAMA>_<Bulan>_<Tahun>.pdf` | |

## D. PDF slip — vs Figma (AC-FE.3, AC-D11.11)

| # | Butir | Hasil |
|---|---|---|
| D1 | Tata letak sesuai `UI Preview Slip Gaji (Export PDF).png`: kop berlogo, judul, identitas, A/B/C, ringkasan, QR + "Jakarta, <tanggal>" | |
| D2 | Bagian B (TK) tanpa Pajak/Tunjangan Pajak; "Jumlah Penghasilan/Potongan" TK = kotor/potongan (CR-067) | |
| D3 | QR berupa gambar; dipindai → halaman verifikasi publik "Dokumen Asli" | |
| D4 | PDF admin untuk slip yang belum dibuka tidak memuat QR | |
| D5 | Satu halaman per pegawai; tanpa kaki "Halaman x dari y" | |

## E. Kelola Slip Gaji (role 1, 3) — pola Fase 3 (AC-FE.4)

| # | Butir | Hasil |
|---|---|---|
| E1 | Tab Monitoring/Impor; tab tersimpan di URL (`?tab=impor`) | |
| E2 | Periode bawaan = periode terbaru yang tersedia; daftar periode dari server | |
| E3 | Filter satker hanya untuk role 1; role 3 hanya melihat satkernya | |
| E4 | Ringkasan Total/Sudah/Belum Dibuka sesuai data | |
| E5 | Tabel: NIP, Nama, Unit/Satker, Periode, Status (badge), Waktu Dibuka WIB, IP; Aksi sticky | |
| E6 | ⋮ urutan Edit → Lihat detail → Cetak PDF → Buka ulang; "Buka ulang" hanya untuk slip sudah dibuka | |
| E7 | Lihat detail: identitas + seluruh kolom GPP & TK (**termasuk** Pajak/Tunjangan Pajak, modal admin legacy) + uang makan + total; status slip tidak berubah | |
| E8 | Edit: 28 kolom terisi, dua kolom → satu kolom di ≤ 600 px; nilai negatif → galat per kolom | |
| E9 | Buka ulang → ConfirmDialog → status kembali "Belum Dibuka"; pegawai dapat membuka 1x lagi | |
| E10 | Periode tanpa data → ajakan impor | |

## F. Impor Slip Gaji (AC-FE.2)

| # | Butir | Hasil |
|---|---|---|
| F1 | Unggah hanya .xlsx/.xls ≤ 5 MB (berkas lain ditolak dengan pesan) | |
| F2 | Filter periode opsional (bulan & tahun sekaligus) | |
| F3 | Tabel pratinjau: status per baris Valid/Duplikat/Galat (badge), **baris galat ditandai warna + daftar pesan**, ringkasan | |
| F4 | "Timpa data yang sudah ada" **tercentang bawaan**; keterangan duplikat mengikuti pilihan | |
| F5 | "Simpan ke Database" lewat ConfirmDialog → hasil (baru/diperbarui/dilewati/gagal) + rincian galat | |
| F6 | Data berubah oleh proses lain (409) → pesan + ajakan unggah ulang | |

## G. Presensi Saya (role 2, 6, 7) — mobile-first (AC-FE.1, AC-FE.8)

| # | Butir | Hasil |
|---|---|---|
| G1 | Tanggal & jam lokal tampil (zonasi satker: WITA/WIT bertambah 60/120 menit) | |
| G2 | Panel Status Lokasi **sebelum** menekan tombol: izin lokasi, koordinat, akurasi, dalam/luar radius + lokasi terdekat + jarak + radius, indikator radius (SVG) | |
| G3 | **Perangkat nyata (HTTPS, ponsel):** izinkan lokasi → status "Diizinkan"; di dalam radius → "Rekam Masuk" aktif | |
| G4 | Perangkat nyata di luar radius → tombol nonaktif dengan alasan teks; pindah ke dalam → aktif dalam ±10 detik | |
| G5 | Tolak izin lokasi → status "Ditolak" + petunjuk; tombol nonaktif (pegawai yang wajib geofence) | |
| G6 | Pegawai tanpa aturan lokasi → tombol aktif tanpa menunggu lokasi | |
| G7 | Di luar jendela (masuk 04:00–12:30, pulang 12:31–23:59 waktu setempat) → tombol nonaktif dengan alasan | |
| G8 | Rekam Masuk → notifikasi + jam masuk tampil; masuk kedua tidak bisa | |
| G9 | Riwayat per bulan: Hari, Tanggal, Jam Masuk, Jam Pulang, Sumber (badge Online/Manual/Impor), paginasi | |
| G10 | Rancangan nyaman di 390 px (tombol lebar penuh, teks terbaca) | |

## H. Data Presensi (role 1, 3, 4, 5) & presensi manual (AC-FE.5)

| # | Butir | Hasil |
|---|---|---|
| H1 | Daftar pegawai sesuai lingkup (role 3 satker sendiri); filter Unit → Satker berjenjang; pencarian | |
| H2 | ⋮ "Lihat presensi" → detail pegawai; tombol "Daftar Pegawai" kembali | |
| H3 | Detail: rentang tanggal (tanpa rentang = 100 data terbaru), badge Sumber, lokasi masuk/pulang | |
| H4 | Akun **bukan** akun khusus (role 1/3/4/5): tanpa "Tambah Presensi" dan tanpa kolom Aksi | |
| H5 | Akun khusus: "Tambah Presensi" + ⋮ Edit → Hapus (merah) | |
| H6 | Tambah: NIP, tanggal (≤ hari ini), jam masuk/pulang (minimal satu); galat per kolom; duplikat → "Gunakan Edit." | |
| H7 | Edit: NIP & tanggal terkunci; jam yang tidak diubah tetap (termasuk detik) | |
| H8 | Hapus → ConfirmDialog → baris hilang | |

## I. Verifikasi publik `/verify/slip/:token` (AC-FE.7)

| # | Butir | Hasil |
|---|---|---|
| I1 | Bisa dibuka tanpa login dan saat sudah login; tanpa layout aplikasi (pola `AuthCardLayout`) | |
| I2 | Token sah → "Dokumen Asli": periode, satker penerbit, waktu terbit, nama tersamar; **tanpa** nominal/NIP | |
| I3 | Token salah/huruf besar/berspasi → pesan seragam "Dokumen tidak ditemukan atau tidak valid." | |
| I4 | Permintaan berulang cepat → pesan "terlalu banyak permintaan" | |
| I5 | Sumber halaman memuat `<meta name="robots" content="noindex, nofollow">` | |
| I6 | Satu-satunya tautan = "Masuk" | |

## J. Catatan & temuan

| No | Halaman / butir | Temuan | Ukuran/browser | Status |
|---|---|---|---|---|
| | | | | |
