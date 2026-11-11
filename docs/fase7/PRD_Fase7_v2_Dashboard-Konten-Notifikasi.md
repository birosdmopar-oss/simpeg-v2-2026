# PRD Fase 7 v2.1 — Dashboard, Konten, Notifikasi (sisa Modul F + ISSUE-058)

| Atribut | Isi |
|---|---|
| Versi | **v2.1 — 11-11-2026** (CR-114: koreksi temuan koding + keputusan sesudah v2.0, lihat §11). v2.0 — 10-11-2026 (CR-112; disusun reviewer CR dari laporan M0 empat jalur `wt/f7-m0/M0_*.md`) |
| Status | **BERLAKU — disetujui Horii 10-11-2026** (izin merge). 8 keputusan prioritas dipilih Horii; 39 keputusan lain = bawaan (saran) **dikonfirmasi Horii 10-11-2026** ("ikut bawaan semua") — semua dikunci (§4) |
| Scope | Fase 7 sisa: F-01, F-02, F-04, F-05, F-06, F-07, F-08, F-11, F-12, F-13 + ISSUE-058 (deviasi K1 Fase 6). **F-03 & F-09 tetap diatur PRD v1.0** (`PRD_Fase7_Statistik-GeneratorDokumen.md`, Done). **F-10 Arsip Digital DITUNDA** (Horii 20-10-2026 & 10-11-2026) |
| Izin kerja | [#4-WorkloadParalelFase7] (Horii 10-11-2026) — anggota & batas di §3 |
| Acuan | PRD v1.0 Fase 7 (§2 aturan, §3 anti-tabrakan, §5 F7-1..27 tetap berlaku untuk F-03/F-09 dan sebagai pola); PRD Fase 8 v1.0 (BP8-1, PN8-2, Q8-06); PRD Fase 4 (D10, DBV-022); PRD Fase 6 v1.0o (§6.2, F6-24, Q6-29); `Bahan Baku SIMPEG/07-*` (DoD Trello QASMTASK-098..110) |

## Cara pakai (WAJIB dibaca)
1. Setiap coder hanya mengerjakan key di jalurnya (§3) dan hanya menyentuh berkas milik jalurnya; berkas bersama = aditif, commit terpisah.
2. Perilaku: **[K]** = ikut kode legacy (rujukan file:baris di laporan M0 jalur), **[V2]** = berbeda dari legacy (alasan di §4/§5),
   **[I]** = inferensi (belum terbukti; dicek saat D1/QA).
3. Skema hanya lewat **DBV-032** (§5.1); koding Jalur A & B menunggu DBV-032 di `main`. Jalur C & D tidak butuh skema.
4. Pertanyaan baru → reviewer (diteruskan ke Horii). Jangan menebak keputusan bisnis.

---

## 0. Gerbang & prasyarat
| ID | Prasyarat | Menahan |
|---|---|---|
| G7-1 | PRD v2.0 ini di `main` (izin Horii) | Koding semua F-xx (kecuali ISSUE-058, sudah dikerjakan) |
| G7-2 | **D1 Fase 7**: hasil `D1_fase7/ambil_d1_fase7.sql` (struktur 13 tabel, jumlah, indeks, FK, ganda/yatim agregat, sebaran kode, master `jenis_dibo`, trigger) dari Horii | PR [DBV] DBV-032 (Q7-27) |
| G7-3 | DBV-032 di `main` (DBV APPROVE WS-2 + izin Horii) | Koding Jalur A & B |
| G7-4 | F-02 dry-run data nyata | Hanya saat cutover (izin Horii), bukan syarat sign-off |
| — | Fase 8 belum mulai koding; F-02 = PN8-2 Fase 8; lonceng F-08 menampilkan angka tiket hanya lewat antarmuka `RingkasanTiket` (NoOp sampai Fase 8, Q8-06 = B) | — |

## 1. Scope
### 1.1 Di dalam scope
| Task | Judul | Inti v2 | QASMTASK | Jalur / key |
|---|---|---|---|---|
| F-01 | Skema dashboard & komunikasi | 13 tabel (§5.1); `inbox/inbox_msg` TIDAK dibuat ulang (DBV-022) | 098 | B / DBV-032 |
| F-02 | Sortir inbox legacy | Semua isi `inbox(_msg)` legacy = tiket Halo Simpeg → `disiplin_ticket(_msg)`; klasifikasi anomali + manifest; `inbox` v2 kosong (Q7-28) | 099 | C / MAKE-086 |
| F-04 | Popup pengumuman | CRUD role 1; tampil **setiap login** selama periode [K] (Q7-02); tanpa push Android (Q7-04) | 101 | A / MAKE-079 |
| F-05 | Banner slider | CRUD role 1; urut `order`; carousel | 102 | A / MAKE-080 |
| F-06 | Form digital (kuesioner) | CRUD role 1; isi role 2,6,7; ganda ditolak; rekap + ekspor XLSX server | 103 | B / MAKE-083 |
| F-07 | Berita | Portal semua role; kelola berita role 1,3 (role 3 butuh persetujuan role 1), kategori role 1; editor **teks sederhana** (Q7-10) | 104 | A / MAKE-081 |
| F-08 | Pusat notifikasi & inbox (UI) | Agregator baca-saja + penanda baca; role **ikut legacy** (Q7-29) | 105 | C / MAKE-085 |
| F-11 | Survei Kepuasan Layanan (SKL) | Rating 1–5 + kritik/saran per riwayat layanan selesai; rekap role 1 | 108 | B / MAKE-084 |
| F-12 | DIBO (Kamus Digital Kepegawaian) | Daftar dasar hukum + berkas; lihat semua role; kelola role 1; master `jenis_dibo` | 109 | A / MAKE-082 |
| F-13 | Frontend dashboard | Beranda admin & pegawai per role [K], donut komposisi [V2] dengan saklar rollback (Q7-36) | 110 | D / MAKE-087 |
| ISSUE-058 | Log integrasi + Admin → Integrasi (deviasi K1 F6) | `GET api/v1/integrasi/log` + halaman role 1 | — | D / MAKE-078 (dikerjakan) |
| Impor T14 | Impor legacy tier 14 konten Fase 7 (13 tabel) + `impor:berkas-konten` | Aturan §10 DBV-032 + §11 (v2.1); **masuk Fase 7** (Horii 11-11-2026) | — | C / MAKE-089 |
| Penutup | QA Lapis 1 Fase 7 + laporan akhir | — | — | Reviewer / MAKE-088 |

### 1.2 Di luar scope
| Hal | Milik | Catatan |
|---|---|---|
| F-10 Arsip Digital (+ "file explorer arsip" di DoD F-13) | Ditunda | Server arsip v2 resmi belum ada |
| Tiket Halo Simpeg (buat/balas/tutup) | Fase 8 E-01..E-09 | F-02 hanya menyiapkan data impor |
| Push popup Android | Tidak dikirim (Q7-04) | Titik sambung `AntrePushPesan` (Q6-29) tetap ada |
| Fitur "ask" DIBO | Tidak ada di legacy (Q7-12) | DoD Trello dikoreksi |
| IPASN di beranda | Ditunda (Q7-43) | Rumus IPASN belum di v2 |
| Halaman daftar KP/TJ/KGB untuk kartu pengingat | Kartu tanpa tautan dulu (Q7-37) | — |
| ~~Impor data form digital & SKL legacy~~ | **Dipindah ke dalam scope v2.1** (MAKE-089, Horii 11-11-2026) | Eksekusi data nyata tetap saat cutover |

---

## 2. Aturan proyek
Semua aturan PRD v1.0 §2 dan AGENTS.md berlaku (UI ⋮ `RowActionsMenu`, commit Bahasa Indonesia tanpa AI/Co-Authored-By, repo publik tanpa
NIP/IP/kredensial, skema lewat DBV, kontrak aditif, quality gate `./check.sh`). Tambahan Fase 7:
- **Legacy grep (insiden 10-11-2026):** grep 3.0 di mesin ini mengabaikan `--exclude` bila `--include` ditulis sesudahnya. Wajib: `--include`
  di depan, lalu `--exclude=constants.php --exclude=database.php --exclude=config.php --exclude=bsre_config.php --exclude=header*.php
  --exclude=Tester.php --exclude-dir=sql`, path dibatasi `application/{controllers,models,libraries,helpers,core,views}`. Alat Grep (ripgrep):
  glob negasi `!{constants.php,database.php,config.php,bsre_config.php,header*.php,Tester.php}` wajib (teruji). Glob positif tanpa negasi =
  pelanggaran. Subagen wajib menerima aturan ini di prompt-nya.
- **Lingkungan:** worktree + DB scratch 3307 milik sendiri, `.env` minimal (DB + `auth.captchaDriver = mock`), satu PHPUnit per DB, gate penuh
  hanya atas aba-aba reviewer (satu gate di 3307).
- **Konfigurasi rahasia:** daftar NIP yang disembunyikan dari hitungan dashboard (Q7-41) hanya di `.env` server, tidak pernah di repo/test.

## 3. Pembagian paralel & kepemilikan berkas
| Jalur | Agen | Key | Berkas MILIK jalur |
|---|---|---|---|
| A konten admin | Worker Fix Bug | MAKE-079..082 | BE `Controllers/Api/Konten/**`, `Libraries/Dashboard/Konten/**` (termasuk `Penargetan/*` bersama popup/banner), `Models/Konten/**`, `Config/RoutesKonten.php` (baru); FE `features/konten/**` (ekspor tunggal `@/features/konten`) |
| B interaksi + skema | ReadOne | DBV-032, MAKE-083, 084 | Migration + dokumen DBV-032; BE `Controllers/Api/Dashboard/{FormDigital,Survey}Controller`, `Libraries/Dashboard/Interaksi/**`, `Config/RoutesDashboard.php` (bagian F-06/F-11, aditif); FE `features/form-digital/**`, `features/survei-layanan/**`; definisi tier impor Fase 7 di `Libraries/Impor` (aditif) |
| C notifikasi | Handling Case | MAKE-085, 086 | BE `Controllers/Api/Notifikasi/**`, `Libraries/Notifikasi/Pusat/**`, `Config/RoutesNotifikasi.php` (baru), antarmuka `SumberNotifikasiInterface` + `RingkasanTiketInterface`; tier "tiket lama" di `Libraries/Impor` (aditif); FE `features/notifikasi/**` |
| D dashboard | Worker Muse | MAKE-078, 087 | BE `Controllers/Api/Dashboard/BerandaController`, `Libraries/Dashboard/Beranda/**`, `Config/Dashboard.php` (baru); FE `features/dashboard/**`, `HomeView.vue` (pertahankan `data-testid="home-role"`), `shared/ui/UiCarouselPanel` (baru) |
| DBV | WS-2 | — | Review DBV-032 + pemetaan F-02 |
| Penutup | Reviewer | CR-112, MAKE-088 | PRD, checklist QA, laporan akhir |

**Berkas bersama (aditif, commit terpisah):** `Config/Routing.php` (`$routeFiles`), `Config/Services.php` (binding sumber notifikasi/tiket),
`Config/Events.php` (hanya bila ada pemicu), `tests/feature/KontrakMatriksRoleTest.php`, `frontend/src/router/index.ts`,
`shared/layouts/nav.config.ts` (+ ekspektasi menu di `shared/layouts/__tests__/*`), `AppTopbar.vue` (prop lonceng sudah ada),
`backend/docs/progress/07-Dashboard.md`, `README-deploy.md` (kunci `.env` baru). **Dilarang** menyentuh modul fase lain di luar titik
sambung yang disebut di §7 (mis. `features/presensi` hanya lewat ekspor komponen yang disetujui pemiliknya, Q7-42).

---

## 4. Register keputusan Fase 7 (Q7-01..Q7-47)
Status: **🔒 = dipilih Horii 10-11-2026**; **◻ = bawaan (saran reviewer/jalur), dikonfirmasi Horii 10-11-2026** ("ikut bawaan semua"). Semua
butir dikunci. Rincian opsi & perbandingan: `Keputusan_Q7_Fase7.md`, `Perbandingan_8_Prioritas_Q7.md` (folder kerja Horii).

### 4.1 Dikunci Horii (8 prioritas)
| # | Keputusan | Label |
|---|---|---|
| Q7-28 🔒 | F-02: seluruh isi `inbox`/`inbox_msg` legacy diperlakukan sebagai **tiket Halo Simpeg** (penulis tunggal `L_support`), dipindah ke `disiplin_ticket(_msg)`; anomali dieskalasi per id; `inbox` v2 kosong saat cutover | [K] |
| Q7-02 🔒 | Popup tampil **setiap login** selama periode aktif (legacy `User.php:401-420`); tanpa kolom "dilihat". DoD "sekali per pegawai" dikoreksi | [K] |
| Q7-10 🔒 | Editor berita **teks sederhana**: tebal, miring, daftar, tautan; tanpa pustaka rich-text baru; isi selalu dibersihkan di server (HTMLPurifier whitelist ketat). Berita lama ber-HTML (impor) ditampilkan setelah dibersihkan (konfigurasi aman standar) | [V2] |
| Q7-29 🔒 | Pusat notifikasi **ikut legacy** untuk role: daftar notifikasi 2,3,6,7; butuh approval & histori 1,2,3; hapus 1,2,6,7; lonceng mengikuti role legacy `MY_Controller:20-175` | [K] |
| Q7-36 🔒 | Beranda admin: **donut komposisi pegawai** [V2]. Legacy = angka + persen tanpa chart (`dashboard_ad` `:47-180`). Rollback terlacak: saklar `Config\Dashboard::$tampilanKomposisi` = `'donut'` (bawaan) / `'angka'` (persis legacy), keduanya dijaga test; data/API sama (Lampiran A) | [V2] |
| Q7-45 🔒 | Ulang tahun hari ini/besok **ikut legacy**: tampil di beranda semua role, dengan field yang sama dengan yang ditampilkan view legacy (diturunkan dari view, bukan dari helper di berkas terlarang) | [K] |
| Q7-41 🔒 | Pengecualian NIP dari hitungan dashboard **ikut legacy** (`$whereHide_nip`), daftar NIP dari konfigurasi rahasia server `dashboard.nipDisembunyikan` (kosong = tanpa pengecualian); hitungan TB role 3 tanpa perpanjangan [K] | [K] |
| Q7-04 🔒 | Push popup Android **tidak dikirim** (legacy `Cron::queue_popup_notif` mati, `exit()`); titik sambung tetap | [K] |

### 4.2 Bawaan — dikonfirmasi Horii 10-11-2026 (◻)
| # | Keputusan bawaan | Label |
|---|---|---|
| Q7-01 | Pemilik form digital & SKL = Jalur B | — |
| Q7-03 | Kolom popup `target`, `type`, `id_unit_satker`, `unit_satker` tidak dibawa. **v2.1:** premis "selalu konstan" keliru untuk `popup.target` (D1: 1/2/3) — tetap tidak dibawa, target diturunkan dari `target_uns`/`target_peg` saat impor; `popup.type` ≠ 1 (video, 1 baris) dilewati impor + dicatat | [V2] |
| Q7-05 | Popup/banner tampil untuk semua role ber-NIP (2–8) yang ada di target; role 1 tanpa NIP tidak (legacy popup 2/6/7) | [V2] |
| Q7-06 | Unggah gambar biasa + cek dimensi/rasio di server (≤ 5 MB, tipe dari isi berkas); tanpa pustaka cropper | [V2] |
| Q7-07 | Berita role 1,3 (role 3 → status menunggu, disetujui role 1); kategori role 1 | [K] |
| Q7-08 | Alur persetujuan berita dibawa; halaman Butuh Approval/Histori di F-08; aksi proses di halaman kelola berita | [K] |
| Q7-09 | Penanda belum dibaca berita via `news_flag` (dihitung saat portal dibuka/direset eksplisit) | [K] |
| Q7-11 | `jenis_dibo` dikelola sebagai master generik (role 1, urutan, status) | [V2] |
| Q7-12 | "Ask" DIBO diabaikan; DoD dikoreksi | [K] |
| Q7-13 | Pegawai hanya melihat DIBO `status=1` (perbaikan bug legacy) | [V2] |
| Q7-14 | Berkas konten di penyimpanan privat + unduh terautentikasi, kunci path acak | [V2] |
| Q7-15 | Target popup/banner = snapshot saat simpan (`user_popup`/`user_banner`) | [K] |
| Q7-16 | Audit lewat `audit_logs` create/update/delete; tanpa menambah nilai ENUM | — |
| Q7-17 | Isi form kedua ditolak 409 | [K] |
| Q7-18 | Skema form v2 tervalidasi (teks, paragraf, pilihan tunggal/ganda, tanggal, angka, berkas) + konverter impor dari formBuilder | [V2] |
| Q7-19 | Hapus form = hapus lunak (status 10), jawaban tetap | [V2] |
| Q7-20 | `user_form_digital` permanen; "belum mengisi" = target − sudah | [V2] |
| Q7-21 | SKL ganda → 200 idempoten | [K] |
| Q7-22 | `skl.jenis_rwy` angka legacy 1–5 + lookup master v2 | [K] |
| Q7-23 | Pengisi SKL role 2, 6 (arti role 7 di SKL dikonfirmasi saat QA) | [K] |
| Q7-24 | Impor data F-06/F-11 = tiket tersendiri | — |
| Q7-25 | Form yang sudah dijawab: pertanyaan dikunci, judul/tanggal/keterangan boleh diubah | [V2] |
| Q7-26 | Ringkasan SKL di dashboard admin dengan rata-rata per jenis layanan | [V2] |
| Q7-27 | D1 13 tabel diminta sebelum PR DBV-032 (G7-2) | — |
| Q7-30 | Sumber pusat notifikasi bertahap: layanan + riwayat/biodata/LKH/Konket; lalu izin presensi + berita lewat adapter | [K] |
| Q7-31 | Notifikasi dianggap dibaca saat item dibuka / "Tandai dibaca" (legacy: membuka daftar = semua dibaca) | [V2] |
| Q7-32 | Tembusan Ketua Tim cuti dibaca dari `inbox` v2 saja | — |
| Q7-33 | Notifikasi layanan (inbox v2) tanpa hapus; aksi Hapus `hidden` | [V2] |
| Q7-34 | Namespace modul notifikasi sendiri (bukan di modul dashboard) | — |
| Q7-35 | Histori approval dari kolom legacy (`show_ua_*`, `notif_date`, `status_al/pyb`, …) dengan presedensi diperbaiki | [K] |
| Q7-37 | Kartu KP/TJ/KGB tanpa tautan dulu | — |
| Q7-38 | Daftar jabatan direktur politeknik di `Config\Dashboard` (bukan hardcode) | [V2] |
| Q7-39 | Banner tampil juga di beranda admin (BE yang menyaring) | [V2] |
| Q7-40 | Popup form digital & SKL tampil di halaman mana pun (shell), dipasang Jalur B | [K] |
| Q7-42 | **v2.1:** presensi di beranda = **tautan ke Presensi Saya** (`PresensiSayaCard`); komponen Fase 5 yang bisa dipakai ulang tidak ada | — |
| Q7-43 | IPASN ditunda | — |
| Q7-44 | **v2.1:** tautan "Update FK" PTT **tidak dipasang** — baris legacy-nya dikomentari (tidak tampil di legacy) [K] | [K] |
| Q7-46 | Kepemilikan `features/dashboard` + `Libraries/Dashboard/Beranda` = Jalur D | — |
| Q7-47 | Kolom `target_peg_desc`/`excl_peg_desc`/`target_uns_desc` ("NIP – nama") tidak dibawa; nama diturunkan saat baca | [V2] |

---

## 5. Spesifikasi per task

### 5.1 F-01 — DBV-032 skema (Jalur B, review WS-2)
- Tabel: `popup`, `user_popup`, `banner`, `user_banner`, `form_digital`, `user_form_digital`, `filled_form_digital`, `news_category`, `news`
  (termasuk `show_notif` 1 baru / 3 dibaca / 2 dihapus), `news_flag`, `skl`, `jenis_dibo`, `dibo`. Kolom [K-kode] di `M0_B_ReadOne.md` §1, dikunci
  dengan D1 (G7-2); kolom yang belum pasti ditandai ⚑ dan ditahan.
- Konstrain [V2] (syarat: audit ganda D1 = 0): UNIQUE `(id_popup,nip)`, `(id_banner,nip)`, `(id_form_digital,nip)` di `user_form_digital` dan
  `filled_form_digital`, `skl(nip,jenis_rwy,id_rwy)`, `news_flag(id_user,id_news)`; FK anak→induk CASCADE (kecuali `filled_form_digital`
  RESTRICT karena hapus form = lunak); `news_flag.id_user` → `pengguna.id`; FK NIP memakai nama registry `TabelAnakNip` yang sudah ada;
  `target_peg`/`excl_peg` didaftarkan `JsonArrayNip` (koreksi NIP ikut); CHECK `skl.rate` 1–5, `skl.jenis_rwy` 1–5.
- Tanpa `user_popup.dilihat_at` (Q7-02). Kolom deskripsi "NIP – nama" tidak dibuat (Q7-47).
- `created_at/updated_at` UTC (CR-086), `*_by` = `pengguna.id` dengan konvensi FK v2. Path berkas VARCHAR(255) kunci storage.
- Tier impor Fase 7 di `Libraries/Impor` (definisi saja; eksekusi = tiket impor Q7-24, kecuali F-02): induk dulu, lalu anak; normalisasi JSON
  ber-`addslashes`; path `assets/upload/...` → kunci storage.

### 5.2 F-02 — Sortir inbox legacy (Jalur C, MAKE-086)
- Bukti [K]: penulis tunggal `inbox(_msg)` = `L_support.php:997/1006/1169/1341` (+ update/close/delete); tidak ada penulis lain.
- Tier "tiket lama" di `php spark impor:legacy` (sebelum tier SIASN), klasifikasi per baris deterministik (`M0_C` §4.2):
  **T-OK** (tiket sah) → `disiplin_ticket`; **T-NORM** (kategori NULL/yatim, flag di luar domain, NIP yatim) → dinormalisasi menurut berkas
  keputusan; **A-ESK** (pengirim ≤ 0, subjek/NIP kosong, tanpa pesan, tanggal nol) → tidak diimpor sampai diputus per id (Horii/DBV).
  Pesan: M-OK / M-NORM / M-IKUT (ikut induk) / M-ESK.
- Manifest jumlah (tanpa isi): sumber = tujuan + dibuang-berkeputusan; ada yang belum diputuskan → **gagal-tertutup**. Klasifikasi juga saat
  `--dry-run`. Data uji sintetis per kelas. `inbox` v2 tidak ditulis (test).
- Data nyata hanya saat cutover dengan izin Horii; pemetaan direview WS-2. Dokumen Mapping Migrasi Prinsip #4 dan DoD QASMTASK-099 dikoreksi.

### 5.3 F-04 Popup (Jalur A, MAKE-079)
- Kelola role 1: `api/v1/konten/popup` CRUD (multipart `berkas`; jpg/jpeg/png/gif, ≤ 5 MB, tipe dari isi), `GET konten/popup/{id}/penerima`.
- Tampil: `GET konten/popup/aktif` → popup yang periodenya berlaku dan menargetkan NIP aktor, urut `order`. FE `PopupAktifDialog` tampil
  **sekali per sesi login** (padanan flashdata legacy saat login) [K Q7-02]; tidak ada endpoint "dilihat".
- Target: penargetan unit/satker/pegawai/pengecualian → snapshot `user_popup` saat simpan; ubah popup menyinkronkan selisih target.
- Tidak mengirim push Android (Q7-04).

### 5.4 F-05 Banner (Jalur A, MAKE-080)
- `konten/banner` CRUD role 1, `penerima`, `GET konten/banner/aktif` (`{id,url,gambar_url,keterangan}`, urut `order`), gambar via
  `GET konten/banner/{id}/gambar` (terautentikasi). Tanggal DATE.
- `BannerCarousel`: sebelumnya/berikutnya/indikator/keyboard, autoplay dapat dijeda, hormati `prefers-reduced-motion`.

### 5.5 F-07 Berita (Jalur A, MAKE-081)
- Baca semua role: `GET konten/berita` (portal, cari, filter kategori, paginasi), `GET konten/berita/{id}`, `GET konten/berita/kategori`,
  lampiran terautentikasi. Visibilitas [K]: `status=1 AND publish_at<=NOW()` + target kementerian/unit/satker.
- Kelola: berita role 1,3 (role 3 → status 0 menunggu, kategori `allow_ap=1`, hanya miliknya); kategori role 1. Persetujuan role 1
  (setuju/tolak + `reason_note`) di halaman kelola berita.
- Editor teks sederhana (Q7-10): komponen custom `shared/ui` (tanpa pustaka baru) menghasilkan HTML terbatas (`p, br, strong, em, ul, ol, li, a`);
  server membersihkan dengan whitelist yang sama; lampiran berkas tetap.
- `news_flag` (Q7-09): dibuat saat berita terbit untuk target (dihitung, bukan sisip massal tanpa kondisi); reset eksplisit
  `POST konten/berita/dibuka`; `news.show_notif`: 1 saat diproses [V2 sampai D1], 3 saat ditandai dibaca oleh F-08, 2 saat pembuat menghapus [V2].
- Sumber notifikasi untuk F-08 lewat `SumberBerita` (implementasi `SumberNotifikasiInterface`, §7).

### 5.6 F-12 DIBO (Jalur A, MAKE-082)
- Baca semua role: `GET konten/dibo` (hanya `status=1` + jenis aktif, Q7-13; filter jenis/tahun/cari `nomor|tentang`), detail, unduh
  `berkas/{1|2|3}`. Kelola role 1: `konten/dibo` CRUD (multipart `berkas_1..3`), master `konten/dibo-jenis` (urutan Naikkan/Turunkan, status).

### 5.7 F-06 Form Digital (Jalur B, MAKE-083)
- Role 1: `form-digital` CRUD + target; `GET form-digital/{id}/respons` berhalaman + `?format=xlsx` (server, audit `dokumen_unduhan`), kolom
  rekap dari skema form.
- Role 2,6,7: `GET form-digital/saya` (form aktif yang menargetkan aktor dan belum diisi), `POST form-digital/{id}/isi` — cek target + periode
  [V2], validasi terhadap skema, berkas via storage, ganda → 409 (dijamin UNIQUE).
- Builder pertanyaan custom `shared/ui` (tanpa formBuilder jQuery). Popup isian tampil di shell (Q7-40).

### 5.8 F-11 SKL (Jalur B, MAKE-084)
- Role 2,6: `GET survei-layanan/saya` (riwayat layanan selesai dengan `rated=2`), `POST survei-layanan` `{jenis_rwy,id_rwy,rate,kritik_saran}` —
  cek kepemilikan riwayat [V2], set `rated=1` dalam satu transaksi, ganda → 200 idempoten.
- Role 1: `GET survei-layanan` (filter jenis/periode, `?format=xlsx`) + ringkasan rata-rata per jenis (dipakai widget beranda admin).
- Kolom `rated` sudah ada di kelima tabel riwayat v2; tidak ada perubahan tabel riwayat.

### 5.9 F-08 Pusat Notifikasi (Jalur C, MAKE-085)
- Sumber (Q7-30): S1 notifikasi layanan (`inbox` v2 Fase 4), S2 `show_notif` riwayat/biodata/LKH/Konket (termasuk `show_notif=3` yang belum
  dibawa), S3 antrean approval dari service antrean yang ada, S4 histori approval (kolom [K], Q7-35); berita & izin presensi lewat adapter.
- Endpoint (`Config/RoutesNotifikasi.php`): `GET notifikasi/ringkasan`, `GET notifikasi`, `POST notifikasi/baca`,
  `DELETE notifikasi/{sumber}/{id}` (S2 saja; S1 tanpa hapus, Q7-33), `GET notifikasi/butuh-approval`, `GET notifikasi/histori-approval?bulan=`.
- Role ikut legacy (Q7-29, §6). Tandai dibaca saat item dibuka/"Tandai dibaca" (Q7-31 bawaan).
- BP8-1: modul notifikasi tidak menyentuh `disiplin_ticket(_msg)`; angka tiket hanya lewat `RingkasanTiketInterface` (NoOp sampai Fase 8);
  test arsitektur melarang string tabel tiket di modul notifikasi; `NotifikasiTidakKeTicketingTest` tetap hijau.

### 5.10 F-13 Frontend Dashboard (Jalur D, MAKE-087)
- **Admin (role 1/3/4/5/8):** banner (Q7-39), 4 kartu pengingat Pensiun/KP/TJ/KGB (tautan hanya Pensiun ke `/dokumen/pensiun`, Q7-37),
  komposisi pegawai (donut atau angka sesuai saklar Q7-36) + status hari ini (TB, IB, CLTN, cuti, presensi online), kartu Butuh Approval
  (`useRingkasanNotifikasi`), masa kerja direktur politeknik role 1/4 (Q7-38), berita terbaru, ulang tahun (Q7-45), ringkasan SKL (Q7-26).
  Lingkup role 3 = satker/unit akun (resolver lingkup F-03).
- **Pegawai (role 2/6/7):** banner, tautan Presensi Saya (Q7-42 v2.1), kartu prediksi (role 2 penuh; role 7 Pensiun + KGB; role 6
  tanpa kartu) [K], kelengkapan data, presensi & AK bawahan untuk atasan [K], ulang tahun, berita terbaru (tautan update FK PTT tidak dipasang, Q7-44 v2.1).
- Popup (`PopupAktifDialog`) di akar `HomeView`.
- BE Jalur D: `GET dashboard/ringkasan` (bentuk per keluarga role; cache pendek per lingkup kecuali "hari ini"; pengecualian NIP Q7-41),
  `GET dashboard/ulang-tahun?hari=ini|besok` (field sesuai view legacy, Q7-45), `GET dashboard/direktur` (role 1/4).
- `Config\Dashboard`: `$tampilanKomposisi` (Q7-36), daftar jabatan direktur (Q7-38); `.env` `dashboard.nipDisembunyikan` (Q7-41, rahasia).

### 5.11 ISSUE-058 (Jalur D, MAKE-078 — sudah dikerjakan)
`GET api/v1/integrasi/log` role 1 (filter layanan, status, tanggal WIB inklusif atas `created_at` UTC; paginasi 25/100; `layanan_tersedia`)
+ halaman Pengaturan → Integrasi (⋮ "Lihat detail"; tombol halaman "Tarik Ulang e-Kinerja" ke `POST integrasi/bkn/tarik`; waktu tampil WIB).
Tafsir "tarik ulang per periode" = form periode di tombol halaman, bukan aksi baris. QA: butir N5/N6 checklist Fase 6.

---

## 6. Matriks role × endpoint (ringkas)
| Endpoint | Role |
|---|---|
| `konten/popup`, `konten/banner` (kelola), `konten/dibo` (kelola), `konten/dibo-jenis`, `konten/berita/kategori` (kelola) | 1 |
| `konten/popup/aktif`, `konten/banner/aktif` | role ber-NIP yang masuk target (Q7-05) |
| `konten/berita` (kelola) | 1, 3 |
| `konten/berita` (baca), `konten/dibo` (baca) | 1–8 |
| `form-digital` (kelola, respons) | 1 |
| `form-digital/saya`, `form-digital/{id}/isi` | 2, 6, 7 |
| `survei-layanan` (rekap) | 1 |
| `survei-layanan/saya`, `POST survei-layanan` | 2, 6 |
| `notifikasi` (daftar), `DELETE notifikasi/...` | daftar 2, 3, 6, 7; hapus 1, 2, 6, 7 [K] |
| `notifikasi/butuh-approval`, `notifikasi/histori-approval` | 1, 2, 3 [K] |
| `notifikasi/ringkasan` (lonceng) | **1–8** (semua role login, `MY_Controller:20-175`), angka per role [K]; tampil tanpa angka/polling selama `ACTIVE_PHASE` < 7 |
| `dashboard/ringkasan`, `dashboard/ulang-tahun` | 1–8 (bentuk per keluarga role) |
| `dashboard/direktur` | 1, 4 |
| `integrasi/log` | 1 |
Semua baris wajib tercermin di `KontrakMatriksRoleTest` (aditif per jalur).

## 7. Kontrak antar-jalur
| Dari → ke | Kontrak |
|---|---|
| A → D | `@/features/konten`: `PopupAktifDialog` (akar `HomeView`, tanpa props, penjaga per sesi login), `BannerCarousel` (`class?`), `BeritaTerbaruWidget` (`limit?`=5, `judul?`, tautan nama route portal berita); memuat data sendiri; kosong/403/galat → tidak render; testid `konten-popup-aktif`, `konten-banner`, `konten-berita-terbaru` |
| C → D | `GET notifikasi/ringkasan` → `{notifikasi_belum_dibaca, butuh_approval, berita_belum_dibaca:int\|null, tiket_belum_dibaca:int\|null, diperbarui_at}`; FE `useRingkasanNotifikasi()` dari `@/features/notifikasi` (singleton, polling 60 detik). D memakai mock bentuk sama sampai C di `main` |
| A ↔ C | `SumberNotifikasiInterface` (berkas C); implementasi `SumberBerita` (A) — binding NoOp sampai F-07 di `main`, lalu A mengganti satu baris `Services.php`; F-08 tidak membaca tabel `news` langsung; `tandaiDibaca()` menyetel `news.show_notif=3` |
| B → D | Ringkasan SKL untuk widget admin (endpoint rekap B); popup isian form/SKL dipasang B di shell (Q7-40) |
| A/B ↔ DBV-032 | Kolom A dikirim ke B (sudah diterima 10-11-2026); B memegang dokumen DBV-032 |

## 8. DoD Trello yang dikoreksi
| Kartu | Butir lama | Koreksi |
|---|---|---|
| QASMTASK-099 F-02 | Data dipindah ke `inbox` v2 (notifikasi) dan `disiplin_ticket` | Semua ke `disiplin_ticket`; `inbox` v2 kosong (Q7-28) |
| QASMTASK-101 F-04 | Popup hanya sekali per pegawai per popup | Tampil setiap login selama periode, sekali per sesi login (Q7-02) |
| QASMTASK-104 F-07 | CRUD berita & kategori role 1,3 | Berita 1,3; kategori 1 (Q7-07) |
| QASMTASK-105 F-08 | Endpoint role 1,2,3,6,7 | Role ikut legacy (Q7-29) |
| QASMTASK-109 F-12 | View/Ask UL_ALL | View saja (Q7-12) |
| QASMTASK-110 F-13 | File explorer arsip digital | N/A — F-10 ditunda |
Butir "Lolos MTC-0xx" = Manual Testing (fase tersendiri), tidak menahan sign-off.

## 9. Urutan kerja
| M | Jalur A | Jalur B | Jalur C | Jalur D |
|---|---|---|---|---|
| M0 ✅ | Peta legacy | Peta legacy + skema | Peta legacy | Peta legacy + MAKE-078 |
| M1 | FE dari mockup (lokal) | DBV-032 (setelah D1) | F-08 BE + FE | MAKE-078 merge; kerangka F-13 + `Config\Dashboard` |
| M2 | F-04 + F-05 | F-06 | F-02 tier + dry-run sintetis | F-13 per role (mock kontrak A/C) |
| M3 | F-07 + F-12 | F-11 | Adapter berita/izin presensi | F-13 memasang komponen A/B/C |
| M4 | Reviewer: regresi gate penuh, QA Lapis 1 Fase 7 (MAKE-088), laporan akhir → sign-off Horii | | | |
Setiap M: branch `fase7/<key>-<slug>` dari `origin/main` → gate cepat → push → CI (reviewer) → gate penuh atas aba-aba → review → izin Horii → ff.

## 10. Definition of Done Fase 7
F-01..F-13 (kecuali F-10) + ISSUE-058 di `main` dengan AC terpenuhi; DBV-032 disetujui DB Validator; regresi gate penuh hijau (termasuk
`NotifikasiTidakKeTicketingTest`, statistik F-03, dokumen F-09); QA Lapis 1 Fase 7 lolos; laporan akhir `docs/fase7/Laporan_Akhir_Fase7.md`
mencatat deviasi [V2] dan cara rollback (Lampiran A); Trello DEV-008 + QASMTASK-098..110 (kecuali 107) dan Notion disinkron; sign-off Horii.
F-02 eksekusi data nyata = cutover (bukan syarat sign-off).

---

## 11. Perubahan v2.1 (CR-114, 11-11-2026)
Koreksi temuan selama koding + keputusan Horii/reviewer/DB Validator sesudah v2.0. Semua sudah diterapkan di `main` `ab1ff02d`.

| # | Bagian | Perubahan | Sumber |
|---|---|---|---|
| 1 | D1 Fase 7 (G7-2) | D1 diambil dari **salinan DEV (simpegdev)**, bukan produksi (Horii). Struktur = acuan skema; angka (jumlah/ganda/yatim/sebaran) = angka dev, **diverifikasi ulang di produksi saat cutover** | Horii 11-11 |
| 2 | Q7-03 | Premis "kolom konstan" dikoreksi (lihat §4.2) | D1 + reviewer |
| 3 | Q7-42 / Q7-44 | Presensi = tautan Presensi Saya; update FK PTT tidak dipasang (lihat §4.2) | Jalur D, temuan legacy |
| 4 | Q7-45 | Field kartu ulang tahun mengikuti view legacy + helper ulang tahun legacy (`function_helper.php` blok `getTodayBday`/`getTomorrowBday`, dibaca atas izin khusus); foto lewat endpoint terautentikasi | Jalur D |
| 5 | Q7-29 / §6 | Lonceng ada di **semua role 1–8** dengan angka per role; daftar/hapus/approval tetap per role legacy | Jalur C |
| 6 | F-04 penargetan | Pegawai eksplisit (`target_peg`) ikut **tanpa** filter status dan diabaikan bila target kementerian, persis legacy (`L_announcement.php:477-587`) [K] — deviasi awal dibatalkan | Jalur A |
| 7 | F-04/F-05 gambar | Batas bawaan v2 di `Config\Konten`: banner rasio 4:1 (±2%) lebar 800–4000 px; popup sisi 200–4000 px; ≤ 5 MB; tipe dicek dari isi berkas (Q7-06) | Reviewer (bisa diubah di config) |
| 8 | F-04/F-05 hapus | Popup/banner tanpa kolom status → **hapus permanen** + berkas dihapus, snapshot di log audit [K] (`L_announcement.php:313`, `:1467`) | Jalur A |
| 9 | F-07 role 3 | Berita yang diubah role 3 **langsung berlaku** seperti legacy (Horii pilih B); saklar `Config\Konten::$beritaUbahRole3KembaliMenunggu` (bawaan `false`) | Horii 11-11 |
| 10 | F-07 lampiran & jam | Lampiran berita (JPG/PNG/GIF/PDF ≤ 5 MB, privat) dan jam terbit **dibawa** — dipakai legacy (`L_news.php`, `detail.php`) [K] | Jalur A |
| 11 | F-07 notifikasi | `news.show_notif`/`notif_date` diisi service saat setuju **dan** tolak (pengganti trigger legacy `news_beUpd`); `SumberBerita` aktif di lonceng sejak DBV-032 | D1 + Jalur A |
| 12 | F-12 DIBO | Hapus = **hapus lunak** (status 10, bisa dipulihkan) [V2]; detail DIBO boleh role 1 [V2]; pegawai hanya melihat status 1 (Q7-13) | Jalur A |
| 13 | DBV-032 | Kolom legacy NULL-able (`news.id_news_category`, `news.ul_creator`, `dibo.id_jenis_dibo/nomor/tahun`) tetap NULL-able [K], wajib hanya di form; Q11 news tanpa FK ke unit/satker/user_level/pengguna (syarat: validasi id di service + audit yatim); FK master RESTRICT; seed `jenis_dibo` 8 id | DB Validator |
| 14 | Koreksi NIP | `NipCascadeService::FASE` = 7: koreksi NIP ikut mengubah tabel Fase 7 (skl, user_popup/banner/form_digital, filled_form_digital) + elemen JSON `target_peg`/`excl_peg` | DBV-032 |
| 15 | Antrean dialog | Urutan: form digital → pengingat cuti (Fase 4, ikut antrean atas keputusan Horii) → popup konten → SKL; ganti password paksa (MAKE-020) di luar antrean | Jalur B |
| 16 | Gating Fase 7 | Menu, lonceng, host dialog, dan konten beranda diam (tanpa request) selama `ACTIVE_PHASE` < 7 (`FASE_LONCENG`/`FASE_INTERAKSI`/`FASE_KONTEN` = 7) | Reviewer |
| 17 | Dashboard | Q-D12 `.env` `dashboard.penggunaLihatSemua`, Q-D18 `.env` `dashboard.nipDikecualikanUlangTahun` (rahasia, ikut legacy); Q-D13..Q-D17 kelengkapan data ikut legacy (A); Tanda Jasa satker/unit diperbaiki [V2] dengan saklar (Lampiran A) | Horii 10-11 |
| 18 | `show_pl` | Histori PIC TB/IB/PMK di Pusat Notifikasi **ditunda** (Horii 10-11) | Horii |
| 19 | Impor T14 | MAKE-089 masuk Fase 7: ENUM dari nilai string; popup video dilewati; JSON tak valid hanya lewat berkas keputusan "__buang" (pola F-02); form lama dikonversi ke SkemaForm v2; kunci berkas deterministik; `impor:berkas-konten` baca-saja sumber; `kritik_saran`/`response` tidak masuk log/laporan | Horii 11-11 + reviewer |
| 20 | F-06/F-11 | Pertanyaan "paragraf" memakai `FormField` textarea; kritik & saran SKL wajib di form, opsional di server; label KARIS/KARSU ikut master | Jalur B |

## Lampiran A — Deviasi [V2] yang bisa dikembalikan ke perilaku legacy
| # | Legacy | v2 | Cara kembali ke legacy |
|---|---|---|---|
| Q7-36 | Komposisi pegawai di dashboard admin tampil sebagai angka + persen (`views/user/dashboard_ad.php:47-180`), tanpa chart | Donut komposisi + tile angka | Ubah `Config\Dashboard::$tampilanKomposisi` dari `'donut'` ke `'angka'` (tanpa ubah kode/API/DB); test menjaga kedua mode |
| Q7-10 | Editor HTML WYSIWYG, isi disimpan hampir mentah | Editor teks sederhana + pembersihan server | Tidak disarankan (celah XSS); bila perlu editor kaya → CR baru dengan pustaka yang disetujui |
| Tanda Jasa (v2.1) | Kartu Tanda Jasa satker/unit memakai tahun berjalan → ≈0 di Sep–Des (bug) | Tahun acuan kementerian | `.env` `dashboard.tandaJasaIkutLegacy = true` (tanpa ubah kode) |
| F-07 role 3 (v2.1) | Berita diubah role 3 langsung berlaku | Sama dengan legacy (bawaan) | Saklar `Config\Konten::$beritaUbahRole3KembaliMenunggu = true` bila ingin kembali menunggu persetujuan |
| Bawaan lain [V2] di §4.2 dan §11 | Lihat label [V2] per butir | — | Perubahan sesudah kunci = CR baru atas keputusan Horii |

## Lampiran B — Riwayat versi
| Versi | Tanggal | Isi |
|---|---|---|
| v1.0 | 21-10-2026 | `PRD_Fase7_Statistik-GeneratorDokumen.md` — F-03, F-09 (Done) |
| v2.0 | 10-11-2026 | CR-112: sisa Fase 7 + ISSUE-058 dari M0 empat jalur; 8 keputusan dipilih Horii, 39 bawaan dikonfirmasi Horii; disetujui masuk `main` |
| v2.1 | 11-11-2026 | CR-114: §11 (20 koreksi/keputusan sesudah v2.0), Q7-03/42/44 dikoreksi, lonceng semua role, impor T14 masuk scope, Lampiran A + Tanda Jasa & berita role 3 |
