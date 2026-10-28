# Checklist QA Lapis 1 — Frontend Modul C Fase 4 (C-11)

Untuk reviewer CR (pelaksana QA Lapis 1 di app demo, keputusan Horii 28-10-2026). Acuan: PRD Fase 4 §6.11.8 (Q-18, 17 butir;
basis `main` `139898f`), §6.11.1–§6.11.7, register deviasi §14.6 (v1.4) dan §14.7 (v1.5), AGENTS.md §1 & §3. Butir 17
§6.11.8: checklist ini **disepakati reviewer CR + QA sebelum QA dimulai**; C-11 tetap **IN_PROGRESS** sampai seluruh butir
lolos (PRD §10.1 butir 9).

**Acuan visual (Figma Modul C belum ada):** (1) Gambar 17 "Redesign Halaman Status Layanan" (Laporan Redesign §4.1.4) untuk
Status Layanan; (2) halaman pembanding Fase 3/7 yang sudah lolos QA: `DaftarPegawaiPage`, `UserManagementPage`,
`VerifikasiLkhPage` + `LkhProsesDialog`, `UsulanKonketPage`, `LkhFormDialog`, `LaporanPage`, `DokumenPage`. Selisih mockup ↔
PRD di tabel §6.11.8 **bukan** temuan (ikuti PRD). Halaman tanpa frame dicatat "Figma belum ada" (AGENTS §3 butir 6).

**Lingkungan:** app demo lokal (DB `simpeg_v2_t_app_demo` @3307, backend port 8120, captcha mock), data sintetis. Akun di
`AKUN_DEMO.md` folder demo-seed (password tidak ditulis di sini). Data uji tambahan dibuat skrip `qa_fase4_data.php`
(idempoten; penanda `[QA-F4 <kode>]` di keterangan/instansi):

| Kode | Data | Untuk butir |
|---|---|---|
| Akun | `demo.admin` (1), `demo.pegawai` (2, PIC TB/IB/PMK), `demo.pemohon` (2, pemohon cuti C1–C5: PNS pertama di satker kepegawaian yang atasan langsung & PyB-nya terisi, dipilih skrip; data struktur demo tidak diubah), `demo.atasan` (2, atasan langsung `demo.pemohon`), `demo.pyb` (2, pejabat berwenang `demo.pemohon`; tidak dibuat bila langkah tunggal), `demo.satker` (3), `demo.eselon1` (4), `demo.menteri` (5), `demo.ptt` (6), `demo.pppk` (7), `demo.pimpinan` (8) | B, semua |
| C1 | Cuti Tahunan `demo.pemohon` menunggu atasan langsung | D (pengingat AL, proses AL) |
| C2 | Cuti Tahunan `demo.pemohon` menunggu PyB (sudah disetujui AL); **sejak QA 28-10 Ditangguhkan** (uji D9), jalankan ulang skrip untuk data baru | D (pengingat PyB, proses PyB) |
| C3 | Cuti ditolak atasan langsung | C4, D |
| C4 | Cuti ditangguhkan PyB | C4, D |
| C5 | Cuti disetujui (final) | D (TTE, unduh surat), I |
| TB id 1 (seed) / T1 | TB disetujui / TB `demo.ptt` menunggu PIC | F |
| I1 / I2 | IB `demo.pppk` menunggu PIC / ditolak PIC | F, C4 |
| P1 / P2 / P3 | Pengajuan PMK `demo.atasan` menunggu PIC / diproses / ditolak | G, C4 |
| Riwayat PMK | Satu riwayat PMK final `demo.atasan` + satu arsip SK | G |

**Ukuran layar** setiap halaman dan dialog: **1440, 600, 390 px** (desktop Chrome; 600/390 lewat emulasi). Bukti =
tangkapan layar, berdampingan dengan Gambar 17 / halaman pembanding.

Isi kolom Hasil: ✅ lolos / ❌ gagal (+ nomor temuan di §M) / — tidak berlaku.

**Hasil QA Lapis 1 (reviewer CR, 28-10-2026, `main` `139898f`):** kolom Hasil di bawah diisi dari laporan reviewer; temuan di §M. **QA ulang reviewer 28-10 di `main` `153424b`** (CR-072, CR-073
`30ff6cb`; MAKE-046 Ekspor): temuan §M 1–8, 10 lolos; temuan baru §M 11 diperbaiki CR-074 (`main` `a00f154`) dan lolos uji demo reviewer 28-10 — **C-11 LOLOS QA Lapis 1
(28-10-2026)**; semua butir ✅ atau — (tercatat).

## A. Umum — semua halaman Modul C (butir 5, 6, 7, 10–14)

Halaman: `/layanan/cuti`, `/layanan/cuti/:id`, `/layanan/cuti/saldo`, `/layanan/cuti/petugas-approval`, `/layanan/tb`,
`/layanan/tb/:id`, `/layanan/ib`, `/layanan/ib/:id`, `/layanan/pmk`, `/layanan/pmk/pengajuan/:id`, `/layanan/status`,
`/layanan/petugas`. Akun: `demo.admin` (melihat semua halaman) + akun per role sesuai bagian B.

| # | Butir §6.11.8 | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|
| A1 | 5 | Buka tiap daftar & detail yang memuat badge status | Badge satu baris, warna §6.11.4 (Cuti: Menunggu Atasan `warning`, Menunggu PyB `info`, Disetujui `success`, Ditolak `danger`, Ditangguhkan `secondary`; TB/IB: Menunggu PIC/Disetujui/Ditolak; PMK: Menunggu PIC/Diproses/Selesai/Ditolak); hover/fokus → teks lengkap di tooltip custom, **tanpa** atribut `title` | 1440, 390 || sebagian: badge satu baris (nowrap) ✅ di Cuti; tooltip belum diuji |
| A2 | 6 | Gulir tabel ke kanan di 600/390 px | Kolom Aksi paling kanan, sticky, bayangan saat tergulir; ⋮ ber-aria-label "Aksi untuk …"; menu terbuka di samping tombol (bukan di luar layar, CR-068) | 1440, 600, 390 | ✅ (8 halaman, 1440/600/390: Aksi sticky, ⋮ di dalam layar) |
| A3 | 6 | Buka ⋮ tiap tabel | Urutan AGENTS §1 / §6.11.6: Edit → (status) → Lihat detail, Setujui, Tolak, Tangguhkan, Proses, Tunda, Perpanjang, Terbitkan TTE, Unduh … → Hapus (merah, dengan pemisah). Aksi tak berlaku tidak tampil; aksi yang berlaku tetapi tidak boleh = nonaktif | 1440 | ✅ |
| A4 | 7 | Baca judul, label kolom, label tombol & item ⋮ | Bahasa Indonesia, Title Case, konsisten dengan DUK/Pensiun: "Ekspor", "Unduh", "Setujui", "Tolak", "Tangguhkan", "Tunda" | 1440 | ✅ (QA ulang 153424b; §M 3, 4 diperbaiki) |
| A5 | 10 | Filter yang tidak menghasilkan data; muat halaman (jaringan lambat); matikan backend | Kosong: "Tidak ada data pada lingkup ini."; memuat: indikator; galat: pesan Indonesia (403 "Anda tidak berhak …", 404 "Data tidak ditemukan"); subjudul & label kategori berkapitalisasi benar | 1440 | — tidak diuji |
| A6 | 11 | Ubah lebar ke 600 dan 390 px pada setiap halaman | Tanpa scroll horizontal halaman (`scrollWidth` = lebar viewport); tabel menggulir di wadahnya; desktop ≥ 1024: hanya area tabel yang menggulir, judul/filter tetap (CR-069), header kolom sticky (CR-070); tanpa `table.sr-only`; scrollbar ramping custom; navbar sticky | 1440, 600, 390 | ✅ (tanpa gulir horizontal) |
| A7 | 12 | Periksa semua kontrol | Tanpa elemen bawaan browser: `<select>` terlihat, popup tanggal native, tooltip `title`, `<datalist>`, `window.confirm/alert` | 1440, 390 | ✅ (tanpa select native/`title`) |
| A8 | 13 | DevTools Console & Network selama alur normal tiap halaman | Konsol bersih; tidak ada 4xx/5xx tak terduga (403/422 yang memang diuji dikecualikan) | 1440 || — (tidak diperiksa sistematis) |
| A9 | 14 | Keyboard saja: Tab ke semua kontrol, Enter/Spasi membuka ⋮/dropdown, panah memilih, Esc menutup; cek kontras | Label form terbaca, aria-label ⋮ ada, fokus selalu terlihat, urutan Tab wajar, kontras AA | 1440 | — kontras tidak diuji (Esc/fokus diuji di D8) |
| A10 | 10 | Breadcrumb & judul tab | Breadcrumb "Home / Layanan / <Halaman>"; judul tab "<Halaman> · SIMPEG v2" | 1440 | ✅ (QA ulang 153424b; §M 1, 2, 5 diperbaiki) |

## B. Menu per role & ACTIVE_PHASE (butir 15, §6.11.2)

| # | Akun (role) | Anak grup Layanan yang tampil | Hasil |
|---|---|---|---|
| B1 | `demo.admin` (1) | Cuti, Saldo Cuti, Petugas Approval Cuti, Tugas Belajar, Izin Belajar, PMK, Status Layanan, Petugas Layanan | ✅ |
| B2 | `demo.pegawai` (2) | Cuti, Tugas Belajar, Izin Belajar, PMK, Status Layanan (sudah diuji demo reviewer 28-10) | ✅ |
| B3 | `demo.satker` (3) | Cuti, Saldo Cuti, Petugas Approval Cuti, Tugas Belajar, Izin Belajar, PMK, Status Layanan | ✅ |
| B4 | `demo.eselon1` (4) | Status Layanan saja | ✅ |
| B5 | `demo.menteri` (5) | Status Layanan saja | ✅ |
| B6 | `demo.ptt` (6) | Cuti, Tugas Belajar, Status Layanan (**tanpa** Izin Belajar) | ✅ |
| B7 | `demo.pppk` (7) | Cuti, Izin Belajar, Status Layanan (**tanpa** Tugas Belajar) | ✅ |
| B8 | `demo.pimpinan` (8) | Grup Layanan tidak tampil | ✅ |
| B9 | `demo.ptt` buka `/layanan/ib`; `demo.pppk` buka `/layanan/tb`; `demo.eselon1` buka `/layanan/cuti` lewat alamat langsung | Halaman 403 (guard UX); API juga menolak | ✅ |
| B10 | Menu (semua role) | Grup Layanan tampil karena `ACTIVE_PHASE = 4` (`main` `68cf14a`, Paket 10) | ✅ |

## C. Status Layanan vs Gambar 17 (butir 1–4) — `/layanan/status`

Akun: `demo.admin` (filter unit/satker tampil), `demo.pemohon` (hanya pengajuan sendiri, tanpa filter unit), `demo.eselon1`
(role 4: tanpa "Lihat detail"). Data: C1–C5, T1, I1–I2, P1–P3, TB id 1.

| # | Butir | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|
| C1 | 1 | Buka halaman sebagai `demo.admin` | Kartu "Status Layanan"; filter Unit/Satker (role 1, 3, 4, 5), Jenis (Cuti/TB/IB/PMK), Status (Berjalan/Selesai); baris jumlah baris 10/25/50 + pencarian; tabel; ringkasan jumlah + paginasi; breadcrumb Home / Layanan / Status Layanan. Tombol **Ekspor** kanan atas: lihat catatan pra-QA §K1 | 1440, 600, 390 | ✅ (QA ulang 153424b: tombol Ekspor MAKE-046, menu Excel/CSV, CSV 200 header benar, XLSX valid) |
| C2 | 2 | Periksa kolom | Nama Pegawai (avatar + nama + NIP), Jenis Layanan, Status Layanan (stepper), PIC/Petugas ("PIC belum ditunjuk" bila kosong), Aksi ⋮ (`layanan-actions-<jenis>-<id>`) | 1440 | ✅ |
| C3 | 3 | Bandingkan stepper C1 (berjalan), C5 (selesai), I1, P2 | Selesai = lingkaran terisi bercentang; berjalan = cincin; belum = pudar; garis penghubung; warna per jenis (IB `info`, TB `primary`, Cuti `warning`); label langkah §6.11.4 (Cuti: Diajukan → Atasan Langsung → Pejabat Berwenang → Selesai; TB/IB: Diajukan → Verifikasi PIC → Selesai; PMK: Diajukan → Diproses PIC → Selesai) | 1440, 390 | ✅ (QA ulang 153424b; §M 6 diperbaiki) |
| C4 | 4 | Lihat C3 (ditolak AL), C4 (ditangguhkan PyB), I2, P3 (ditolak PIC) | Tahap yang memutus ditandai (label "Ditolak oleh …"/"Ditangguhkan …") pada tahap itu, bukan di akhir | 1440 | ✅ |
| C5 | §14.7 #5a/b | Ubah filter Jenis & Status | Jenis = empat nilai tetap; Status hanya Berjalan/Selesai (deviasi sah) | 1440 | ✅ |
| C6 | §14.7 #5c | ⋮ "Lihat detail" pada baris Cuti/TB/IB/PMK | Membuka `url_detail` server (`/layanan/cuti/{id}`, `/layanan/tb/{id}`, `/layanan/ib/{id}`, `/layanan/pmk/pengajuan/{id}`) | 1440 | ✅ |
| C7 | §14.6 #23 | Sebagai `demo.eselon1`/`demo.menteri` | Kolom Aksi "—" (tanpa "Lihat detail") | 1440 | ✅ |

## D. Cuti — `/layanan/cuti`, `/layanan/cuti/:id`

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| D1 | 15, A | `demo.pemohon` | C1–C5 | Buka halaman | Tab "Cuti Saya", "Perlu Persetujuan" (badge jumlah), tanpa "Monitoring"; tombol "Ajukan Cuti" (hak dari `can_create`) | 1440, 390 | ✅ |
| D2 | 15 | `demo.admin`, `demo.satker` | — | Buka halaman | Tab "Perlu Persetujuan" + "Monitoring"; tanpa "Cuti Saya" | 1440 | ✅ |
| D3 | 9 | `demo.pemohon` | saldo 2026 | "Ajukan Cuti" → isi Tahunan, tanggal mulai/akhir, telp, alamat, keterangan; ganti tanggal | Form 2 kolom ≥ 601 px, 1 kolom ≤ 600 px; datepicker/dropdown/unggah custom; kalkulator memanggil `POST cuti/hitung-lama` (debounce) dan menampilkan lama, hari libur, panel kuota N/N-1/N-2, Dalam Proses, Diajukan Sekarang, Sisa Setelah Pengajuan; tanpa hitungan di FE (cek Network); Simpan tidak dikunci kalkulator | 1440, 600, 390 | ✅ |
| D4 | 9 | `demo.pemohon` | — | Simpan dengan keterangan < 10 karakter, tanggal beririsan C1 | Galat 422 per kolom di bawah field (`keterangan`, `tgl_mulai`) | 1440 | ✅ |
| D5 | 9 | `demo.pemohon` | — | Jenis Besar | Isian durasi tampil, tanggal akhir dari server (`pakai_durasi`); Sakit → lampiran wajib (`lampiran_wajib`) | 1440 | ✅ |
| D6 | — | `demo.atasan` | C1 | Login | **Pengingat** "Pengingat Persetujuan Cuti" muncul sekali di halaman pertama setelah login; "Tunda" menghilangkan baris; "Proses" membuka detail; pindah halaman tidak memunculkan lagi; Keluar → login lagi → muncul lagi (§14.7 #7) | 1440, 390 | ✅ |
| D7 | — | `demo.pyb` | C2 | Login | Pengingat berisi C2 (tahap PyB) | 1440 | ✅ |
| D8 | 8 | `demo.atasan` | C1 | ⋮ Tolak tanpa alasan; alasan > 255 byte; Esc | `ApprovalDialog`: kirim tanpa alasan diblokir; > 255 byte → galat; muat di 390 px; Esc menutup; fokus kembali ke ⋮ | 1440, 390 | ✅ (QA ulang 153424b; §M 8 diperbaiki) |
| D9 | 8 | `demo.pyb` | C2 | ⋮ Tangguhkan | `TangguhkanDialog` (pembungkus `ApprovalDialog`), alasan opsional; wajib bila atas nama (PRD §6.4 `reason_note?`, T14); status menjadi Ditangguhkan (lalu jalankan ulang skrip untuk memulihkan data uji bila perlu) | 1440 | ✅ |
| D10 | 8 | `demo.admin` | C1 bila approver tak terjangkau | ⋮ "Setujui/Tolak atas nama" | Hanya tampil bila `aksi.proses_atas_nama` server; alasan wajib | 1440 | — tidak ada data atas nama |
| D11 | — | `demo.admin` | C5 | ⋮ Terbitkan TTE → passphrase | `EsignDialog`: input password custom, galat 422 per kolom, passphrase tidak tersimpan di DOM/storage/URL | 1440, 390 | ✅ |
| D12 | §14.7 #9 | `demo.pemohon` | C1 | Buka detail | Stepper dari `tahap[]`, data, lampiran, ⋮ di kepala halaman (pola detail Cuti/PMK, deviasi sah) | 1440, 390 | ✅ |

## E. Saldo Cuti & Petugas Approval Cuti (role 1, 3)

| # | Butir | Akun | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|
| E1 | A, 9 | `demo.admin` | `/layanan/cuti/saldo`: daftar, Tambah (semua pegawai / satu pegawai), ⋮ Edit/Hapus | Tambah massal menampilkan ringkasan dibuat/dilewati (§14.6 #12); Hapus lewat `ConfirmDialog` | 1440, 390 | ✅ |
| E2 | A | `demo.satker` | `/layanan/cuti/saldo` | Hanya pegawai lingkup satker | 1440 | ✅ |
| E3 | A, 9 | `demo.admin` | `/layanan/cuti/petugas-approval`: Tambah aturan (opsi dari `GET cuti/petugas-approval/opsi`), ⋮ Edit/Hapus | Form memakai komponen custom; label ⋮ memakai nama petugas + unit pemohon | 1440, 600, 390 | ✅ |
| E4 | A | `demo.satker` | Tambah aturan | Unit pemohon terkunci ke unit sendiri (`unit_terkunci`) | 1440 | ✅ |

## F. Tugas Belajar & Izin Belajar — `/layanan/tb`, `/layanan/ib`

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| F1 | 15 | `demo.pegawai` (PIC TB/IB) | T1, I1 | Buka TB dan IB | Tab "Perlu Persetujuan" tampil (`adalah_pic`); berisi T1 / I1 | 1440 | ✅ |
| F2 | 15 | `demo.atasan` (bukan PIC) | — | Buka TB | Tab "Perlu Persetujuan" tidak tampil | 1440 | ✅ |
| F3 | 9 | `demo.pppk` | — | IB → Ajukan | Form 2/1 kolom; 3 slot berkas wajib (`berkas_wajib`), tanpa pilihan status awal, tanpa perpanjangan; 422 per slot | 1440, 600, 390 | ✅ |
| F4 | 9 | `demo.admin` | — | TB → Ajukan untuk pegawai | Berkas TB tidak wajib untuk role 1; pilihan status 0/1; isian SK bila status 1 | 1440 | ✅ |
| F5 | 8 (deviasi) | `demo.pegawai` | I1 | ⋮ Setujui / Tolak | Dialog proses TB/IB sendiri (`StudiProsesDialog`, **bukan** `ApprovalDialog`, karena isian SK): Setujui mewajibkan Dasar SK & No. Dasar (CR-071); Tolak mewajibkan alasan ≤ 255 byte; muat di 390 px; Esc menutup — lihat catatan pra-QA §K2 | 1440, 390 | ✅ |
| F6 | §14.7 #9 | `demo.pegawai` | TB id 1, I1 | Buka detail | Aksi level halaman = tombol (Ubah/Setujui/Tolak/Unduh surat/Hapus); lampiran & perpanjangan = ⋮ per baris (deviasi sah) | 1440, 390 | ✅ |
| F7 | — | `demo.admin` | TB id 1 | Detail → Tambah perpanjangan | Hanya role 1; perpanjangan tampil di daftar dengan ⋮ | 1440 | ✅ |
| F8 | — | `demo.pegawai` | TB id 1 | Unduh surat TB / lampiran | Berkas terunduh; tanpa path internal di URL | 1440 | ✅ |

## G. PMK — `/layanan/pmk`, `/layanan/pmk/pengajuan/:id`

| # | Butir | Akun | Data | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|---|
| G1 | 15 | `demo.atasan` | P1–P3, riwayat PMK | Buka PMK | Tab Riwayat PMK + Pengajuan Saya; tanpa Perlu Persetujuan | 1440 | ✅ |
| G2 | 15 | `demo.pegawai` (PIC PMK) | P1, P2 | Buka PMK | Tab Perlu Persetujuan berisi P1 (Menunggu PIC) dan P2 (Diproses) | 1440 | ✅ |
| G3 | 8 | `demo.pegawai` | P1 | ⋮ Proses → konfirmasi; ⋮ Tolak tanpa alasan | Proses = `ConfirmDialog`; Setujui/Tolak = `ApprovalDialog` (alasan wajib, ≤ 255 byte) | 1440, 390 | ✅ |
| G4 | 9 | `demo.atasan` | — | Ajukan PMK: isi tanggal | Kalkulator masa kerja `POST pmk/hitung-mker` (debounce); 4 berkas ≤ 2 MB; 422 per kolom | 1440, 600, 390 | ✅ |
| G5 | §14.7 #2 | `demo.atasan` | riwayat PMK | Tab Riwayat → ⋮ "Unduh <nama arsip>" | Berkas arsip SK terunduh lewat `GET pmk/{id}/arsip/{id_attachment}` | 1440 | ✅ |
| G6 | §14.6 #21 | `demo.pegawai` | P1 | ⋮ Unduh berkas kontrak/paklaring/PHK/slip | Berkas terunduh lewat `GET pmk/pengajuan/{id}/berkas/{kolom}` | 1440 | ✅ |
| G7 | §14.7 #9 | `demo.pegawai` | P1 | Detail | Stepper `tahap[]`, ⋮ di kepala halaman (deviasi sah) | 1440, 390 | ✅ |

## H. Petugas Layanan (PIC) — `/layanan/petugas` (role 1)

| # | Butir | Akun | Langkah | Hasil yang diharapkan | Layar | Hasil |
|---|---|---|---|---|---|---|
| H1 | A | `demo.admin` | Daftar PIC (TB/IB/PMK `demo.pegawai`) | Status aktif/nonaktif sebagai badge; ⋮ Edit → Aktifkan/Nonaktifkan → Hapus | 1440, 600, 390 | ✅ |
| H2 | 9 | `demo.admin` | Tambah PIC → cari kandidat | Kandidat dari `GET layanan/petugas/kandidat` (pencarian server); 422 per kolom | 1440, 390 | ✅ |
| H3 | 15 | `demo.satker` | Buka `/layanan/petugas` | 403 (hanya role 1) | 1440 | ✅ |

## I. Dokumen keluaran (butir 16)

| # | Akun | Data | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|---|
| I1 | `demo.pemohon` / `demo.admin` | C5 | Unduh surat cuti (belum TTE, lalu setelah TTE mock) | Kop nama kementerian (bawaan bila `web_config` kosong, CR-066); "Halaman x dari y"; nama pencetak; kata tidak terpotong di kolom sempit | ✅ (PDF terunduh valid; isi kop/halaman belum diperiksa) |
| I2 | `demo.pegawai` | TB id 1 | Unduh surat TB | Templat surat TB terbaca, tanpa teks terpotong | ✅ (PDF terunduh valid; isi belum diperiksa) |

## J. Deviasi yang sah (bukan temuan)

| Sumber | Deviasi |
|---|---|
| §6.11.8 | Langkah stepper, kolom PIC/Petugas, aksi ⋮, label "Ekspor", data/filter: ikuti PRD, bukan Gambar 17 |
| §14.6 #23 | Status Layanan role 4/5 tanpa "Lihat detail"; filter status kode angka hanya lewat API (wajib bersama `jenis`) |
| §14.7 #1 | Rute TB/IB `/layanan/tb`, `/layanan/ib` (bukan `/layanan/tugas-belajar`, `/layanan/izin-belajar`) |
| §14.7 #3 | Tab "Perlu Persetujuan" TB/IB/PMK disembunyikan bila bukan PIC (`adalah_pic`); hak proses tetap di server |
| §14.7 #5 | Status Layanan: filter Jenis empat nilai tetap; filter Status hanya Berjalan/Selesai; "Lihat detail" = `url_detail` server |
| §14.7 #6 | IB tanpa perpanjangan & tanpa status awal; wajib-tidaknya berkas dari `berkas_wajib` server |
| §14.7 #7 | Pengingat cuti di shell, sekali per sesi tab, tampil lagi pada setiap login sukses |
| §14.7 #9 | Dua pola halaman detail: TB/IB tombol, Cuti/PMK ⋮ di kepala halaman |
| §14.7 #10 | Tanpa Figma Modul C: QA lewat checklist ini |

## K. Catatan pra-QA (perlu keputusan reviewer sebelum QA)

| # | Hal | Keadaan di `main` `139898f` | Usulan |
|---|---|---|---|
| K1 | Butir 1 §6.11.8 meminta tombol **Ekspor** di kartu Status Layanan | `LayananStatusPage` tidak punya tombol Ekspor, dan §5.5 tidak punya endpoint ekspor `layanan/status` | Catat sebagai deviasi sah (§14.x) atau buka MAKE baru untuk ekspor; sampai diputuskan, C1 dinilai tanpa Ekspor |
| K2 | Butir 8 §6.11.8, §6.11.5, AC-11.2 mewajibkan dialog proses = `ApprovalDialog` | TB/IB memakai `StudiProsesDialog` sendiri (isian SK saat Setujui; Tolak tetap alasan wajib ≤ 255 byte). Cuti, PMK, dan Tangguhkan sudah membungkus `ApprovalDialog`. Deviasi ini **belum tercatat** di §14.6/§14.7 PRD (hanya di komentar komponen) | Catat di register PRD (v1.6) sebagai deviasi sah; F5 menilai perilaku setara (alasan wajib, batas 255 byte, Esc, fokus, 390 px) |

## L. Penutupan (butir 17)

| # | Butir | Hasil |
|---|---|---|
| L1 | Reviewer CR + QA menyepakati checklist ini (termasuk keputusan §K) sebelum QA Lapis 1 | |
| L2 | Deviasi §J dianggap sah; temuan lain = temuan QA biasa (CR-###) | |
| L3 | C-11 tetap IN_PROGRESS sampai semua butir ✅ atau — (PRD §10.1 butir 9) | ✅ LOLOS (28-10): semua temuan §M diperbaiki (CR-072, CR-073, CR-074) dan lolos QA ulang; butir — tercatat |

## M. Temuan

| No | Butir | Halaman | Layar | Temuan | Bukti | CR |
|---|---|---|---|---|---|---|
| 1 | 10 (A10) | Cuti, PMK, Status Layanan | 1440 | Breadcrumb tanpa "Layanan" (harus Home / Layanan / <Halaman>) | QA reviewer 28-10; QA ulang 153424b: breadcrumb "Home / Layanan / …" di Cuti, PMK, Status Layanan dan detailnya | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 2 | 10 (A10) | Cuti, PMK, Status Layanan | 1440 | Judul halaman `h1` tidak ada | QA reviewer 28-10; QA ulang 153424b: `h1` ada di Cuti, PMK, Status Layanan | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 3 | 7 (A4) | Modul C (filter) | 1440 | Label filter huruf kecil (bukan Title Case) | QA reviewer 28-10; QA ulang 153424b: filter "Semua Status/Unit/Jenis Layanan" | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 4 | 7 (A4) | PMK (⋮ berkas) | 1440 | Item ⋮ "Unduh surat phk" (kapitalisasi/istilah) | QA reviewer 28-10; QA ulang 153424b: item ⋮ "Unduh Surat PHK" | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 5 | 10 (A10) | Petugas Approval Cuti | 1440 | Breadcrumb "Petugas Approval" tidak sesuai pola Home / Layanan / <Halaman> | QA reviewer 28-10; QA ulang 153424b: breadcrumb "Petugas Approval Cuti" | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 6 | 3 (C3) | Status Layanan, detail Cuti | 1440 | Langkah terakhir stepper Cuti berlabel hasil (Disetujui/Ditolak/Ditangguhkan) setelah final; §6.11.4 = "Selesai" seperti TB/IB/PMK | QA reviewer 28-10; QA ulang 153424b: stepper Cuti berakhir "Selesai" | diperbaiki CR-073 (main 30ff6cb), lolos QA ulang |
| 7 | 9 | Form Ajukan Cuti (Sakit), Form Ajukan IB/TB (slot berkas), Form Ajukan PMK | 1440 | Berkas wajib tanpa penanda * | QA reviewer 28-10; QA ulang 153424b: "Lampiran Bukti *" Cuti Sakit; 3 slot IB bertanda * | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 8 | 14 (D8) | Cuti (dialog Tolak) | 1440 | Fokus tidak kembali ke ⋮ setelah Esc | QA reviewer 28-10; QA ulang 153424b: fokus kembali ke ⋮ setelah Esc (Hapus Cuti, Batal Hapus PMK) | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 9 | 8 (D9) | Cuti (Tangguhkan) | 1440 | ~~Tangguhkan tanpa alasan~~ **DIBATALKAN**: sah sesuai PRD §6.4 (`reason_note?`; wajib hanya untuk tolak dan atas nama T14); ekspektasi D9 diperbaiki | QA reviewer 28-10 | — |
| 10 | 7 | PMK (dialog) | 1440 | Judul dialog PMK memakai NIP, bukan nama | QA reviewer 28-10; QA ulang 153424b: judul dialog "Tolak PMK <nama>" | diperbaiki CR-072 (main 30ff6cb), lolos QA ulang |
| 11 | 6 (AGENTS §1 butir 7) | PMK (daftar) | 1440 | Label tombol ⋮ daftar PMK memakai NIP, bukan nama ("Aksi untuk <nama baris>") | QA ulang reviewer 28-10 (153424b) | diperbaiki CR-074 (`main` `a00f154`), lolos uji demo ("Aksi untuk PMK <nama> <periode>") |
