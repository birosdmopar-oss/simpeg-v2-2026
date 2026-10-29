# Checklist QA Lapis 1 — Frontend Paket 5A (Q5A-18)

> **HASIL 29-10-2026: LOLOS dengan temuan rendah (§J).** Dijalankan reviewer CR di app demo lokal (`main` `6c116ac`,
> data sintetis, slip lewat impor UI) untuk role 1, 2, 3, 6 (role 4/5/7/8 dari QA Lapis 1 5C). Uji perangkat nyata
> (G3, G4 GPS lewat HTTPS, pindai QR fisik) menjadi blocker go-live (`Laporan_Akhir_Fase5.md` §8).

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
| A1 | Navbar atas sticky; menu samping & isi halaman punya area gulir mandiri; scrollbar ramping custom | ✅ |
| A2 | Tidak ada elemen bawaan browser yang terlihat: `<select>` native, popup tanggal/jam bawaan, tooltip `title`, `alert/confirm` | ✅ (satu `<select>` `sr-only` + `aria-hidden` milik `UiSelect`, tidak terlihat) |
| A3 | Tidak ada scroll horizontal halaman di 390/600 px; tabel tergulir di wadahnya sendiri | ✅ 390 px: `scrollWidth` = lebar viewport (Presensi Saya, Slip Gaji, Verifikasi; Presensi Saya setelah CR-081) |
| A4 | Desktop ≥ 1024: hanya area tabel yang bergulir, judul/filter tetap, header kolom sticky (CR-069; Kelola Slip tab Monitoring & Data Presensi; tab Impor menggulir normal); mobile: header kolom sticky (CR-070, bila sudah di `main`) | ✅ Kelola Slip & Data Presensi: hanya area tabel menggulir |
| A5 | Semua aksi per baris lewat ⋮ di kolom Aksi paling kanan; menu terbuka **di samping tombol** (bukan di luar layar) di desktop & 390 px | ✅ menu terbuka di samping tombol, di dalam viewport (423 px) |
| A6 | Label menu ⋮ persis baku; aksi destruktif merah paling bawah dengan pemisah; aksi yang tidak berlaku tidak tampil | ✅ Kelola Slip: Edit → Lihat detail → Cetak PDF → Buka ulang (hanya slip sudah dibuka); Data Presensi: Edit → Hapus (merah, pemisah) |
| A7 | Aksi berisiko memakai `ConfirmDialog`; tombol batal & konfirmasi jelas | ✅ impor, Buka ulang, Hapus presensi |
| A8 | Keyboard: Tab mencapai semua kontrol, Enter/Spasi membuka, Esc menutup menu & dialog, fokus selalu terlihat | ✅ / ❌ T5A-04 (fokus ke BODY setelah aksi ⋮ yang memuat ulang daftar) |
| A9 | Pesan galat server tampil apa adanya (403 "tidak berhak", 404, 422 per kolom, 429) | ✅ 403, 404, 422 per kolom, 429 |
| A10 | Breadcrumb & judul halaman benar; judul tab browser "<Halaman> · SIMPEG v2" | ✅ |

## B. Menu per role (AC-FE.6)

| # | Role | Anak grup Presensi yang tampil | Hasil |
|---|---|---|---|
| B1 | 1 Super Admin | Kelola Slip Gaji, Data Presensi | ✅ (+ menu 5C) |
| B2 | 2 Pegawai | Presensi Saya, Slip Gaji | ✅ (+ Laporan Tukin Saya) |
| B3 | 3 Admin Satker | Kelola Slip Gaji, Data Presensi | ✅ (+ menu 5C) |
| B4 | 4 Admin View Eselon I | Data Presensi | ✅ (QA 5C: Data Presensi + Rekap Kehadiran) |
| B5 | 5 Menteri | Data Presensi | ✅ (QA 5C: Data Presensi) |
| B6 | 6 PTT | Presensi Saya, Slip Gaji | ✅ (+ Laporan Tukin Saya) |
| B7 | 7 PPPK | Presensi Saya, Slip Gaji | ✅ (QA 5C, + Laporan Tukin Saya) |
| B8 | 8 Pimpinan | Grup Presensi tidak tampil | ✅ (QA 5C) |
| B9 | Membuka URL halaman yang tidak berhak lewat alamat langsung → halaman 403 | ✅ role 3 → `/presensi`, `/presensi/slip-gaji` = halaman 403 |

## C. Slip Gaji pegawai — vs Figma (AC-FE.3)

| # | Butir | Hasil |
|---|---|---|
| C1 | Tata letak, warna, tipografi, radius, bayangan kartu slip sesuai `Figma - UI Slip Gaji.png` | ✅ desktop 1440: tiga kolom Penghasilan / Potongan / Ringkasan + Cetak PDF kanan atas (isi kelompok A/B/C ikut legacy `0cf7e67`) |
| C2 | Peringatan "Data pada slip gaji berasal dari Biro Perencanaan Keuangan…" tampil | ✅ |
| C3 | Empat keadaan: tanpa periode; data belum tersedia; tersedia belum dibuka (tombol "Lihat Slip Gaji"); sudah dibuka pada kunjungan berikutnya (waktu WIB, "hubungi Admin SIMPEG", **tanpa nominal & tanpa Cetak PDF**) | ✅ belum tersedia / tersedia (Lihat Slip Gaji) / sudah dibuka (WIB, "hubungi Admin SIMPEG", tanpa nominal & Cetak PDF); "tanpa periode" tidak terjadi (daftar periode sejak 2024, ikut legacy) |
| C4 | Sebelum tanggal 4 pukul 10:00 WIB periode berjalan belum bisa dibuka | ✅ periode November (sudah diimpor) belum bisa dipilih sebelum 4-11 10:00 WIB |
| C5 | Modal "Slip gaji hanya dapat dibuka satu kali": "Lanjutkan" nonaktif sampai centang | ✅ |
| C6 | Setelah buka: identitas, A. GPP (Penghasilan/Potongan berkelompok), B. TK **tanpa baris Pajak & Tunjangan Pajak** (CR-067), C. Uang Makan, ringkasan total | ✅ TK tanpa Pajak/Tunjangan Pajak |
| C7 | Nominal format rupiah tanpa desimal; potongan bertanda "-" | ✅ |
| C8 | Muat ulang halaman setelah buka → keadaan "sudah dibuka" (nominal tidak tersimpan di browser) | ✅ tanpa nominal di browser storage |
| C9 | Cetak PDF mengunduh berkas `Slip_Gaji_<NAMA>_<Bulan>_<Tahun>.pdf` | ✅ `Slip_Gaji_<NAMA>_Oktober_2026.pdf` |

## D. PDF slip — vs Figma (AC-FE.3, AC-D11.11)

| # | Butir | Hasil |
|---|---|---|
| D1 | Tata letak sesuai `UI Preview Slip Gaji (Export PDF).png`: kop berlogo, judul, identitas, A/B/C, ringkasan, QR + "Jakarta, <tanggal>" | ✅ terhadap legacy `cetak.php` @`0cf7e67` (QR + "Generated by SIMPEG" + tanggal kanan atas, A/B/C, ringkasan); PNG Figma versi lebih lama (tanpa A/B/C) — deviasi dicatat; logo kop demo kosong = QC-2 |
| D2 | Bagian B (TK) tanpa Pajak/Tunjangan Pajak; "Jumlah Penghasilan/Potongan" TK = kotor/potongan (CR-067) | ✅ |
| D3 | QR berupa gambar; dipindai → halaman verifikasi publik "Dokumen Asli" | ✅ QR gambar; URL token sah → "Dokumen Asli" (token dari service, pindai fisik belum — T5A-03) |
| D4 | PDF admin untuk slip yang belum dibuka tidak memuat QR | ✅ PDF admin slip belum dibuka: tanpa gambar QR |
| D5 | Satu halaman per pegawai; tanpa kaki "Halaman x dari y" | ✅ |

## E. Kelola Slip Gaji (role 1, 3) — pola Fase 3 (AC-FE.4)

| # | Butir | Hasil |
|---|---|---|
| E1 | Tab Monitoring/Impor; tab tersimpan di URL (`?tab=impor`) | ✅ |
| E2 | Periode bawaan = periode terbaru yang tersedia; daftar periode dari server | ✅ bawaan = bulan berjalan yang sudah dirilis (legacy `Sl_gaji.php:219-221`); periode masa depan yang sudah diimpor ikut tampil di pilihan [V2] |
| E3 | Filter satker hanya untuk role 1; role 3 hanya melihat satkernya | ✅ |
| E4 | Ringkasan Total/Sudah/Belum Dibuka sesuai data | ✅ |
| E5 | Tabel: NIP, Nama, Unit/Satker, Periode, Status (badge), Waktu Dibuka WIB, IP; Aksi sticky | ✅ |
| E6 | ⋮ urutan Edit → Lihat detail → Cetak PDF → Buka ulang; "Buka ulang" hanya untuk slip sudah dibuka | ✅ |
| E7 | Lihat detail: identitas + seluruh kolom GPP & TK (**termasuk** Pajak/Tunjangan Pajak, modal admin legacy) + uang makan + total; status slip tidak berubah | ✅ status tetap |
| E8 | Edit: 28 kolom terisi, dua kolom → satu kolom di ≤ 600 px; nilai negatif → galat per kolom | ✅ satu kolom di 423 px; negatif → "Nilai harus berupa angka non-negatif." |
| E9 | Buka ulang → ConfirmDialog → status kembali "Belum Dibuka"; pegawai dapat membuka 1x lagi | ✅ (role 3) → Belum Dibuka + notifikasi |
| E10 | Periode tanpa data → ajakan impor | ✅ |

## F. Impor Slip Gaji (AC-FE.2)

| # | Butir | Hasil |
|---|---|---|
| F1 | Unggah hanya .xlsx/.xls ≤ 5 MB (berkas lain ditolak dengan pesan) | ✅ `.txt` & > 5 MB ditolak klien; isi bukan Excel ditolak server |
| F2 | Filter periode opsional (bulan & tahun sekaligus) | ✅ |
| F3 | Tabel pratinjau: status per baris Valid/Duplikat/Galat (badge), **baris galat ditandai warna + daftar pesan**, ringkasan | ✅ |
| F4 | "Timpa data yang sudah ada" **tercentang bawaan**; keterangan duplikat mengikuti pilihan | ✅ |
| F5 | "Simpan ke Database" lewat ConfirmDialog → hasil (baru/diperbarui/dilewati/gagal) + rincian galat | ✅ 5 baru / 3 gagal + rincian (label baris ganda — T5A-02) |
| F6 | Data berubah oleh proses lain (409) → pesan + ajakan unggah ulang | — baris yang bentrok saat commit dihitung "diperbarui" (validasi ulang dalam transaksi); 409 hanya pada balapan: BE `ImporSlipKonflikUniqueTest` |

## G. Presensi Saya (role 2, 6, 7) — mobile-first (AC-FE.1, AC-FE.8)

| # | Butir | Hasil |
|---|---|---|
| G1 | Tanggal & jam lokal tampil (zonasi satker: WITA/WIT bertambah 60/120 menit) | ✅ WIB |
| G2 | Panel Status Lokasi **sebelum** menekan tombol: izin lokasi, koordinat, akurasi, dalam/luar radius + lokasi terdekat + jarak + radius, indikator radius (SVG) | ✅ (lokasi disimulasikan di halaman; pengaturan browser tidak diubah) |
| G3 | **Perangkat nyata (HTTPS, ponsel):** izinkan lokasi → status "Diizinkan"; di dalam radius → "Rekam Masuk" aktif | — perangkat nyata HTTPS → blocker go-live |
| G4 | Perangkat nyata di luar radius → tombol nonaktif dengan alasan teks; pindah ke dalam → aktif dalam ±10 detik | ✅ simulasi: luar radius → nonaktif + alasan, kembali → aktif ±10 detik; perangkat nyata → blocker go-live |
| G5 | Tolak izin lokasi → status "Ditolak" + petunjuk; tombol nonaktif (pegawai yang wajib geofence) | ✅ |
| G6 | Pegawai tanpa aturan lokasi → tombol aktif tanpa menunggu lokasi | ✅ (PTT tanpa aturan lokasi) |
| G7 | Di luar jendela (masuk 04:00–12:30, pulang 12:31–23:59 waktu setempat) → tombol nonaktif dengan alasan | ✅ |
| G8 | Rekam Masuk → notifikasi + jam masuk tampil; masuk kedua tidak bisa | ✅ jalur Rekam Pulang (QA malam hari; masuk di luar jendela) |
| G9 | Riwayat per bulan: Hari, Tanggal, Jam Masuk, Jam Pulang, Sumber (badge Online/Manual/Impor), paginasi | ✅ |
| G10 | Rancangan nyaman di 390 px (tombol lebar penuh, teks terbaca) | ✅ |

## H. Data Presensi (role 1, 3, 4, 5) & presensi manual (AC-FE.5)

| # | Butir | Hasil |
|---|---|---|
| H1 | Daftar pegawai sesuai lingkup (role 3 satker sendiri); filter Unit → Satker berjenjang; pencarian | ✅ role 1 & 3 |
| H2 | ⋮ "Lihat presensi" → detail pegawai; tombol "Daftar Pegawai" kembali | ✅ |
| H3 | Detail: rentang tanggal (tanpa rentang = 100 data terbaru), badge Sumber, lokasi masuk/pulang | ✅ |
| H4 | Akun **bukan** akun khusus (role 1/3/4/5): tanpa "Tambah Presensi" dan tanpa kolom Aksi | ✅ role 3 |
| H5 | Akun khusus: "Tambah Presensi" + ⋮ Edit → Hapus (merah) | ✅ |
| H6 | Tambah: NIP, tanggal (≤ hari ini), jam masuk/pulang (minimal satu); galat per kolom; duplikat → "Gunakan Edit." | ✅ wajib isi, tanggal > hari ini, duplikat "Gunakan Edit." |
| H7 | Edit: NIP & tanggal terkunci; jam yang tidak diubah tetap (termasuk detik) | ✅ jam masuk `07:30:27` tetap setelah ubah jam pulang |
| H8 | Hapus → ConfirmDialog → baris hilang | ✅ |

## I. Verifikasi publik `/verify/slip/:token` (AC-FE.7)

| # | Butir | Hasil |
|---|---|---|
| I1 | Bisa dibuka tanpa login dan saat sudah login; tanpa layout aplikasi (pola `AuthCardLayout`) | ✅ (juga saat login) |
| I2 | Token sah → "Dokumen Asli": periode, satker penerbit, waktu terbit, nama tersamar; **tanpa** nominal/NIP | ✅ |
| I3 | Token salah/huruf besar/berspasi → pesan seragam "Dokumen tidak ditemukan atau tidak valid." | ✅ token salah & huruf besar |
| I4 | Permintaan berulang cepat → pesan "terlalu banyak permintaan" | ✅ |
| I5 | Sumber halaman memuat `<meta name="robots" content="noindex, nofollow">` | ✅ |
| I6 | Satu-satunya tautan = "Masuk" | ✅ |

## J. Catatan & temuan

| No | Halaman / butir | Temuan | Ukuran/browser | Status |
|---|---|---|---|---|
| T5A-01 | Verifikasi publik | Token berganti pada komponen yang sama (navigasi SPA) tidak memuat ulang; buka lewat QR (muat penuh) tidak terdampak | semua | Rendah — terbuka |
| T5A-02 | F5 rincian galat impor | Label baris ganda: "GPP baris 9: Sheet GPP baris 9: …" | semua | Rendah — terbuka |
| T5A-03 | D3 PDF slip | QR hampir tanpa zona kosong bawah; garis kotak "Generated by SIMPEG" menempel. Pindai fisik belum (tanpa pembaca QR di mesin QA) | PDF | Rendah — cek saat uji perangkat |
| T5A-04 | A8 Kelola Slip (Buka ulang), Data Presensi (Hapus) | Fokus jatuh ke BODY: baris "Memuat…" (`v-if`) menggantikan semua baris saat daftar dimuat ulang, sehingga ⋮ tujuan fokus (CR-078 T-06) ikut lepas | semua | Rendah–sedang — terbuka |
| D-01 | D1 | PDF ikut legacy `cetak.php` @`0cf7e67`; PNG Figma export PDF versi lebih lama (tanpa A/B/C, kalimat "Petugas Pengelola…", tanggal di bawah) | PDF | Deviasi — keputusan Horii |
| D-02 | E2 | Pilihan periode admin memuat periode masa depan yang sudah diimpor (legacy hanya s.d. bulan berjalan); bawaan tetap bulan berjalan | semua | Deviasi kecil [V2] |
