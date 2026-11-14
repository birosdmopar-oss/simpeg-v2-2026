# Checklist QA Lapis 1 — F-10 Arsip Digital (Tahap 1 + Tahap 2)

Untuk reviewer CR (pelaksana QA Lapis 1 F-10; PRD F-10 §11.3, §11.4 DoD). Acuan: PRD F-10 **v1.3** (`main`,
`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md`) dan **v1.4** (CR-122, branch `docs/cr-122-prd-f10-v14` @`448b302c`: angka D1
salinan dev §10, koreksi F10-37 (6)) — §0 gerbang, §3 register F10-01..F10-38, §5.2 AC1-01..AC1-18, §6.2 AC2-01..AC2-13,
§6.6 risiko diterima, §7 penyajian berkas (dua jalur), §8.1 endpoint, §9 FE, §11.3 daftar "Bukan bug", §12.1 matriks role.
Kontrak: `backend/app/Controllers/Api/Kepegawaian/README.md:244-302` (bagian "Arsip F-10"). Penyajian Nginx: README-deploy
§1 (contoh server) dan §1l + ADR-038 (paket MAKE-093, branch `f10/make-093-penyajian-berkas` @`29ca410c`, **belum** di
`main`). Pola: Checklist QA Lapis 1 Fase 7 (`docs/fase7/Checklist_QA_Lapis1_Fase7.md`; F-10 dikecualikan di `:16`).
Checklist ini **disepakati reviewer CR sebelum QA dimulai**; pertanyaan pra-QA dan keputusannya di §K.

**Status (13-11-2026).** Belum dieksekusi. Tahap 1 sudah di `main`: MAKE-090 BE (`fa1f6686`) dan MAKE-091 FE (`483c5892`).
MAKE-093 (Nginx, README-deploy §1l, ADR-038, koreksi §7.5) masih di branch. G10-4 (verifikasi Nginx server dev) belum
dijalankan operator/Horii. Tahap 2 (MAKE-092 BE di branch `f10/make-092-arsip-aksi-be` @`c49d5a42`, MAKE-096 FE di branch
`f10/make-096-arsip-aksi-fe` @`149b816a`) sudah dimulai tetapi belum ada di `main`: semua butir bertanda
**(Tahap 2 — sesudah MAKE-092/096 di main)** baru dijalankan sesudah kedua paket itu di-merge. Nama testid/endpoint
Tahap 2 di checklist ini diambil dari PRD §8.1 dan §9.3, sudah dicocokkan dengan kedua branch itu, dan dicocokkan ulang
dengan SHA yang di-merge sebelum dijalankan (§K K-07).

**Cakupan.** Seluruh scope F-10: indeks arsip (folder + daftar), dua jalur buka berkas (legacy publik lewat Nginx, unggahan
v2 privat lewat endpoint `buka` + audit + throttle), catatan pembukaan daftar F10-17, halaman FE (route, pemilih pegawai,
folder, tabel, ⋮, tombol header), dan aksi Tahap 2 (Edit, Hubungkan, Hapus, Ekspor, filter "Belum terhubung"). Di luar
cakupan: QR surat cuti ber-TTE legacy (B10-2), blocker deploy B10-1..B10-3 (syarat cutover, **bukan** sign-off, PRD §11.4),
eksekusi `lampiran:tarik` data nyata (cutover), dan MTC-046 (fase Manual Testing tersendiri, PRD §11.3).

**Cara membaca nomor butir.** Awalan bagian: `GF` gerbang, `A` akses & pintu masuk, `B` folder & daftar, `C` status berkas
& URL, `D` catatan pembukaan daftar, `N` jalur publik Nginx, `P` jalur privat `buka` (API), `F` jalur privat (FE), `R`
pratinjau tiga browser, `V` tampilan & viewport, `M` matriks role 1–8, `W`/`E`/`H`/`X`/`Y` aksi Tahap 2, `Z` regresi & efek
yang diterima, `BB` daftar "Bukan bug". Butir bertanda **[S]** wajib di **server dev dengan Nginx** (G10-4). PRD menjalankan
seluruh QA Lapis 1 di server dev dengan Nginx, bukan rute dev (§7.4 `:342`, §11.3 `:575`); menjalankan butir lain di mesin
lokal adalah usulan yang menunggu keputusan (§K K-11). Kode data (mis. `AR1`, `PA`) menunjuk tabel "Data uji gabungan".

## Lingkungan & persiapan

**Server dev dengan Nginx (wajib untuk butir [S]; PRD §7.4 akhir, §11.3).** `main` terbaru terdeploy lewat hook
(README-deploy §2). Prasyarat sebelum butir [S]: MAKE-093 di `main` dan blok Nginx `location ^~ /berkas/` + `map` + snippet
header terpasang sesuai README-deploy §1 dan §1l (branch `29ca410c`: `README-deploy.md:546` dst.), hasil verifikasi operator
tercatat di tabel "Hasil verifikasi" §1l (G10-4). `.env` server: `arsip.penyajianBerkasLegacy = publik`,
`arsip.dasarUrlBerkas = /berkas`, `arsip.sajiBerkasDev = false` (`backend/.env.example:119-121`). Host, akun, dan path server
**tidak** ditulis di checklist maupun laporan.

**Mesin lokal (usulan alternatif untuk butir non-Nginx; ◻ §K K-11 karena menyimpang dari PRD §7.4 `:342`).** App demo reviewer `wt/demo` pada `main` terbaru, DB
`simpeg_v2_t_app_demo` @3307, backend 8120, frontend 5190, captcha mock, `CI_ENVIRONMENT = development`, data sintetis
(prosedur reset Checklist Fase 7 "Lingkungan & persiapan"). Untuk membuka berkas legacy tanpa Nginx: `.env` demo
`arsip.sajiBerkasDev = true` dan `arsip.dasarUrlBerkas = http://localhost:8120/berkas` (rute dev `GET berkas/(:any)`,
`backend/app/Config/RoutesPegawai.php:105-109`; hanya dua pola legacy, tanpa cek izin, header sama dengan Nginx,
`backend/app/Controllers/BerkasDevController.php`). Batasan: nama berkas berkarakter di luar `permittedURIChars` (mis.
`( ) , ' & +`) tidak bisa dibuka lewat rute dev (PRD §7.4); butir yang menguji header/hak baca/log Nginx **tidak** sah di
lokal. Kembalikan `arsip.sajiBerkasDev = false` sesudah QA.

**`ACTIVE_PHASE`.** Di `main` nilainya **5** (`frontend/src/shared/layouts/nav.config.ts:95`); menu "Arsip" ber-`phase: 7`
(`nav.config.ts:171`), jadi menu tersembunyi (QF10-3: ikut gerbang Fase 7, PRD `:637`). `ACTIVE_PHASE` dinaikkan ke 7 hanya
saat rilis (`docs/fase7/Laporan_Akhir_Fase7.md:168`). Halaman tetap terbuka lewat URL langsung dan tombol "Arsip" di header
Detail Pegawai (PRD §9.1). Butir menu (A9) dijalankan di mesin lokal dengan `ACTIVE_PHASE = 7` **di worktree demo saja, tanpa
commit** (pola Checklist Fase 7 A-K2), lalu dikembalikan ke 5. Lihat §K K-02.

**`.env` uji sementara** (restart backend, kembalikan sesudah butirnya): `arsip.penyajianBerkasLegacy = privat` (rollback
F10-24; butir P11–P13, C9). Jangan ubah `.env` server dev tanpa izin operator (§K K-04).

**Isi kolom Hasil:** ✅ lolos / ❌ gagal (+ nomor temuan di §T) / — tidak berlaku / **[test-otomatis]** (diterima lewat
bukti test otomatis gate `main`, lihat §K). Bukti: tangkapan layar UI, respons API, keluaran `curl -I`, atau baris DB —
**tanpa** NIP/NIK, host, token, path berkas, nama berkas nyata, maupun isi berkas. Sebut kodenya (mis. "AR2", "NIP PA").

### Alat bantu

**Rujukan singkat.** `ArsipService.php`, `OtorisasiArsip.php` = `backend/app/Libraries/Kepegawaian/Arsip/…`; `Config/Arsip.php`
= `backend/app/Config/Arsip.php`; `RoutesPegawai.php` = `backend/app/Config/RoutesPegawai.php`; `PegawaiScope.php` =
`backend/app/Libraries/Kepegawaian/Scope/PegawaiScope.php`; berkas FE tanpa folder (`*.vue`, `*.ts`, `*.spec.ts`) =
`frontend/src/features/kepegawaian/…` (akar fitur, `views/`, `components/`, `components/arsip/`, `composables/`,
`__tests__/`); `nav.config.ts` =
`frontend/src/shared/layouts/nav.config.ts`.

**Memanggil API** (Git Bash). Token akses ada di cookie httpOnly `access_token` (`backend/app/Config/Jwt.php:42`; DevTools →
Application → Cookies); filter JWT juga menerima header `Authorization: Bearer` (`backend/app/Filters/JwtAuthFilter.php:16`,
`:80`). Token **jangan** ditempel di laporan. Amplop galat `{"status":"error","message":…,"errors":{…}}` (catatan O-7
Checklist Fase 7); 403 dari filter role berpesan `Forbidden` (`backend/app/Filters/RoleFilter.php:38`).

```bash
B=<dasar API>/api/v1            # server dev: https://<host>/api/v1 ; lokal: http://localhost:8120/api/v1
T="Authorization: Bearer <JWT>"
curl -s -H "$T" "$B/pegawai/<NIP PA>/arsip"                                             # folder
curl -s -H "$T" "$B/pegawai/<NIP PA>/arsip/berkas?id_jenis_rwy=9&page=1&per_page=25"    # daftar
curl -s -H "$T" "$B/pegawai/<NIP PA>/arsip/berkas?id_jenis_rwy=tanpa_jenis"
curl -s -H "$T" "$B/pegawai/<NIP PA>/arsip/berkas?q=%25&per_page=500"                    # q literal + per_page dijepit
curl -s -D - -o /dev/null -H "$T" "$B/pegawai/<NIP PA>/arsip/berkas/<id AR1>/buka"       # header buka
for i in 1 2 3 4 5 6 7; do curl -s -o /dev/null -w "%{http_code}\n" -H "$T" "$B/pegawai/<NIP PA>/arsip/berkas/<id AR1>/buka"; done
```

**Header Nginx [S]** (jalankan di mesin reviewer; `H=https://<host>`; perintah lengkap di README-deploy §1l "Verifikasi"):

```bash
curl -sI "$H/berkas/<path AR2 ter-encode>"          # 200 application/pdf inline + 5 header
curl -sI "$H/berkas/2026/11/<kunci AR1>.pdf"         # 404 (kunci v2 tidak disajikan Nginx)
```

**Audit** (baca-saja; kolom `id_pengguna_actor`, `nip_actor`, `entity`, `entity_id`, `event`, `after_json`, `created_at`,
`backend/app/Models/AuditLogModel.php:44-53`):

```sql
SELECT id_log, id_pengguna_actor, entity, event, JSON_KEYS(after_json) AS kunci_after, after_json, created_at
FROM audit_logs WHERE entity IN ('arsip_lihat','arsip_unduh') ORDER BY id_log DESC LIMIT 20;
```

Di laporan cukup sebut jumlah baris, `entity`, `event`, kunci `after_json`, dan bahwa `entity_id` = NIP sasaran (tanpa
menulis NIP-nya).

**Berkas uji** (di folder kerja sementara, bukan folder repo; lalu ditaruh di akar lampiran sesuai kolom Path tabel data):

```bash
php -r '$i=imagecreatetruecolor(600,400); imagestring($i,5,20,20,"[QA-F10] contoh",0xFFFFFF);
imagepng($i,"uji.png"); imagejpeg($i,"uji.jpg"); imagegif($i,"uji.gif");'
# PDF 1 halaman: cetak halaman teks "[QA-F10] contoh" ke PDF (Print to PDF), simpan sebagai uji.pdf; salin juga sebagai UJI.PDF
printf '<html><body onload="alert(1)">uji</body></html>' > uji.html
printf '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>' > uji.svg
printf '<?php echo 1;' > uji.php
cp uji.pdf uji.docx                                   # isi apa saja; yang diuji jalur unduh menurut ekstensi
```

## Data uji gabungan

Semua data **sintetis** dengan penanda `[QA-F10 <kode>]` di `tag`/nama; NIP pegawai uji = NIP sintetis DB demo (atau NIP
sintetis yang dibuat khusus di server dev, §K K-03). Skrip penanam data dan berkas (usulan `qa_f10.php` di folder demo-seed,
idempoten, ber-guard DB) **belum ada** (§K K-03). Path ditulis relatif akar lampiran (`writable/uploads/lampiran`;
server: `shared/writable/uploads/lampiran`). `<kunci>` = 32 hex acak.

| Kode | Data | Untuk butir |
|---|---|---|
| Akun | Lokal: `demo.admin` 1, `demo.pegawai` 2 (= pemilik PA), `demo.pemohon` 2 (pegawai lain), `demo.satker` 3 (unit 1/satker 1, lingkup memuat PA), `demo.eselon1` 4, `demo.menteri` 5, `demo.ptt` 6 (= PB), `demo.pppk` 7 (= PC), `demo.pimpinan` 8 (akun di `AKUN_DEMO.md`, password tidak ditulis). Server dev: akun uji setara per role (§K K-03) | semua |
| U3d | (opsional) akun role 3 **tanpa satker** dengan unit yang jalur daftar ≠ jalur detail (`PegawaiScope.php:30-34`), + pegawai sintetis yang tampil di daftarnya tetapi di luar lingkup detail | A3 |
| PA | Pegawai pemilik arsip utama (`demo.pegawai`), memuat AR1–AR33 + AR-P | A, B, C, D, P, F, R, W–Y |
| PB / PC | `demo.ptt` / `demo.pppk`, masing-masing 1 berkas v2 (kode 38) + 1 berkas legacy (kode 0) | A5, M, H6 |
| PL | Pegawai sintetis di satker/unit **lain** (di luar lingkup `demo.satker`), 2 berkas | A2, M3, W4 |
| PD | Pegawai sintetis tanpa baris `document_attachment` | B7 |
| AR1 | Kode 9 Jabatan, **kunci v2** `2026/11/<kunci>.pdf`, berkas ada, `tag` terisi | C, P, F, R |
| AR2 | Kode 9 Jabatan, **legacy** `arsip/<NIP PA>/jabatan/sk_uji.pdf`, berkas ada | C, N, R |
| AR3 | Kode 11 KP, legacy `arsip/<NIP PA>/kp/SK_UJI.PDF` (ekstensi huruf besar), berkas ada | C6, N3 |
| AR4 / AR5 / AR6 | Kode 14 Pendidikan, legacy `.jpg` / `.png` / `.gif`, berkas ada | N, R |
| AR7 | Kode 38 Data Umum, kunci v2 `.png`, berkas ada | P, R |
| AR8 | Kode 37 Status Data Umum, kunci v2 `.pdf`, berkas ada | P, E4 |
| AR9 | Kode 0 Belum Terhubung, legacy `arsip/<NIP PA>/lain/yatim.pdf`, berkas ada | B, H, BB |
| AR10 | Kode 0, kunci v2 `.pdf`, berkas ada | H, X5 |
| AR11 | Kode 0, legacy `.docx`, berkas ada | C6, N4, X |
| AR12 | Kode 22 Kursus/Seminar, kunci v2 `.docx`, `tag` "[QA-F10 AR12] SK Jabatan" (tanpa ekstensi) | C6, P5, F4 |
| AR13 | Kode 5 Pelatihan, legacy cocok regex, **berkas tidak ada** di disk | C1 |
| AR14 | Kode 10 KGB, kunci v2, **berkas tidak ada** | C1, P8 |
| AR15 | Kode 13 Organisasi, path legacy bersegmen berawalan titik `arsip/<NIP PA>/.x/a.pdf` | C2 |
| AR16 / AR17 | Kode 20 Keluarga, path `kolom/…` / `form-digital/…` (berkas ada) | C2, N |
| AR18 | Kode 32 SKP, `path` kosong (D1 v1.4: 2 baris seperti ini) | C2 |
| AR19 | `id_riwayat` **NULL** (D1 v1.4: 2 baris) | B4, E6 |
| AR20 | Kode 1 Alamat, kunci v2 `.pdf`, `id_entri` = riwayat **status 10** | B3, P9 |
| AR21 | Kode 14, kunci v2, `id_entri` riwayat **tidak ada** | B3, P9 |
| AR22 | Kode 13, legacy, `id_entri` = riwayat **pengajuan** (status 0) | B3 |
| AR23 | Kode 7 Hukuman Disiplin, kunci v2, `id_entri` riwayat hukdis Disetujui RW2 | B2, BB |
| AR24 | Kode 36 Pemberhentian Sementara JFT, `id_entri` riwayat jabatan | B5 |
| AR25 | Kode 45 Surat Cuti (ber-TTE), kunci v2 `.pdf` | B1, P, BB |
| AR26 | Kode 42 PMK **legacy** `arsip/…` `.pdf` (AR26-42) + kode 43, 46, 49 kunci v2 | B1, C6, N1, P, BB |
| AR27 | Kode 3 Anak, `id_entri` = `detail_anak` milik PA (ringkasan lewat induk) | B5 |
| AR28 | Kode 0, legacy `.html` dan `.svg` (skrip `onload`) | N5, R4 |
| AR29 | Kode 9, kunci v2 dengan `tag` **dan** `display_name` kosong | C5 |
| AR30 | Kode 0, legacy, `tag` "[QA-F10 AR30] 100%_uji <b>x</b>" | B6, E2 |
| AR31 | Kode 14, legacy `assets/upload/<…>/uji.jpg` (F10-33; D1 v1.4: 0 baris di salinan dev → sintetis) | C7, N2 |
| AR32 | Kode 9, path legacy berawalan `/`, berspasi, dan ber-`\` | C7 |
| AR33 | Kode 0, legacy `.php` | N5 |
| AR34 | Kode 9 Jabatan, **kunci v2** `.jpg`, berkas ada | R2 |
| AR-P | 30 baris kode 32 SKP legacy (`[QA-F10 P01..P30]`) untuk paginasi | B6 |
| RW1 | Riwayat Jabatan (9) PA **Disetujui** | H2, H3, H7 |
| RW2 | Riwayat Hukdis (7) PA Disetujui | H, BB |
| RW3 | Riwayat Organisasi (13) PA **bukan** Disetujui | H4 |
| RW4 | Riwayat Jabatan milik `demo.pemohon` | H4 |
| SQ | Isi `siasn_upload_arsip` sebelum/sesudah Hubungkan (jumlah baris saja) | H8 |

## GF. Gerbang & prasyarat (PRD §0, §11.4)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| GF1 | G10-1 | Cek PRD F-10 di `main` (v1.3) dan status v1.4 | v1.3 di `main`; v1.4 (CR-122) di `main` atau tercatat sebagai branch ber-izin merge; checklist mengacu versi yang berlaku saat QA | |
| GF2 | G10-2 | Cek kode izin workload Tahap 1 dan Tahap 2 | Izin Tahap 1 (MAKE-090/091/093) dan Tahap 2 (MAKE-092/096) tercatat oleh reviewer | |
| GF3 | — | Cek isi `main`: MAKE-090, MAKE-091, MAKE-093; Tahap 2: MAKE-092, MAKE-096 | Tahap 1 + MAKE-093 di `main` sebelum bagian N/R; MAKE-092/096 di `main` sebelum bagian W–Y | |
| GF4 | G10-4 [S] | Baca tabel "Hasil verifikasi" README-deploy §1l | Terisi operator/Horii untuk server dev: `curl -I` (header + 404 kunci v2), hak baca user Nginx, Chrome/Edge/Firefox, nilai CSP akhir | |
| GF5 | §11.1–§11.2 | Cek gate penuh `./check.sh` dan CI `main` sesudah paket terakhir | Lolos (PHPUnit, PHPStan, PHP-CS-Fixer, ESLint, vue-tsc, Vitest, build); termasuk regresi B-18, Arsip Data Umum, SIASN arsip, `s_stat`, kelengkapan beranda | |
| GF6 | §11.4 | Cek B10-1..B10-3 | Tercatat sebagai blocker deploy/cutover, **tidak** menahan sign-off | |
| GF7 | — | Cek status checklist ini | Checklist disepakati reviewer CR; butir ◻ §K (K-01..K-11) sudah diputus | |
| GF8 | K-03 | Cek data uji di lingkungan QA | Data uji sintetis (baris `document_attachment` + berkas sintetis sesuai tabel "Data uji gabungan") dan akun per role 1–8 tersedia, tanpa NIP nyata | |

## A. Akses & pintu masuk (Tahap 1; AC1-01..AC1-04, AC1-14, §5.1, §9.1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A1 | AC1-01 | `demo.admin`, `demo.eselon1`, `demo.menteri`: buka `/arsip/<NIP PA>` dan `/arsip/<NIP PL>` (URL langsung) | Folder tampil untuk keduanya (role 1/4/5 = semua pegawai) | |
| A2 | AC1-02 | `demo.satker`: buka `/arsip/<NIP PA>` lalu `/arsip/<NIP PL>`; API `GET pegawai/<NIP PL>/arsip` | PA tampil; PL → pesan galat di halaman (`arsip-failure`), API 403 "Anda tidak berhak mengakses data pegawai ini." (`OtorisasiArsip.php:61`) | |
| A3 | AC1-02 | (U3d) cari pegawai di pemilih yang tampil di daftar tetapi di luar lingkup detail, pilih | Halaman Arsip 403 (sama dengan Detail Pegawai) [K]. Bila U3d tidak tersedia → [test-otomatis] (§K K-05) | |
| A4 | AC1-03 | `demo.pegawai`: buka `/arsip`, `/arsip/<NIP pemohon>`, `/arsip/<NIP pemohon>/9` | Dialihkan ke `/arsip/<NIP PA>` (dan `/arsip/<NIP PA>/9`) tanpa pesan galat (`routes.arsip.ts:23-36`) | |
| A5 | AC1-03 | API: `demo.pegawai`, `demo.ptt`, `demo.pppk` → `GET pegawai/<NIP lain>/arsip` dan `…/arsip/berkas` | 403 [V2] | |
| A6 | AC1-04 | `demo.pimpinan` (role 8): buka `/arsip`, `/arsip/<NIP PA>`; API ketiga endpoint Tahap 1 | Halaman terlarang (`meta.roles` 1–7, `routes.arsip.ts:48`); API 403 `Forbidden` (filter route, `RoutesPegawai.php:97`) | |
| A7 | AC1-04 | `demo.pimpinan`: buka Detail Pegawai PA (bila boleh) | Tombol "Arsip" (`pegawai-header-arsip`) tidak tampil (`DetailPegawaiPage.vue:62`) | |
| A8 | AC1-14 | Role 1–7: buka Detail Pegawai PA (pegawai: NIP sendiri) | Tombol lama berlabel **"Arsip Data Umum"**, testid `btn-arsip`, membuka dialog 37/38 (judul "Arsip Data Umum", `ArsipKepegawaianDialog.vue:85`); tombol baru **"Arsip"** (`pegawai-header-arsip`, `PegawaiHeaderCard.vue:164`) menuju `/arsip/<NIP PA>` | |
| A9 | F10-06, QF10-3 | Lokal, `ACTIVE_PHASE = 7` tanpa commit: lihat sidebar tiap role 1–8; lalu kembalikan ke 5 | Menu "Arsip" untuk role 1–7, tidak untuk 8; role 2/6/7 → arsip sendiri, 1/3/4/5 → pemilih. Dengan nilai 5: menu tidak ada, URL langsung tetap terbuka | |
| A10 | §9.2 | Role 1/3/4/5: `/arsip` → ketik < 3 huruf, lalu ≥ 3 huruf NIP/nama PA, pilih saran | < 3 huruf tanpa permintaan; ≥ 3 huruf memanggil `GET pegawai?search=` (`ArsipPemilihPegawai.vue:50`); memilih → `/arsip/<NIP PA>` | |
| A11 | §9.2 | Breadcrumb di `/arsip/<NIP PA>/9` untuk `demo.admin` dan `demo.pegawai` | **Arsip** › **<nama (gelar)>** › **Jabatan**; tautan "Arsip" untuk pegawai berakhir di arsip sendiri | |
| A12 | F10-11 | `demo.admin` dan `demo.pegawai`: periksa `/arsip/<NIP PA>`, `/arsip/<NIP PA>/semua`, dan menu ⋮ (Tahap 1 dan, sesudah MAKE-096 di `main`, Tahap 2) | Tidak ada tombol unggah, dropzone, atau input berkas di halaman Arsip [K]; unggah tetap lewat form riwayat, Arsip Data Umum, dan layanan (PRD `:65`, `:108`) | |

## B. Folder & daftar berkas (Tahap 1; AC1-05, AC1-06, AC1-09, AC1-11, AC1-13, F10-29)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B1 | AC1-05 | `demo.admin` `/arsip/<NIP PA>` + API folder | Folder untuk setiap kode yang punya berkas (riwayat, 0, 37, 38, 42–50, NULL); folder 0 berkas tidak dikirim | |
| B2 | AC1-05, F10-30 | `demo.pegawai` (pemilik) dan `demo.eselon1`: buka folder Hukuman Disiplin | Lampiran AR23 **dan** ringkasan riwayatnya tampil, walau tab hukdis B-18 tersembunyi bagi role itu [K] | |
| B3 | AC1-06, F10-16 | Folder Alamat / Pendidikan / Organisasi | AR20 (status 10), AR21 (`id_entri` tak ada), AR22 (pengajuan) tampil tanpa badge tambahan; `url_berkas` terisi | |
| B4 | AC1-09, F10-29 | Urutan kartu di grid | "Semua berkas" paling atas; jenis menurut `jenis_rwy.order`; "Belum Terhubung" lalu "Tanpa Jenis" (AR19) paling bawah; testid `arsip-folder-semua`, `arsip-folder-<id>`, `arsip-folder-0`, `arsip-folder-tanpa_jenis` (`ArsipFolderGrid.vue:53`) | |
| B5 | AC1-13, F10-22 | Kolom "Data Riwayat" di "Semua berkas" | Ringkasan bentuk `previewRwy` untuk kode 1, 3 (AR27), 5, 7, 9, 36 (AR24 = bentuk 9), 10, 11, 13, 14, 20, 22, 23, 32; kode 37–50 dan baris tak ditemukan "—" | |
| B6 | AC1-11, F10-20/21 | Cari `%`, `_`, `100%_uji`; API `per_page=500`; folder SKP (AR-P) | `%`/`_` literal (hanya AR30 cocok untuk `100%_uji`); `per_page` dijepit 100; tabel 25 baris + `UiPagination` (`ArsipFolderPage.vue:22`, `:148-150`); urut bawaan `id_attachment` menurun | |
| B7 | §9.2 | `demo.admin` `/arsip/<NIP PD>` | "Pegawai ini belum memiliki berkas arsip." (`arsip-kosong`) tanpa grid | |
| B8 | §9.2 | `/arsip/<NIP PA>/abc` | "Folder tidak ditemukan." (`ArsipFolderPage.vue:64`), tanpa panggilan daftar | |
| B9 | AC1-09 | Bandingkan `jumlah` tiap folder dengan hitungan DB per `id_riwayat` NIP PA | Sama; total = jumlah baris NIP PA | |
| B10 | §8.1 | API `id_jenis_rwy=abc`, `sort=x`, `order=x` | 422 dengan `errors.id_jenis_rwy` / `errors.sort` / `errors.order` (`ArsipService.php:317`, `:143`, `:147`) | |
| B11 | F10-21 | Di "Semua berkas" cari (a) potongan `display_name` sebuah baris ber-`tag` kosong, (b) potongan `filename` baris legacy ber-`tag` dan `display_name` kosong, (c) potongan teks kolom "Data Riwayat" yang tidak ada di `tag`/`display_name`/`filename` | (a) dan (b) cocok; (c) tidak cocok (ringkasan riwayat tidak ikut dicari) [V2] | |
| B12 | AC1-13 | Ringkasan riwayat tanpa N+1 query (satu query per kode per halaman) | [test-otomatis] `backend/tests/Kepegawaian/ArsipEndpointTest.php:541` (`testRingkasanKodeLainDanTanpaNPlusSatu`, menghitung query lewat event `DBQuery`) | |

## C. Status berkas, URL, dan kolom tertutup (Tahap 1; AC1-07, AC1-08, AC1-10, AC1-16)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C1 | AC1-07 | Baris AR13, AR14 (UI + API) | `berkas_ada: false`, `status_berkas: "hilang"`, `url_berkas`/`akses_berkas`/`jenis_buka` null, badge "Berkas hilang" (`arsip-badge-hilang-<id>`), ⋮ Buka `disabled`; respons 200; kartu folder menghitung `jumlah_hilang` | |
| C2 | AC1-08 | Baris AR15, AR16, AR17, AR18 | `status_berkas: "tak_dapat_dibuka"`, `url_berkas` null, badge "Tidak dapat dibuka" (`arsip-badge-tak-dapat-dibuka-<id>`), tanpa 500; dihitung di `jumlah_tak_dapat_dibuka`, bukan `jumlah_hilang`; log backend tidak memuat path | |
| C3 | AC1-10 | Simpan JSON folder + daftar "Semua berkas" (semua halaman), cari **kunci** `path`, `url`, `basename`, `document_id` (daftar AC1-10, PRD `:198`), kunci `filename`, kunci `NIP` (huruf besar, nama kolom tabel; pencarian peka huruf besar/kecil), dan basename 32 hex | Tidak ada satu pun; satu-satunya turunan path = `url_berkas` item `publik`. Yang **sah** ada: `pegawai.nip` di respons folder (`OtorisasiArsip.php:71`) dan NIP di dalam `url_berkas` (path legacy `arsip/<NIP>/…`, URL `buka` C4) | |
| C4 | AC1-10, F10-34 | Item AR1 | `akses_berkas: "privat"`, `url_berkas` = `pegawai/<NIP PA>/arsip/berkas/<id>/buka` (tanpa slash awal, tanpa kunci) | |
| C5 | AC1-10, F10-19 | Item AR29 | `nama_arsip` = "Berkas <id_attachment>" | |
| C6 | AC1-16 | Item AR2, AR3, AR11, AR12, AR26-42 | AR2 `publik` + `pratinjau`; AR3 (`.PDF`) terisi + `pratinjau`; AR11 `publik` + `unduh`; AR12 `privat` + `unduh`; AR26-42 (PMK legacy) `publik` + `pratinjau` (§7.5, BB6) | |
| C7 | AC1-16, F10-33 | Item AR31, AR32 | AR31 `publik` (`/berkas/assets/upload/…`); AR32 URL ternormalisasi, tiap segmen ter-encode (spasi → `%20`, `\` → `/`, tanpa `//`) | |
| C8 | §8.1 | API folder | `penyajian_berkas_legacy: "publik"`; `aksi.tulis: false`, `aksi.jenis_hubungkan: []` (Tahap 1) | |
| C9 | F10-24 | Lokal, `.env` `arsip.penyajianBerkasLegacy = privat`: ulangi C6/C7 | Berkas legacy kini `privat` dengan URL `buka`; kunci v2 tidak berubah; `penyajian_berkas_legacy: "privat"`. Kembalikan ke `publik` | |

## D. Catatan pembukaan daftar F10-17 (Tahap 1; AC1-12, F10-26, §8.2)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D1 | AC1-12 | `demo.admin` buka grid folder PA (`/arsip/<NIP PA>`), lalu dari grid buka "Semua berkas" dan cari, dalam 15 menit | Satu baris `arsip_lihat` `create` bila dibuka berurutan dari grid: `entity_id` = NIP PA, `after_json` `{endpoint: "folder", filter:{id_jenis_rwy: null, belum_terhubung: null, ada_pencarian: false}}` (`ArsipService.php:71`), tanpa nama berkas; daftar sesudahnya kena dedup satu kunci untuk folder + daftar (`ArsipService.php:343-366`). Dua baris **boleh** bila URL folder (`/arsip/<NIP PA>/semua` atau `/9`) dibuka langsung, karena permintaan folder dan daftar berangkat bersamaan (`ArsipFolderPage.vue:104-105`; AC1-12 PRD `:200`; BB18) | |
| D2 | AC1-12 | Ulangi D1 dengan `demo.satker`, `demo.eselon1`, `demo.menteri` | Masing-masing satu baris (aktor berbeda) bila dibuka berurutan dari grid; dua baris boleh untuk URL folder langsung (BB18) | |
| D3 | AC1-12 | `demo.pegawai` membuka arsip sendiri; `demo.ptt`, `demo.pppk` sama | Tidak ada baris `arsip_lihat` | |
| D4 | F10-26 | Sesudah > 15 menit, `demo.admin` buka lagi folder PA | Baris `arsip_lihat` baru | |
| D5 | §8.2 fail-closed | Gagal tulis audit → 500 | [test-otomatis] `ArsipEndpointTest::testCatatanPembukaanDaftarFailClosed` (tidak diuji manual: merusak DB) | |
| D6 | AC1-12 | Aktor role 1/3/4/5 yang belum tercatat untuk NIP PA dalam 15 menit memanggil API `…/arsip/berkas?q=uji` **lebih dulu** (tanpa membuka folder) | Satu baris `arsip_lihat`: `after_json.endpoint = "berkas"`, `filter.ada_pencarian: true` (`ArsipService.php:150-154`), tanpa teks `q` di mana pun dalam baris | |

## N. Jalur publik berkas legacy lewat Nginx (Tahap 1; F10-18 (a), F10-25, §7.3, G10-4) — semua [S]

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| N1 | §7.3 | [S] `curl -sI` URL publik AR2, AR4, AR5, AR6, AR26-42 | 200; `Content-Type` `application/pdf` / `image/jpeg` / `image/png` / `image/gif`; `Content-Disposition: inline`; `X-Content-Type-Options: nosniff`; `Cache-Control: no-store`; `Referrer-Policy: no-referrer`; CSP sesuai snippet (atau nilai akhir G10-4) | |
| N2 | F10-33 | [S] `curl -sI` AR31 (`assets/upload/…`) dan AR3 (`.PDF`) | 200, tipe pratinjau, `inline` | |
| N3 | §7.3 | [S] `curl -sI` URL publik tanpa login dan tanpa cookie | 200 (tanpa cek izin, keputusan sadar F10-18 (a); lihat BB1) | |
| N4 | F10-32 | [S] `curl -sI` AR11 (`.docx`) | `application/octet-stream` + `attachment` | |
| N5 | F10-25, R5 | [S] `curl -sI` AR28 (`.html`, `.svg`) dan AR33 (`.php`) | `application/octet-stream` + `attachment`; `.php` tidak dieksekusi (isi mentah, tanpa keluaran PHP) | |
| N6 | F10-18 (b) | [S] `curl -sI /berkas/YYYY/MM/<kunci AR1>.pdf` (berkas ada di disk) | **404** | |
| N7 | §7.3 butir 2 | [S] `curl -sI` AR16 (`kolom/…`), AR17 (`form-digital/…`), AR15 (segmen titik), `/berkas/arsip/<NIP PA>/` (folder), `/berkas/arsip/%2e%2e/%2e%2e/cache/<…>` | 404 (traversal 400/404), tidak pernah isi `writable/cache`, tanpa listing | |
| N8 | AC1-07 | [S] `curl -sI` URL publik AR13 (berkas hilang) | 404 | |
| N9 | §7.3 butir 4 | [S] Operator: `tail` log akses Nginx sesudah N1–N8 | Tidak ada baris `/berkas/` | |
| N10 | §7.3 butir 5 | [S] Operator: `sudo -u <user-nginx> test -r <berkas AR2>` | OK; hak `0640`/`0750` tidak diubah | |
| N11 | §7.3 butir 1, B10-1 | [S] Operator: `find <akar lampiran> -type l` | Kosong | |
| N12 | §9.1 | [S] SPA: muat ulang `/arsip/<NIP PA>/9` di browser | Halaman SPA (bukan 404 Nginx); `/arsip/…` tidak bertabrakan dengan `/berkas/…` | |

## P. Jalur privat `buka` — API (Tahap 1; AC1-17, F10-34..F10-36)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| P1 | AC1-17 | `demo.admin`: `curl -D -` `buka` AR1 | 200; `Content-Type: application/pdf`; `Content-Disposition: inline; filename="…"; filename*=UTF-8''…` (nama = nama arsip + `.pdf`); `nosniff`; `Cache-Control: private, no-store`; `Referrer-Policy: no-referrer`; CSP = `Config\Arsip::CSP_BERKAS` (`Config/Arsip.php:64`) | |
| P2 | AC1-17 | `buka` AR7 (`.png`), AR8 (`.pdf`), AR25 (kode 45), AR26 (43/46/49) | 200, tipe dari daftar tertutup, `inline` | |
| P3 | AC1-17 | `buka` tanpa token | 401 | |
| P4 | AC1-17 | Matriks: role 1/4/5 → PA; role 3 → PA (200) dan PL (403); role 2/6/7 → NIP sendiri (200) dan NIP lain (403); role 8 → 403 | Sesuai §12.1 | |
| P5 | F10-34 (4) | `buka` AR12 (`.docx`, `tag` tanpa ekstensi) | `application/octet-stream` + `attachment`, nama berkas berakhiran `.docx` | |
| P6 | AC1-17 | `buka` `{id}` milik PL lewat `pegawai/<NIP PA>/…` | 404 "Arsip tidak ditemukan." (`ArsipService.php:229`) | |
| P7 | F10-34 (3) | `buka` AR2 (legacy) saat saklar `publik` | 404 "Berkas arsip tidak tersedia." (`ArsipService.php:237`) | |
| P8 | AC1-17 | `buka` AR14 (hilang), AR15 (tak cocok regex) | 404, log tanpa path | |
| P9 | AC1-17, F10-16 | `buka` AR20 (riwayat status 10), AR21 (`id_entri` tak ada) | 200 (berbeda dengan unduh B-18 yang 404, lihat BB9) | |
| P10 | F10-35 | Sesudah P1–P9, cek `audit_logs` | Satu baris `arsip_unduh` `create` per 200 (termasuk `demo.pegawai` membuka miliknya sendiri), `entity_id` = NIP sasaran, `after_json` = `{id_attachment, id_riwayat, jenis_buka}` saja; 401/403/404 tidak menulis baris | |
| P11 | F10-24 | Lokal, saklar `privat`: `buka` AR2, AR31 | 200 lewat `buka` dengan header, audit, throttle yang sama | |
| P12 | F10-36 | Prasyarat: tunggu ≥ 60 detik sejak `buka` terakhir akun itu (P1–P11 memakai ember yang sama, `ArsipService.php:241`), atau pakai akun yang belum membuka. `demo.admin`: 7 permintaan `buka` AR1 beruntun tanpa jeda | 6 × 200 lalu 429 "Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit." (`Config/Arsip.php:82`, `:84`) | |
| P13 | F10-36 | Tunggu ±10 detik sesudah 429, `buka` sekali; lalu langsung sekali lagi | 200 lalu 429 (token bucket, 1 jatah per 10 detik) | |
| P14 | F10-36 | Saat jatah habis: `buka` AR14 (404) dan `buka` `{id}` NIP lain (404/403) | Tetap 404/403, bukan 429 (penolakan pasti dicek sebelum throttle, `ArsipService.php:241`) | |
| P15 | F10-36 | Habiskan jatah `buka`, lalu unduh Dokumen F-09 dengan akun yang sama | Unduhan Dokumen tidak terkena 429 dari ember arsip (ember terpisah) | |
| P16 | F10-35 | `record()` mengembalikan 0 → tetap 200 | [test-otomatis] `ArsipEndpointTest::testBukaAuditNolTetapBerhasil` | |
| P17 | AC1-17 | Satu akun (ember per akun): `demo.pegawai` (pemilik PA), jatah penuh (tunggu > 60 detik sejak `buka` terakhir akun itu). 10× `buka` AR14 (404), 3× `buka` lewat `pegawai/<NIP pemohon>/…` (403), lalu langsung 6× `buka` AR1 | Keenam `buka` AR1 = 200; baru permintaan ke-7 yang 429 (penolakan 403/404 tidak memakan jatah, PRD `:205`, `ArsipService.php:240-241`) | |
| P18 | F10-30, §7.5 | `demo.eselon1` (role 4) dan `demo.menteri` (role 5): `buka` AR25 dan AR26-43 lewat Arsip; lalu endpoint berkas layanan cuti/PMK untuk berkas yang sama dengan akun itu | Lewat Arsip 200; endpoint layanan mengikuti aturannya sendiri (ditolak bila role itu tidak berhak di layanan) — perbedaan ini sesuai keputusan (BB6) | |

## F. Jalur privat — FE popup + blob (Tahap 1; AC1-18, F10-37, §9.3)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| F1 | AC1-18 | ⋮ (`arsip-actions-<id AR1>`, aria-label "Aksi untuk <nama arsip>") → Buka, tab Network terbuka | Popup 600×600 `arsip-popup` langsung terbuka dengan "Memuat berkas…", lalu pratinjau PDF (URL `blob:`); permintaan `buka` lewat klien API | |
| F2 | AC1-18 | ⋮ → Buka AR2 (publik) | `window.open` URL publik (popup 600×600, `noopener`), tanpa permintaan API | |
| F3 | AC1-18 | Selama AR1 dimuat, buka ⋮ baris privat lain dan baris publik | Buka privat lain `disabled`; Buka publik tetap aktif (`useBukaBerkasArsip.ts:97-100`) | |
| F4 | AC1-18 | ⋮ → Buka AR12 (privat `unduh`) | Tanpa popup; berkas terunduh dengan nama berakhiran `.docx` | |
| F5 | AC1-18 | Blokir popup di pengaturan browser, ⋮ → Buka AR1 | Pesan "Popup diblokir browser. Izinkan popup untuk situs ini." (`arsip-buka-galat`), tanpa permintaan API | |
| F6 | AC1-18 | Prasyarat: ≥ 60 detik sejak `buka` terakhir akun itu (lihat P12). Buka 7 berkas privat berbeda beruntun; klik berikutnya sesudah berkas sebelumnya tampil (selama satu berkas privat dimuat, Buka privat lain `disabled`, `useBukaBerkasArsip.ts:97-100`) | Ke-7: popup tertutup, pesan 429 di halaman (`UiNotice`, bisa ditutup), bukan JSON mentah | |
| F7 | AC1-18 | Hapus cookie `access_token` (cookie refresh dibiarkan), ⋮ → Buka AR1 | Refresh berhasil; pratinjau tampil; tanpa JSON 401 mentah | |
| F8 | AC1-18 | Hapus semua cookie sesi, ⋮ → Buka AR1 | Popup tertutup; halaman dialihkan ke `/login` tanpa pesan tambahan | |
| F9 | F10-37 (6) v1.4 `:140` (bukan §9.3 `:521`, yang belum diselaraskan, K-08) | 403 `password_change_required` saat Buka | Popup tertutup, pesan server atau cadangan "Anda harus mengganti password terlebih dahulu." tampil (tidak memicu handler interceptor). Bila sulit dipicu manual → [test-otomatis] `useBukaBerkasArsip.spec.ts:264`, `:274` | |
| F10 | F10-37 (5) | Buka AR1, tunggu > 60 detik, lalu buka lagi; pindah halaman saat berkas dimuat | Pratinjau tetap tampil sebelum jeda; sesudah pindah halaman popup basi tertutup. Pencabutan object URL dibuktikan [test-otomatis] `useBukaBerkasArsip.spec.ts:168`, `:183`, `:340` | |
| F11 | AC1-18 | Ganti halaman/pencarian sesudah pesan galat tampil | Pesan galat Buka hilang (`ArsipBerkasTable.vue:31-36`) | |
| F12 | AC1-18 | Tipe blob pratinjau dari daftar tertutup menurut ekstensi, bukan dari tipe respons (respons `text/html` bernama `.pdf` → `application/pdf`; ekstensi lain → `application/octet-stream`; PRD `:569`) | [test-otomatis] `useBukaBerkasArsip.spec.ts:148` | |

## R. Pratinjau di Chrome, Edge, Firefox (Tahap 1; F10-10, G10-4, §11.3) — semua [S]

Satu kolom hasil per butir; tuliskan hasil per browser (mis. "C ✅ / E ✅ / F ❌ T-0x").

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| R1 | F10-10 | [S] Jalur publik: ⋮ → Buka AR2 (PDF), AR4 (JPG), AR5 (PNG) | Pratinjau tampil di popup di ketiga browser | |
| R2 | F10-10 | [S] Jalur privat: ⋮ → Buka AR1 (PDF), AR7 (PNG), AR34 (JPG kunci v2) | Pratinjau tampil (popup + blob) di ketiga browser | |
| R3 | F10-37 (5) | [S] Popup blob PDF: tombol unduh dan cetak penampil PDF, **sebelum** dan **sesudah** 60 detik | Berfungsi; bila gagal sesudah pencabutan, catat per browser di §T dan di tabel G10-4 | |
| R4 | R5 | [S] ⋮ → Buka AR28 (`.html`, `.svg`) | Terunduh, tidak dirender, tidak ada `alert` | |
| R5 | F10-34 (4) | [S] Dalam keadaan login, buka `api/v1/pegawai/<NIP PA>/arsip/berkas/<id AR1>/buka` langsung sebagai tab | Pratinjau tampil (CSP endpoint tidak mematahkan penampil); bila patah, catat nilai CSP yang dipakai (pratinjau didahulukan) | |
| R6 | §5.1 | Peramban ponsel (atau emulasi): ⋮ → Buka AR2 dan AR1 | Terbuka sebagai tab (bukan bug, BB15) | |

## V. Tampilan & viewport (AC1-15, §9.2, §9.3)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| V1 | AC1-15 | Viewport **1440** px: `/arsip`, `/arsip/<NIP PA>`, `/arsip/<NIP PA>/semua` | Tanpa scroll horizontal halaman | |
| V2 | AC1-15 | Viewport **390** px: halaman yang sama | Tanpa scroll horizontal halaman; grid folder satu kolom (`ArsipFolderGrid.vue:48`); tabel menggulir di dalam kartu, kolom Aksi **sticky** di kanan (`ArsipBerkasTable.vue:61`, `:92`) | |
| V3 | §9.2 | Nama arsip/ringkasan panjang (AR30) | Terpotong rapi + tooltip; `<b>` tampil sebagai teks (tanpa `v-html`) | |
| V4 | §9.3 | Menu ⋮ Tahap 1 | Hanya item "Buka" (`ArsipBerkasTable.vue:41`); `disabled` bila hilang/tak dapat dibuka | |
| V5 | §9.2 | Keadaan memuat / kosong / galat (folder & daftar, putus jaringan) | Skeleton; "Belum ada berkas." / "Tidak ada berkas yang cocok dengan pencarian."; pesan galat + "Coba lagi" (`arsip-coba-lagi`) | |
| V6 | §9.3 (Tahap 2 — sesudah MAKE-092/096 di main) | Ulangi V1–V2 dengan filter, tombol ekspor, dan dialog Edit/Hubungkan/Hapus terbuka | Tanpa scroll horizontal; dialog terbaca di 390 px | |

## M. Matriks role 1–8 (PRD §12.1)

Uji tiap baris dengan API (dan UI bila ada). "sendiri" = NIP akun itu; "lingkup" = PA (dalam) dan PL (luar) untuk `demo.satker`.
Kolom endpoint Tahap 2 dijalankan **(Tahap 2 — sesudah MAKE-092/096 di main)**.

| # | Role (akun) | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| M1 | 1 `demo.admin` | Folder/daftar/`buka` PA & PL; T2: ekspor, `opsi-hubungkan`, PATCH (termasuk kode 37), `hubungkan`, DELETE | Semua 200/berhasil; kode 37 boleh diubah | |
| M2 | 2 `demo.pegawai` | Sendiri vs `demo.pemohon` | Sendiri 200; NIP lain 403. T2: ekspor/`opsi-hubungkan`/PATCH/DELETE sendiri boleh, PATCH kode 37 → 403; `hubungkan` hanya ke tujuan tidak terkunci (saat ini 38) | |
| M3 | 3 `demo.satker` | PA (dalam lingkup) vs PL (luar) | Baca/`buka`/ekspor PA 200, PL 403. T2 tulis dan `opsi-hubungkan`: lingkup **ubah**; PATCH kode 37 → 403 | |
| M4 | 4 `demo.eselon1` | PA & PL | Baca/`buka`/ekspor 200. T2 tulis + `opsi-hubungkan` 403; item tulis ⋮ `hidden` | |
| M5 | 5 `demo.menteri` | PA & PL | Sama dengan M4 | |
| M6 | 6 `demo.ptt` | Sendiri vs PA | Sama dengan M2 | |
| M7 | 7 `demo.pppk` | Sendiri vs PA | Sama dengan M2 | |
| M8 | 8 `demo.pimpinan` | Semua endpoint F-10 | 403 `Forbidden` (filter route); tanpa menu/tombol | |
| M9 | — | URL publik `/berkas/…` berkas legacy [S] | Tanpa cek izin untuk siapa pun (BB1) | |
| M10 | §11.1 | Baris kontrak role di test | [test-otomatis] `backend/tests/feature/KontrakMatriksRoleTest.php:383-385` (Tahap 1) + baris Tahap 2 MAKE-092 | |

## W. Tahap 2 umum (Tahap 2 — sesudah MAKE-092/096 di main; AC2-01..AC2-04, AC2-11, F10-38)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| W1 | AC2-01 | Daftar route `php spark routes` (filter `arsip`) | Aksi tulis hanya `PATCH …/arsip/berkas/{id}`, `POST …/hubungkan`, `DELETE …/arsip/berkas/{id}`; tidak ada GET yang mengubah data; tanpa token CSRF terpisah (BB16) | |
| W2 | AC2-02 | Role 4/5/8 → semua aksi tulis + `opsi-hubungkan` | 403; di FE item Edit/Hubungkan/Hapus `hidden` untuk 4/5 | |
| W3 | AC2-03 | Role 2/6/7 → NIP lain (403); `{id}` PL lewat `pegawai/<NIP sendiri>/…` (404) | 403 / 404 (anti-IDOR) | |
| W4 | AC2-04 | `demo.satker` → aksi tulis PL | 403 | |
| W5 | AC2-11 | Buka ⋮ baris kode 0 dan baris terhubung (pilih item lewat `data-action`) | Urutan Edit → Buka → Hubungkan → Hapus (`danger`, garis pemisah); Hubungkan/Hapus `hidden` pada baris bukan kode 0; selama satu aksi berjalan, item tulis lain `disabled` | |
| W6 | §8.1 | API folder dengan akun role 1, 2, 4 | `aksi.tulis` sesuai role; `aksi.jenis_hubungkan` role 2/6/7 = kode tidak terkunci saja (saat ini `[38]`); role 4/5 kosong | |
| W7 | §8.1 | API daftar: `aksi.edit/hubungkan/hapus` per baris | Sesuai role, lingkup ubah, kode 0, dan kode 37 (role ≠ 1 → `edit: false`) | |

## E. Edit / Ubah nama (Tahap 2 — sesudah MAKE-092/096 di main; AC2-08, AC2-13, F10-31)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| E1 | AC2-08 | `demo.pegawai` Edit AR2 (terhubung ke riwayat Disetujui) → nama baru | Berhasil [K] (BB12); satu audit `update` `document_attachment` | |
| E2 | AC2-08 | Edit dengan `<b>x</b>`, byte kontrol, spasi di tepi | Tag HTML & byte kontrol dibuang, di-trim; tampil sebagai teks biasa | |
| E3 | AC2-08 | Edit kosong, hanya spasi, hanya `<b></b>`, 256 karakter | 422 `errors.tag`; 255 karakter berhasil | |
| E4 | AC2-13, F10-31 (b) | Edit AR8 (kode 37): `demo.pegawai`, `demo.satker`, `demo.ptt`, `demo.pppk`; lalu `demo.admin` | Role 2/3/6/7: item Edit `hidden`, API 403 "Anda tidak berhak mengubah arsip data umum pegawai ini."; role 1 berhasil | |
| E5 | QF10-8 | Edit AR7 (kode 38) oleh `demo.pegawai` dan `demo.satker` | Berhasil (kode 38 ikut legacy, tanpa `bolehTulis()`) | |
| E6 | F10-29 | Baris AR19 (Tanpa Jenis) | Hanya Edit yang ditawarkan | |
| E7 | AC2-08 | Form Arsip Data Umum (dialog 37/38) | Batas 100 karakter di form itu tidak berubah | |

## H. Hubungkan (Tahap 2 — sesudah MAKE-092/096 di main; AC2-05..AC2-07, AC2-12, AC2-13, F10-28, F10-38)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| H1 | F10-28 | `demo.admin` ⋮ → Hubungkan AR9: daftar jenis | 1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, 38; tanpa 37, 39–42, 43–50 | |
| H2 | AC2-06 | `demo.admin` Hubungkan AR9 → Jabatan RW1 | Berhasil; `id_riwayat` 9, `nama_riwayat` = "Jabatan", `id_entri` = RW1; satu audit `update` | |
| H3 | AC2-12 | Sesudah H2 | Baris pindah ke folder Jabatan; jumlah folder Belum Terhubung/Jabatan berubah tanpa muat ulang penuh | |
| H4 | AC2-06 | API `hubungkan` ke RW3 (bukan Disetujui), RW4 (NIP lain), id tak ada; jenis 37 / 43 | 404 untuk RW3/RW4/tak ada; 422 untuk jenis di luar F10-28 | |
| H5 | AC2-05 | `hubungkan` dan DELETE pada AR2 (sudah terhubung) | 422 `errors.id_attachment` "Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat." | |
| H6 | AC2-13 | `demo.pegawai` / `demo.ptt` / `demo.pppk`: dialog Hubungkan; API `hubungkan` ke RW1 dan `opsi-hubungkan?id_riwayat=9` | Dialog hanya menawarkan Data Umum (38); API 403 "Data yang sudah disetujui tidak dapat diubah."; ke 38 berhasil (`id_entri` NULL) | |
| H7 | AC2-13 | `demo.satker` Hubungkan AR10 → RW1 | Berhasil (role 3 tidak terkena kunci) | |
| H8 | AC2-07 | Hitung `siasn_upload_arsip` sebelum/sesudah H2 (SQ) | Jumlah tidak berubah oleh Hubungkan (antre SIASN berikutnya = risiko diterima, BB11) | |
| H9 | F10-30 | `demo.pegawai` `opsi-hubungkan?id_riwayat=7` | Lihat H6 (403 kunci); `demo.admin`: memuat RW2 + ringkasan (BB7) | |
| H10 | F10-38 (1) | Urutan galat & ulang kunci sesudah `FOR UPDATE` | [test-otomatis] test MAKE-092 (status tujuan berubah di antara jalan cepat dan baca ulang) | |

## X. Hapus arsip yatim (Tahap 2 — sesudah MAKE-092/096 di main; AC2-05, AC2-09, F10-09)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| X1 | AC2-11 | Sesudah bagian N: ⋮ → Hapus AR11 (legacy kode 0) | `ConfirmDialog` tampil; Batal tidak mengubah apa pun | |
| X2 | AC2-09 | Konfirmasi Hapus AR11 | Baris hilang dari DB; audit `delete` dengan snapshot baris; berkas di disk **tetap ada** | |
| X3 | R7 | [S] `curl -sI` URL publik AR11 (dicatat sebelum X2) | Masih 200 (BB10) | |
| X4 | AC2-05 | Hapus baris terhubung lewat API | 422 (lihat H5) | |
| X5 | R7 | Hapus AR10 (kode 0, kunci v2), lalu `buka` id-nya | 404; berkas fisik tetap di disk | |

## Y. Ekspor & filter (Tahap 2 — sesudah MAKE-092/096 di main; AC2-10, §6.1, F10-27)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| Y1 | AC2-10 | "Semua berkas" → `arsip-ekspor` → Excel, lalu CSV | `.xlsx` dan `.csv` (bukan `.txt`); kolom Nama Arsip, Jenis Riwayat, Data Riwayat; tanpa URL berkas; `Cache-Control: no-store` | |
| Y2 | AC2-10 | Ekspor dengan baris ber-`tag` `=1+1` | CSV `'=1+1`; XLSX berupa teks | |
| Y3 | AC2-10 | Ekspor dengan filter jenis / pencarian aktif | Isi = daftar yang sama dengan filter itu | |
| Y4 | F10-26 | `demo.admin` ekspor arsip PA dua kali beruntun | Dua baris `arsip_lihat` `endpoint: "ekspor"` (tanpa dedup); `demo.pegawai` ekspor miliknya → tidak dicatat | |
| Y5 | §6.1 | Centang `arsip-filter-belum-terhubung` sesudah memilih jenis di `arsip-filter-jenis` | Select ter-reset ke "Semua"; hanya baris kode 0 | |
| Y6 | §6.1 [K] | Sesudah Y5, pilih jenis Jabatan | Daftar kosong (BB13) | |
| Y7 | §6.1 | Filter hanya di "Semua berkas" | Folder jenis tidak menampilkan filter | |

## Z. Regresi & efek yang diterima (§6.6, §7.5)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| Z1 | §7.5 | Tab lampiran B-18 riwayat Jabatan PA: unduh lampiran | Tetap privat (`attachment`, butuh login; BB17); tidak berubah | |
| Z2 | §7.5 | Dialog "Arsip Data Umum": unggah, lihat, hapus 37/38 | Perilaku lama tetap | |
| Z3 | §7.5 | Endpoint berkas layanan cuti/TB/IB/PMK | Tetap privat; berkas 43–50 tidak punya URL publik (N6) | |
| Z4 | §6.6 (Tahap 2 — sesudah MAKE-092/096 di main) | Sesudah Hubungkan ke kode 11: kartu kelengkapan beranda pegawai PA | Bisa berubah [K] (BB11) | |
| Z5 | §6.6 (Tahap 2 — sesudah MAKE-092/096 di main) | Hubungkan ke 9/10/11/32: respons `services/s_stat` | Berkas bisa ikut tampil [K] (BB11); [test-otomatis] bila klien uji tidak tersedia | |
| Z6 | §7.5 | Foto / Plt-Plh / Konket berpath `arsip/…` yang berkasnya ada | 200 (koreksi dokumen usang); [test-otomatis] `PathArsipLegacyTerbacaTest` (MAKE-093) | |
| Z7 | §11.1 | Test pembanding regex README §1l vs `Config\Arsip` | [test-otomatis] `backend/tests/unit/Deploy/ReadmeBerkasNginxTest.php` (MAKE-093) | |

## BB. "Bukan bug" — sesuai keputusan (PRD §11.3)

Pelaksana QA **tidak** mencatat hal berikut sebagai temuan. Kolom Hasil diisi ✅ bila perilaku teramati sesuai.

| # | Perilaku | Dasar | Butir terkait | Hasil |
|---|---|---|---|---|
| BB1 | URL berkas legacy bisa dibuka tanpa login atau dari akun lain | F10-18 (a) | N3, M9 | |
| BB2 | Berkas unggahan v2 (termasuk lampiran cuti/TB/IB/PMK dan surat ber-TTE) tidak punya URL publik; butuh login dengan izin melihat pegawai itu | F10-18 (b) | N6, P4 | |
| BB3 | Lebih dari 6 berkas v2 beruntun → pesan batas; ±10 detik kemudian satu berkas bisa dibuka lagi; berkas legacy tidak dibatasi | F10-36 | P12, P13, P17, F6 | |
| BB4 | Pembukaan berkas v2 oleh pemiliknya sendiri tercatat di audit | F10-35 | P10 | |
| BB5 | Pembukaan berkas legacy lewat URL publik tidak tercatat di audit | F10-08 | N3 | |
| BB6 | Lampiran cuti/TB/IB/PMK v2 bisa dibuka di Arsip walau endpoint layanan menolak role itu; PMK hasil impor legacy lewat URL publik | F10-30, §7.5 | P2, P18, C6, N1, Z3 | |
| BB7 | Role 2/4/5/6/7 melihat lampiran **dan ringkasan** riwayat yang tersembunyi di B-18 (mis. hukdis), termasuk di opsi Hubungkan | F10-30 | B2, H9 | |
| BB8 | Pegawai yang muncul di pemilih tetapi di luar lingkup detail role 3 → 403 | §5.1 | A3 | |
| BB9 | Lampiran riwayat status 10 tetap tampil dan bisa dibuka | F10-16 | B3, P9 | |
| BB10 | Hapus arsip yatim tidak menghapus berkas fisik; URL lama berkas legacy masih terbuka | F10-09, R7 | X2, X3 | |
| BB11 | Hubungkan ke Jabatan/Diklat sertifikasi bisa memicu antre SIASN; ke 9/10/11/32 mengubah `s_stat`; ke 11 mengubah kelengkapan beranda | §6.6 | H8, Z4, Z5 | |
| BB12 | Role 2/6/7 tidak bisa Hubungkan ke riwayat Disetujui (dialog praktis hanya Data Umum 38); role 2/3/6/7 tidak bisa mengubah nama kode 37; tetapi role 2/6/7 tetap bisa mengubah nama arsip yang terhubung ke riwayat Disetujui (selain 37) | F10-31, PB-4 | H6, E1, E4 | |
| BB13 | Centang "Belum terhubung" + memilih jenis → daftar kosong | §6.1 [K] | Y6 | |
| BB14 | Berkas selain pdf/gambar terunduh, tidak dipratinjau | F10-32 | N4, F4, R4 | |
| BB15 | Berkas dibuka di popup kecil; di ponsel terbuka sebagai tab | §5.1 | R6 | |
| BB16 | Tidak ada token CSRF terpisah | §6.1 | W1 | |
| BB17 | Endpoint unduh B-18 lama tetap butuh login | §7.5 | Z1 | |
| BB18 | Membuka URL folder langsung (`/arsip/<NIP>/semua` atau `/<kode>`) bisa menghasilkan dua baris `arsip_lihat` (permintaan folder dan daftar bersamaan; dedup cek-lalu-tulis) | AC1-12 (PRD `:200`) | D1, D2 | |

## K. Keputusan / pertanyaan pra-QA

◻ = menunggu keputusan reviewer/Horii (tidak diputus di checklist ini).

| # | Temuan / pertanyaan | Keputusan |
|---|---|---|
| K-01 | Bagian N dan R (dan GF4) bergantung pada MAKE-093 di `main` dan G10-4 oleh operator/Horii. MAKE-093 masih di branch `29ca410c`. | Informasi: N/R dijalankan sesudah GF3 dan GF4 terpenuhi. "QA Tahap 1 boleh dilakukan reviewer lebih awal" (PRD §13 `:657`) ada dalam urutan **sesudah** MAKE-093 dan tidak berarti boleh sebelum G10-4; apakah bagian lain boleh lebih dulu di lokal = ◻ K-11 |
| K-02 | `ACTIVE_PHASE` di `main` masih 5 (`nav.config.ts:95`) walau Fase 7 sudah sign-off 11-11-2026; menu Arsip ber-`phase: 7` (`:171`) tersembunyi. Di server dev konstanta tidak bisa diubah tanpa deploy. | Informasi: `ACTIVE_PHASE` dinaikkan ke 7 hanya saat rilis Fase 7 (`docs/fase7/Laporan_Akhir_Fase7.md:168`, blocker deploy/go-live); F-10 ikut gerbang Fase 7 (QF10-3, PRD `:637`); kenaikan konstanta = keputusan Horii/reviewer di penutup rilis (F8-50, `docs/fase8/PRD_Fase8_HaloSimpeg.md:907`). Jadi QA di server dev memakai URL langsung + tombol header, dan A9 di lokal dengan 7 tanpa commit. ◻ Hanya bila Horii ingin menaikkan konstanta lebih awal: kapan? |
| K-03 | Data uji F-10 (baris `document_attachment` + berkas fisik sintetis, akun per role) belum punya skrip; di server dev belum jelas akun uji dan izin menanam data sintetis di DB + akar lampiran. | ◻ Siapa menyiapkan skrip `qa_f10.php` (lokal) dan data/berkas sintetis di server dev (operator), dan cara membersihkannya sesudah QA? Tanpa NIP nyata |
| K-04 | Butir saklar `privat` (C9, P11) mengubah `.env`. | Usulan: hanya di lokal; ◻ bila Horii ingin juga di server dev, butuh izin operator |
| K-05 | A3 butuh akun role 3 tanpa satker yang jalur daftar ≠ jalur detail (`PegawaiScope.php:30-34`); akun demo `demo.satker` bersatker sehingga kedua jalur sama. | ◻ Tambah akun sintetis U3d, atau terima [test-otomatis] test lingkup `PegawaiScope` di gate |
| K-06 | Tahap 2 dibagi MAKE-092 (BE) dan MAKE-096 (FE); semula PRD §13 menulis MAKE-092 "BE+FE" | ✔ Tercatat di PRD F-10 v1.5 §13 (CR-123) |
| K-07 | Testid/endpoint Tahap 2 diambil dari PRD §8.1 dan §9.3, lalu dicocokkan dengan branch MAKE-092 @`c49d5a42` (`RoutesPegawai.php`: `GET …/arsip/ekspor`, `GET …/arsip/opsi-hubungkan`, `PATCH …/arsip/berkas/(:num)`, `POST …/hubungkan`, `DELETE …/arsip/berkas/(:num)`; filter tulis role 1, 2, 3, 6, 7) dan MAKE-096 @`149b816a` (`arsip-filter-jenis`, `arsip-filter-belum-terhubung`, `arsip-ekspor`; item ⋮ `edit` → `buka` → `hubungkan` → `hapus` `danger`): nama cocok. Keduanya belum di `main`. | Reviewer mencocokkan ulang terhadap SHA yang di-merge sebelum bagian W–Y dijalankan |
| K-08 | Docblock `frontend/src/features/kepegawaian/composables/useBukaBerkasArsip.ts:12-13` masih menyebut 403 `password_change_required` "ditangani interceptor tanpa pesan tambahan", sedangkan kode `:60-62` menampilkan pesan (sesuai koreksi F10-37 (6) PRD v1.4 `:140`). PRD v1.4 §9.3 `:521` juga masih usang ("403 `password_change_required` → handler ganti password (`:215-217`)") dan bertentangan dengan F10-37 (6). | Informasi: perilaku kode benar; perbaikan komentar bisa ikut paket FE berikutnya. Acuan QA F9 = F10-37 (6) `:140`. ◻ §9.3 `:521` ikut dikoreksi: amandemen CR-122 sebelum merge, atau CR berikutnya (reviewer) |
| K-09 | Butir yang praktis hanya terbukti lewat test (fail-closed audit D5, audit 0 P16, pencabutan object URL F10, urutan kunci H10, regex README Z7, tanpa N+1 B12, tipe blob F12). | Usulan [test-otomatis] dengan rujukan test di gate `main`; ◻ disepakati reviewer |
| K-10 | Angka D1 (v1.4) dari salinan dev, bukan produksi: 0 baris `assets/upload/…`, 2 baris `id_riwayat` NULL, 2 baris `path` kosong, maksimum 576 baris per pegawai. | Informasi: AR18, AR19, AR31 sintetis; kinerja folder untuk pegawai ±576 baris boleh dicek bila data sintetis cukup (◻ opsional) |
| K-11 | PRD menjalankan QA Lapis 1 F-10 di server dev dengan Nginx, **bukan** rute dev (§7.4 `:342`, §11.3 `:575`). Checklist ini mengusulkan butir non-[S] (termasuk C9/P11 saklar `privat` dan pembukaan berkas legacy lewat rute dev `arsip.sajiBerkasDev = true`) di mesin lokal. Laporan Akhir Fase 7 §10.6 menulis G10-4 menahan QA Lapis 1 F-10. | ◻ Reviewer/Horii: bolehkah butir non-[S] dijalankan di lokal dengan rute dev, menyimpang dari PRD §7.4/§11.3? Bila boleh: apakah hasil lokal cukup untuk sign-off, atau harus diulang di server dev? Bila tidak: seluruh checklist menunggu G10-4 |

## T. Temuan

(Diisi pelaksana QA. Satu baris per temuan; kolom Butir memakai nomor berawalan, mis. `N6`, `P12`.)

| # | Butir | Temuan | Dampak | Usulan / status |
|---|---|---|---|---|
| | | | | |
