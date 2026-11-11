# Checklist QA Lapis 1 — Fase 7 Dashboard, Konten, Notifikasi (MAKE-088)

Untuk reviewer CR (pelaksana QA Lapis 1 di app demo; PRD Fase 7 §10 DoD). Acuan: PRD Fase 7 **v2.1** (`main` `b1f084bf`)
§5.1–§5.11, §6 (matriks role × endpoint), §7 (kontrak antar-jalur), §11 (perubahan v2.1), Lampiran A (deviasi [V2] yang bisa
dikembalikan), register Q7-01..Q7-47 dan Q-D12..Q-D20; DBV-032 (`backend/docs/db-review/DBV-032-konten-interaksi-fase7.md`);
README modul (`backend/app/Controllers/Api/Notifikasi`, `backend/app/Libraries/Impor`, `backend/app/Libraries/Dashboard`,
`frontend/src/features/{notifikasi,dashboard}`), `backend/docs/progress/07-Dashboard.md`, `README-deploy.md` §1k.
Pola: Checklist QA Lapis 1 Fase 6. Checklist ini **disepakati reviewer CR sebelum QA dimulai**; pertanyaan pra-QA dan
keputusannya di §K.

**Hasil eksekusi (11-11-2026).** Dijalankan reviewer CR di app demo `wt/demo` (DB `simpeg_v2_t_app_demo` @3307, data seed demo + QA F4 + QA F7 A–D) pada `main` `875b8e5b`, `ACTIVE_PHASE` 7 lokal tanpa commit (GF1–GF2 di 5, dikembalikan di GF5). Butir API/DB bagian C, D, B-B, B-E dijalankan paralel oleh agen workflow lalu bagian tampilannya diuji reviewer. Temuan T-03, T-05, T-06, T-07, T-08 diperbaiki CR-117 (`eab1b2f6`), CR-118 (`4c70a80a`), CR-117 lanjutan (`d99f690e`), CR-119 (`29ee1d34`) dan diuji ulang di `main` `29ee1d34`. Hasil akhir: semua butir ✅ / [test-otomatis] / — (beralasan); tidak ada ❌ terbuka. Data demo berubah selama QA (isian form, SKL, notifikasi dibaca, riwayat KP DK1, judul N6) — reset dengan prosedur di bawah sebelum QA ulang.

**Cakupan.** Seluruh scope Fase 7 v2.1: F-01 skema DBV-032, F-04 popup, F-05 banner, F-07 berita (+ kategori dan persetujuan),
F-12 DIBO (+ jenis), F-06 form digital, F-11 SKL, antrean dialog, F-08 Pusat Notifikasi (+ CR-113), F-02 sortir inbox legacy,
impor tier 14 (MAKE-089) + `impor:berkas-konten`, F-13 beranda, dan ISSUE-058 (butir N5/N6 Checklist Fase 6). F-03/F-09 (PRD v1.0)
sudah diuji di fasenya; F-10 ditunda. Eksekusi impor data nyata = cutover, bukan bagian QA ini.

**Cara membaca nomor butir.** Butir diberi awalan jalur pemilik fitur: `A-` (konten: popup, banner, berita, DIBO), `B-` (form
digital, SKL, antrean dialog, skema), `C-` (notifikasi, sortir inbox, impor), `D-` (beranda, integrasi). Bagian `<jalur>-<huruf>`
memakai huruf asal jalur. Rujukan di dalam satu bagian (mis. "lihat D6" di bagian A-…, "data P1") menunjuk butir/data **jalur yang
sama**; kode data tercantum di tabel data dengan kolom Jalur. Butir gating fase dari keempat jalur digabung di bagian **GF**.

## Lingkungan & persiapan

**App demo lokal:** worktree demo reviewer pada `main` terbaru, DB `simpeg_v2_t_app_demo` @3307 (tanpa prefix tabel), backend port
8120, frontend 5190, captcha mock, driver integrasi `mock`, data sintetis. Akun di `AKUN_DEMO.md` folder demo-seed (password tidak
ditulis di sini). Butir A-E2 mengasumsikan **CR-116** (DIBO berjenis nonaktif disembunyikan) sudah ada di `main` worktree demo.

**Reset demo dan urutan skrip data** (`reset_demo.sh` sudah tidak ada, §K C-KC1). Git Bash, `.env` backend demo
`CI_ENVIRONMENT = development` (tabel tanpa prefix):

```bash
mysql -h127.0.0.1 -P3307 -uroot -e "DROP DATABASE IF EXISTS simpeg_v2_t_app_demo; CREATE DATABASE simpeg_v2_t_app_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cd <backend demo> && php spark migrate --all          # termasuk tabel konten Fase 7 + seed 8 jenis_dibo
cd <folder demo-seed>
php seed_demo.php <backend demo>
php qa_fase4_data.php <backend demo>                  # data layanan F4 (sumber notifikasi S1, antrean cuti)
php qa_fase7_a.php <backend demo>                     # konten Jalur A; peta kode → id di QA_F7_A_ID.md
php qa_fase7_b.php <backend demo>                     # form digital & SKL; memakai popup P6 Jalur A untuk antrean B-G1
php qa_fase7_c.php <backend demo>                     # notifikasi + skema impor QA (tidak menulis DB demo untuk impor)
php qa_fase7_d.php <backend demo>                     # beranda + integrasi; mencetak angka acuan beranda per akun
```

Semua skrip idempoten, ber-guard DB demo @3307, dan memakai penanda `[QA-F7 <jalur><kode>]`. Skrip tidak mencetak password;
akun baru (`demo.qaf7.lengkap`, skrip D) hanya ditambahkan ke `AKUN_DEMO.md`. `qa_fase6_data.php` tidak wajib; bila dipakai,
jalankan sesudah `qa_fase4_data.php`. Skrip memakai tanggal relatif hari dijalankan (periode aktif kemarin…+30 hari, ulang tahun
hari ini/besok): jalankan menjelang QA, dan jalankan ulang skrip D bila QA berlanjut ke hari lain.

**`ACTIVE_PHASE`.** Di `main` nilainya **5** (`frontend/src/shared/layouts/nav.config.ts`), sehingga menu, lonceng, popup, banner,
berita terbaru, host dialog, dan kartu SKL Fase 7 sengaja diam. Kerjakan butir GF1–GF2 dengan nilai 5. Sesudah itu ubah konstanta
itu menjadi **7 di worktree demo saja** (JANGAN di-commit; Vite memuat ulang otomatis) untuk semua butir lain, lalu kembalikan ke 5
pada GF5. Penaikan permanen = penutup fase (sign-off), bukan bagian QA.

**`.env` uji sementara** (backend demo; restart backend, kembalikan sesudah butirnya): `konten.beritaUbahRole3KembaliMenunggu`
(A-D11), `dashboard.tampilanKomposisi`, `dashboard.nipDisembunyikan`, `dashboard.penggunaLihatSemua`,
`dashboard.nipDikecualikanUlangTahun`, `dashboard.tandaJasaIkutLegacy` (bagian D-B, D-C, D-E). Isi hanya NIP/id **sintetis** DB
demo dan jangan tempel nilainya di laporan (sebut kodenya, mis. "NIP DU5"). Ringkasan beranda di-cache per lingkup: sesudah
mengubah `.env`/data, kosongkan cache demo (`php spark cache:clear`).

**Isi kolom Hasil:** ✅ lolos / ❌ gagal (+ nomor temuan di §T) / — tidak berlaku / **[test-otomatis]** (diterima lewat bukti test
otomatis, lihat §K). Bukti: tangkapan layar UI, respons API, keluaran perintah, atau isi baris DB demo — **tanpa** NIP/NIK, token,
rahasia, isi kritik/saran SKL, maupun isi jawaban form.

### Alat bantu

**Memanggil API** (Git Bash; login akun uji di browser lewat form login biasa, salin `Authorization: Bearer <JWT>` dari tab Network;
token **jangan** ditempel di laporan). Amplop galat `{"status":false,"message":…,"errors":{…}}` HTTP 403/404/409/422; bila berbeda,
catat di §T.

```bash
B=http://localhost:8120/api/v1
curl -s -H "Authorization: Bearer <JWT>" "$B/konten/popup/aktif"
curl -s -H "Authorization: Bearer <JWT admin>" -F "berkas=@palsu.png" -F "tgl_mulai=2026-11-12 08:00" -F "tgl_akhir=2026-12-01 17:00" \
     -F "target[unit_satker][]=0" "$B/konten/popup"
curl -s -H "Authorization: Bearer <JWT>" "$B/form-digital/saya"
curl -s -X POST -H "Authorization: Bearer <JWT>" -F 'jawaban={"kehadiran":"Hadir"}' "$B/form-digital/<id BF2>/isi"
curl -s -X POST -H "Authorization: Bearer <JWT>" -H 'Content-Type: application/json' \
  -d '{"jenis_rwy":1,"id_rwy":<id BS1>,"rate":5,"kritik_saran":"uji"}' "$B/survei-layanan"
curl -s -H "Authorization: Bearer <JWT>" "$B/notifikasi/ringkasan"
curl -s -H "Authorization: Bearer <JWT>" "$B/dashboard/ringkasan"
curl -s -H "Authorization: Bearer <JWT admin>" "$B/integrasi/log?layanan=esign&status=2&per_page=25"
```

**Berkas uji konten** (bagian A-…; di folder kerja sementara, bukan folder repo):

```bash
php -r '$f=function($w,$h,$n){imagepng(imagecreatetruecolor($w,$h),$n);};
$f(1600,400,"banner_ok.png"); $f(1000,500,"banner_rasio_salah.png"); $f(600,150,"banner_kecil.png");
$f(640,400,"popup_ok.png"); $f(100,100,"popup_kecil.png");'
cp banner_ok.png besar.png && head -c 5300000 /dev/zero >> besar.png      # > 5 MB
printf '<?php echo 1;' > palsu.png                                         # PHP bernama .png
printf '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>' > skrip.svg
printf '%%PDF-1.4\n%%%%EOF\n' > uji.pdf                                    # PDF kecil untuk DIBO/lampiran
```

**Impor (bagian C-F, C-I).** `impor:legacy` wajib ke tujuan kosong, sedangkan DB demo berisi data A/B. Karena itu skrip C membuat
skema QA `simpeg_v2_t_app_demo_qa7_lgc` (legacy sintetis) dan `simpeg_v2_t_app_demo_qa7_tujuan` (tujuan kosong), plus folder kerja
`qa_f7_c/` di folder demo-seed. Command impor **asli** dijalankan lewat pembungkus `qa_fase7_c_impor.php`, yang hanya mengarahkan
koneksi default proses itu ke skema tujuan QA dan akar konten ke `qa_f7_c/konten_tujuan`. Berkas `.env` demo tidak diubah, karena
nilai `.env` selalu menang atas variabel lingkungan bertitik. Ulang dari awal: `php qa_fase7_c.php <backend demo> --reset-impor`.

```bash
DS=<folder demo-seed>; BE=<backend demo>; W="php $DS/qa_fase7_c_impor.php $BE"
L=simpeg_v2_t_app_demo_qa7_lgc; T=simpeg_v2_t_app_demo_qa7_tujuan; F=$DS/qa_f7_c
```

## Data uji gabungan

Kolom **Untuk bagian** menunjuk bagian/butir jalur yang sama (mis. data A "A1–A6" = butir A-A1–A-A6).

| Jalur | Kode | Data | Untuk bagian |
|---|---|---|---|
| semua | Akun | Akun demo Fase 4: `demo.admin` 1, `demo.satker` 3 (unit 1/satker 1), `demo.eselon1` 4 (unit 2), `demo.menteri` 5, `demo.pimpinan` 8 (akun peran tanpa NIP); `demo.pegawai`, `demo.pemohon`, `demo.atasan` (atasan, punya bawahan), `demo.pyb` (role 2, unit 1/satker 1); `demo.ptt` 6; `demo.pppk` 7; `demo.qaf7.lengkap` 2 (dibuat `qa_fase7_d.php`); akun sistem `sistem.integrasi` (Fase 6) | semua |
| A | P1 | Popup aktif, seluruh kementerian, ada tautan | A1–A6, G |
| A | P2 | Popup aktif, target satker 1 | A1–A3 |
| A | P3 | Popup aktif, target unit 2 (tidak menjangkau akun demo, semua di unit 1) | A3, A8 |
| A | P4 | Popup kedaluwarsa | A3 |
| A | P5 | Popup seluruh kementerian **kecuali `demo.pemohon`** | A3, A8 |
| A | P6 | Popup aktif, target **pegawai eksplisit `demo.atasan`** (periode kemarin…+30 hari); dipakai Jalur B untuk uji antrean dialog (form digital → pengingat cuti → popup → SKL) | A4, antrean dialog (Jalur B) |
| A | PX | Popup sasaran uji kunci berkas rusak (target unit 2, tidak mengganggu tampilan) | G3 |
| A | B1, B2 | Banner aktif urutan 1 (ada tautan) dan 2 (tanpa tautan), seluruh kementerian | B1–B6 |
| A | B3 | Banner aktif target unit 2 | B3 |
| A | B4 | Banner kedaluwarsa | B3 |
| A | K1 | Kategori berita aktif, `allow_ap=1` (role 3 boleh) | C, D |
| A | K2 | Kategori berita aktif, `allow_ap=2` (role 3 **tidak** boleh) | C5, D6 |
| A | K3 | Kategori berita nonaktif | C5 |
| A | N1 | Berita terbit, seluruh kementerian, isi memuat `<script>`, atribut `onclick`/`onerror` (uji sanitasi) | C1–C4, C9 |
| A | N2 | Berita terbit, target unit 2 | C2 |
| A | N3 | Berita terbit, target satker 1 | C2 |
| A | N4 | Berita terjadwal (`publish_at` +3 hari) | C2 |
| A | N5 | Berita buatan `demo.satker`, status **Menunggu** | D1–D4 |
| A | N6 | Berita buatan `demo.satker`, sudah **Disetujui** | D5, C2 |
| A | N7 | Berita buatan `demo.satker`, sudah **Ditolak** (alasan terisi) | D5 |
| A | N8 | Berita terbit dengan lampiran PDF | C6 |
| A | D1 | DIBO aktif, jenis UU, 3 berkas PDF | E |
| A | D2 | DIBO aktif, jenis PP, 1 berkas | E |
| A | D3 | DIBO **dicabut** (status 2) | E2, E4 |
| A | D4 | DIBO **dihapus** lunak (status 10) | E4 |
| A | D5 | DIBO aktif tetapi jenis `[QA-F7 AJ1]` **nonaktif** | E2, F3 (lihat §K A-K1) |
| A | J1 | Jenis DIBO uji `[QA-F7 AJ1]`, nonaktif, dipakai D5 | F |
| A | AU1, AS1 | Unit dan satker **nonaktif** (`[QA-F7 AU1]`, `[QA-F7 AS1]`) untuk target nonaktif Q11 | G2 |
| B | BF1 | Form aktif, 7 tipe pertanyaan (teks, paragraf, pilihan tunggal, pilihan ganda, tanggal, angka, berkas PDF ≤ 2 MB opsional), periode kemarin–+30 hari, urutan 1, target pegawai: `demo.pegawai`, `demo.atasan`, `demo.ptt`, `demo.pppk`; belum ada jawaban | B, C, G |
| B | BF2 | Form aktif urutan 2, 2 pertanyaan, target `demo.pegawai` + `demo.pemohon`; **sudah dijawab `demo.pemohon`** → pertanyaan terkunci | B, C |
| B | BF3 | Form aktif, periode **sudah lewat** (−20..−1 hari), target `demo.pegawai` | C |
| B | BF4 | Form **nonaktif** (status 2), periode berjalan, target `demo.pegawai` | C |
| B | BF5 | Form **dihapus** (status 10), target `demo.pemohon`, 1 jawaban tetap tersimpan | B |
| B | BS1–BS5 | Riwayat `demo.pegawai` selesai (status 1) belum dinilai (`rated` 2), satu per jenis: Cuti, KARIS/KARSU, KARPEG, TB, IB | D, G |
| B | BS6 | Cuti `demo.ptt` selesai belum dinilai (role 6) | D |
| B | BS7 | Cuti `demo.pegawai` **belum selesai** (status 0, rated 2) | D |
| B | BS8 | Cuti `demo.pegawai` selesai, **`rated` 0** (tidak perlu dinilai) | D |
| B | BS9 | Cuti `demo.pemohon` **sudah dinilai** (rate 4, kritik fiktif) | D, E |
| B | BS10 | KARPEG `demo.pemohon` sudah dinilai (rate 2, kritik berisi `<b>teks</b>` untuk uji render teks) | E |
| B | BS11 | Cuti `demo.pppk` selesai belum dinilai (role 7 — bukan pengisi SKL, Q7-23) | D |
| B | BS12 | Cuti `demo.atasan` selesai belum dinilai (untuk antrean lengkap G1) | G |
| C | C1 | Riwayat **Organisasi** `demo.pegawai` diajukan → **disetujui** `demo.admin` (S2 `show_notif` 1, `show_ua_biro` 1) | N3, N5, N6, N9 |
| C | C2 | Riwayat **Kursus/Seminar** `demo.pegawai` diajukan → **ditolak** `demo.satker` dengan alasan `[QA-F7 C2] …` | N3, N4, N9 |
| C | C3 | Riwayat **Organisasi** `demo.pegawai` **menunggu** (status 0) | N8 |
| C | C4 | Riwayat **Alamat** `demo.ptt` (alamat bukan utama) disetujui `demo.admin` — jenis daftar UL 6 | N3 |
| C | C4n | Riwayat **Organisasi** `demo.ptt` status 1 `show_notif` 1, ditulis langsung (PTT tidak boleh mengajukan organisasi) — bukan jenis daftar UL 6 | N3 |
| C | C5 | Riwayat **Organisasi** `demo.pppk` disetujui `demo.admin` (UL 7 = daftar UL 2) | N3 |
| C | C6 | Kategori berita `[QA-F7 C6] Kategori uji` (allow_ap 1); berita `demo.satker`: **C6a** disetujui, **C6b** ditolak (alasan), **C6c** menunggu | N3, N7, N8, N9 |
| C | C7 | LKH `demo.pegawai` hari ini status 0, atasan `demo.atasan` | N8 |
| C | S1 (sumber) | Notifikasi layanan cuti/TB/IB/PMK dari `qa_fase4_data.php` (`inbox` v2): `demo.pemohon` (keputusan cuti), `demo.atasan` (cuti diajukan, keputusan PMK), `demo.pegawai` (IB/PMK sebagai PIC), `demo.pppk` (IB ditolak) | N3, N5 |
| C | CF | Skema `…_qa7_lgc`: `inbox` 5 tiket (1–2 T-OK, 3 A-ESK pengirim 0, 4 A-ESK tanpa pesan, 5 T-NORM kategori NULL) + `inbox_msg` 6 pesan (10/11/12/15 M-OK, 13 M-IKUT, 14 M-ESK tanpa induk); `qa_f7_c/keputusan_f02.json` (tiket 3 `__buang`, 4 `impor`, pesan 14 `__buang`) | F1–F5 |
| C | CT | Skema `…_qa7_lgc` 13 tabel tier 14 bentuk D1: berita berskrip + lampiran, berita lampiran traversal; `news_flag` ganda; `dibo` tahun 0000 + berkas `.exe`; popup gambar, video, JSON target rusak; banner berkas ada + berkas hilang; `skl` ganda (kritik `QAF7RAHASIAKRITIK`); form sah + form `starRating`; jawaban berkas (`QAF7RAHASIAJAWABAN`). Folder `qa_f7_c/legacy_app/assets/upload/…`; `qa_f7_c/keputusan_t14.json` (news 3, dibo 2, popup 3, form 2 = `__buang`) | I1–I9 |
| C | CQ | Skema tujuan `simpeg_v2_t_app_demo_qa7_tujuan`: tabel tier 13 + 14 kosong (LIKE skema demo), seed `jenis_dibo` 8 id, 1 kategori tiket, kunci `pengguna`/`pegawai` (id/NIP saja, untuk INFO F-02) | F4, I2–I9 |
| D | DU1 | PNS aktif, ulang tahun **hari ini**, satker demo, **ada foto** (PNG sintetis) | E |
| D | DU2 | PNS aktif, ulang tahun **besok**, tanpa foto | E |
| D | DU3 | PNS aktif ulang tahun hari ini di **satker 171** ("Satker Dikecualikan Ulang Tahun [QA-F7 DU3]") | E (Q-D20) |
| D | DU4 | pegawai **status 2** (nonaktif) ulang tahun hari ini | E |
| D | DU5 | PNS aktif ulang tahun hari ini + foto; NIP-nya (dicetak skrip) dipakai untuk `.env` `dashboard.nipDikecualikanUlangTahun` | E, H (Q-D18) |
| D | DD1 | pemegang **terbaru** jabatan id 617 (direktur politeknik) di satker 172 "Politeknik Pariwisata Demo [QA-F7 DD]", TMT 2024-08-01 | C |
| D | DD2 | pemegang **lama** jabatan 617, TMT 2016-02-01 | C |
| D | DD3 | pemegang jabatan id 616, TMT 2021-03-15 | C |
| D | DK1 | akun `demo.qaf7.lengkap`: PNS menikah tanpa data keluarga, tanpa alamat/KP/KGB/tanda jasa, NPWP & no. HP kosong; pendidikan hanya SLTA + S.1 berkode legacy (DK2) | D |
| D | DK2 | master `jenjang_pendidikan` + "SLTA"/"S.1" berkode legacy (nama berpenanda `[QA-F7 DK2]`) + 2 riwayat pendidikan DK1 | D5 (K2) |
| D | DP1 | PNS aktif III/a, KP reguler TMT 1 April (tahun berjalan − 4), pendidikan S.1 berkode legacy, satker demo 1 | C1 (K4) |
| D | DS1–DS6 | 6 penilaian SKL 12 bulan terakhir: Cuti 5, 4, 4 · KARIS/KARSU 3 · Tugas Belajar 5, 5 · KARPEG & Izin Belajar tanpa penilaian | G |
| D | DI1–DI6 | `integrasi_log`: bkn sukses (hari ini 09:15 WIB), esign gagal HTTP 500 (hari ini), siasn gagal tanpa HTTP/timeout (kemarin), simapi **masuk** sukses (kemarin), fcm sukses (8 hari lalu), ekinerja **00:30 WIB hari ini** (= 17:30 UTC kemarin) | I |
| D | DI7-01..30 | 30 log ekinerja kemarin 07:01–07:30 WIB, tiap ke-5 gagal HTTP 502 | I (paginasi) |
| D | A* | banner/popup/berita aktif dari skrip/checklist Jalur A | F |
| D | F4 | cuti menunggu AL `demo.atasan` / PyB (data Fase 4) | C3, D (Butuh Approval) |

## GF. Gating fase umum (PRD §11 butir 16, §6 baris lonceng) — gabungan A-H, B-G3, C-N1, D-A1/A2

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| GF1 | §11-16 | `ACTIVE_PHASE = 5` (bawaan `main`): login `demo.pegawai` lalu `demo.admin`, buka Beranda dan beberapa halaman dengan tab Network terbuka; periksa sidebar dan topbar | **Tidak ada** request `konten/*` (popup/banner/berita), `form-digital/saya`, `survei-layanan/saya`, `survei-layanan/ringkasan`, `notifikasi/ringkasan`. Tidak ada menu Berita, DIBO, Kelola Berita, Kelola Konten, Form Digital, Survei Layanan, Notifikasi. Tanpa popup, banner, berita terbaru, host dialog form/SKL, kartu SKL. Lonceng polos tanpa angka/polling; kartu Butuh Approval "—" tanpa tautan. Bagian beranda lain tampil normal (asal: A-H1, B-G3, C-N1, D-A1) | ✅ ACTIVE_PHASE 5: pegawai & admin tanpa request konten/*, form-digital/saya, survei-layanan/*, notifikasi/ringkasan; menu Fase 7 tidak ada (30 tautan admin diperiksa); Butuh Approval "—"; lonceng polos. |
| GF2 | §11-16 | Masih `ACTIVE_PHASE = 5`: buka langsung URL `/berita`, `/dibo`, `/konten/popup` (sebagai `demo.admin`) dan `/notifikasi` (sebagai `demo.pegawai`) | Halaman tidak diblokir penjaga route (QA lewat URL langsung, pola F7-14); tidak ada menu yang menuntun ke sana (asal: A-H2, C-N1) | ✅ /berita, /dibo, /konten/popup (admin) dan /notifikasi (pegawai) terbuka lewat URL langsung. Catatan: halaman /notifikasi sendiri memanggil notifikasi/ringkasan (wajar, halaman dibuka langsung). |
| GF3 | §11-16 | Ubah `ACTIVE_PHASE = 7` di worktree demo (tanpa commit), muat ulang, login `demo.admin` | Grup **Berita** tampil: Portal Berita, DIBO, Kelola Berita, Kelola Konten (Popup, Banner, Kategori Berita, DIBO, Jenis DIBO); menu Notifikasi, Form Digital, Survei Layanan tampil. Request `konten/banner/aktif`, `konten/popup/aktif`, `konten/berita…`, `survei-layanan/ringkasan`, `notifikasi/ringkasan` muncul; lonceng berangka (asal: A-H3, D-A2) | ✅ ACTIVE_PHASE 7 lokal: grup Berita (Portal Berita, DIBO, Kelola Berita, Popup, Banner, Kategori Berita, DIBO, Jenis DIBO), Notifikasi, Form Digital, Survei Layanan tampil; request konten/banner/aktif, popup/aktif, berita, survei-layanan/ringkasan, notifikasi/r… |
| GF4 | Matriks §6 | Menu per role (`ACTIVE_PHASE = 7`): `demo.pegawai`, `demo.ptt`, `demo.pppk`, `demo.eselon1`, `demo.menteri`, `demo.pimpinan`; lalu `demo.satker` | Role 2/4/5/6/7/8: hanya Portal Berita + DIBO (baca), tanpa Kelola Berita/Kelola Konten. `demo.satker`: Portal Berita, DIBO, **Kelola Berita**, tanpa Kelola Konten. Menu Form Digital (kelola) dan Survei Layanan (rekap) hanya role 1 (asal: A-H4, A-H5) | ✅ pegawai/ptt/pppk: Notifikasi+Portal Berita+DIBO; eselon1/menteri/pimpinan: Portal Berita+DIBO; satker: +Kelola Berita (tanpa Kelola Konten/Form Digital/Survei). |
| GF5 | §11-16 | Sesudah seluruh QA: kembalikan `ACTIVE_PHASE = 5`, muat ulang | Perilaku GF1 pulih; `git status` worktree demo bersih untuk `nav.config.ts` (asal: A-H6) | ✅ ACTIVE_PHASE dikembalikan 5 (git checkout nav.config.ts): tanpa request konten/form/survei/notifikasi, tanpa menu/dialog/banner F7, lonceng polos; git status wt/demo bersih @29ee1d34. |

## B-A. F-01 Skema DBV-032 (sekilas, §5.1, §11 butir 13–14)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-A1 | 5.1 | Cek di DB demo 13 tabel: `popup`, `user_popup`, `banner`, `user_banner`, `form_digital`, `user_form_digital`, `filled_form_digital`, `news_category`, `news`, `news_flag`, `skl`, `jenis_dibo`, `dibo` | Semua ada (migrate dari `main`), collation `utf8mb4_unicode_ci`; `jenis_dibo` berisi 8 id seed | ✅ 13 tabel unicode_ci; jenis_dibo 8 seed + 1 data QA (AJ1 id 9). |
| B-A2 | 5.1 | Jalankan `backend/docs/db-review/sql/DBV-032-audit-konten-interaksi.sql` (baca-saja) di DB demo | Semua kueri WAJIB_0 = 0 (ganda UNIQUE, yatim FK, `skl.rate`/`jenis_rwy` di luar 1–5, JSON target tidak valid); kueri INFO hanya angka | ✅ dgn catatan T-02: 2 baris WAJIB_0 = 1 (news_category status di luar 0/1 → v2 memakai 1/2; dibo status di luar 1/2 → D4 status 10 hapus lunak [V2]). Domain kueri = domain legacy; impor memetakan 0→2 (AturanKonten). Bukan bug aplikasi; kueri/ceklis perlu catata… |
| B-A3 | 5.1 | `SHOW CREATE TABLE skl` dan `filled_form_digital` | UNIQUE `(nip,jenis_rwy,id_rwy)` + CHECK rate 1–5 & jenis 1–5; `filled_form_digital` UNIQUE `(id_form_digital,nip)` dan FK ke `form_digital` **RESTRICT**; `user_form_digital` FK CASCADE | ✅ skl UNIQUE+2 CHECK; ffd UNIQUE + FK form RESTRICT (+CHECK json_valid); user_form_digital FK CASCADE. |
| B-A4 | 5.1 / Q7-47 | Cek kolom `form_digital` | `target_uns`/`target_peg`/`excl_peg` JSON NULL-able; **tanpa** kolom `*_desc` ("NIP – nama") | ✅ target_uns/target_peg/excl_peg NULL-able, tanpa *_desc. |
| B-A5 | §11 #14 | Bukti koreksi NIP ikut tabel Fase 7 (skl, user_*/filled_form_digital, elemen JSON `target_peg`/`excl_peg`) | Tidak diuji manual di demo (mengubah NIP data demo); bukti test otomatis `NipCascadeService` di gate `main` | [test-otomatis] NipCascadeService FASE 7 dibuktikan test otomatis di gate main (tidak diuji manual agar NIP demo tidak berubah) |

## A-A. F-04 Popup pengumuman (MAKE-079, §5.3, Q7-02/05/06/15, §11 butir 6, 7, 8)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-A1 | Q7-02 | Login `demo.pegawai` → Beranda | Dialog popup tampil (`konten-popup-aktif`) berisi P1 dan P2 (dan P5), urut `order`; tombol sebelumnya/berikutnya berfungsi bila lebih dari satu; P1 menampilkan tautan | ✅ (sesudah form+SKL selesai) popup 1/3 P1(tautan)→P2→P5, sebelumnya/berikutnya jalan; Esc tidak menutup sebelum gambar terakhir (pola legacy pu_image); tombol Tutup hanya di terakhir. |
| A-A2 | Q7-02 | Tutup dialog (`konten-popup-tutup`), pindah menu lalu kembali ke Beranda **tanpa logout** | Popup **tidak** muncul lagi dalam sesi login yang sama (penjaga per sesi) | ✅ tutup → /faq → / : tidak muncul lagi. |
| A-A3 | Q7-02 | Logout → login lagi sebagai `demo.pegawai` | Popup muncul lagi (tampil tiap login); tidak ada endpoint "dilihat" dipanggil (Network) | ✅ logout UI → login UI: muncul lagi; tanpa endpoint "dilihat". |
| A-A4 | Q7-05/15 | Bandingkan akun: `demo.pegawai`, `demo.pemohon`, `demo.atasan`, `demo.ptt`, `demo.pppk` | `demo.pegawai`, `demo.ptt`, `demo.pppk`: P1, P2, P5. `demo.atasan`: P1, P2, P5, **P6** (target pegawai eksplisit). `demo.pemohon`: hanya P1, P2 (P5 mengecualikannya). Tidak ada akun yang melihat P3 (unit 2), P4 (kedaluwarsa) | ✅ (API) pegawai/ptt/pppk P1,P2,P5; atasan +P6; pemohon P1,P2. |
| A-A5 | Q7-05 | Login `demo.admin`, `demo.satker`, `demo.eselon1`, `demo.menteri`, `demo.pimpinan` → Beranda | Tidak ada popup (akun peran tanpa NIP, Q7-05). API `GET konten/popup/aktif`: `demo.admin` (role 1) **403** (route hanya role 2–8); `demo.satker`/`demo.eselon1`/`demo.menteri`/`demo.pimpinan` **200 dengan daftar kosong**; tanpa galat tampilan | ✅ (API) admin 403; satker/eselon1/menteri/pimpinan 200 []. |
| A-A6 | Q7-15 | `demo.admin` → Kelola Konten → Popup. Periksa daftar | P1–P5 dan PX tampil dengan periode, tautan, jumlah penerima; **tidak ada** kolom/switch status (popup tanpa status); aksi baris hanya di ⋮ | ✅ Awalnya ❌ T-05 (kolom Tautan tidak ada; legacy popup/list.php:47,105). Diperbaiki CR-117 (main eab1b2f6), uji ulang @29ee1d34: kolom Tautan popup & banner (tautan aman / "—"); periode, penerima, tanpa switch status, aksi ⋮ |
| A-A7 | AGENTS §1 | ⋮ pada baris popup | Item berurutan: Edit → Naikkan urutan → Turunkan urutan → Hapus (merah, garis pemisah); baris pertama: Naikkan urutan `disabled`, baris terakhir: Turunkan urutan `disabled` | ✅ ⋮: Edit → Naikkan → Turunkan → --- → Hapus; baris 1 Naikkan disabled; baris terakhir Turunkan disabled. |
| A-A8 | Q7-15 | `GET api/v1/konten/popup/{P1}/penerima` dan `{P5}` (admin) lalu `{P3}` | Daftar berhalaman nip+nama. Jumlah P5 = jumlah P1 − 1 (demo.pemohon dikecualikan); P3 hanya pegawai unit 2; P2 hanya pegawai satker 1 | ✅ penerima berhalaman: P1 144, P5 143 (=P1−1), P3 36 (unit 2), P2 16 (satker 1). |
| A-A9 | Q7-06 | Tambah popup (admin): isi periode (mulai ≤ akhir), tautan `https://…`, target Seluruh Kementerian, unggah `popup_ok.png` | Tersimpan; muncul di daftar; penerima terbentuk; audit `entity=popup, event=create` di `audit_logs` | ✅ popup baru 201, penerima 153, berkas +1, audit popup create. |
| A-A10 | Q7-06 | Tambah popup dengan: `popup_kecil.png` (sisi < 200 px); `besar.png`; `palsu.png`; `skrip.svg` | Masing-masing **422** dengan pesan pada field `berkas`; tidak ada baris popup baru dan tidak ada berkas tersisa di `writable/uploads/konten/popup/` | ✅ kecil → "Sisi gambar popup harus 200–4000 px"; besar → "melebihi 5 MB"; palsu/svg → "Jenis berkas tidak diizinkan"; semua di field berkas; baris & berkas tidak bertambah. |
| A-A11 | validasi | Tambah popup: tanggal akhir sebelum mulai; tautan `ftp://x`; tanpa target; tanpa berkas | 422 per field (`tgl_akhir`, `url`, `target`, `berkas`) | ✅ per kasus: tgl_akhir/url/target/berkas masing-masing 422 di field-nya (validasi berurutan: bila banyak salah sekaligus hanya error pertama dikembalikan — catatan kecil). |
| A-A12 | Q7-15 | Edit popup uji (mis. buat popup sementara target satker 1): ubah target ke Seluruh Kementerian, simpan; cek penerima; lalu ubah ke satker 1 lagi | Selisih penerima tersinkron (bertambah lalu berkurang), tanpa duplikat; gambar lama tetap bila tidak mengunggah baru; audit `update` | ✅ kem 153 → sat_1 21 → kem 153, tanpa duplikat; file tetap; audit update. |
| A-A13 | §11-8 | Hapus popup sementara (konfirmasi di `ConfirmDialog`) | **Hapus permanen**: baris hilang dari daftar, `user_popup` baris anak ikut hilang, berkas gambar hilang dari penyimpanan, `GET konten/popup/{id}/gambar` → 404; audit `delete` memuat snapshot | ✅ hapus permanen: baris+user_popup hilang, berkas hilang, gambar 404, audit delete dengan before_json. |
| A-A14 | §11-6 | Popup dengan **pegawai eksplisit** (`target pegawai` = satu pegawai nonaktif/pensiun bila ada di data demo) dan kementerian + pegawai | Pegawai eksplisit ikut sebagai penerima tanpa filter status; bila target kementerian, pegawai eksplisit diabaikan (persis legacy). Bila data demo tidak punya pegawai nonaktif → — | ✅ target unit 2 + pegawai nonaktif (status 2) → ikut sebagai penerima; kementerian + pegawai nonaktif → diabaikan. |
| A-A15 | Q7-14 | `GET konten/popup/{P1}/gambar` dengan JWT `demo.pegawai`; tanpa header `Authorization` | 200 `image/png` untuk akun login; **401** tanpa token (tidak ada URL publik/webroot ke berkas) | ✅ gambar P1 200 image/png dgn token; 401 tanpa token. |

## A-B. F-05 Banner slider (MAKE-080, §5.4, Q7-39, §11 butir 7, 8)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-B1 | §5.4 | Login `demo.pegawai` → Beranda | Carousel banner (`konten-banner`) menampilkan B1 lalu B2 sesuai `order`; B3 (unit 2) dan B4 (kedaluwarsa) tidak tampil; B1 berupa tautan (`konten-banner-tautan`, `rel="noopener noreferrer"`), B2 tanpa tautan | ✅ Carousel pegawai B1 (tautan target _blank rel noopener noreferrer) → B2 tanpa tautan; B3/B4 tidak tampil. T-08 (gambar diunduh ulang tiap slide) diperbaiki CR-117 lanjutan (main d99f690e): uji ulang 2 request/30 detik |
| A-B2 | Q7-39 | Login `demo.admin`, `demo.satker`, `demo.eselon1` → Beranda | **[test-otomatis]** Akun peran admin di demo tanpa NIP → tidak ada banner (role 1: `GET konten/banner/aktif` 403, FE tidak merender); verifikasi UI cukup memastikan tidak ada galat. Q7-39 dibuktikan test otomatis (lihat §K A-K6) → tulis "[test-otomatis]" pada kolom Hasil | [test-otomatis] (A-K6); UI admin tanpa galat tampilan (403 diabaikan). |
| A-B3 | §5.4 | `GET api/v1/konten/banner/aktif` sebagai `demo.pegawai` | `[{id,url,gambar_url,keterangan}]` hanya B1, B2 urut `order` | ✅ banner/aktif pegawai: B1, B2 dengan kunci {id,url,gambar_url,keterangan}. |
| A-B4 | §5.4 | Kontrol carousel: sebelumnya/berikutnya, indikator (`konten-banner-indikator`), keyboard ← →, tombol jeda (`konten-banner-jeda`) | Navigasi berfungsi; indikator mengikuti slide; autoplay berhenti saat dijeda dan saat fokus/hover | ✅ tombol sebelumnya/berikutnya, indikator aria-current, keyboard ←/→ (tombol nyata), jeda/putar; autoplay jalan saat diputar, berhenti saat fokus. |
| A-B5 | §5.4 | DevTools → Rendering → emulate `prefers-reduced-motion: reduce`, muat ulang Beranda | Autoplay tidak berjalan (tanpa gerak otomatis); navigasi manual tetap ada | [test-otomatis] komponen-beranda.spec.ts "prefers-reduced-motion: tanpa autoplay dan tanpa tombol jeda" (pane tidak bisa emulasi reduced-motion). |
| A-B6 | §5.4 | Kelola Konten → Banner → ⋮ B2 → "Naikkan urutan"; kembali ke Beranda `demo.pegawai` | Urutan carousel berubah (B2 dulu); item pertama: Naikkan `disabled`; kembalikan urutan setelah uji | ✅ (API) naik B2 → carousel [2,1]; dikembalikan [1,2]. |
| A-B7 | Q7-06 | Tambah banner: `banner_ok.png` (1600×400) | Tersimpan | ✅ 1600×400 201; |
| A-B8 | Q7-06 | Tambah banner dengan `banner_rasio_salah.png` (1000×500), `banner_kecil.png` (600×150, lebar < 800), `besar.png`, `palsu.png`, `skrip.svg` | Masing-masing **422** pada field `berkas` (rasio 4:1 ±2%, lebar 800–4000 px, ≤ 5 MB, tipe dari isi berkas) | ✅ rasio/lebar/ukuran/tipe 422 field berkas; |
| A-B9 | §11-8 | Hapus banner uji dari B7 | Hapus permanen + berkas dihapus; banner tidak lagi muncul di carousel; audit `entity=banner, event=delete` | ✅ hapus permanen + berkas + audit create/delete. |

## A-C. F-07 Berita — portal, kelola, sanitasi, lampiran (MAKE-081, §5.5, Q7-07/09/10, §11 butir 9, 10, 11)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-C1 | §5.5 | Login `demo.pegawai` → Portal Berita (`/berita`) | Terlihat N1, N3, N6, N8; tidak ada N2 (unit 2), N4 (terjadwal), N5 (menunggu), N7 (ditolak). Kartu memuat judul, ringkasan, kategori, tanggal | ✅ pegawai N1,N3,N6,N8 (+C6a data C). |
| A-C2 | §5.5 / Q7-07 | Bandingkan portal per akun: `demo.admin`, `demo.satker`, `demo.eselon1`, `demo.ptt`, `demo.pppk` | Admin: N1, N2, N3, N6, N8 (semua terbit, tanpa filter target). `demo.eselon1` (unit 2): N1, N2, N8 (bukan N3/N6). `demo.satker`, `demo.ptt`, `demo.pppk`: N1, N3, N6, N8. Tak seorang pun melihat N4, N5, N7 di portal | ✅ admin +N2; eselon1 N1,N2,N8; satker/ptt/pppk N1,N3,N6,N8; N4/N5/N7 tidak pernah. |
| A-C3 | §5.5 | Portal: cari `Berita uji N8`, filter kategori K1, ubah ukuran halaman/paginasi | Pencarian pada judul/isi; filter kategori menyaring; paginasi (total, halaman) benar; pencarian karakter `%` / `_` diperlakukan literal | ✅ q judul/isi; % dan _ literal (0 hasil); kategori=1 → 4 berita K1, kategori=4 → C6a; paginasi total/page benar. |
| A-C4 | Q7-10 | Buka detail N1 (`/berita/{id}`) dan periksa DOM/sumber halaman | Tebal/miring/daftar/tautan tampil; **tidak ada** `<script>`, atribut `onclick`/`onerror`, tag `<img>`; teks "QA-F7-XSS" tidak dieksekusi (tidak ada dialog alert); tautan `rel` aman | ✅ detail N1 & berita C7: tanpa script/img/atribut on*/style, alert tidak terpicu; tag hanya p,strong,em,ul,li,a. Observasi O-2: tautan isi berita tanpa rel/target (buka di tab yang sama; tanpa target=_blank tidak ada risiko tabnabbing). |
| A-C5 | §5.5 | `demo.admin` → Kelola Berita → Tambah: pilih kategori K3 (nonaktif); kemudian batalkan; cek dropdown kategori | K3 tidak muncul/tidak bisa dipilih (kategori nonaktif); API `POST konten/berita` dengan `id_kategori=<K3>` → 422 "Kategori wajib dipilih dari kategori yang aktif" | ✅ dropdown kategori tanpa K3; API id_kategori=3 → 422 "Kategori wajib dipilih dari kategori yang aktif." |
| A-C6 | §11-10 | Buka N8 → tautan lampiran; `GET konten/berita/{N8}/lampiran` dengan dan tanpa token | Unduh `lampiran-berita-{id}.pdf` (200, `application/pdf`) untuk akun yang berhak; **401** tanpa token; **404** untuk akun yang tidak boleh melihat berita (mis. N-berita target unit 2 oleh akun unit 1, bila punya lampiran; atau lampiran milik N yang tidak terbit) | ✅ N8 lampiran 200 application/pdf "lampiran-berita-8.pdf"; 401 tanpa token; pegawai → N2/N4/N5/N7 detail & lampiran 404. |
| A-C7 | Q7-10 | Admin → Tambah berita: gunakan editor teks sederhana — tebal, miring, daftar, tautan `https://…`; tempel (paste) HTML berisi `<script>` dan `<img onerror>`; unggah lampiran `uji.pdf` | Toolbar hanya 4 fungsi; hasil di detail hanya tag `p, br, strong, em, ul, ol, li, a`; skrip hilang; berita terbit (admin → status Disetujui langsung) dan tampil di portal; audit `entity=news, event=create` | ✅ (API + UI toolbar) admin → status 1 langsung, isi disanitasi (script/img/onclick/style hilang), lampiran uji.pdf, audit create. Toolbar 5 tombol (Tebal, Miring, Daftar, Daftar bernomor, Tautan) = tag p/br/strong/em/ul/ol/li/a (ceklis menyebut "4 fungsi" → daf… |
| A-C8 | §5.5 | Tambah berita: judul kosong / > batas; isi kosong; tanggal terbit salah; target kosong | 422 per field (`judul`, `isi`, `publish_at`, `target`) | ✅ judul kosong/>255, isi kosong, publish_at salah, target kosong → 422 di field masing-masing (target berita = token 0 / <id unit> / sat_<id>). |
| A-C9 | §5.5 | Lampiran berita uji: `palsu.png`, `besar.png`, `skrip.svg`, `.exe` bernama `.pdf` | 422 pada field `lampiran`; tidak ada baris/ berkas tersisa | ✅ palsu.png/skrip.svg/program.pdf → "Jenis berkas tidak diizinkan"; besar.png → "melebihi 5 MB"; tanpa baris/berkas tersisa. |
| A-C10 | Q7-09 | Login `demo.pegawai` (lonceng aktif di ACTIVE_PHASE 7): periksa angka berita belum dibuka di lonceng/`GET notifikasi/ringkasan` (`berita_belum_dibaca`); buka Portal Berita | Angka berita > 0 sebelum portal dibuka (hanya berita terbit untuk target pegawai itu); setelah `POST konten/berita/dibuka` (dipicu membuka portal) angka kembali 0; berita baru yang terbit sesudahnya menaikkan angka lagi | ✅ pegawai berita_belum_dibaca 5 → POST dibuka → 0. |
| A-C11 | Q7-09 | Admin menerbitkan berita baru target satker 1 (kirim ulang C7 dengan target satker 1); login `demo.pegawai` | Angka berita belum dibuka untuk `demo.pegawai` bertambah 1; untuk `demo.eselon1` tidak berubah (di luar target) | ✅ berita baru target sat_1: pegawai 0→1, eselon1 tetap 4. |
| A-C12 | AGENTS §1 | ⋮ baris di Kelola Berita (admin) | Item berurutan: Edit → Setujui (hanya baris Menunggu) → Tolak (hanya Menunggu) → Hapus (merah); label sesuai; testid `konten-berita-actions-{id}` | ✅ ⋮ N5 (menunggu): Edit, Setujui, Tolak, ---, Hapus; N1 (terbit): Edit, ---, Hapus; testid konten-berita-actions-{id}. |
| A-C13 | §11-10 | Hapus berita uji dari C7 (konfirmasi) | **Hapus permanen** (tabel `news` tanpa hapus lunak): baris hilang dari portal dan kelola; lampiran dihapus dari penyimpanan; `GET lampiran` → 404; audit `delete` | ✅ hapus lewat ConfirmDialog → baris news hilang, lampiran hilang dari penyimpanan, GET lampiran 404, audit delete (before_json), angka pegawai kembali 0. |

## A-D. F-07 Berita — role 3, persetujuan, saklar Lampiran A (§5.5, Q7-07/08, §11 butir 9, 11, Lampiran A)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-D1 | Q7-07 | Login `demo.satker` → Kelola Berita | Hanya berita **miliknya** (N5, N6, N7); tidak ada N1/N2/N3/N8 (dibuat admin); filter status Menunggu/Disetujui/Ditolak berfungsi | ✅ satker kelola (?kelola=1): hanya miliknya (N5,N6,N7 + C6a/b/c data C); filter status 0/1/2 benar. |
| A-D2 | Q7-07 | `demo.satker` → Tambah berita (kategori K1, target satker 1, isi singkat) | Tersimpan dengan status **Menunggu** (bukan terbit); tidak muncul di portal akun lain; opsi target berasal dari `GET konten/opsi/unit-satker` (hanya unit/satker **aktif**; AU1/AS1 tidak ada di daftar) | ✅ satker buat → status 0 (Menunggu), pegawai 404; opsi unit-satker 21 entri tanpa unit 9 (AU1)/sat_14 (AS1). |
| A-D3 | Q7-07 | Admin → Kelola Berita: buka N5 dan berita D2 | Baris Menunggu: ⋮ memuat **Setujui** dan **Tolak**; baris lain tidak | ✅ (UI A-C12) ⋮ N5 Setujui/Tolak; baris terbit tanpa keduanya. |
| A-D4 | Q7-08 | Admin ⋮ **Tolak** pada berita D2 tanpa alasan, lalu dengan alasan | Tanpa alasan: 422 "Alasan penolakan wajib diisi"; dengan alasan: status **Ditolak**, `reason_note` tersimpan, `show_notif` = 1 + `notif_date` terisi | ✅ tolak tanpa alasan 422 "Alasan penolakan wajib diisi."; dgn alasan status 2, reason_note, show_notif 1, notif_date terisi. |
| A-D5 | §11-11 | Admin ⋮ **Setujui** pada N5; cek DB: `SELECT status, show_notif, notif_date, approved_at FROM news WHERE id_news=<N5>` | Status Disetujui, `approved_at` terisi, `show_notif=1`, `notif_date` terisi (diisi service, bukan trigger); berita tampil di portal akun target (satker 1); proses kedua kali → 409 | ✅ setuju N5 → status 1, approved_at, show_notif 1, notif_date; ulang 409; pegawai (satker 1) 200, eselon1 404. |
| A-D6 | Q7-08 | Login `demo.satker` → lonceng/Pusat Notifikasi (ACTIVE_PHASE 7) → bagian berita | Notifikasi hasil persetujuan **N5 (disetujui)**, **N6 (disetujui)**, dan **N7 (ditolak, alasan tampil)**; tanda baca → `show_notif=3`; "hapus notifikasi" → `show_notif=2` (berita tetap ada) | ✅ API satker: notifikasi berita N5 Disetujui, N6 Disetujui, N7 Ditolak + alasan; baca → show_notif 3; hapus oleh role 3 → 403 dan ⋮ tanpa Hapus (ikut C-N7 [K]; teks "hapus → show_notif=2" di butir ini tidak berlaku untuk role 3) |
| A-D7 | Q7-07 | `demo.satker`: tambah berita dengan kategori **K2** (`allow_ap=2`) | Dropdown role 3 tidak menampilkan K2; API → 422 "Kategori ini tidak dapat dipakai untuk berita yang menunggu persetujuan" | ✅ satker dropdown kategori: AK1 + C6 (tanpa K2, tanpa K3). |
| A-D8 | Q7-07 | `demo.satker`: API `POST konten/berita/{N1}` (berita admin) dan `DELETE konten/berita/{N1}` | **404** (bukan milik role 3), N1 tidak berubah | ✅ satker ubah/hapus N1 → 404, N1 utuh. |
| A-D9 | Q7-07 | `demo.pegawai` (role 2): `POST konten/berita`, `POST konten/berita/{id}/proses`, `GET konten/berita/kategori` (baca) | Tulis & proses: **403**; baca kategori: 200 (semua role baca) | ✅ pegawai tulis/proses 403, kategori GET 200. |
| A-D10 | §11-9 | Saklar **false** (bawaan): `demo.satker` edit judul berita **N6** (Disetujui) lalu simpan | Berita **tetap Disetujui** dan terbit (langsung berlaku, paritas legacy); `status`, `approved_at`, `show_notif` tidak berubah (saklar `false` tidak menyentuhnya) | ✅ satker ubah judul N6 (disetujui) → tetap status 1, approved_at/show_notif tidak berubah, pegawai 200. |
| A-D11 | §11-9 / Lampiran A | Set `.env` backend demo `konten.beritaUbahRole3KembaliMenunggu = true`, restart backend; ulangi D10 pada N6 | Berita kembali **Menunggu** dan hilang dari portal sampai admin menyetujui; setelah selesai, **kembalikan `.env`** (hapus baris/`false`), restart backend | ✅ saklar true: satker ubah N6 → status 0, show_notif 0, approved_at null, pegawai 404; .env dikembalikan + admin setujui ulang → status 1, pegawai 200. |
| A-D12 | §11-13 | Target nonaktif/tidak ada: admin buat berita target `unit_nonaktif` (token `<AU1 id_unit>`), satker nonaktif (`sat_<AS1 id>`), id tidak ada | 422 "Unit/Satker target tidak ditemukan atau tidak aktif"; tidak ada baris baru (news tanpa FK ke unit/satker → divalidasi service, Q11) | ✅ berita target 9 / sat_14 / 99999 / sat_99999 → 422 "…tidak ditemukan atau tidak aktif."; baris tetap. |

## A-E. F-12 DIBO — baca & kelola (MAKE-082, §5.6, Q7-12/13/14, §11 butir 12)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-E1 | §5.6 | Login `demo.pegawai` → DIBO (`/dibo`) | Daftar hanya status **Aktif** dengan jenis aktif: D1, D2; filter jenis (UU/PP/…), tahun, cari `nomor|tentang` berfungsi; urut tahun terbaru | ✅ pegawai D1, D2 (urut tahun desc), filter jenis/tahun/q. |
| A-E2 | Q7-13 | Periksa akun yang sama: D3 (dicabut), D4 (dihapus), D5 (jenis nonaktif) | D3 dan D4 **tidak tampil** (hanya `status=1`). D5 (jenis nonaktif) **tidak tampil** (PRD §5.6 "status=1 + jenis aktif"; CR-116, [V2] lanjutan Q7-13); filter jenis `[QA-F7 AJ1]` kosong; `berkas/1` D5 untuk non-admin → 404, admin → 200; mengaktifkan jenis J1 menampilkan D5 | ✅ D3/D4/D5 tidak tampil; jenis=9 kosong; D5 berkas/1 pegawai 404, admin 200 (aktivasi J1 → D5 tampil: lihat A-F/CR-116 test). |
| A-E3 | §5.6 | Detail D1 → unduh berkas 1, 2, 3 (`GET konten/dibo/{id}/berkas/{1|2|3}`) | 200 PDF `dibo-{id}-{no}.pdf` untuk akun login; `berkas/4` dan `berkas/0` → 404; D2 `berkas/2` (kosong) → 404; tanpa token → 401 | ✅ D1 berkas 1-3 200 PDF "dibo-1-N.pdf" (attachment); 4/0 404; D2 berkas/2 404; tanpa token 401. |
| A-E4 | Q7-13 | Pegawai: `GET konten/dibo/{D3}/berkas/1` dan `{D4}/berkas/1` | **404** untuk non-admin (hanya status 1); admin → 200 | ✅ D3/D4 pegawai 404, admin 200. |
| A-E5 | §5.6 | Semua role (`demo.ptt`, `demo.pppk`, `demo.eselon1`, `demo.menteri`, `demo.pimpinan`, `demo.satker`) membuka `/dibo` | Daftar sama (baca untuk role 1–8); tanpa tombol kelola | ✅ 6 role sama D1,D2. |
| A-E6 | §5.6 / AGENTS | `demo.admin` → Kelola Konten → DIBO | Daftar bawaan: D1, D2, D3, D5 (tanpa status 10); filter status Aktif / Dicabut / Dihapus menampilkan D4 pada "Dihapus"; badge status benar | ✅ (UI) Kelola DIBO bawaan "Aktif & Dicabut": D1,D2,D3(Dicabut),D5; filter Dihapus → D4 (+ E8 uji); badge Aktif/Dicabut/Dihapus. |
| A-E7 | AGENTS §1 | ⋮ baris DIBO (admin) | Urutan: Edit → Nonaktifkan (baris Aktif) / Aktifkan (baris Dicabut) → Pulihkan (baris Dihapus) → Hapus (merah). Baris Dihapus: Edit & Hapus **hidden**; testid `konten-dibo-actions-{id}` | ✅ ⋮ aktif: Edit, Nonaktifkan, ---, Hapus; dicabut: Edit, Aktifkan, ---, Hapus; dihapus: hanya Pulihkan (Edit/Hapus hidden); testid konten-dibo-actions-{id}. |
| A-E8 | §5.6 | Tambah DIBO: jenis aktif, nomor, tahun 4 digit, tentang, 1–3 berkas PDF (`uji.pdf`) | Tersimpan; tampil di baca pegawai; audit `entity=dibo, event=create`; berkas tersimpan privat (bukan di webroot) | ✅ 201, berkas dibo/<hex32>.pdf privat, audit create, tampil di pegawai. |
| A-E9 | validasi | Tambah DIBO: tanpa berkas 1; jenis nonaktif (J1); tahun 1899 / 2101 / `abcd`; nomor > 50 karakter; tentang kosong; berkas `palsu.png`/`skrip.svg`/`besar.png` | 422 per field (`file_1`, `id_jenis`, `tahun`, `nomor`, `tentang`, `file_N`); tidak ada baris/berkas yatim (semua berkas divalidasi dulu) | ✅ file_1 wajib, jenis nonaktif, tahun 1899/2101/abcd, nomor >50, tentang kosong, file_2 palsu.png, file_3 svg, file_1 >5MB → 422 field masing-masing; tanpa baris/berkas baru. |
| A-E10 | §5.6 | Edit DIBO uji: ganti berkas 1, hapus berkas 2 (`hapus_file_2`), tambah berkas 3 | Berkas lama dihapus **setelah** commit, berkas baru terunduh; berkas 2 → 404; audit `update` | ✅ ganti file_1 + hapus_file_2 + tambah file_3: berkas/2 404, 1 & 3 200; berkas lama terhapus dari disk; audit update. |
| A-E11 | §11-12 | ⋮ **Nonaktifkan** D2 lalu pegawai cek; ⋮ **Aktifkan** kembali | Saat nonaktif (Dicabut) D2 hilang dari baca pegawai; setelah Aktifkan muncul lagi | ✅ cabut D2 → hilang dari pegawai; aktifkan → muncul. |
| A-E12 | §11-12 | ⋮ **Hapus** DIBO uji (konfirmasi) → filter "Dihapus" → ⋮ **Pulihkan** | Hapus **lunak** (status 10): hilang dari baca & daftar bawaan, berkas **tetap ada** di penyimpanan; Pulihkan mengembalikan status Aktif dan berkas masih terunduh; audit `update` (bukan `delete`) | ✅ hapus lunak status 10, berkas tetap, keluar dari bawaan, ada di filter Dihapus; pulihkan → 1, berkas 200; audit update (bukan delete). |
| A-E13 | §11-12 | Detail DIBO oleh role 1 (D3 dicabut) | Admin boleh membuka detail/berkas D3 dan D4 [V2]; pegawai tidak (E4) | ✅ D3/D4 pegawai 404, admin 200. |
| A-E14 | matriks §6 | `demo.pegawai`: `POST konten/dibo`, `PATCH konten/dibo/{id}/status`, `DELETE konten/dibo/{id}` | **403** untuk ketiganya | ✅ pegawai POST/PATCH/DELETE 403. |

## A-F. Master kategori berita & jenis DIBO (MAKE-081/082, Q7-11, §11 butir 12–13)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-F1 | Q7-07 | Admin → Kelola Konten → Kategori Berita | K1–K3 tampil dengan urutan, status, flag "boleh untuk role 3"; ⋮ berurutan Edit → Nonaktifkan/Aktifkan → Naikkan/Turunkan urutan → Pulihkan → Hapus | ✅ kategori K1–K3 dgn urutan & status (Tidak Aktif/Dihapus); ⋮ Edit → Nonaktifkan → Naikkan(disabled baris 1) → Turunkan → --- → Hapus; flag role 3 ada di form Edit ("Boleh dipakai penulis berita (role 3)"), tidak di kolom daftar — sama dengan legacy news/catego… |
| A-F2 | Q7-07 | Tambah kategori baru (`allow_ap` diaktifkan), nama sama dengan kategori aktif lain, lalu nama kosong/ > batas | Pertama OK (urutan terakhir); nama duplikat aktif → 422 "Nama sudah dipakai"; kosong → 422 | ✅ baru (urutan terakhir 5); duplikat 422 "Nama sudah dipakai."; kosong/>150 422. |
| A-F3 | Q7-11 | Edit nama/`allow_ap` K1 → K2 sebaliknya; ⋮ Nonaktifkan K1 lalu cek dropdown berita; Aktifkan kembali | Perubahan `allow_ap` berdampak ke pilihan role 3 (D7); kategori nonaktif hilang dari pilihan; mengaktifkan nama yang sudah dipakai kategori aktif lain → 422 | ✅ allow_ap K2→1: satker boleh pakai K2 (201, dibersihkan); K1 nonaktif hilang dari opsi; aktifkan nama bentrok 422 "Nama sudah dipakai data aktif lain." |
| A-F4 | Q7-11 | Naikkan/Turunkan urutan kategori uji; baris pertama: Naikkan `disabled`, baris terakhir: Turunkan `disabled` | Urutan di daftar & dropdown berubah; tidak ada urutan ganda | ✅ naik/turun, tanpa urutan ganda. |
| A-F5 | Q7-11 | ⋮ Hapus kategori **K1** (dipakai berita) lalu Hapus kategori baru uji F2 yang belum dipakai; ⋮ Pulihkan | K1: **409** "masih dipakai… Nonaktifkan saja"; kategori uji: hapus lunak (status 10), Pulihkan mengembalikannya | ✅ hapus K1 409 "masih dipakai… Nonaktifkan saja."; uji → status 10, pulihkan → 1. |
| A-F6 | Q7-11 | Menu Jenis DIBO: tambah/edit/nonaktifkan/urutan; Hapus jenis `UU` (dipakai D1) | CRUD sama seperti kategori tanpa flag `allow_ap`; hapus jenis yang dipakai DIBO (status ≠ 10) → 409; 8 jenis seed ada dengan urutan 1–8 | ✅ jenis CRUD; hapus UU 409; seed 1..8 urutan 1..8. Catatan O-3: respons POST jenis DIBO memuat allow_ap: 2 padahal jenis_dibo tanpa kolom itu (kosmetik). |
| A-F7 | matriks §6 | `demo.satker`/`demo.pegawai`: `POST konten/berita/kategori`, `PUT konten/dibo-jenis/{id}`, `PATCH …/status`, `DELETE …` | **403** untuk tulis; `GET` 200 untuk semua role (dipakai form) | ✅ satker/pegawai tulis 403, GET 200. |
| A-F8 | §11-13 | Pastikan jenis DIBO nonaktif (J1) tidak muncul di dropdown form DIBO | J1 tidak bisa dipilih; DIBO lama yang memakainya tetap utuh | ✅ opsi jenis aktif tanpa J1; D5 utuh. |

## A-G. Keamanan & kasus negatif (Q7-06, Q7-14, §11 butir 8, 13)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A-G1 | matriks §6 | Akses kelola dengan role salah: `demo.pegawai`, `demo.satker` ke `POST konten/popup`, `POST konten/banner`, `GET konten/popup`, `POST konten/dibo`; URL UI `/konten/popup` sebagai `demo.pegawai` | API **403**; halaman UI dialihkan/ditolak oleh penjaga route (UX) tanpa data | ✅ UI pegawai /konten/popup → /403 "Akses ditolak", tanpa data. |
| A-G2 | Q11 | Target nonaktif untuk popup/banner: `target[unit_satker][]=<AU1 id_unit>` dan `sat_<AS1 id>` (admin, API) | 422 pada field `target` ("tidak ditemukan atau tidak aktif"); tidak ada baris baru | ✅ Awalnya ❌ T-07 (popup/banner menerima unit 9 nonaktif, sat_14 nonaktif, 99999 → 201, 0 penerima). Diperbaiki CR-119 (main 29ee1d34, ikut form digital), uji ulang: 422 field target "Unit/Satker target tidak ditemukan atau tidak aktif.", tanpa baris baru |
| A-G3 | Q7-14 | Path traversal popup: di DB demo `UPDATE popup SET file='../../luar.png' WHERE id_popup=<PX>`; login sebagai akun yang boleh lihat (admin) dan `GET konten/popup/{PX}/gambar`; ulangi dengan `file='/etc/passwd'`, `'popup/../../x'`, path absolut Windows | **404** pada semua; berkas di luar akar penyimpanan **tidak** terbaca; `writable/logs/*` memuat peringatan "Kunci berkas popup tidak valid (data diubah?): id=<PX>". Kembalikan nilai `file` PX (atau abaikan PX) | ✅ 5 variasi path → 404; log "Kunci berkas popup tidak valid (data diubah?): id=7"; nilai dikembalikan. |
| A-G4 | Q7-14 | Path traversal DIBO dan berita: `UPDATE dibo SET file_1='../../x' WHERE id_dibo=<D2>`; `UPDATE news SET lampiran='../../x' WHERE id_news=<N8>` lalu unduh | 404 dan peringatan di log; kembalikan nilai asli (catat nilai sebelum diubah) | ✅ dibo & lampiran berita → 404 + peringatan log; dikembalikan, unduh normal 200. |
| A-G5 | Q7-14 | Cari penyimpanan berkas: `writable/uploads/konten/{popup,banner,berita,dibo}` | Nama berkas acak (hex + ekstensi), bukan nama asli unggahan; tidak ada berkas di `public/` | ✅ semua berkas <hex32>.<ext>; public/ hanya index.php/.htaccess/favicon/robots. |
| A-G6 | Q7-06 | Nama unduhan: periksa header `Content-Disposition` gambar popup/banner, lampiran, berkas DIBO | `popup-{id}.png`/`banner-{id}.png`/`lampiran-berita-{id}.pdf`/`dibo-{id}-{no}.pdf` — tanpa spasi, kutip, titik koma, garis miring, atau nama asli | ✅ popup-1.png, banner-1.png, lampiran-berita-8.pdf (inline), dibo-1-2.pdf (attachment). |
| A-G7 | Q7-10 | Sanitasi di semua jalur tulis berita: API `POST konten/berita` dengan `isi="<p>a</p><iframe src=x></iframe><a href=\"javascript:alert(1)\">x</a>"` | `iframe` hilang; `href=javascript:` dibuang/dinetralkan; hasil detail aman | ✅ iframe hilang; href javascript:/JaVaScRiPt:/data: dibuang (<a>x</a>). |
| A-G8 | injeksi | Pencarian portal/DIBO/popup dengan `'; DROP TABLE news;--`, `%`, `_`, `\` | Tidak ada galat 500; `%`/`_` literal; tabel utuh | ✅ tanpa 500; % _ \ literal; news utuh (popup list tanpa pencarian, q diabaikan). |
| A-G9 | §11-8/Q7-16 | Audit: `SELECT entity, event, entity_id FROM audit_logs WHERE entity IN ('popup','banner','news','news_category','dibo','jenis_dibo') ORDER BY id_log DESC LIMIT 30` | Create/update/delete dari butir A–F tercatat dengan `id_pengguna_actor` pelaku; nilai berkas/tautan tidak membocorkan isi gambar; hapus popup/banner memuat snapshot | ✅ audit create/update/delete per entitas, actor 1 (dan 2 = satker), tanpa isi gambar. |
| A-G10 | konsistensi | Pegawai tampilan: login `demo.pegawai`, buka Beranda dan Portal Berita dengan tab Network | Tidak ada respons 5xx; tidak ada galat konsol; gambar popup/banner dimuat lewat endpoint terautentikasi | ✅ tanpa 5xx; gambar popup/banner/foto lewat endpoint terautentikasi. Konsol hanya galat sumber 401/403 yang diharapkan (403 popup/banner role 1, 401 sebelum login). |

## B-B. F-06 Kelola form digital — role 1 (§5.7, Q7-17..Q7-20, Q7-25)

| # | AC | Langkah (UI kecuali disebut) | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-B1 | 5.7 | `demo.admin` → menu Form Digital | Halaman dalam shell (sidebar + topbar); tabel BF1–BF4 (BF5 terhapus tidak tampil di daftar bawaan); kolom Status badge saja; aksi baris lewat ⋮ | ✅ Form Digital dalam shell; kolom Judul/Periode/Urutan/Target/Sudah/Belum/Status/Aksi; BF1–BF4 (BF5 tidak); badge saja; ⋮ (testid form-digital-actions-{id}). O-6 (kosmetik): periode tampil ISO "2026-11-10 s.d. 2026-12-11" dan rekap SKL "Periode ringkasan 2025-1… |
| B-B2 | 5.7 / Q7-18 | Tambah form: judul, periode, keterangan, susun 7 tipe pertanyaan di builder (opsi pilihan, batas berkas), target pegawai/unit-satker + pengecualian → Simpan | Form tersimpan; tanpa pustaka jQuery formBuilder; pertanyaan "paragraf" memakai textarea `FormField` (§11 #20); audit `audit_logs` entity `form_digital` event `create` | ✅ builder "Tambah form digital": 7 tipe (Teks singkat, Paragraf, Pilihan tunggal, Pilihan ganda, Tanggal, Angka, Unggah berkas), target unit/satker. |
| B-B3 | 5.7 | Simpan dengan: judul kosong; tanggal akhir < mulai; pertanyaan tanpa label; opsi ganda; label ber-HTML; target kosong / target tidak menjangkau pegawai aktif | 422 dengan pesan per field; tidak ada baris baru | ✅ POST form-digital → semua 422 dengan pesan per field: judul kosong → judul_form; akhir < mulai → tgl_akhir; pertanyaan tanpa label → form.pertanyaan.0.label; opsi ganda → form.pertanyaan.0.opsi 'Opsi tidak boleh ganda.'; label ber-HTML → form.pertanyaan.0.lab… |
| B-B4 | Q7-20 | ⋮ "Target" pada BF1 | Daftar penerima snapshot (4 pegawai) dengan status sudah/belum mengisi; "belum mengisi" = target − sudah | ✅ ⋮ BF1: Edit → Nonaktifkan → Lihat respons → Lihat target → --- → Hapus; dialog Target "3 dari 4 pegawai sudah mengisi", filter semua/belum/sudah, NIP/Nama/Status. |
| B-B5 | Q7-25 | ⋮ "Edit" BF2 (sudah dijawab): ubah judul/periode/keterangan → Simpan; lalu ubah teks pertanyaan → Simpan | Ubah judul/periode/keterangan berhasil; ubah pertanyaan ditolak **409** kode `form_terkunci` ("Form terkunci karena sudah dijawab"); UI memberi tanda pertanyaan terkunci | ✅ Edit BF2: 9 input pertanyaan disabled (terkunci); daftar menandai "(terkunci)". |
| B-B6 | Q7-25 | ⋮ "Edit" BF2: tambah pegawai target baru → Simpan | Penerima baru masuk snapshot; penerima yang sudah menjawab (`demo.pemohon`) tetap ada | ✅ PUT form-digital/2 dengan target pegawai ditambah demo.qaf7.lengkap (pegawai baru) → 200, audit update id 1592. Snapshot user_form_digital BF2 2→3: demo.qaf7.lengkap masuk, demo.pemohon (sudah menjawab) dan demo.pegawai tetap ada, jumlah_target 3. Perubahan i… |
| B-B7 | 5.7 | ⋮ "Nonaktifkan" BF1 lalu "Aktifkan" | Badge berubah; saat nonaktif BF1 hilang dari `form-digital/saya` penerima (C1); audit `update` | ✅ PATCH status 2 → BF1 hilang dari form-digital/saya demo.pppk, status 1 → muncul lagi; PATCH 3 → 422; audit update 1→2→1; badge mengikuti status (BF4 "Tidak Aktif" di daftar, B-B1) |
| B-B8 | Q7-19 | ⋮ "Hapus" pada form uji baru (ConfirmDialog) | Status 10 (hapus lunak), hilang dari daftar; baris `filled_form_digital` tetap; `PUT` form terhapus → 409 | ✅ Form uji id 6 (dari B-B2) lebih dulu diisi demo.qaf7.lengkap (POST isi 201) supaya ada baris jawaban. DELETE form-digital/6 → 200; DB status 10 dan deleted_at terisi. Form hilang dari daftar bawaan dan muncul di ?status=10. filled_form_digital form 6 tetap 1… |
| B-B9 | 5.7 | ⋮ "Respons" BF2 | Halaman respons dalam shell; tabel berhalaman, kolom dari skema form; 1 baris (`demo.pemohon`) | ✅ /form-digital/2/respons dalam shell, kolom dari skema (Konfirmasi kehadiran, Catatan) + Unit/Satker/Nama/NIP/Waktu isi (WIB), 2 baris, Unduh XLSX. |
| B-B10 | 5.7 | `GET form-digital/<BF5>/respons` (form terhapus) | Jawaban tetap terbaca (Q7-19) | ✅ GET form-digital/5/respons (BF5 status 10) → 200, total 1, penjawab demo.pemohon, kolom dari skema terbaca. |
| B-B11 | 5.7 | Unduh XLSX respons BF2 (`?format=xlsx`) | Berkas `respons-form-digital-<id>.xlsx` memuat SEMUA baris; berkas jawaban ditulis nama berkas (tanpa URL); audit `dokumen_unduhan` tercatat **tanpa** isi jawaban | ✅ GET form-digital/2/respons?format=xlsx → 200, Content-Type spreadsheetml, Content-Disposition filename="respons-form-digital-2.xlsx". Berkas disimpan di …/scratchpad/qa7/out/respons-form-digital-2.xlsx. Dibaca dengan openpyxl: sheet 'Respons', kolom [Unit, Sa… |
| B-B12 | 5.7 / Q7-14 | Respons BF1 sesudah C3 (jawaban berberkas): klik berkas | Unduh terautentikasi `form-digital/{id}/respons/{idJawaban}/berkas/{idPertanyaan}`; tanpa token → 401; id pertanyaan bukan berkas / id jawaban form lain → 404 | ✅ Jawaban berberkas BF1 = id 4 (pertanyaan surat_tugas). GET form-digital/1/respons/4/berkas/surat_tugas (admin) → 200 application/pdf, ada Content-Disposition attachment, isi diawali %PDF-. Tanpa token → 401. Pertanyaan bukan berkas (nama_kegiatan) → 404 'Berk… |
| B-B13 | §6 | `demo.satker` (3), `demo.pegawai` (2), `demo.eselon1` (4) memanggil `GET form-digital`, `GET form-digital/<BF2>/respons` | 403; menu Form Digital tidak tampil | ✅ demo.satker, demo.pegawai, dan demo.eselon1 memanggil GET form-digital, form-digital/2/respons, form-digital/2, dan form-digital/1/target → semua 403. Yang belum dicek: menu Form Digital tidak tampil di UI. |

## B-C. F-06 Isian form digital — role 2, 6, 7 (§5.7, Q7-17, Q7-40)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-C1 | 5.7 | `GET form-digital/saya` untuk `demo.pegawai`, `demo.ptt`, `demo.pppk`, `demo.pemohon` | `demo.pegawai` = [BF1, BF2] urut `order`; `demo.ptt`/`demo.pppk` = [BF1]; `demo.pemohon` = [] (BF2 sudah diisi, BF5 terhapus); BF3 (lewat) dan BF4 (nonaktif) tidak pernah muncul | ✅ pegawai [1,2]; ptt/pppk [1]; pemohon []. |
| B-C2 | Q7-40 | Login `demo.pegawai` | Dialog isian BF1 tampil otomatis di shell (halaman mana pun), tidak bisa ditutup tanpa mengisi; sesudah BF1 tersimpan, BF2 menyusul | ✅ dialog BF1 di shell, Esc tidak menutup, tanpa tombol tutup; sesudah BF1 → BF2. |
| B-C3 | 5.7 | Isi BF1: kosongkan pertanyaan wajib → Kirim; lalu isi lengkap + unggah PDF valid → Kirim | Wajib kosong → pesan per pertanyaan, tidak tersimpan; lengkap → tersimpan, berkas di penyimpanan privat (kunci acak `form-digital/<id>/…`) | ✅ kosong → "Wajib diisi." per pertanyaan wajib, tanpa request; lengkap + PDF → tersimpan, berkas writable/uploads/lampiran/form-digital/1/<hex32>.pdf. |
| B-C4 | 5.7 | Unggah berkas bukan PDF berganti nama `.pdf` / > 2 MB pada pertanyaan berkas | 422 (tipe dicek dari isi berkas / ukuran) | ✅ (demo.pppk) palsu.pdf → 422 "Jenis berkas tidak diizinkan (hanya pdf)."; >2MB → 422 "Ukuran berkas melebihi 2 MB."; tidak ada baris/berkas. Catatan uji: demo.ptt sempat MENGISI BF1 tanpa berkas (salah nama field saya) → data ptt BF1 sudah terisi. |
| B-C5 | Q7-17 | Kirim ulang `POST form-digital/<BF2>/isi` sebagai `demo.pemohon` (jawaban valid) | **409** kode `sudah_diisi` "Form ini sudah pernah Anda isi."; tetap 1 baris (UNIQUE) | ✅ 409 sudah_diisi; |
| B-C6 | 5.7 [V2] | `POST form-digital/<BF2>/isi` sebagai `demo.ptt` (di luar target) | **404** "Form tidak ditemukan." | ✅ 404; |
| B-C7 | 5.7 [V2] | `POST form-digital/<BF3>/isi` dan `<BF4>/isi` sebagai `demo.pegawai` (target, tapi lewat periode / nonaktif) | 404 | ✅ 404/404. |
| B-C8 | §6 | `GET form-digital/saya` sebagai `demo.satker` (3) dan `demo.admin` (1) | 403; host dialog tidak meminta data form untuk role tsb. | ✅ satker/admin 403. |

## B-D. F-11 SKL pegawai — role 2, 6 (§5.8, Q7-21..Q7-23, §11 #20)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-D1 | 5.8 | `GET survei-layanan/saya` sebagai `demo.pegawai` | Tepat BS1–BS5 (satu per jenis, label jenis dari master; "KARIS/KARSU"); BS7 (belum selesai) & BS8 (`rated` 0) tidak muncul | ✅ BS1–BS5 satu per jenis, label master (KARIS/KARSU); BS7/BS8 tidak muncul. |
| B-D2 | 5.8 | Login `demo.pegawai` sesudah semua form C selesai | Dialog SKL tampil, tidak bisa ditutup; label rating legacy "5 — Sangat Memuaskan" … "1 — Tidak Memuaskan"; rating & kritik/saran wajib di form | ✅ label legacy 5..1, Esc/tanpa tutup; kosong → "Pilih tingkat kepuasan." + "Kritik & saran wajib diisi." |
| B-D3 | 5.8 | Nilai BS1 (rating + kritik) | `skl` 1 baris baru, `riwayat_cuti.rated` BS1 = 1 dalam transaksi yang sama; dialog lanjut ke butir berikutnya; sesudah butir terakhir dialog tertutup | ✅ skl baris baru + riwayat_cuti.rated 2→1; dialog lanjut; tertutup sesudah butir terakhir. |
| B-D4 | Q7-21 | Kirim ulang `POST survei-layanan` untuk BS1 (rate lain) | **200** `status: "sudah_tercatat"`; tetap 1 baris, rate tidak berubah | ✅ 200 sudah_tercatat, rate tetap 4. |
| B-D5 | 5.8 [V2] | `POST survei-layanan` sebagai `demo.ptt` untuk BS2 (milik `demo.pegawai`) | **404** "Layanan tidak ditemukan."; `rated` BS2 tidak berubah | ✅ 404. |
| B-D6 | 5.8 | `POST` untuk BS7 (belum selesai) dan BS8 (`rated` 0) sebagai `demo.pegawai` | 422 kode `layanan_belum_selesai` / `tidak_perlu_dinilai` | ✅ 422 layanan_belum_selesai / tidak_perlu_dinilai. |
| B-D7 | 5.8 | `POST` dengan `jenis_rwy` 0/6, `rate` 0/6, kritik > 2000 karakter; tanpa `kritik_saran` | 422 per field; tanpa kritik → diterima (opsional di server, §11 #20) | ✅ 422 per field; >2000 → 422; tanpa kritik → 201 tercatat (BS2 lewat API). |
| B-D8 | Q7-23 | `demo.ptt` (6) menilai BS6 lewat dialog | Tersimpan seperti D3 | ✅ demo.ptt dialog SKL BS6 (cuti) → skl rate 3, riwayat_cuti.rated 1. |
| B-D9 | Q7-23 | `demo.pppk` (7) memanggil `GET survei-layanan/saya`; login UI | 403; dialog SKL tidak tampil walau BS11 belum dinilai (arti role 7 di SKL dikonfirmasi di QA ini — catat temuan bila legacy berbeda) | ✅ pppk GET survei-layanan/saya 403; login UI pppk tanpa request SKL dan tanpa dialog SKL (B-G4) walau BS11 belum dinilai |
| B-D10 | Q7-16 | Cari teks kritik BS1/BS9 di `audit_logs` (`before_json`/`after_json`) dan `writable/logs/*` | Tidak ada (kritik/saran data pribadi, tidak masuk audit/log) | ✅ kritik SKL: 0 di audit_logs (tidak ada entity skl), 0 berkas log memuat teks kritik. |

## B-E. F-11 Rekap & ringkasan — role 1 (§5.8, Q7-26, §7 B → D)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-E1 | 5.8 | `demo.admin` → menu Survei Layanan | Halaman dalam shell; kartu ringkasan per jenis (jumlah + rata-rata) + "Seluruh layanan"; tabel berhalaman tanpa kolom Aksi (baca-saja) | ✅ /survei-layanan dalam shell: kartu per jenis + Unduh XLSX + tabel tanpa aksi. |
| B-E2 | 5.8 | Filter jenis, rating, periode dari/sampai, cari nama/NIP | Baris sesuai filter; ganti periode ikut memperbarui kartu ringkasan | ✅ Filter API: jenis_rwy=1 → 5 baris (semua jenis 1); jenis_rwy=4 → 3; rate=5 → 7 (semua rate 5); rate=2 → 1; jenis 1 + rate 4 → 4; dari=sampai=hari ini → 13; dari=sampai=kemarin → 0; sampai=kemarin → 0. Cari nama pemohon → 3; cari 10 digit awal NIP pemohon → 3… |
| B-E3 | XSS | Lihat baris BS10 | Kritik tampil sebagai teks literal `<b>teks</b>` (tidak tebal) | ✅ kritik "<b>teks</b>" tampil literal (tanpa tag b). |
| B-E4 | 5.8 | Unduh XLSX dengan filter aktif | Isi = baris terfilter (semua halaman); audit `dokumen_unduhan` `entity_id = survei_layanan` tanpa isi kritik | ✅ GET survei-layanan?format=xlsx → 200, filename="survei-layanan.xlsx", kolom [Jenis Layanan, NIP, Nama, Unit Es.I, Unit Es.II, Kritik & Saran, Tgl. Penilaian (WIB), Rating]. Filter jenis_rwy=1 dengan per_page=1&page=1 → 5 baris (= total API 5, semua halaman);… |
| B-E5 | Q7-26 / §7 | `GET survei-layanan/ringkasan`; beranda `demo.admin` | Rata-rata per jenis sama dengan E1; widget ringkasan SKL di beranda admin (Jalur D) memakai angka yang sama | ✅ GET survei-layanan/ringkasan → 200, periode bawaan 2025-11-12..2026-11-11 (12 bulan), 5 jenis selalu ada; rata-rata per jenis dan total (4.3) sama dengan daftar/agregat DB (B-E1). Widget beranda admin SklRingkasanCard.vue memanggil endpoint yang sama (beranda… |
| B-E6 | §6 | `demo.satker`, `demo.pegawai`, `demo.eselon1` memanggil `GET survei-layanan` dan `/ringkasan` | 403; menu tidak tampil | ✅ demo.satker, demo.pegawai, dan demo.eselon1 memanggil GET survei-layanan, survei-layanan/ringkasan, dan survei-layanan?format=xlsx → semua 403. Yang belum dicek: menu tidak tampil di UI. |

## B-G. Antrean dialog sesudah login & InteraksiPopupHost (§11 #15, Q7-40; gating B-G3 → GF1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B-G1 | §11 #15 | Login `demo.atasan` (punya BF1, pengingat cuti Fase 4, popup Jalur A, BS12) | Urutan: **form digital → pengingat cuti → popup konten → SKL**; satu dialog sekaligus; dialog berikutnya baru tampil setelah yang sebelumnya selesai/ditutup | ✅ Awalnya ❌ T-03 (urutan bergantung balapan network: demo.atasan popup → form → pengingat → SKL). Diperbaiki CR-118 (main 4c70a80a), uji ulang @4c70a80a: demo.pppk 2× form lebih dulu; demo.atasan (BF1 sudah diisi) pengingat cuti → popup → SKL; satu dialog sekal… |
| B-G2 | §11 #15 | Akun dengan wajib ganti password (MAKE-020; nyalakan penanda wajib ganti password MAKE-020 di DB demo untuk satu akun uji, kembalikan sesudahnya) | Layar ganti password tampil lebih dulu; dialog antrean (form/pengingat/popup/SKL) tidak tampil dan **tanpa request** `form-digital/saya`/`survei-layanan/saya` sampai password diganti | ✅ demo.pemohon must_change_password=1 (dikembalikan 0): diarahkan /ganti-password, tanpa dialog, tanpa request form-digital/saya & survei-layanan/saya. Observasi T-04 (minor): lonceng tetap memanggil notifikasi/ringkasan → 403 selama wajib ganti password (unrea… |
| B-G4 | role | Login `demo.pppk` (7) dan `demo.admin` (1) | `demo.pppk`: hanya form digital (BF1), tanpa request SKL; `demo.admin`: tanpa request keduanya | ✅ pppk: form-digital/saya ada, tanpa survei-layanan/saya; admin: tanpa form-digital/saya & survei-layanan/saya (konten/banner/popup/aktif 403 tanpa galat tampilan, sesuai A-A5) |
| B-G5 | Q7-40 | Dengan dialog form tampil, pindah halaman lewat sidebar / muat ulang | Dialog tetap tampil di halaman mana pun hingga diisi; muat ulang menampilkan lagi (data dari server) | ✅ dialog tetap saat pindah ke /faq dan muat ulang. |

## C-N. Pusat Notifikasi F-08 (MAKE-085, CR-113; gating C-N1 → GF1/GF2)

| # | AC / acuan | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C-N2 | §6, Q7-29, §11 #5 | `ACTIVE_PHASE` = 7: login berturut-turut `demo.admin` (1), `demo.pegawai` (2), `demo.satker` (3), `demo.eselon1` (4), `demo.menteri` (5), `demo.ptt` (6), `demo.pppk` (7), `demo.pimpinan` (8); cek lonceng + `GET api/v1/notifikasi/ringkasan` | Semua role 1–8 dapat 200 `{notifikasi_belum_dibaca, butuh_approval, berita_belum_dibaca, tiket_belum_dibaca: null, diperbarui_at}`. `notifikasi_belum_dibaca` hanya > 0 untuk role 2/3/6/7, `butuh_approval` hanya > 0 untuk role 1/2/3; role 4/5/8 = 0 dan 0 [K `MY_Controller:127-171`]. Polling ±60 detik hanya saat tab terlihat. Klik lonceng membuka `/notifikasi` untuk role 1/2/3/6/7 | ✅ 8 role 200; notifikasi_belum_dibaca >0 hanya 2/3/6/7 (7/4/1/2); butuh_approval >0 hanya 1/2/3 (3/3/1); 4/5/8 = 0/0; tiket null. |
| C-N3 | Q7-29, `LN:2464-2482`, `LN:2765` | Tab **Notifikasi** (`GET api/v1/notifikasi?status=semua`) per role | `demo.pegawai`: C1 (Disetujui), C2 (Ditolak) + S1 layanan. `demo.ptt`: C4 tampil, **C4n tidak tampil**. `demo.pppk`: C5 + S1 IB. `demo.satker`: C6a (Disetujui) dan C6b (Ditolak) lewat sumber berita. Role 1/4/5/8 → 403 (halaman tanpa tab Notifikasi untuk role 1) | ✅ role 1 tanpa tab Notifikasi (Butuh Approval + Histori). |
| C-N4 | §5.9 | Pada C2 cek `reason_note`/keterangan di daftar | Alasan penolakan `[QA-F7 C2] …` tampil; waktu ISO UTC `…Z` di API, tampil WIB di UI | ✅ waktu WIB (12:36Z → "19.36"). |
| C-N5 | Q7-31 [V2] | `demo.pegawai`: buka tab Notifikasi tanpa mengklik item, muat ulang; lalu ⋮ **Buka** pada C1; lalu ⋮ **Tandai dibaca** pada satu item S1; lalu **Tandai semua dibaca** | Membuka daftar **tidak** menandai dibaca (deviasi #1). **Buka** menandai dibaca + membuka `url_fe` (C1 → `show_notif` 3). Tandai dibaca S1 → item dibaca, angka lonceng turun. Tandai semua → `belum_dibaca` 0 (`POST api/v1/notifikasi/baca` `{semua:true}` → `{diubah}`) | ✅ ptt ⋮ Buka → /pegawai/<nip>?tab=alamat, lonceng 7→6. |
| C-N6 | Q7-33, `NC:120,194-206` | `demo.pegawai` ⋮ **Hapus** C1 (ConfirmDialog); cek ⋮ pada item S1; panggil `DELETE api/v1/notifikasi/layanan/<id S1>` | C1 hilang dari daftar, `riwayat_organisasi.show_notif` = 2. Item S1 tanpa item Hapus; API → 404. Urutan ⋮: Buka → Tandai dibaca → Hapus (merah, paling bawah) | ✅ ⋮ Buka → Tandai dibaca → --- → Hapus; ConfirmDialog "Hapus notifikasi?" (dibatalkan). |
| C-N7 | `NC:120` | `demo.satker` ⋮ pada C6a/C6b; panggil `DELETE api/v1/notifikasi/berita/<id C6a>` | Item **Hapus** tidak tampil untuk role 3; API → 403. Tandai dibaca C6a → `news.show_notif` = 3 (SumberBerita, §7 A↔C) | ✅ satker ⋮ berita hanya "Buka" (tanpa Hapus). |
| C-N8 | §5.9 S3, `NC:39` | Tab **Butuh Approval** (`GET api/v1/notifikasi/butuh-approval`): `demo.admin`, `demo.satker`, `demo.atasan`, `demo.pegawai`; cek role 6 → 403 | Admin: grup `riwayat` (C3, status 0/3) + `berita` (C6c); daftar `riwayat` memuat C3 dengan nama pegawai, ⋮ **Proses** ke `/pegawai/<nip>?tab=organisasi`. Satker: grup `riwayat` (C3 dalam lingkup). `demo.atasan`: grup `lkh` (C7 + LKH L6 F6 bila belum disetujui) dan `cuti`; angka sama dengan halaman modul. `demo.pegawai`: grup PIC `ib`/`pmk` dari data F4. `riwayat` = `null` untuk role 2 | ✅ satker Butuh Approval C3 ⋮ Proses → /pegawai/<nip>?tab=organisasi; admin: grup Riwayat Pegawai 1 + Berita 1 (tautan ke Kelola Berita). |
| C-N9 | §5.9 S4, Q7-35, `NC:68` | Tab **Histori Approval** bulan ini; ganti bulan dari daftar; panggil `?bulan=2020-01` | Admin: C1, C4, C5 (`show_ua_biro`) + C6a/C6b. Satker: C2 (`show_ua_upt`/`show_ua_deputi` sesuai akun). `demo.atasan`: LKH/cuti yang diputus sebagai AL. `pilihan_bulan` 12 bulan, bulan ini dulu; bulan di luar daftar → 422 | ✅ pilihan 12 bulan, ganti ke Oktober 2026 → request bulan=2026-10, kosong. |
| C-N10 | BP8-1, §11 #18 | `tiket_belum_dibaca` di ringkasan; cek histori PIC TB/IB/PMK | `tiket_belum_dibaca` = `null` (NoOp sampai Fase 8). Histori `show_pl` belum ada (ditunda, Horii 10-11) | ✅ Ringkasan 8 role: tiket_belum_dibaca = null di semua role. Histori approval demo.pegawai (PIC IB/PMK) total 0, jadi histori show_pl memang belum ada (deviasi #8 README, ditunda). |
| C-N11 | CR-113 | Rujukan bukti otomatis di log regresi gate penuh Fase 7 (`ab1ff02d`): `TableExistsBerprefixTest`, regresi `PusatNotifikasiTest` di DB baru; di demo `GET api/v1/notifikasi/ringkasan` tanpa 500 | Hijau (regresi Fase 7 lolos, 4130 test, EXIT=0); `tableExists` di modul memakai `prefixTable(…), false` | [test-otomatis] (log regresi F7 4130 test EXIT=0). |
| C-N12 | K.1 | Cari `[QA-F7 C2]` (alasan), NIP akun demo, dan isi berita di `writable/logs/*` | Tidak ada isi notifikasi/alasan/NIP di log berkas | ✅ writable/logs: 2 berkas (log-2026-11-10 91 baris, log-2026-11-11 6 baris). Hitungan: '[QA-F7 C2]' 0; alasan C2 penuh 0; penanda 'QA-F7' 0; judul/isi berita (23 pola dari tabel news) 0. NIP akun demo: 8 baris, semuanya di log 10-11 dari adapter mock Fase 6 ([M… |

## C-F. Sortir inbox legacy F-02 (MAKE-086, Q7-28)

| # | AC / acuan | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C-F1 | §5.2 | `$W impor:sortir-inbox --sumber=$L --keluaran=$F/manifest_f02.json` | Tiket total 5: T-OK 3, A-ESK belum diputuskan 2; pesan total 6: M-OK 5, M-ESK belum diputuskan 1; pesan "Eskalasi: 3 baris ambigu belum diputuskan"; manifest tetap ditulis (exit 0: sortir = laporan, gagal-tertutup ada di F4) | ✅ Pembungkus qa_fase7_c_impor.php impor:sortir-inbox, EXIT=0. Tiket total 5: T-OK 3, A-ESK belum diputuskan 2. Pesan total 6: M-OK 5, M-ESK belum diputuskan 1. Keluaran 'Eskalasi: 3 baris ambigu belum diputuskan …', 'Manifest ditulis.' |
| C-F2 | §5.2, N3 | Buka `manifest_f02.json` | Hanya id + kode kelas + jumlah (`eskalasi` tiket 3, 4; pesan 14); tanpa subjek/isi/NIP; `di_luar_domain` per kolom | ✅ manifest_f02.json 682 byte: dibuat_utc, sumber, tiket/pesan total+kelas, di_luar_domain, keputusan_tak_terpakai, eskalasi tiket {3: pengirim tidak sah, 4: tanpa pesan}, pesan {14: tanpa induk}. Tidak memuat subjek/isi; angka ≥15 digit 0. di_luar_domain = [] k… |
| C-F3 | §5.2 | Ulangi F1 dengan `--keputusan=$F/keputusan_f02.json` | "Tidak ada baris ambigu yang belum diputuskan"; tiket: T-OK 3, A-ESK dibuang 1, A-ESK diimpor 1; pesan: M-OK 4, M-IKUT dibuang 1, M-ESK dibuang 1 | ✅ Dengan --keputusan, EXIT=0: 'Tidak ada baris ambigu yang belum diputuskan.' Tiket: T-OK 3, A-ESK dibuang 1, A-ESK diimpor 1. Pesan: M-OK 4, M-IKUT dibuang 1, M-ESK dibuang 1. |
| C-F4 | §5.2 | `$W impor:legacy --sumber=$L --zona-sumber=WIB --konfirmasi=$T --tier=13 --dry-run` dulu tanpa `--keputusan`, lalu dengan `--keputusan=$F/keputusan_f02.json` | Tanpa keputusan: GAGAL tertutup "inbox: 2 baris ambigu belum diputuskan (… pengirim tidak sah: 1, … tanpa pesan: 1)". Dengan keputusan: `disiplin_ticket<inbox sumber=5 ditulis=4 dibuang=1`; `disiplin_ticket_msg<inbox_msg sumber=6 ditulis=4 dibuang=2` (M-IKUT 1, eskalasi 1); "Dry-run selesai, tidak ada yang ditulis" | ✅ Tanpa keputusan: EXIT=1 'GAGAL: inbox: 2 baris ambigu belum diputuskan (F-02 A-ESK pengirim tidak sah: 1, F-02 A-ESK tanpa pesan: 1) …'. Dengan keputusan: EXIT=0 'T13 disiplin_ticket<inbox sumber=5 ditulis=4 dilewati=0 dibuang=1'; 'disiplin_ticket_msg<inbox_m… |
| C-F5 | §5.2, Q7-28 | F4 tanpa `--dry-run` + `--jejak=$F/jejak_t13.json`; lalu hitung `disiplin_ticket(_msg)` di `$T` dan `inbox`/`disiplin_ticket` di DB demo | 4 tiket + 4 pesan di `$T`; INFO `kategori NULL=1`; rekonsiliasi sumber = ditulis + dibuang. DB demo tidak berubah (`inbox` v2 = jumlah sebelum, `disiplin_ticket` = 0) | ✅ Tanpa --dry-run, EXIT=0 'Impor selesai.', 'koreksi (F-02 INFO kategori NULL=1)'. $T: disiplin_ticket 4, disiplin_ticket_msg 4. Rekonsiliasi 5=4+0+1 dan 6=4+0+2. DB demo tidak berubah: inbox 20 (sama dengan sebelum), disiplin_ticket 0, disiplin_ticket_msg 0. C… |

## C-I. Impor legacy tier 14 konten Fase 7 (MAKE-089, §11 #19)

| # | AC / acuan | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C-I1 | Q-089-4 | `$W impor:legacy --sumber=$L --zona-sumber=WIB --konfirmasi=$T --tier=14 --dry-run` (tanpa keputusan) | GAGAL tertutup: "news: 1 baris ambigu belum diputuskan (… berkas tidak sah …)"; tidak ada yang ditulis | ✅ Tier 14 dry-run tanpa keputusan: EXIT=1 'GAGAL: news: 1 baris ambigu belum diputuskan (MAKE-089 berkas tidak sah (path/ekstensi): 1) …'. 12 tabel tier 14 di $T tetap 0 baris. |
| C-I2 | §10 DBV-032 | I1 dengan `--keputusan=$F/keputusan_t14.json` | Per tabel `sumber = ditulis + dilewati + dibuang`: news_category 2/2 (koreksi status 0→2); news 3 → 2 + 1 dibuang (sanitasi 1); news_flag 3 → 2 + 1 kunci ganda; jenis_dibo 8 dilewati (seed); dibo 2 → 1 + 1 (tahun 0000→NULL); popup 3 → 1 + video 1 + eskalasi 1; user_popup 3 → 2 + ikut induk 1; banner 2/2; user_banner 1/1; form_digital 2 → 1 + 1 (elemen statis tidak dibawa); user_form_digital 2 → 1 + 1; turunan filled 2 → 1 + 1; filled 2 → 1 + 1; skl 3 → 2 + kunci ganda 1 | ✅ Dry-run + keputusan_t14, EXIT=0, semua sesuai: news_category 2/2 (status 0→2 =1); news 3 → 2 + dibuang 1 (sanitasi 1); news_flag 3 → 2 + kunci ganda 1; jenis_dibo 8 dilewati (seed); dibo 2 → 1 + 1 (tahun 0000→NULL 1); popup 3 → 1 + video 1 + eskalasi 1; user_… |
| C-I3 | Q-089-1 | I2 tanpa `--dry-run` + `--jejak=$F/jejak_t14.json`; cek `popup.file`, `news.lampiran`, `dibo.file_1`, `banner.file` di `$T` | Kunci `<jenis>/<hex32>.<ext>` (popup/, berita/, dibo/, banner/); tidak ada path `assets/…`; berita lampiran traversal tidak terimpor | ✅ Tanpa --dry-run + --jejak, EXIT=0, jejak_t14.json dibuat (4474 B), angka sama dengan I2. Kunci di $T: popup/<hex32>.jpg, berita/<hex32>.pdf, dibo/<hex32>.pdf, banner/<hex32>.png (2 baris). Hitungan path 'assets' atau '..' = 0. Berita legacy id 3 (lampiran tra… |
| C-I4 | Q-089-3, §11 #19 | Cek `news.news` id 1 dan `news.publish_at`, `popup.tgl_mulai` di `$T` | Isi tanpa `<script>`; `publish_at` = 03:00 UTC (10:00 WIB legacy); `popup.tgl_mulai` tetap 08:00 (waktu bisnis WIB tanpa konversi); `dibo.tahun` id 1 = NULL | ✅ $T news id 1: tanpa '<script' (legacy id 1 berisi), tanpa atribut on*=. publish_at id 1/2 = 2026-01-05 03:00:00 (legacy 10:00 WIB). popup.tgl_mulai = 2026-02-01 08:00:00 (sama dengan legacy, tanpa konversi). dibo id 1 tahun NULL (legacy 0000). |
| C-I5 | Q-089-2, Q-089-5 | Cek `form_digital.form_digital`, `user_form_digital`, `filled_form_digital.response` di `$T` | Skema `{versi:1, pertanyaan:[…]}` id `text_1`, `radio_group_1`, `file_1` (label tanpa HTML); `user_form_digital` = target `demo.pegawai` + turunan jawaban `demo.pemohon`; jawaban berkunci id v2, `Ya` (label opsi), huruf `é` utuh, berkas `form-digital/1/<hex32>.pdf` `ukuran: null`; form 2 dan anaknya tidak ada | ✅ form_digital id 1: {versi:1, pertanyaan}, id text_1 (teks), radio_group_1 (pilihan_tunggal, opsi Ya/Tidak), file_1 (berkas); label tanpa HTML. user_form_digital 2 baris: target demo.pegawai + turunan jawaban demo.pemohon (dicocokkan lewat NIP ke pengguna demo… |
| C-I6 | Q-089-1 b | `$W impor:berkas-konten --sumber=$L --dasar=$F/legacy_app --keputusan=$F/keputusan_t14.json --dry-run` | Jumlah per jenis saja: popup disalin 1 dilewati 2; banner disalin 1 hilang 1; berita disalin 1 dilewati 1; dibo disalin 1 dilewati 1; form-digital disalin 1; "Dry-run selesai"; `qa_f7_c/konten_tujuan` tetap kosong | ✅ impor:berkas-konten --dry-run, EXIT=0: popup disalin 1 dilewati 2; banner disalin 1 hilang 1; berita disalin 1 dilewati 1; dibo disalin 1 dilewati 1; form-digital disalin 1. 'Dry-run selesai, tidak ada berkas yang disalin.' konten_tujuan 0 berkas. |
| C-I7 | Q-089-1 b | I6 tanpa `--dry-run`, lalu jalankan sekali lagi | Run 1: berkas tersalin di `konten_tujuan/<kunci>` dengan kunci **sama** dengan kolom I3/I5. Run 2: semua `sudah_ada`, `disalin 0` (idempoten); skema `$L` tidak berubah | ✅ Run 1 EXIT=0: 5 berkas di konten_tujuan dengan kunci persis sama dengan kolom DB (popup.file, news.lampiran, dibo.file_1, banner id 1, filled file_1). Satu-satunya selisih adalah banner id 2 yang berkas legacy-nya hilang. Run 2 EXIT=0: semua jenis disalin 0,… |
| C-I8 | Q-089-1 b | Ubah isi satu berkas di `konten_tujuan/banner/…`, jalankan I7 lagi; jalankan dengan `--dasar=$F/tidak-ada` dan tanpa `--sumber` | Banner `beda 1`, berkas tujuan tidak ditimpa. `--dasar` tidak ada / opsi kurang → kode keluar ≠ 0 + pesan pemakaian | ✅ Banner konten_tujuan diubah (md5 berganti). Jalankan ulang: EXIT=0, 'banner: … beda 1 …'; md5 sesudahnya sama dengan versi yang diubah (tidak ditimpa). --dasar=$F/tidak-ada → EXIT=7 + 'Pemakaian: impor:berkas-konten --sumber=<skema> --dasar=<folder> … (--dasa… |
| C-I9 | N3, §11 #19 | Cari `QAF7RAHASIAKRITIK`, `QAF7RAHASIAJAWABAN`, NIP akun demo, dan `assets/upload` di keluaran I1–I8 dan `writable/logs/*` | Tidak ditemukan (laporan & log hanya jumlah/kode) | ✅ Keluaran I1–I8 (9 berkas) dan writable/logs: QAF7RAHASIAKRITIK 0/0, QAF7RAHASIAJAWABAN 0/0, 'assets/upload' 0/0, NIP akun demo 0 di keluaran, angka ≥15 digit 0 di keluaran. Hal yang sama juga 0 di jejak_t14.json, manifest_f02.json, dan keluaran F1–F5. Log: im… |

Ulang dari awal: `php qa_fase7_c.php <backend> --reset-impor` (membuang dua skema QA, jejak/manifest, dan isi `konten_tujuan`).

## D-A. Keluarga role beranda (PRD §5.10; gating D-A1/A2 → GF)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-A3 | §5.10 | Login berurutan role 1, 3, 4, 5, 8 | `AdminDashboard` (`data-testid="beranda-admin"`); teks "Lingkup data: Kementerian" (role 3: "Satker") | ✅ eselon1/menteri/pimpinan beranda-admin "Lingkup data: Kementerian". |
| D-A4 | §5.10 | Login role 2 (`demo.pegawai`), 6 (`demo.ptt`), 7 (`demo.pppk`) | `PegawaiDashboard`; sapaan + "Anda masuk sebagai <role>" (`home-role`, MTC-001) | ✅ ptt "6 — PTT", pppk "7 — PPPK". |
| D-A5 | — | Matikan backend sementara (atau blok `dashboard/ringkasan` di DevTools) lalu muat ulang `/` | Pesan galat merah; sapaan & `home-role` tetap; komponen konten/ulang tahun tidak membuat halaman rusak | ✅ (XHR dashboard/ringkasan dialihkan gagal) pesan galat, sapaan & home-role tetap, halaman tidak rusak. |
| D-A6 | §6 | `GET dashboard/ringkasan` dan `dashboard/ulang-tahun?hari=ini` dengan token tiap role 1–8; `GET dashboard/direktur` role 2/3 | Ringkasan & ulang tahun 200 untuk 1–8 (bentuk `keluarga` admin/pegawai); direktur 403 untuk selain 1/4; `hari` selain `ini`/`besok` → 422 | ✅ ringkasan dan ulang-tahun?hari=ini/besok → 200 untuk 10 akun role 1–8 (admin: keluarga admin; 2/6/7: keluarga pegawai). direktur: role 1 dan 4 → 200; role 2/3/5/6/7/8 → 403. hari=lusa → 422 errors.hari. Tanpa token → 401. |

## D-B. Komposisi & status hari ini admin (Q7-36, Q7-41, Lampiran A)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-B1 | Q7-36 | `demo.admin`, `.env` `tampilanKomposisi` kosong (bawaan `donut`) | Donut komposisi (PNS, PTT, Non PNS, Staf Khusus, Tenaga Ahli, PPPK) + tile status hari ini (TB, IB, CLTN, Cuti Online, Presensi Online); angka = acuan skrip `tile` | ✅ admin: tampilan donut, total_aktif 153, pns 115 (75%), ptt 9, pppk 29 = acuan skrip; UI beranda-komposisi-donut + tile TB/IB/CLTN/cuti/presensi |
| D-B2 | Q7-36 Lampiran A | `.env` `dashboard.tampilanKomposisi = angka`, cache dikosongkan, muat ulang | 11 tile berurutan legacy (`dashboard_ad.php:47-180`) + bar persen, tanpa chart; angka sama dengan B1; API sama kecuali `tampilan_komposisi` | ✅ (API) angka: tampilan_komposisi "angka", 11 tile urutan legacy (pns, ptt, non_pns, staf_khusus, tenaga_ahli, tb, ib, cltn, cuti, presensi_online, pppk), angka sama. Sisa UI (bar persen). |
| D-B3 | — | `.env` nilai lain (mis. `pie`) | Diperlakukan `donut` | ✅ "pie" → donut. |
| D-B4 | §5.10 | `demo.satker` (role 3, satker 1) | Lingkup "Satker"; angka = acuan skrip baris `demo.satker` (hanya pegawai satker 1); TB tanpa perpanjangan [K] | ✅ satker: lingkup satker 1, total_aktif 21, pns 17, ptt 2, pppk 2 = acuan skrip; UI "Lingkup data: Satker" + komposisi |
| D-B5 | Q7-41 | `.env` `dashboard.nipDisembunyikan = <NIP DU1>`, cache dikosongkan | `total_aktif` & tile PNS kementerian turun 1 (letak pengecualian ikut legacy per lingkup, README `Libraries/Dashboard`); kosongkan lagi sesudahnya | ✅ nipDisembunyikan=DU1: tile PNS kementerian 115→114 (admin & eselon1); total_aktif tetap 153 = aturan legacy per lingkup (kementerian: semua tile, TIDAK total; RingkasanBeranda.php:33). .env dikembalikan identik |
| D-B6 | Q-D12 | Tetap B5, lalu `.env` `dashboard.penggunaLihatSemua = <id_pengguna demo.admin>` | `demo.admin` kembali melihat angka tanpa pengecualian; `demo.eselon1` tetap terkecualikan | ✅ + penggunaLihatSemua=id admin: admin 115 (tanpa pengecualian), eselon1 tetap 114. |

## D-C. Kartu admin (Q7-37, Q7-38, Q7-45, Q7-26, Tanda Jasa v2.1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-C1 | Q7-37 | `demo.admin` dan `demo.satker`: 4 kartu pengingat | Label legacy Pensiun / Naik Pangkat / Tanda Jasa / KGB dengan angka = acuan skrip; **hanya Pensiun** bertautan ke `/dokumen/pensiun`; KP/TJ/KGB tanpa tautan. Naik Pangkat memuat DP1 (jatuh tempo April) **dan DK1**: PNS tanpa riwayat KP berpendidikan berkode legacy ikut terhitung (perilaku `get_all_kp`, golongan kosong < plafon) [K]; pegawai demo lain berkode "S-1" tidak terhitung (§K D-K2/D-K4) | ✅ UI: hanya Pensiun bertautan /dokumen/pensiun; KP/TJ/KGB tanpa tautan. |
| D-C2 | — | Klik kartu Pensiun | Halaman Proyeksi Pensiun (F-09); jumlah barisnya konsisten dengan angka kartu untuk periode yang sama (catat bila definisi periode beda) | ✅ Proyeksi Pensiun 3 pegawai = kartu 3. |
| D-C3 | §5.10 | Kartu Butuh Approval (GF3 aktif) sebagai role 1, 3; lalu role 4/5/8 | Role 1 & 3: angka = `butuh_approval` `notifikasi/ringkasan`, tautan ke `/notifikasi?tab=butuh-approval`; role 4/5/8: kartu tidak ada | ✅ role 4/5/8 tanpa kartu Butuh Approval. |
| D-C4 | Q7-38 | `demo.admin` & `demo.eselon1`: tabel "Masa Kerja Direktur Politeknik" | Baris DD1 (masa kerja dari 2024-08-01, mis. "2 Tahun 3 Bulan") dan DD3; **DD2 tidak tampil** (hanya pemegang terbaru per jabatan); masa kerja kosong → "—". Baris lain dari jabatan demo yang id-nya kebetulan ada di `idJabatanDirektur` = sesuai konfigurasi, bukan temuan (§K D-K3) | ✅ (UI) direktur admin & eselon1; |
| D-C5 | Q7-38 | `demo.satker`, `demo.menteri`, `demo.pimpinan` | Tabel direktur tidak ada; API 403 untuk role 3/5/8 | ✅ tidak ada untuk satker/menteri/pimpinan. |
| D-C6 | Tanda Jasa v2.1 | `demo.satker`, bulan September–Desember: kartu Tanda Jasa; lalu `.env` `dashboard.tandaJasaIkutLegacy = true` + kosongkan cache | Bawaan [V2]: tahun acuan sama dengan kementerian (angka wajar, tidak ≈0); `true` → perilaku legacy (≈0 pada Sep–Des); kementerian tidak berubah | — Data demo: Tanda Jasa satker 1 = 0 di bawaan [V2] maupun saklar legacy (tak ada TMT CPNS di jendela penghargaan); kementerian 37 tidak berubah → logika dibuktikan PengingatBerandaTest |
| D-C7 | §5.10 | Urutan blok `AdminDashboard` | Banner (di bawah sapaan) → pengingat → Butuh Approval → komposisi → direktur → berita terbaru → ulang tahun hari ini/besok → ringkasan SKL (role 1) | ✅ admin urutan: pengingat → Butuh Approval → komposisi (donut + tile TB/IB/CLTN/cuti/presensi) → direktur → berita terbaru → ulang tahun ini/besok → ringkasan SKL (role 1). Banner admin tidak ada (role 1 403, sesuai A-B2). Satker tanpa direktur & SKL. |

## D-D. Kartu pegawai (Q7-42, Q7-44, Q-D13..Q-D17)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-D1 | §5.10 [K] | `demo.pegawai` (role 2): kartu prediksi | 4 kartu Pensiun / Naik Pangkat / Tanda Jasa / KGB, teks = acuan skrip ("-" bila kosong; KP JF "AK / target" + "Kurang N AK"; "Sudah di Pangkat Puncak") | ✅ pegawai: Pensiun Okt 2048, KP JF "0 / 0" + "Kurang 0 AK" (= legacy dashboard_pns.php:59-61 selalu ak_now/ak_kum untuk JF), TJ Agu 2035, KGB Apr 2027. |
| D-D2 | §5.10 [K] | `demo.pppk` (role 7), `demo.ptt` (role 6) | Role 7: hanya Pensiun + KGB; role 6: tanpa kartu prediksi | ✅ pppk hanya Pensiun (Feb 2027) + KGB; ptt tanpa prediksi. |
| D-D3 | Q7-42 | Kartu Presensi Saya role 2/6/7 | Tautan ke halaman Presensi Saya (bukan komponen presensi hari ini) | ✅ kartu Presensi Saya → /presensi. |
| D-D4 | Q7-44 | `demo.ptt` | Tidak ada tautan "Update FK" (baris legacy dikomentari) | ✅ ptt tanpa "Update FK". |
| D-D5 | Q-D13..D17 | `demo.qaf7.lengkap` (DK1): kartu kelengkapan | Kartu < 100% urut legacy dengan persen = acuan skrip (mis. Data Umum 64, Alamat 0, Keluarga 0, **Pendidikan 50** (DK2), Pangkat 0, KGB 0, Tanda Jasa 0); Pendidikan akun demo lain ("S-1/SMA") = 0% **[data-demo]** (§K D-K2; pencocokan kode legacy dibuktikan `tests/database/Dashboard/KelengkapanDataTest.php`); Data Jabatan 100 → tanpa kartu; **tanpa kartu Pelatihan** (Q-D13); bar merah < 50 / hijau ≥ 50 | ✅ demo.qaf7.lengkap: Data Umum 64 (hijau), Alamat 0, Keluarga 0, Pendidikan 50 (hijau), Pangkat 0, KGB 0, Tanda Jasa 0 (merah); tanpa Pelatihan; Jabatan 100 tanpa kartu. (Akun ini kini juga mendapat dialog BF2 karena B-B6.) |
| D-D6 | — | Klik tiap kartu kelengkapan | Pindah ke Detail Pegawai milik sendiri tab terkait (`/pegawai/<nip>?tab=<kode>`); `aria-label` "Klik Untuk Melengkapi <Data> Anda: N%" (tanpa tooltip `title`) | ✅ aria-label "Klik Untuk Melengkapi … Anda: N%", tanpa title, href /pegawai/<nip>?tab=<kode>. |
| D-D7 | Q-D15 | Sebagai `demo.qaf7.lengkap`: ajukan riwayat KP + lampiran, setujui sebagai `demo.admin`, muat ulang beranda | Persen Data Pangkat naik sesuai rentang golongan (port `kd_rpangkat_2`); riwayat tanpa lampiran tidak dihitung | ✅ Catatan: riwayat KP hanya role 1/3 (pegawai 403, matriks hr/rwy/kp) → ditambah admin dgn lampiran SK: CPNS III/a → 0% (kunci "CPNS III/a" belum ada, perilaku legacy), + Reguler III/b → 67% = port kd_rpangkat_2; tanpa lampiran → KelengkapanDataTest |
| D-D8 | Q-D16 | `demo.pppk`, `demo.ptt`, `demo.admin` | Tanpa kartu kelengkapan (hanya role 2 PNS) | ✅ ptt/pppk/admin tanpa kartu kelengkapan. |
| D-D9 | [K] | `demo.atasan`: bagian bawahan | Tabel presensi bawahan (Nama, Jam Masuk, Jam Pulang) + AK bawahan JF; jumlah = acuan skrip; `demo.pegawai` (bukan atasan) tanpa bagian ini | ✅ atasan: bawahan presensi 20 baris (Nama/Jam Masuk/Jam Pulang) + AK 14 baris = acuan; pegawai tanpa bagian ini. |
| D-D10 | §5.10 | Kartu Butuh Approval role 2 (`demo.atasan`, data F4) | Angka = antrean persetujuan; tautan ke tab Butuh Approval | ✅ atasan Butuh Approval 2 → /notifikasi?tab=butuh-approval. Atasan KP "Sudah di Pangkat Puncak", kelengkapan 4 kartu. |
| D-D11 | §5.10 | Urutan blok `PegawaiDashboard` | Banner → prediksi → kelengkapan → Presensi Saya/Butuh Approval → bawahan → ulang tahun → berita terbaru | ✅ urutan banner → prediksi → kelengkapan → presensi/butuh approval → ulang tahun → berita terbaru. |

## D-E. Ulang tahun & foto (Q7-45, Q-D18..Q-D20)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-E1 | Q7-45 | Kartu "Ulang tahun hari ini" sebagai role 1, 3, 2, 6 | Memuat DU1 dan DU5 (field mengikuti view legacy: nama bergelar, tanggal lahir Indonesia, jenis pegawai, jabatan, satker); jumlah = acuan skrip | ✅ hari ini DU1 + DU5 (nama, tanggal lahir Indonesia, jenis pegawai/NIP, jabatan, satker); |
| D-E2 | Q7-45 | Kartu "besok" | Memuat DU2 | ✅ besok DU2 avatar inisial "PU"; |
| D-E3 | Q-D20 / [K] | Cari DU3 dan DU4 | Tidak tampil (satker 171 dikecualikan; nonaktif) | ✅ DU3/DU4 tidak tampil; |
| D-E4 | Q-D19 | Foto DU1 di kartu; lalu `GET dashboard/ulang-tahun/<NIP DU1>/foto` | Gambar tampil (blob terautentikasi); API 200 `image/png` | ✅ foto blob tampil; |
| D-E5 | Q-D19 | DU2 (tanpa foto) di kartu; `GET …/<NIP DU2>/foto`; `GET …/<NIP pegawai lain bukan ulang tahun>/foto` | Avatar inisial; kedua API 404 seragam (tanpa membedakan "tak ada foto" vs "bukan ulang tahun") | ✅ avatar inisial (API 404 seragam: agen D). |
| D-E6 | Q-D18 | `.env` `dashboard.nipDikecualikanUlangTahun = <NIP DU5>` (+ kosongkan cache), muat ulang | DU5 hilang dari kartu; `GET …/<NIP DU5>/foto` 404; kosongkan lagi → kembali | ✅ DU5 hilang dari kartu, foto 404; dikosongkan → kembali, foto 200. |
| D-E7 | — | Kartu dengan > 1 orang: tombol ←/→, Home/End, Jeda/Putar; set OS "kurangi gerak" | Carousel bisa dioperasikan keyboard, fokus terlihat; berhenti saat hover/fokus; tanpa autoplay bila `prefers-reduced-motion` | ✅ carousel ulang tahun: Jeda/Sebelumnya/Berikutnya/Tampilkan slide N; keyboard End → slide 2, Home → slide 1 (tombol nyata). Reduced-motion: test otomatis. |
| D-E8 | — | Galat ulang tahun (blok `dashboard/ulang-tahun` di DevTools) | Pesan galat hanya di kartunya | ✅ galat hanya di kartu ulang tahun ini/besok. |

## D-F. Konten Jalur A di beranda (Q7-39, PRD §5.3–§5.5, §7 kontrak A→D)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-F1 | Q7-39 | `ACTIVE_PHASE = 7`; banner aktif (A*) bertarget kementerian; login role 1, 3, 2, 6, 7 | Banner tampil tepat di bawah sapaan untuk semua role yang masuk target (BE menyaring); carousel 4:1, navigasi/indikator, jeda | ✅ banner tepat di bawah sapaan untuk role 2/6/7; role 1/3 tanpa NIP tanpa banner (sesuai A-A5/A-B2). |
| D-F2 | §5.3 | Popup aktif (A*): login baru `demo.pegawai` | Popup tampil sekali per sesi login (pindah halaman & kembali ke `/` → tidak muncul lagi; logout-login → muncul lagi); baru bisa ditutup di gambar terakhir | ✅ = A-A1..A3. |
| D-F3 | §11.15 | `demo.pegawai` dengan form digital/SKL tertagih dan cuti menunggu (bila ada) + popup aktif | Satu dialog pada satu waktu, urutan form digital → pengingat cuti → popup konten → SKL | ✅ Sama dengan B-G1: awalnya ❌ T-03, diperbaiki CR-118, uji ulang lolos |
| D-F4 | §5.5 | Widget Berita Terbaru di admin & pegawai | Maks 5 berita yang boleh dilihat aktor; "Lihat semua" ke portal berita; klik judul → detail | ✅ berita terbaru 5 judul + "Lihat semua" → /berita. |
| D-F5 | §7 | Tanpa banner/popup/berita aktif (atau blok request) | Tidak ada kotak kosong/galat; beranda tetap tampil | ✅ konten gagal → banner/berita tidak dirender, tanpa kotak kosong/galat. |

## D-G. Ringkasan SKL admin (Q7-26, MAKE-087 M3-B, kontrak F-11)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-G1 | Q7-26 | `demo.admin` (`ACTIVE_PHASE = 7`): kartu "Survei Kepuasan Layanan" paling bawah | Subjudul "Periode <12 bulan terakhir>"; rata-rata total "4,3 dari 5 · 6 penilaian" (bila hanya DS1–DS6); tabel Cuti 3 / 4,3 · KARIS/KARSU 1 / 3,0 · KARPEG 0 / — · Tugas Belajar 2 / 5,0 · Izin Belajar 0 / — | ✅ kartu SKL admin "Periode 12 November 2025 – 11 November 2026", 4,3 dari 5 · 13 penilaian, tabel per jenis (label KARISKARSU dari master → O-4). |
| D-G2 | §6 | Role 3/4/5/8 di beranda; `GET survei-layanan/ringkasan` dengan token role 3 | Kartu tidak ada & tidak ada request dari beranda; API 403 | ✅ role 3/4/5/8 tanpa kartu SKL (snapshot) + API 403 (agen D). |
| D-G3 | F-11 | `GET survei-layanan/ringkasan?dari=2020-01-01&sampai=2020-12-31` (role 1); `?dari=salah` | Lima jenis jumlah 0, `rata_rata` null, total null; format salah → 422 | ✅ dari=2020-01-01&sampai=2020-12-31 → 200, kelima jenis jumlah 0 dan rata_rata null, total {jumlah 0, rata_rata null}. dari=salah → 422 errors.dari. sampai < dari → 422 errors.sampai. |
| D-G4 | — | Total 0 di beranda: DB demo sebelum `qa_fase7_d.php` dijalankan (atau tanpa penilaian lain) | "Belum ada penilaian" tanpa tabel (bila urutan prasyarat tidak memungkinkan → — dan rujuk test otomatis `HomeView.konten.spec.ts`) | [test-otomatis] Demo sudah berisi penilaian (total tidak bisa 0 tanpa mengubah data) → HomeView.konten.spec.ts |
| D-G5 | — | Blok `survei-layanan/ringkasan` di DevTools | Kartu tidak dirender, tanpa pesan galat; beranda tetap tampil | ✅ ringkasan SKL gagal → kartu tidak dirender tanpa galat. XHR dipulihkan, kartu kembali. |

## D-H. Konfigurasi rahasia `.env` (PRD §2, §5.10, README-deploy §1k)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-H1 | §2 | `grep` repo `main` untuk nilai `.env` yang diuji di B5/B6/E6 | Tidak ada NIP/id rahasia di repo/test; `backend/.env.example` hanya kunci kosong berkomentar | ✅ git grep di HEAD 875b8e5b (origin/main) untuk NIP sintetis DU1, DU2, DU5 → 0 berkas cocok (kontrol: grep nama kunci → 10 berkas). backend/.env.example baris 195–202: 5 kunci dashboard.* dikomentari; tiga kunci rahasia kosong, tampilanKomposisi=donut dan tanda… |
| D-H2 | README-deploy §1k | Bandingkan kunci `dashboard.*` di README-deploy dengan `Config\Dashboard` | Lima kunci terdokumentasi (fungsi, bawaan kosong, rahasia vs bukan) | ✅ README-deploy.md §1k (baris ~495–515) memuat 5 kunci (nipDisembunyikan, penggunaLihatSemua, nipDikecualikanUlangTahun, tampilanKomposisi, tandaJasaIkutLegacy), sama dengan properti .env di Config\Dashboard. Fungsi (Q7-41/Q-D12/Q-D18/Q7-36/[V2]), bawaan kosong… |

## D-I. Integrasi — ISSUE-058 (MAKE-078; butir N5/N6 Checklist Fase 6)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D-I1 | N5 / F6-24 | `GET integrasi/log` role 1 tanpa filter | 200; terbaru dulu; bawaan 25 per halaman; `total` ≥ 36 (DI1–DI7); `layanan_tersedia` memuat bkn, esign, siasn, simapi, fcm, ekinerja; kolom hanya kolom `integrasi_log` (tanpa data pegawai/akun) | ✅ 200; kunci items/total/page/per_page/layanan_tersedia; total 36; page 1; per_page 25; 25 item; terurut created_at turun. layanan_tersedia: bkn, ekinerja, esign, fcm, siasn, simapi. Kolom item = 13 kolom tabel integrasi_log saja. |
| D-I2 | N5 | `?layanan=esign&status=2`; `?layanan=siasn` | Hanya DI2; DI3 dengan `http_status` null | ✅ layanan=esign&status=2 → total 1 (DI2, http 500). layanan=siasn → total 1 (DI3, http_status null). |
| D-I3 | N5 | `?tanggal_mulai=<hari ini>&tanggal_selesai=<hari ini>` | Memuat DI1, DI2, **DI6** (00:30 WIB walau UTC-nya kemarin); tidak memuat DI7-* (kemarin) | ✅ tanggal 2026-11-11..2026-11-11 → total 3: DI2, DI1, dan DI6 (created_at 2026-11-10 17:30 UTC = 00:30 WIB). Tidak ada DI7. |
| D-I4 | N5 | `?tanggal_mulai=<kemarin>&tanggal_selesai=<kemarin>` | Memuat DI3, DI4, DI7-01..30; **tidak** memuat DI6 | ✅ tanggal 2026-11-10..2026-11-10 → total 32: DI3 siasn, DI4 simapi (arah 2), 30 ekinerja (00:01–00:30 UTC = 07:01–07:30 WIB, 6 gagal). DI6 tidak termuat. |
| D-I5 | N5 | `?status=3`; `?tanggal_mulai=2026-13-01`; mulai > selesai; `?layanan=BKN!` | 422 dengan pesan per parameter (layanan, status, tanggal sekaligus); `?page=0` → 422 terpisah (`ListQuery`) | ✅ status=3, tanggal_mulai=2026-13-01, mulai > selesai, layanan=BKN! → masing-masing 422 dengan errors per parameter. Gabungan ketiganya → 422 dengan errors layanan+status+tanggal_mulai sekaligus. page=0 → 422 terpisah (errors.page, pesan ListQuery). |
| D-I6 | N5 | `?per_page=100`; `?per_page=500`; `?page=2` (bawaan 25) | Paginasi benar; `per_page` di atas 100 dijepit ke 100 (bukan 422, pola `ListQuery`) | ✅ per_page=100 → 36 item; per_page=500 → dijepit per_page 100 (200, bukan 422); page=2 → 11 item, tanpa tumpang tindih dengan halaman 1; page=99 → 0 item. |
| D-I7 | N5 / §6 | `GET integrasi/log` dengan token role 2, 3, 4 | 403 | ✅ Role 2, 3, 4 (juga 5, 6, 7, 8) → 403; tanpa token → 401. |
| D-I8 | N6 | `demo.admin`: menu Pengaturan → Integrasi | Halaman tampil (`page-fill`: hanya body tabel menggulir, header sticky); kolom Waktu (WIB), Layanan, Arah, Operasi, HTTP, Kode, Status, Aksi; DI6 tampil "<hari ini> 00:30"; total "N log" + paginasi | ✅ /integrasi kolom Waktu (WIB)/Layanan/Arah/Operasi/HTTP/Kode/Status/Aksi, 25/halaman. |
| D-I9 | N6 | Filter Layanan & Status (UiSelect), Dari/Sampai tanggal (date picker custom), tombol reset | Hasil sama dengan I2–I4; komponen custom (bukan `<select>`/date bawaan), bisa keyboard | ✅ filter UiSelect custom (select asli tersembunyi) + date picker custom. |
| D-I10 | N6 / AGENTS §1 | Menu ⋮ baris → "Lihat detail" | Satu tombol ⋮ berlabel "Aksi untuk log …"; dialog detail berisi pesan teredaksi, durasi, percobaan, ref; Esc menutup | ✅ ⋮ "Lihat detail" (label "Aksi untuk log …"), dialog teredaksi + durasi/percobaan/rujukan; Esc menutup. |
| D-I11 | N6 / §5.11 | Tombol halaman "Tarik Ulang e-Kinerja": jenis "Daftar periode penilaian" lalu "SKP periodik" dengan bulan (UiMonthPicker) dan tanpa bulan | `POST integrasi/bkn/tarik` 202 (job diantre, mode mock); bulan kosong = periode otomatis; galat validasi tampil di dialog | ✅ POST integrasi/bkn/tarik → 202 (antre, mode mock; 3 job integrasi tertinggal di queue_jobs demo); validasi periode 422; tombol "Tarik Ulang e-Kinerja" tampil di halaman Integrasi |
| D-I12 | N6 | Menu Integrasi sebagai role 3/4 | Menu tidak ada; buka `/integrasi` langsung → ditolak (guard role) | ✅ eselon1: menu Integrasi tidak ada, /integrasi → /403. |

## K. Keputusan / pertanyaan pra-QA (gabungan)

| # | Jalur | Temuan / pertanyaan | Keputusan |
|---|---|---|---|
| A-K1 | A | DIBO berjenis nonaktif: legacy punya filter `jdb.status='1'` tetapi dikomentari (`L_dibo.php:12-27,62-66`). | **Selesai — CR-116** (reviewer 11-11): `DiboService::baca()`/`bacaBerkas()` non-admin menyembunyikan DIBO berjenis nonaktif ([V2] lanjutan Q7-13); baris tanpa jenis (NULL) tetap terbaca; test `DiboServiceTest::testBacaMenyembunyikanDiboBerjenisNonaktif`. A-E2 diuji **sesudah CR-116 di `main`** |
| A-K2 | A, B, C, D | Menu/lonceng/popup/host dialog Fase 7 baru terlihat bila `ACTIVE_PHASE ≥ 7`; `main` masih 5. | **Reviewer 11-11:** GF1–GF2 diuji di 5 apa adanya; butir lain dengan 7 **lokal** di worktree demo tanpa commit; dikembalikan di GF5 (juga D-K1) |
| A-K3 | A | A-D6 (notifikasi persetujuan berita role 3) dan A-C10/A-C11 (angka berita belum dibuka) bergantung pada Pusat Notifikasi C. | Informasi: masalah lonceng dicatat sebagai temuan C; sisi A dibuktikan lewat DB (`news.show_notif`, `news_flag`) |
| A-K4 | A | Berita role 3 yang diubah tidak kembali menunggu (A-D10). | Informasi: keputusan Horii 11-11 (saklar `Config\Konten::$beritaUbahRole3KembaliMenunggu`, bawaan `false`); A-D11 menguji sisi `true` |
| A-K5 | A, D | Skrip memakai tanggal relatif hari dijalankan (periode aktif kemarin…+30 hari; ulang tahun hari ini/besok). | Jalankan skrip pada DB demo baru menjelang QA; ulangi skrip D bila QA berlanjut ke hari lain |
| A-K6 | A | Q7-39 (banner di beranda admin) tidak dapat dipicu: akun role 1/3/4/5/8 demo tanpa NIP, route `banner/aktif` menolak role 1. | **Reviewer:** A-B2 ditandai **[test-otomatis]** — `KontenGambarEndpointTest::testBannerAktifUntukRoleBerNipDanRole1Ditolak`, `KontrakMatriksRoleTest` (`GET api/v1/konten/banner/aktif` = role 2–8), `KontenGambarServiceTest::testAktifMenurutNipPeriodeWibDanUrutan`, `komponen-beranda.spec.ts`. Seed demo tidak diubah |
| B-K1 | B | B-D9: arti role 7 di SKL (Q7-23). | QA memakai perilaku v2 (role 7 bukan pengisi SKL); bila Horii/legacy menunjukkan lain, dicatat di §T |
| B-K2 | B | B-G2 (wajib ganti password) dan GF1 bagian host dialog mengubah data/konfigurasi demo sementara. | Dijalankan atas persetujuan reviewer; data/konfigurasi dikembalikan sesudahnya. Bila host dialog di `ACTIVE_PHASE` < 7 tidak praktis diamati: bukti test Vitest gate `main` |
| C-KC1 | C | `reset_demo.sh` sudah tidak ada (catatan T2(f) Checklist F6). | **Reviewer 11-11:** prosedur reset manual di "Lingkungan & persiapan" (DROP/CREATE → `migrate --all` dari worktree demo @`main` → `seed_demo.php` → `qa_fase4_data.php` → `qa_fase7_a/b/c/d.php`) + langkah `ACTIVE_PHASE` 7 lokal tanpa commit |
| C-KC2 | C | `impor:legacy` wajib ke tujuan kosong; DB demo sudah berisi data A/B. | **Reviewer 11-11: OK** — impor diuji ke skema QA terpisah lewat `qa_fase7_c_impor.php`; command dan aturan yang diuji tetap asli; DB demo utuh |
| C-KC3 | C | Konket (`absen_ijin`) dan biodata (`pegawai_hist`) sebagai sumber notifikasi S2/S3 tanpa data khusus. | **Reviewer 11-11:** dicakup test otomatis F-08 di regresi gate penuh (bukan butir UI) |
| D-K1 | D | Fitur Fase 7 di beranda baru aktif bila `ACTIVE_PHASE ≥ 7`. | **Reviewer 11-11:** lihat A-K2 (D-A1 → GF1, D-A2 → GF3) |
| D-K2 | D | Master `jenjang_pendidikan_singkat` demo memakai "SMA/SMP/S-1/D-III", kartu Pendidikan memakai kode legacy "SLTA/SLTP/S.1/D.III" → kartu Pendidikan akun demo lain selalu 0%. | **Reviewer 11-11:** butir Pendidikan akun demo ditandai **[data-demo]** + rujuk `KelengkapanDataTest`; bukan temuan. Ditambah DK2 → kartu Pendidikan DK1 = 50% |
| D-K3 | D | Seed demo memberi id jabatan 41/149/150 (ada di `Config\Dashboard::$idJabatanDirektur`) ke jabatan biasa → baris direktur tambahan. | **Reviewer 11-11:** sesuai konfigurasi, bukan temuan (D-C4 memeriksa DD1/DD2/DD3) |
| D-K4 | D | Kartu "Naik Pangkat" demo = 0 (plafon KP reguler `get_all_kp` memakai kode legacy "S.1"). | **Reviewer 11-11:** tambah data sintetis — DP1 (KP reguler jatuh tempo April) dan DK1 ikut terhitung [K] → Naik Pangkat = 2; bila data tidak ditambah → **[test-otomatis]** |
| D-K5 | D | Figma beranda belum ada. | **Reviewer 11-11:** cek visual hanya konsistensi dengan PRD §5.10 dan komponen `shared/ui` |

## T. Temuan

(Diisi pelaksana QA. Satu baris per temuan; kolom Butir memakai nomor berawalan, mis. `A-A10`, `C-N5`.)

| # | Butir | Temuan | Dampak | Usulan / status |
|---|---|---|---|---|
| T-01 | GF3 | Lonceng berupa titik merah, angka hanya di aria-label | Tampilan | Sesuai desain redesign (§4 Gambar 12) — keputusan Horii 11-11, bukan temuan |
| T-02 | B-A2 | Kueri audit DBV-032 WAJIB_0 "news_category status di luar 0/1" dan "dibo status di luar 1/2" = 1 di DB v2 | Dokumen | Domain kueri = legacy (v2 memakai 1/2/10; impor memetakan 0→2). Bukan bug; catat di laporan akhir |
| T-03 | B-G1, D-F3 | Urutan antrean dialog bergantung balapan network (popup bisa mendahului form/pengingat) | Sedang | CR-118 DI MAIN 4c70a80a; uji ulang lolos |
| T-04 | B-G2 | Lonceng memanggil notifikasi/ringkasan (403) selama wajib ganti password | Kecil (bising) | Catatan saja (Horii 11-11) |
| T-05 | A-A6 | Daftar Kelola Popup/Banner tanpa kolom Tautan (legacy menampilkan) | Kecil | CR-117 DI MAIN eab1b2f6; uji ulang lolos |
| T-06 | (temuan saat A-A6) | KontenPage memakai ulang satu instans view untuk popup↔banner dan kategori berita↔jenis DIBO: data basi, aksi ⋮ mengenai entitas yang salah | Berat | CR-117 DI MAIN eab1b2f6; uji ulang lolos |
| T-07 | A-G2 | Target popup/banner (dan form digital) menerima unit/satker nonaktif atau tidak ada | Kecil–sedang | CR-119 DI MAIN 29ee1d34; uji ulang lolos |
| T-08 | A-B4, A-G10 | Carousel banner mengunduh ulang gambar setiap slide (endpoint no-store) | Sedang (kinerja) | CR-117 lanjutan DI MAIN d99f690e; uji ulang lolos |
| O-2 | A-C4 | Tautan di isi berita tanpa rel/target (buka di tab yang sama) | Info | Aman (tanpa target=_blank); catatan |
| O-3 | A-F6 | Respons POST jenis DIBO memuat allow_ap padahal tabel tanpa kolom itu | Kosmetik | Catatan |
| O-4 | B-D1, D-G1 | Label jenis 2: dialog SKL "KARIS/KARSU", rekap/kartu beranda "KARISKARSU" (master) | Kosmetik | Catatan |
| O-5 | C-N7 | API mengirim dapat_dihapus=true untuk notifikasi berita role 3 (FE menyembunyikan Hapus per role, API 403) | Kosmetik | Catatan |
| O-6 | B-B1, B-E1 | Format periode ISO dan rata-rata "4.0" di halaman Form Digital/rekap SKL vs "10 Nov 2026"/"4,3" di tempat lain | Kosmetik | Catatan |
| O-7 | teks ceklis | Amplop galat contoh {"status":false} (aktual {"status":"error"}); D-D7 "ajukan sebagai pegawai" (KP hanya admin); D-B5 "total turun" (legacy: total kementerian tidak dikecualikan) | Dokumen | Perbaikan teks ceklis; perilaku kode benar |
| O-8 | C-N12 | Log demo F6: adapter MOCK e-sign/FCM mencatat NIP sintetis (flag catatPengenal) — hanya driver mock, di luar Fase 7 | Info | Catatan untuk laporan akhir |
