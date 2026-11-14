# Checklist QA Lapis 1 — F-10 Arsip Digital (Tahap 1 + Tahap 2)

Untuk reviewer CR (pelaksana QA Lapis 1 F-10; PRD F-10 §11.3, §11.4 DoD). Acuan: PRD F-10 **v1.5** (`main`,
`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md`; riwayat versi `:816-819` memuat v1.3, v1.4 CR-122, dan v1.5 CR-123) — §0
gerbang, §3 register F10-01..F10-38, §5.2 AC1-01..AC1-18, §6.2 AC2-01..AC2-13, §6.6 risiko diterima, §7 penyajian berkas
(dua jalur), §8.1 endpoint, §9 FE, §11.3 daftar "Bukan bug", §12.1 matriks role. Kontrak:
`backend/app/Controllers/Api/Kepegawaian/README.md:249-349` (bagian "Arsip F-10"). Penyajian Nginx: README-deploy §1
(contoh server) dan §1l (`README-deploy.md:546` dst.) + ADR-038 (paket MAKE-093, sudah di `main`). Pola: Checklist QA Lapis 1
Fase 7 (`docs/fase7/Checklist_QA_Lapis1_Fase7.md`; F-10 dikecualikan di `:16`). Checklist ini **disepakati reviewer CR
sebelum QA dimulai**; pertanyaan pra-QA dan keputusannya di §K.

**Status (15-11-2026).** Dieksekusi reviewer CR di **mesin lokal** untuk semua butir non-[S] (K-11 diputus Horii
13-11-2026), pada app demo `main` `07d5a7a2` dengan data sintetis. Semua paket F-10 sudah di `main`: Tahap 1 MAKE-090 BE
(`fa1f6686`) dan MAKE-091 FE (`483c5892`), MAKE-093 penyajian berkas (merge `a0418d1f`), Tahap 2 MAKE-092 BE (`f3827106`)
dan MAKE-096 FE (`07d5a7a2`). G10-4 (verifikasi Nginx server dev) **belum** dijalankan operator/Horii, jadi butir [S]
menunggu. Ringkasan 154 butir GF–Z: **✅ 121**, **[test-otomatis] 11** (4 di antaranya
sebagian: A3, D5, F9, Z5), **◻ [S] 20**, ◻ 1 (R6, bersama bagian R di server dev), GF5 ✅ (gate penuh lokal + CI), **❌ 0**. BB1..BB18: ✅ 14 (4 di antaranya ✅ lokal: BB2, BB6, BB10, BB14, bagian [S]-nya masih ◻), [test-otomatis]
sebagian 1, ◻ [S] 2, ◻ 1 (bersama bagian R). Temuan §T: 4, semuanya rendah dan **tidak** menahan sign-off. Rujukan file:line
dan ekspektasi di 136 butir bagian GF, A–D, P, F, V, M, W, E, H, X, Y, Z dicocokkan ulang dengan `07d5a7a2` (K-07: 100
cocok, 36 selisih kelas (a) berupa rujukan atau teks usang yang sudah dikoreksi langsung di kolom AC/Langkah/Hasil yang
diharapkan, 0 selisih perilaku), kecuali `backend/.env.example:119-121`; satu rujukan usang tambahan (F11) dikoreksi saat
tinjauan dokumen. Rujukan di bagian N, R, BB, dan teks §K tidak termasuk pencocokan K-07.

**Cakupan.** Seluruh scope F-10: indeks arsip (folder + daftar), dua jalur buka berkas (legacy publik lewat Nginx, unggahan
v2 privat lewat endpoint `buka` + audit + throttle), catatan pembukaan daftar F10-17, halaman FE (route, pemilih pegawai,
folder, tabel, ⋮, tombol header), dan aksi Tahap 2 (Edit, Hubungkan, Hapus, Ekspor, filter "Belum terhubung"). Di luar
cakupan: QR surat cuti ber-TTE legacy (B10-2), blocker deploy B10-1..B10-3 (syarat cutover, **bukan** sign-off, PRD §11.4),
eksekusi `lampiran:tarik` data nyata (cutover), dan MTC-046 (fase Manual Testing tersendiri, PRD §11.3).

**Cara membaca nomor butir.** Awalan bagian: `GF` gerbang, `A` akses & pintu masuk, `B` folder & daftar, `C` status berkas
& URL, `D` catatan pembukaan daftar, `N` jalur publik Nginx, `P` jalur privat `buka` (API), `F` jalur privat (FE), `R`
pratinjau tiga browser, `V` tampilan & viewport, `M` matriks role 1–8, `W`/`E`/`H`/`X`/`Y` aksi Tahap 2, `Z` regresi & efek
yang diterima, `BB` daftar "Bukan bug". Butir bertanda **[S]** wajib di **server dev dengan Nginx** (G10-4). PRD menjalankan
seluruh QA Lapis 1 di server dev dengan Nginx, bukan rute dev (§7.4 `:342`, §11.3 `:575`); sesuai keputusan K-11 (Horii
13-11-2026), butir non-[S] dijalankan di mesin lokal dengan rute dev sesudah Tahap 2 di `main`, dan butir [S] menyusul di
server dev sesudah G10-4. Kode data (mis. `AR1`, `PA`) menunjuk tabel "Data uji gabungan".

## Lingkungan & persiapan

**Server dev dengan Nginx (wajib untuk butir [S]; PRD §7.4 akhir, §11.3).** `main` terbaru terdeploy lewat hook
(README-deploy §2). Prasyarat sebelum butir [S]: MAKE-093 di `main` (✔ merge `a0418d1f`) dan blok Nginx
`location ^~ /berkas/` + `map` + snippet header terpasang sesuai README-deploy §1 dan §1l (`README-deploy.md:546` dst.), hasil
verifikasi operator tercatat di tabel "Hasil verifikasi" §1l (`README-deploy.md:649-653`, per 15-11-2026 masih kosong; G10-4).
`.env` server: `arsip.penyajianBerkasLegacy = publik`, `arsip.dasarUrlBerkas = /berkas`, `arsip.sajiBerkasDev = false`
(`backend/.env.example:119-121`). Host, akun, dan path server **tidak** ditulis di checklist maupun laporan.

**Mesin lokal (✔ diputus K-11, Horii 13-11-2026: butir non-[S] diuji di lokal dengan rute dev sesudah Tahap 2 di `main`).**
App demo reviewer `wt/demo` pada `main` `07d5a7a2`, DB `simpeg_v2_t_app_demo` @3307, backend 8120, frontend 5190, captcha
mock, `CI_ENVIRONMENT = development`, data sintetis (prosedur reset Checklist Fase 7 "Lingkungan & persiapan"). Untuk membuka
berkas legacy tanpa Nginx: `.env` demo `arsip.sajiBerkasDev = true` dan `arsip.dasarUrlBerkas = http://localhost:8120/berkas`
(rute dev `GET berkas/(:any)`, `backend/app/Config/RoutesPegawai.php:113-117`; hanya dua pola legacy, tanpa cek izin, header
sama dengan Nginx, `backend/app/Controllers/BerkasDevController.php`). Batasan: nama berkas berkarakter di luar
`permittedURIChars` (mis. `( ) , ' & +`) tidak bisa dibuka lewat rute dev (PRD §7.4); butir yang menguji header/hak baca/log
Nginx **tidak** sah di lokal. Rute dev berkas legacy aktif selama QA 15-11-2026; sesudahnya `.env` demo dikembalikan identik.

**`ACTIVE_PHASE`.** Di `main` nilainya **5** (`frontend/src/shared/layouts/nav.config.ts:97`); menu "Arsip" ber-`phase: 7`
(`nav.config.ts:173`), jadi menu tersembunyi (QF10-3: ikut gerbang Fase 7, PRD `:637`). `ACTIVE_PHASE` dinaikkan ke 7 hanya
saat rilis (`docs/fase7/Laporan_Akhir_Fase7.md:168`). Halaman tetap terbuka lewat URL langsung dan tombol "Arsip" di header
Detail Pegawai (PRD §9.1). Butir menu (A9) dijalankan di mesin lokal dengan `ACTIVE_PHASE = 7` **di worktree demo saja, tanpa
commit** (pola Checklist Fase 7 A-K2), lalu dikembalikan ke 5 (✔ 15-11-2026, worktree demo bersih). Lihat §K K-02.

**`.env` uji sementara** (restart backend, kembalikan sesudah butirnya): `arsip.penyajianBerkasLegacy = privat` (rollback
F10-24; butir P11–P13, C9). Jangan ubah `.env` server dev tanpa izin operator (§K K-04). Dijalankan hanya di lokal dan sudah
dikembalikan ke `publik`.

**Isi kolom Hasil:** ✅ lolos / **✅ sebagian** (bagian yang dijalankan lolos; bagian yang tidak dijalankan disebut) /
**✅ lokal** (bagian lokal lolos; bagian [S] masih ◻) / ❌ gagal (+ nomor temuan di §T) / — tidak berlaku /
**[test-otomatis]** (diterima lewat bukti test otomatis gate `main`, lihat §K) / **[test-otomatis] sebagian** (test hanya
mencakup sebagian klaim; celahnya disebut) / **◻ [S] menunggu G10-4** (butir server dev) / **◻ bersama bagian R di server
dev** (butir non-[S] yang dijalankan bersama bagian R). Butir [test-otomatis] BE (PHPUnit) isi test-nya dibaca, tetapi
PHPUnit tidak dijalankan per butir; status lolosnya = CI `main` #825 dan gate penuh GF5 (4274 test lolos). Vitest dijalankan (F9, F10,
F12). Bukti: tangkapan layar UI, respons
API, keluaran `curl -I`, atau baris DB — **tanpa** NIP/NIK, host, token, path berkas, nama berkas nyata, id baris, maupun isi
berkas. Sebut kodenya (mis. "AR2", "NIP PA").

### Alat bantu

**Rujukan singkat.** `ArsipService.php`, `OtorisasiArsip.php`, `AksiArsip.php` = `backend/app/Libraries/Kepegawaian/Arsip/…`;
`Config/Arsip.php` = `backend/app/Config/Arsip.php`; `RoutesPegawai.php` = `backend/app/Config/RoutesPegawai.php`;
`PegawaiScope.php` = `backend/app/Libraries/Kepegawaian/Scope/PegawaiScope.php`; `ArsipDataUmum.php` =
`backend/app/Libraries/Kepegawaian/Lampiran/ArsipDataUmum.php`; `RiwayatDefinisi.php` =
`backend/app/Libraries/Kepegawaian/Riwayat/RiwayatDefinisi.php`; `ListQuery.php` = `backend/app/Libraries/ListQuery.php`;
berkas FE tanpa folder (`*.vue`, `*.ts`,
`*.spec.ts`) = `frontend/src/features/kepegawaian/…` (akar fitur, `views/`, `components/`, `components/arsip/`,
`composables/`, `__tests__/`); `nav.config.ts` = `frontend/src/shared/layouts/nav.config.ts`.

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
menulis NIP-nya). Audit `document_attachment` (Tahap 2) menyimpan snapshot baris lengkap di `before_json`/`after_json`
(memuat path dan NIP): cukup laporkan jumlah baris dan `event`.

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

Semua data **sintetis** dengan penanda `[QA-F10 <kode>]`; NIP pegawai uji = NIP sintetis DB demo (atau NIP sintetis yang
dibuat khusus di server dev, §K K-03). Skrip penanam data dan berkas `qa_f10.php` disiapkan reviewer di folder demo-seed (di
luar repo): idempoten (jalan kedua tidak membuat baris), dengan pengaman DB demo dan akar lampiran demo, tanpa DELETE/DROP, tanpa
akun baru (§K K-03). Path ditulis relatif akar lampiran (`writable/uploads/lampiran`; server: `shared/writable/uploads/lampiran`).
`<kunci>` = 32 hex acak. Lokal 15-11-2026: penanaman data menambah 76 baris QA `document_attachment` dan 2 pegawai (PL, PD); tahap tulis
menambah 6 baris lewat SQL (idempoten, tanpa audit; baris kunci v2 mendapat salinan berkas baru). Kolom "Catatan penanaman lokal"
mencatat penyimpangan dari rancangan.

| Kode | Data | Untuk butir | Catatan penanaman lokal (15-11-2026) |
|---|---|---|---|
| Akun | Lokal: `demo.admin` 1, `demo.pegawai` 2 (= pemilik PA), `demo.pemohon` 2 (pegawai lain), `demo.satker` 3 (unit 1/satker 1, lingkup memuat PA), `demo.eselon1` 4, `demo.menteri` 5, `demo.ptt` 6 (= PB), `demo.pppk` 7 (= PC), `demo.pimpinan` 8 (akun di `AKUN_DEMO.md`, password tidak ditulis). Server dev: akun uji setara per role (§K K-03) | semua | Kesembilan akun login 200 dengan role claim sesuai |
| U3d | (opsional) akun role 3 **tanpa satker** dengan unit yang jalur daftar ≠ jalur detail (`PegawaiScope.php:30-34`), + pegawai sintetis yang tampil di daftarnya tetapi di luar lingkup detail | A3 | **Dilewati**: butuh akun baru (tidak dibuat); A3 → [test-otomatis] (K-05, T-03) |
| PA | Pegawai pemilik arsip utama (`demo.pegawai`), memuat AR1–AR34 + AR-P | A, B, C, D, P, F, R, W–Y | Penanda `[QA-F10 <kode>]` ditaruh di kolom `filename` semua baris (AR29 wajib `tag` dan `display_name` kosong). Folder awal: 73 baris (70 QA + 3 lama), 22 folder |
| PB / PC | `demo.ptt` / `demo.pppk`, masing-masing 1 berkas v2 (kode 38) + 1 berkas legacy (kode 0) | A5, M, H6 | 403 kunci H6/M6/M7 memakai riwayat jabatan Disetujui milik PB/PC sendiri di DB demo |
| PL | Pegawai sintetis di satker/unit **lain** (di luar lingkup `demo.satker`), 2 berkas | A2, M3, W4 | Satker/unit lain; 1 berkas kode 9 (PL-9) dan 1 berkas kode 0 (PL-0) |
| PD | Pegawai sintetis tanpa baris `document_attachment` | B7 | Di satker `demo.satker` |
| AR1 | Kode 9 Jabatan, **kunci v2** `2026/11/<kunci>.pdf`, berkas ada, `tag` terisi | C, P, F, R | |
| AR2 | Kode 9 Jabatan, **legacy** `arsip/<NIP PA>/jabatan/sk_uji.pdf`, berkas ada | C, N, R | Diganti nama di E1 |
| AR3 | Kode 11 KP, legacy `arsip/<NIP PA>/kp/SK_UJI.PDF` (ekstensi huruf besar), berkas ada | C6, N3 | |
| AR4 / AR5 / AR6 | Kode 14 Pendidikan, legacy `.jpg` / `.png` / `.gif`, berkas ada | N, R | AR4 tanpa `tag` dan `display_name` (untuk B11 (b)) |
| AR7 | Kode 38 Data Umum, kunci v2 `.png`, berkas ada | P, R | `tag` NULL, `display_name` terisi (untuk B11 (a)) |
| AR8 | Kode 37 Status Data Umum, kunci v2 `.pdf`, berkas ada | P, E4 | |
| AR9 | Kode 0 Belum Terhubung, legacy `arsip/<NIP PA>/lain/yatim.pdf`, berkas ada | B, H, BB | Terhubung ke RW1 di H2; uji UI W5/H1/H6 memakai baris kode 0 lain (Y2b) |
| AR10 | Kode 0, kunci v2 `.pdf`, berkas ada | H7 | Dipakai H7 (menjadi kode 9); X5 memakai AR10b |
| AR10b | Kode 0, kunci v2 `.pdf`, berkas ada (tambahan tahap tulis) | X5 | Pengganti AR10 karena AR10 dipakai H7 |
| AR11 | Kode 0, legacy `.docx`, berkas ada | C6, N4, X | Dihapus di X2; X1 di UI memakai AR28-html (Batal) dan AR33 (Hapus) |
| AR12 | Kode 22 Kursus/Seminar, kunci v2 `.docx`, `tag` "[QA-F10 AR12] SK Jabatan" (tanpa ekstensi) | C6, P5, F4 | |
| AR13 | Kode 5 Pelatihan, legacy cocok regex, **berkas tidak ada** di disk | C1 | |
| AR14 | Kode 10 KGB, kunci v2, **berkas tidak ada** | C1, P8 | |
| AR15 | Kode 13 Organisasi, path legacy bersegmen berawalan titik `arsip/<NIP PA>/.x/a.pdf` | C2 | Berkas fisik tetap dibuat di segmen `.x` (berguna untuk N7) |
| AR16 / AR17 | Kode 20 Keluarga, path `kolom/…` / `form-digital/…` (berkas ada) | C2, N | Nama berkas `qa_f10_*` |
| AR18 | Kode 32 SKP, `path` kosong (D1 v1.4: 2 baris seperti ini) | C2 | Menunjuk riwayat SKP bantu berstatus 2 |
| AR19 | `id_riwayat` **NULL** (D1 v1.4: 2 baris) | B4, E6 | 1 baris |
| AR20 | Kode 1 Alamat, kunci v2 `.pdf`, `id_entri` = riwayat **status 10** | B3, P9 | |
| AR21 | Kode 14, kunci v2, `id_entri` riwayat **tidak ada** | B3, P9 | |
| AR22 | Kode 13, legacy, `id_entri` = riwayat **pengajuan** (status 0) | B3 | |
| AR23 | Kode 7 Hukuman Disiplin, kunci v2, `id_entri` riwayat hukdis Disetujui RW2 | B2, BB | |
| AR24 | Kode 36 Pemberhentian Sementara JFT, `id_entri` riwayat jabatan | B5 | |
| AR25 | Kode 45 Surat Cuti (ber-TTE), kunci v2 `.pdf` | B1, P, BB | Tertaut riwayat cuti PA status 1 |
| AR26 | Kode 42 PMK **legacy** `arsip/…` `.pdf` (AR26-42) + kode 43, 46, 49 kunci v2 | B1, C6, N1, P, BB | 4 baris. AR26-43 tertaut riwayat cuti PA status 1; AR26-42/46/49 `id_entri` NULL (PA tanpa riwayat PMK/TB/IB), jadi P18 untuk TB/IB/PMK tidak tertaut data layanan |
| AR27 | Kode 3 Anak, `id_entri` = `detail_anak` milik PA (ringkasan lewat induk) | B5 | |
| AR28 | Kode 0, legacy `.html` dan `.svg` (skrip `onload`) | N5, R4 | 2 baris: AR28-html, AR28-svg. AR28-svg dihubungkan ke RW1 di uji UI H3 |
| AR29 | Kode 9, kunci v2 dengan `tag` **dan** `display_name` kosong | C5 | |
| AR30 | Kode 0, legacy, `tag` "[QA-F10 AR30] 100%_uji <b>x</b>" | B6, E2 | Disanitasi di E2, lalu diganti nama 255 karakter di uji UI E1 |
| AR31 | Kode 14, legacy `assets/upload/<…>/uji.jpg` (F10-33; D1 v1.4: 0 baris di salinan dev → sintetis) | C7, N2 | |
| AR32 | Kode 9, path legacy berawalan `/`, berspasi, dan ber-`\` | C7 | Path memuat `//`, `\`, dan spasi sekaligus |
| AR33 | Kode 0, legacy `.php` | N5 | Dihapus di uji UI X1 |
| AR34 | Kode 9 Jabatan, **kunci v2** `.jpg`, berkas ada | R2 | |
| AR-P | 30 baris kode 32 SKP legacy (`[QA-F10 P01..P30]`) untuk paginasi | B6 | Menunjuk riwayat SKP bantu berstatus 2 (ringkasan kode 32 terisi tanpa menambah SKP Disetujui) |
| AR-H6 | Kode 0 milik PA (tambahan tahap tulis) | H6, M2 | Dihubungkan `demo.pegawai` ke 38 |
| AR-DS | Kode 0 milik PA (tambahan tahap tulis) | M3 | Dihapus `demo.satker` |
| AR-Z4 | Kode 0 milik PA, berkas ada (tambahan tahap tulis) | Z4 | Dihubungkan ke riwayat KP (kode 11) |
| PB-0b / PC-0b | Kode 0 milik PB / PC (tambahan tahap tulis) | M6, M7 | Dihapus pemiliknya |
| Y2 / Y2b | Kode 0 PA; Y2 `tag` persis `=1+1` (penanda di `display_name`/`filename`); Y2b `tag` "[QA-F10 Y2] =1+1" | Y2 | Hanya Y2 yang memicu escape (sel berawalan `=`); Y2b dipakai uji UI W5/H1/H6 |
| RW1 | Riwayat Jabatan (9) PA **Disetujui** | H2, H3, H7 | |
| RW2 | Riwayat Hukdis (7) PA Disetujui | H, BB | Status 1, masa hukuman sudah lama berakhir (tidak aktif) |
| RW3 | Riwayat Organisasi (13) PA **bukan** Disetujui | H4 | Status 2 (Ditolak) |
| RW4 | Riwayat Jabatan milik `demo.pemohon` | H4 | |
| SQ | Isi `siasn_upload_arsip` sebelum/sesudah Hubungkan (jumlah baris saja) | H8 | 0 baris sebelum dan sesudah |

## GF. Gerbang & prasyarat (PRD §0, §11.4)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| GF1 | G10-1 | Cek PRD F-10 di `main` | v1.5 di `main` (memuat koreksi v1.4 CR-122 dan v1.5 CR-123); checklist mengacu v1.5 | ✅ Judul PRD di `main` "v1.5 (disetujui Horii)"; riwayat versi `:816-819` memuat v1.3, v1.4, v1.5 |
| GF2 | G10-2 | Cek kode izin workload Tahap 1 dan Tahap 2 | Izin Tahap 1 (MAKE-090/091/093) dan Tahap 2 (MAKE-092/096) tercatat oleh reviewer | ✅ Izin [#7-WorkloadF10] (Tahap 1 + perubahan anggota Tahap 2 13-11-2026) tercatat reviewer |
| GF3 | — | Cek isi `main`: MAKE-090, MAKE-091, MAKE-093; Tahap 2: MAKE-092, MAKE-096 | Tahap 1, MAKE-093, MAKE-092, dan MAKE-096 di `main` | ✅ `git merge-base --is-ancestor` ke `main` benar untuk MAKE-090 `fa1f6686`, MAKE-091 `483c5892`, MAKE-093 `a0418d1f`, MAKE-092 `f3827106`, MAKE-096 `07d5a7a2` |
| GF4 | G10-4 [S] | Baca tabel "Hasil verifikasi" README-deploy §1l (`README-deploy.md:649-653`) | Terisi operator/Horii untuk server dev: `curl -I` (header + 404 kunci v2), hak baca user Nginx, Chrome/Edge/Firefox, nilai CSP akhir | ◻ [S] menunggu G10-4 (tabel masih kosong) |
| GF5 | §11.1–§11.2 | Cek gate penuh `./check.sh` dan CI `main` sesudah paket terakhir | Lolos (PHPUnit, PHPStan, PHP-CS-Fixer, ESLint, vue-tsc, Vitest, build); termasuk regresi B-18, Arsip Data Umum, SIASN arsip, `s_stat`, kelengkapan beranda | CI main #825 (`07d5a7a2`) hijau; gate penuh lokal: ✅ `./check.sh` di `07d5a7a2` (15-11-2026, DB scratch @3307): PHPStan "No errors", PHP-CS-Fixer 0 dari 1450 berkas, PHPUnit 4274 test (3 dilewati), Vitest 179 berkas / 1991 test, build lolos, EXIT=0, tanpa berkas terhapus |
| GF6 | §11.4 | Cek B10-1..B10-3 | Tercatat sebagai blocker deploy/cutover, **tidak** menahan sign-off | ✅ Laporan Akhir Fase 7 §10.7 "Blocker deploy / cutover F-10 (bukan syarat sign-off, PRD §11.4)" memuat B10-1..B10-3; PRD `:46-48` sama |
| GF7 | — | Cek status checklist ini | Checklist disepakati reviewer CR; butir ◻ §K (K-01..K-11) sudah diputus | ✅ Disepakati reviewer CR; §K diputus, ◻ tersisa (di §K) hanya K-02 (opsional) dan K-03 bagian server dev |
| GF8 | K-03 | Cek data uji di lingkungan QA | Data uji sintetis (baris `document_attachment` + berkas sintetis sesuai tabel "Data uji gabungan") dan akun per role 1–8 tersedia, tanpa NIP nyata | ✅ Lokal (sesudah penanaman data, sebelum tahap tulis): `document_attachment` 96 baris (76 baris QA bertanda `[QA-F10`), 9 akun role 1–8 login 200; U3d tidak tersedia (A3 [test-otomatis]). Server dev: ◻ K-03 |

## A. Akses & pintu masuk (Tahap 1; AC1-01..AC1-04, AC1-14, §5.1, §9.1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| A1 | AC1-01 | `demo.admin`, `demo.eselon1`, `demo.menteri`: buka `/arsip/<NIP PA>` dan `/arsip/<NIP PL>` (URL langsung) | Folder tampil untuk keduanya (role 1/4/5 = semua pegawai) | ✅ UI: ketiga akun melihat `arsip-folder-grid` untuk PA (23 kartu termasuk "Semua berkas") dan PL; tanpa `arsip-failure` |
| A2 | AC1-02 | `demo.satker`: buka `/arsip/<NIP PA>` lalu `/arsip/<NIP PL>`; API `GET pegawai/<NIP PL>/arsip` | PA tampil; PL → pesan galat di halaman (`arsip-failure`), API 403 "Anda tidak berhak mengakses data pegawai ini." (`OtorisasiArsip.php:164`) | ✅ API: folder PA 200; folder dan daftar PL 403 dengan pesan itu. UI: PA grid; PL `arsip-failure` (pesan generik FE "Anda tidak berhak mengakses data ini.") |
| A3 | AC1-02 | (U3d) cari pegawai di pemilih yang tampil di daftar tetapi di luar lingkup detail, pilih | Halaman Arsip 403 (sama dengan Detail Pegawai) [K]. Bila U3d tidak tersedia → [test-otomatis] (§K K-05) | [test-otomatis] sebagian (K-05): `backend/tests/Kepegawaian/PegawaiScopeTest.php:196`, `:166` dan `PegawaiEndpointTest.php:119` membuktikan daftar ≠ detail; Arsip memakai jalur detail (`OtorisasiArsip.php:163`). Belum ada test endpoint Arsip role 3 tanpa satker (T-03) |
| A4 | AC1-03 | `demo.pegawai`: buka `/arsip`, `/arsip/<NIP pemohon>`, `/arsip/<NIP pemohon>/9` | Dialihkan ke `/arsip/<NIP PA>` (dan `/arsip/<NIP PA>/9`) tanpa pesan galat (`routes.arsip.ts:23-36`) | ✅ UI: ketiga URL berakhir di `/arsip/<NIP PA>` dan `/arsip/<NIP PA>/9`; tanpa `arsip-failure`, notice, atau pemilih pegawai |
| A5 | AC1-03 | API: `demo.pegawai`, `demo.ptt`, `demo.pppk` → `GET pegawai/<NIP lain>/arsip` dan `…/arsip/berkas` | 403 [V2] | ✅ API: semua kombinasi (pegawai→pemohon/PB, ptt→PA/PC, pppk→PA) folder dan daftar 403 "Anda tidak berhak mengakses data pegawai ini." |
| A6 | AC1-04 | `demo.pimpinan` (role 8): buka `/arsip`, `/arsip/<NIP PA>`; API ketiga endpoint Tahap 1 | Halaman terlarang (`meta.roles` 1–7, `routes.arsip.ts:48`); API 403 `Forbidden` (filter route, `RoutesPegawai.php:97`) | ✅ UI: kedua URL dialihkan ke `/403` "Akses ditolak". API: folder, daftar, `buka`, ekspor, `opsi-hubungkan` 403 `Forbidden` |
| A7 | AC1-04 | `demo.pimpinan`: buka Detail Pegawai PA (bila boleh) | Tombol "Arsip" (`pegawai-header-arsip`) tidak tampil (`DetailPegawaiPage.vue:62`) | ✅ UI: Detail Pegawai PA terbuka; `pegawai-header-arsip` tidak ada di DOM; `btn-arsip` tetap ada |
| A8 | AC1-14 | Role 1–7: buka Detail Pegawai PA (pegawai: NIP sendiri) | Tombol lama berlabel **"Arsip Data Umum"**, testid `btn-arsip`, membuka dialog 37/38 (judul "Arsip Data Umum", `ArsipKepegawaianDialog.vue:85`); tombol baru **"Arsip"** (`pegawai-header-arsip`, `PegawaiHeaderCard.vue:164`) menuju `/arsip/<NIP PA>` | ✅ UI role 1–7 (role 2/6/7 NIP sendiri): `btn-arsip` "Arsip Data Umum" membuka dialog "Arsip Data Umum"; `pegawai-header-arsip` "Arsip" menuju `/arsip/<NIP pemilik>` |
| A9 | F10-06, QF10-3 | Lokal, `ACTIVE_PHASE = 7` tanpa commit: lihat sidebar tiap role 1–8; lalu kembalikan ke 5 | Menu "Arsip" untuk role 1–7, tidak untuk 8; role 2/6/7 → arsip sendiri, 1/3/4/5 → pemilih. Dengan nilai 5: menu tidak ada, URL langsung tetap terbuka | ✅ UI: role 1/3/4/5 → `/arsip` dengan `arsip-pemilih-pegawai`; 2/6/7 → arsip sendiri; 8 tanpa menu. Nilai 5: menu hilang, `/arsip` langsung tetap terbuka. Konstanta dikembalikan, worktree demo bersih |
| A10 | §9.2 | Role 1/3/4/5: `/arsip` → ketik < 3 huruf, lalu ≥ 3 huruf NIP/nama PA, pilih saran | < 3 huruf tanpa permintaan; ≥ 3 huruf memanggil `GET pegawai?search=` (`ArsipPemilihPegawai.vue:50`); memilih → `/arsip/<NIP PA>` | ✅ UI `demo.admin`: 2 huruf tanpa permintaan; ≥ 3 huruf satu `GET pegawai?…&search=` 200, 1 saran PA; memilih → `/arsip/<NIP PA>`. Role 3/4/5 memakai komponen yang sama |
| A11 | §9.2 | Breadcrumb di `/arsip/<NIP PA>/9` untuk `demo.admin` dan `demo.pegawai` | **Arsip** › **<nama (gelar)>** › **Jabatan**; tautan "Arsip" untuk pegawai berakhir di arsip sendiri | ✅ UI: Arsip › <nama (gelar)> › Jabatan untuk kedua akun; tautan "Arsip" pegawai berakhir di `/arsip/<NIP PA>` |
| A12 | F10-11 | `demo.admin` dan `demo.pegawai`: periksa `/arsip/<NIP PA>`, `/arsip/<NIP PA>/semua`, dan menu ⋮ (Tahap 1 dan Tahap 2) | Tidak ada tombol unggah, dropzone, atau input berkas di halaman Arsip [K]; unggah tetap lewat form riwayat, Arsip Data Umum, dan layanan (PRD `:65`, `:108`) | ✅ UI: di `/arsip/<NIP PA>`, `/semua`, `/9` dan saat menu ⋮ terbuka `input[type=file]` 0, tanpa dropzone atau tombol unggah; item ⋮ hanya `edit`/`buka`/`hubungkan`/`hapus` |

## B. Folder & daftar berkas (Tahap 1; AC1-05, AC1-06, AC1-09, AC1-11, AC1-13, F10-29)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| B1 | AC1-05 | `demo.admin` `/arsip/<NIP PA>` + API folder | Folder untuk setiap kode yang punya berkas (riwayat, 0, 37, 38, 42–50, NULL); folder 0 berkas tidak dikirim | ✅ API: 22 folder (riwayat 1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 32, 36; 37, 38, 42, 43, 45, 46, 49; 0; NULL), total 73, tanpa folder 0 berkas |
| B2 | AC1-05, F10-30 | `demo.pegawai` (pemilik) dan `demo.eselon1`: buka folder Hukuman Disiplin | Lampiran AR23 **dan** ringkasan riwayatnya tampil, walau tab hukdis B-18 tersembunyi bagi role itu [K] | ✅ UI kedua akun: kartu "Hukuman Disiplin 1 berkas"; AR23 tampil dengan ringkasan riwayat |
| B3 | AC1-06, F10-16 | Folder Alamat / Pendidikan / Organisasi | AR20 (status 10), AR21 (`id_entri` tak ada), AR22 (pengajuan) tampil tanpa badge tambahan; `url_berkas` terisi | ✅ API: AR20 dan AR21 privat ber-URL `buka`, AR22 publik ber-URL; item tanpa kunci badge tambahan (ringkasan AR21 "—") |
| B4 | AC1-09, F10-29 | Urutan kartu di grid | "Semua berkas" paling atas; jenis menurut `jenis_rwy.order`; "Belum Terhubung" lalu "Tanpa Jenis" (AR19) paling bawah; testid `arsip-folder-semua`, `arsip-folder-<id>`, `arsip-folder-0`, `arsip-folder-tanpa_jenis` (`ArsipFolderGrid.vue:53`) | ✅ API + UI: `arsip-folder-semua` teratas; urutan testid jenis = `jenis_rwy` urut `order` di DB; lalu `arsip-folder-0`, `arsip-folder-tanpa_jenis` |
| B5 | AC1-13, F10-22 | Kolom "Data Riwayat" di "Semua berkas" | Ringkasan bentuk `previewRwy` untuk kode 1, 3 (AR27), 5, 7, 9, 36 (AR24 = bentuk 9), 10, 11, 13, 14, 20, 22, 23, 32; kode 37–50 dan baris tak ditemukan "—" | ✅ API: ringkasan terisi untuk 1, 3 (AR27), 5, 7, 9, 10, 11, 13, 14, 20, 22, 32, 36 (AR24 bentuk 9); "—" untuk 0, 37, 38, 42–49, NULL, dan AR21. Kode 23 tidak ada di data uji (dicakup B12) |
| B6 | AC1-11, F10-20/21 | Cari `%`, `_`, `100%_uji`; API `per_page=500`; folder SKP (AR-P) | `%`/`_` literal (hanya AR30 cocok untuk `100%_uji`); `per_page` dijepit 100 (`ListQuery.php:126`, `PER_PAGE_MAKS`); tabel 25 baris + `UiPagination` (`ArsipFolderPage.vue:42`, `:344-346`); urut bawaan `id_attachment` menurun | ✅ API: `q=%` → 1 (AR30); `q=_` → 44 (= hitungan SQL literal); `100%_uji` → AR30 saja; `per_page=500` → 100; bawaan 25 item menurun; folder SKP 31 baris → 25 + 6. UI: `UiPagination` tampil (F11) |
| B7 | §9.2 | `demo.admin` `/arsip/<NIP PD>` | "Pegawai ini belum memiliki berkas arsip." (`arsip-kosong`) tanpa grid | ✅ API 200, total 0; UI `demo.admin`: `arsip-kosong` "Pegawai ini belum memiliki berkas arsip.", tanpa grid dan tanpa kartu folder |
| B8 | §9.2 | `/arsip/<NIP PA>/abc` | "Folder tidak ditemukan." (`ArsipFolderPage.vue:128`), tanpa panggilan daftar; API folder tetap dipanggil (`:190`) kecuali ada cache | ✅ UI: `arsip-failure` "Folder tidak ditemukan." berjudul "Folder"; ada permintaan folder, tanpa permintaan `arsip/berkas`. Folder angka tak dikenal: T-01 |
| B9 | AC1-09 | Bandingkan `jumlah` tiap folder dengan hitungan DB per `id_riwayat` NIP PA | Sama; total = jumlah baris NIP PA | ✅ Hitungan SQL per `id_riwayat` sama persis dengan `jumlah` tiap folder; total 73 = 73 |
| B10 | §8.1 | API `id_jenis_rwy=abc`, `sort=x`, `order=x`, `belum_terhubung=2` | 422 dengan `errors.id_jenis_rwy` / `errors.sort` / `errors.order` (`ArsipService.php:442`, `:235`, `:239`); `errors.belum_terhubung` "Saringan belum terhubung harus 0 atau 1." (`:231`) | ✅ API: keempatnya 422 dengan kunci `errors` dan pesan sesuai |
| B11 | F10-21 | Di "Semua berkas" cari (a) potongan `display_name` sebuah baris ber-`tag` kosong, (b) potongan `filename` baris legacy ber-`tag` dan `display_name` kosong, (c) potongan teks kolom "Data Riwayat" yang tidak ada di `tag`/`display_name`/`filename` | (a) dan (b) cocok; (c) tidak cocok (ringkasan riwayat tidak ikut dicari) [V2] | ✅ API: (a) potongan `display_name` AR7 → AR7; (b) potongan `filename` AR4 → AR4; (c) potongan ringkasan → 0 |
| B12 | AC1-13 | Ringkasan riwayat tanpa N+1 query (satu query per kode per halaman) | [test-otomatis] `backend/tests/Kepegawaian/ArsipEndpointTest.php:543` (`testRingkasanKodeLainDanTanpaNPlusSatu`, menghitung query lewat event `DBQuery`) | [test-otomatis] isi test dibaca: 8 kode (termasuk 23), query riwayat > 0 dan ≤ 8 per halaman |

## C. Status berkas, URL, dan kolom tertutup (Tahap 1; AC1-07, AC1-08, AC1-10, AC1-16)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| C1 | AC1-07 | Baris AR13, AR14 (UI + API) | `berkas_ada: false`, `status_berkas: "hilang"`, `url_berkas`/`akses_berkas`/`jenis_buka` null, badge "Berkas hilang" (`arsip-badge-hilang-<id>`), ⋮ Buka `disabled`; respons 200; kartu folder menghitung `jumlah_hilang` | ✅ API: keduanya sesuai, respons 200; `jumlah_hilang` Pelatihan dan KGB 1 (total 2). UI: badge "Berkas hilang" tampil, ⋮ Buka `disabled` |
| C2 | AC1-08 | Baris AR15, AR16, AR17, AR18 | `status_berkas: "tak_dapat_dibuka"`, `url_berkas` null, badge "Tidak dapat dibuka" (`arsip-badge-tak-dapat-dibuka-<id>`), tanpa 500; dihitung di `jumlah_tak_dapat_dibuka`, bukan `jumlah_hilang`; log backend tidak memuat path | ✅ API: keempatnya sesuai, tanpa 500; `jumlah_tak_dapat_dibuka` total 4, `jumlah_hilang` folder itu 0; tidak ada berkas log backend baru sama sekali selama QA (bukti lemah untuk "log tanpa path"). UI: badge tampil, Buka `disabled` |
| C3 | AC1-10 | Simpan JSON folder + daftar "Semua berkas" (semua halaman), cari **kunci** `path`, `url`, `basename`, `document_id` (daftar AC1-10, PRD `:198`), kunci `filename`, kunci `NIP` (huruf besar, nama kolom tabel; pencarian peka huruf besar/kecil), dan basename 32 hex | Tidak ada satu pun; satu-satunya turunan path = `url_berkas` item `publik`. Yang **sah** ada: `pegawai.nip` di respons folder (`OtorisasiArsip.php:182`) dan NIP di dalam `url_berkas` (path legacy `arsip/<NIP>/…`, URL `buka` C4). Item kini juga memuat kunci `aksi` (Tahap 2) | ✅ Folder + 3 halaman daftar (73 baris) dijelajah rekursif: tanpa kunci terlarang dan tanpa string 32 hex; NIP hanya di `pegawai.nip` dan `url_berkas` (45 publik, 22 privat) |
| C4 | AC1-10, F10-34 | Item AR1 | `akses_berkas: "privat"`, `url_berkas` = `pegawai/<NIP PA>/arsip/berkas/<id>/buka` (tanpa slash awal, tanpa kunci) | ✅ `privat`; `url_berkas` relatif `pegawai/<NIP PA>/arsip/berkas/<id AR1>/buka` |
| C5 | AC1-10, F10-19 | Item AR29 | `nama_arsip` = "Berkas <id_attachment>" | ✅ `nama_arsip` = "Berkas " + `id_attachment` AR29 |
| C6 | AC1-16 | Item AR2, AR3, AR11, AR12, AR26-42 | AR2 `publik` + `pratinjau`; AR3 (`.PDF`) terisi + `pratinjau`; AR11 `publik` + `unduh`; AR12 `privat` + `unduh`; AR26-42 (PMK legacy) `publik` + `pratinjau` (§7.5, BB6) | ✅ Kelima item sesuai |
| C7 | AC1-16, F10-33 | Item AR31, AR32 | AR31 `publik` (`/berkas/assets/upload/…`); AR32 URL ternormalisasi, tiap segmen ter-encode (spasi → `%20`, `\` → `/`, tanpa `//`) | ✅ AR31 publik berawalan `/berkas/assets/upload/`; AR32 publik, `%20`, tanpa `\`, tanpa `//` |
| C8 | §8.1 | API folder dengan akun role 1–7 | `penyajian_berkas_legacy: "publik"`; `aksi` dihitung per aktor (`ArsipService.php:516-529`): role 1 → `tulis: true` + 15 objek `{id_jenis_rwy, label}` urut `jenis_rwy.order`; role 2/6/7 untuk NIP sendiri → `true` + objek kode 38 saja; role 3 dalam lingkup ubah → `true` + 15 objek; role 4/5 → `false`, `[]` | ✅ API: `penyajian_berkas_legacy` "publik" untuk semua akun; `aksi` per role sesuai |
| C9 | F10-24 | Lokal, `.env` `arsip.penyajianBerkasLegacy = privat`: ulangi C6/C7 | Berkas legacy kini `privat` dengan URL `buka`; kunci v2 tidak berubah; `penyajian_berkas_legacy: "privat"`. Kembalikan ke `publik` | ✅ Lokal: folder "privat"; 45 item legacy menjadi privat ber-URL `buka`; item kunci v2 tidak berubah. Dikembalikan ke `publik` (P7 404 legacy terkonfirmasi) |

## D. Catatan pembukaan daftar F10-17 (Tahap 1; AC1-12, F10-26, §8.2)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| D1 | AC1-12 | `demo.admin` buka grid folder PA (`/arsip/<NIP PA>`), lalu dari grid buka "Semua berkas" dan cari, dalam 15 menit | Satu baris `arsip_lihat` `create` bila dibuka berurutan dari grid: `entity_id` = NIP PA, `after_json` `{endpoint: "folder", filter:{id_jenis_rwy: null, belum_terhubung: null, ada_pencarian: false}}` (`ArsipService.php:74`), tanpa nama berkas; daftar sesudahnya kena dedup satu kunci untuk folder + daftar (`ArsipService.php:468-494`; daftar mencatat `belum_terhubung: false`, `:247`). Dua baris **boleh** bila URL folder (`/arsip/<NIP PA>/semua` atau `/9`) dibuka langsung, karena permintaan folder dan daftar berangkat bersamaan (`ArsipFolderPage.vue:190-191`; AC1-12 PRD `:200`; BB18) | ✅ API: folder → daftar → cari berurutan: tepat 1 baris `create`, `entity_id` = NIP PA, kunci `after_json` `endpoint` dan `filter` dengan isi folder sesuai; tanpa nama berkas dan teks `q` |
| D2 | AC1-12 | Ulangi D1 dengan `demo.satker`, `demo.eselon1`, `demo.menteri` | Masing-masing satu baris (aktor berbeda) bila dibuka berurutan dari grid; dua baris boleh untuk URL folder langsung (BB18) | ✅ API: satker → PA 1 baris; eselon1 → PA 1 baris; menteri → PL 1 baris (menteri → PA sudah tercatat di D6 sehingga kena dedup, sesuai kunci aktor + NIP) |
| D3 | AC1-12 | `demo.pegawai` membuka arsip sendiri; `demo.ptt`, `demo.pppk` sama | Tidak ada baris `arsip_lihat` | ✅ API: folder, daftar, cari arsip sendiri: 0 baris baru untuk ketiganya |
| D4 | F10-26 | Sesudah > 15 menit, `demo.admin` buka lagi folder PA | Baris `arsip_lihat` baru | ✅ UI: baris non-ekspor terakhir admin → PA berumur > 15 menit; membuka folder menulis tepat 1 baris `folder` baru; `/semua` segera sesudahnya tidak menambah baris (dedup) |
| D5 | §8.2 fail-closed | Gagal tulis audit → 500 | [test-otomatis] `ArsipEndpointTest::testCatatanPembukaanDaftarFailClosed` (tidak diuji manual: merusak DB) | [test-otomatis] sebagian (K-09): `ArsipEndpointTest.php:312`, `record()` = 0 → RuntimeException untuk folder dan daftar, kunci dedup tidak terisi; diuji di tingkat service (controller tanpa catch → 500 lewat handler global) |
| D6 | AC1-12 | Aktor role 1/3/4/5 yang belum tercatat untuk NIP PA dalam 15 menit memanggil API `…/arsip/berkas?q=uji` **lebih dulu** (tanpa membuka folder) | Satu baris `arsip_lihat`: `after_json.endpoint = "berkas"`, `filter` = `{id_jenis_rwy: null, belum_terhubung: false, ada_pencarian: true}` (`ArsipService.php:143`, `:247`), tanpa teks `q` di mana pun dalam baris | ✅ `demo.menteri`: 1 baris `endpoint` "berkas", `filter` sesuai; teks `q` tidak ada |

## N. Jalur publik berkas legacy lewat Nginx (Tahap 1; F10-18 (a), F10-25, §7.3, G10-4) — semua [S]

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| N1 | §7.3 | [S] `curl -sI` URL publik AR2, AR4, AR5, AR6, AR26-42 | 200; `Content-Type` `application/pdf` / `image/jpeg` / `image/png` / `image/gif`; `Content-Disposition: inline`; `X-Content-Type-Options: nosniff`; `Cache-Control: no-store`; `Referrer-Policy: no-referrer`; CSP sesuai snippet (atau nilai akhir G10-4) | ◻ [S] menunggu G10-4 |
| N2 | F10-33 | [S] `curl -sI` AR31 (`assets/upload/…`) dan AR3 (`.PDF`) | 200, tipe pratinjau, `inline` | ◻ [S] menunggu G10-4 |
| N3 | §7.3 | [S] `curl -sI` URL publik tanpa login dan tanpa cookie | 200 (tanpa cek izin, keputusan sadar F10-18 (a); lihat BB1) | ◻ [S] menunggu G10-4 |
| N4 | F10-32 | [S] `curl -sI` AR11 (`.docx`) | `application/octet-stream` + `attachment` | ◻ [S] menunggu G10-4 |
| N5 | F10-25, R5 | [S] `curl -sI` AR28 (`.html`, `.svg`) dan AR33 (`.php`) | `application/octet-stream` + `attachment`; `.php` tidak dieksekusi (isi mentah, tanpa keluaran PHP) | ◻ [S] menunggu G10-4 |
| N6 | F10-18 (b) | [S] `curl -sI /berkas/YYYY/MM/<kunci AR1>.pdf` (berkas ada di disk) | **404** | ◻ [S] menunggu G10-4 |
| N7 | §7.3 butir 2 | [S] `curl -sI` AR16 (`kolom/…`), AR17 (`form-digital/…`), AR15 (segmen titik), `/berkas/arsip/<NIP PA>/` (folder), `/berkas/arsip/%2e%2e/%2e%2e/cache/<…>` | 404 (traversal 400/404), tidak pernah isi `writable/cache`, tanpa listing | ◻ [S] menunggu G10-4 |
| N8 | AC1-07 | [S] `curl -sI` URL publik AR13 (berkas hilang) | 404 | ◻ [S] menunggu G10-4 |
| N9 | §7.3 butir 4 | [S] Operator: `tail` log akses Nginx sesudah N1–N8 | Tidak ada baris `/berkas/` | ◻ [S] menunggu G10-4 |
| N10 | §7.3 butir 5 | [S] Operator: `sudo -u <user-nginx> test -r <berkas AR2>` | OK; hak `0640`/`0750` tidak diubah | ◻ [S] menunggu G10-4 |
| N11 | §7.3 butir 1, B10-1 | [S] Operator: `find <akar lampiran> -type l` | Kosong | ◻ [S] menunggu G10-4 |
| N12 | §9.1 | [S] SPA: muat ulang `/arsip/<NIP PA>/9` di browser | Halaman SPA (bukan 404 Nginx); `/arsip/…` tidak bertabrakan dengan `/berkas/…` | ◻ [S] menunggu G10-4 |

## P. Jalur privat `buka` — API (Tahap 1; AC1-17, F10-34..F10-36)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| P1 | AC1-17 | `demo.admin`: `curl -D -` `buka` AR1 | 200; `Content-Type: application/pdf`; `Content-Disposition: inline; filename="…"; filename*=UTF-8''…` (nama = nama arsip + `.pdf`); `nosniff`; `Cache-Control: private, no-store`; `Referrer-Policy: no-referrer`; CSP = `Config\Arsip::CSP_BERKAS` (`Config/Arsip.php:64`) | ✅ 200 `application/pdf`, `inline` + `filename*` = nama arsip + `.pdf`, `nosniff`, `private, no-store`, `no-referrer`, CSP = `CSP_BERKAS`; audit +1 |
| P2 | AC1-17 | `buka` AR7 (`.png`), AR8 (`.pdf`), AR25 (kode 45), AR26 (43/46/49) | 200, tipe dari daftar tertutup, `inline` | ✅ Semua 200 `inline`: AR7 `image/png`; AR8, AR25, AR26-43/46/49 `application/pdf`; audit +1 per panggilan |
| P3 | AC1-17 | `buka` tanpa token | 401 | ✅ 401, audit +0 |
| P4 | AC1-17 | Matriks: role 1/4/5 → PA; role 3 → PA (200) dan PL (403); role 2/6/7 → NIP sendiri (200) dan NIP lain (403); role 8 → 403 | Sesuai §12.1 | ✅ Role 1/4/5 → PA dan PL 200; role 3 → PA 200, PL 403; role 2/6/7 → sendiri 200, NIP lain 403; role 8 → 403 `Forbidden` |
| P5 | F10-34 (4) | `buka` AR12 (`.docx`, `tag` tanpa ekstensi) | `application/octet-stream` + `attachment`, nama berkas berakhiran `.docx` | ✅ 200 `application/octet-stream` + `attachment`, nama = `tag` + `.docx` |
| P6 | AC1-17 | `buka` `{id}` milik PL lewat `pegawai/<NIP PA>/…` | 404 "Arsip tidak ditemukan." (`ArsipService.php:354`) | ✅ 404 "Arsip tidak ditemukan." |
| P7 | F10-34 (3) | `buka` AR2 (legacy) saat saklar `publik` | 404 "Berkas arsip tidak tersedia." (`ArsipService.php:362`) | ✅ 404 "Berkas arsip tidak tersedia." |
| P8 | AC1-17 | `buka` AR14 (hilang), AR15 (tak cocok regex) | 404, log tanpa path | ✅ Keduanya 404 "Berkas arsip tidak tersedia."; tidak ada berkas log backend baru sama sekali selama QA (bukti lemah untuk "log tanpa path") |
| P9 | AC1-17, F10-16 | `buka` AR20 (riwayat status 10), AR21 (`id_entri` tak ada) | 200 (berbeda dengan unduh B-18 yang 404, lihat BB9) | ✅ Keduanya 200 `application/pdf`, audit +1 |
| P10 | F10-35 | Sesudah P1–P9, cek `audit_logs` | Satu baris `arsip_unduh` `create` per 200 (termasuk `demo.pegawai` membuka miliknya sendiri), `entity_id` = NIP sasaran, `after_json` = `{id_attachment, id_riwayat, jenis_buka}` saja; 401/403/404 tidak menulis baris | ✅ 34 panggilan P1–P9/P18: 23 × 200 masing-masing tepat 1 baris `create`; 11 × 401/403/404 0 baris; kunci `after_json` tiga itu saja; `entity_id` = NIP sasaran; 1 baris beraktor `demo.pegawai` (miliknya) |
| P11 | F10-24 | Lokal, saklar `privat`: `buka` AR2, AR31 | 200 lewat `buka` dengan header, audit, throttle yang sama | ✅ Lokal: AR2 200 `application/pdf`, AR31 200 `image/jpeg`, `inline`, header sama, audit +1 masing-masing; saklar dikembalikan |
| P12 | F10-36 | Prasyarat: tunggu ≥ 60 detik sejak `buka` terakhir akun itu (P1–P11 memakai ember yang sama, throttle `ArsipService.php:366`), atau pakai akun yang belum membuka. `demo.admin`: 7 permintaan `buka` AR1 beruntun tanpa jeda | 6 × 200 lalu 429 "Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit." (`Config/Arsip.php:82`, `:84`) | ✅ 6 × 200 lalu 429 dengan pesan itu; audit 6 baris (429 tidak dicatat) |
| P13 | F10-36 | Tunggu ±10 detik sesudah 429, `buka` sekali; lalu langsung sekali lagi | 200 lalu 429 (token bucket, 1 jatah per 10 detik) | ✅ ±11 detik sesudah 429: 200, lalu langsung 429 |
| P14 | F10-36 | Saat jatah habis: `buka` AR14 (404) dan `buka` `{id}` NIP lain (404/403) | Tetap 404/403, bukan 429 (penolakan pasti dicek sebelum throttle: `ArsipService.php:345-363`, throttle `:366`) | ✅ Kontrol AR1 = 429; AR14 404, `{id}` PL lewat NIP PA 404; bukan 429 |
| P15 | F10-36 | Habiskan jatah `buka`, lalu unduh Dokumen F-09 dengan akun yang sama | Unduhan Dokumen tidak terkena 429 dari ember arsip (ember terpisah) | ✅ Saat jatah habis unduh DUK F-09 200 (xlsx); kontrol `buka` sesudahnya tetap 429 |
| P16 | F10-35 | `record()` mengembalikan 0 → tetap 200 | [test-otomatis] `ArsipEndpointTest::testBukaAuditNolTetapBerhasil` | [test-otomatis] `ArsipEndpointTest.php:602` (tingkat service: `buka()` tetap `application/pdf` inline tanpa exception; K-09) |
| P17 | AC1-17 | Satu akun (ember per akun): `demo.pegawai` (pemilik PA), jatah penuh (tunggu > 60 detik sejak `buka` terakhir akun itu). 10× `buka` AR14 (404), 3× `buka` lewat `pegawai/<NIP pemohon>/…` (403), lalu langsung 6× `buka` AR1 | Keenam `buka` AR1 = 200; baru permintaan ke-7 yang 429 (penolakan 403/404 tidak memakan jatah, PRD `:205`, `ArsipService.php:345-366`) | ✅ 10 × 404, 3 × 403, lalu 6 × 200 dan ke-7 429 |
| P18 | F10-30, §7.5 | `demo.eselon1` (role 4) dan `demo.menteri` (role 5): `buka` AR25 dan AR26-43 lewat Arsip; lalu endpoint berkas layanan cuti/PMK untuk berkas yang sama dengan akun itu | Lewat Arsip 200; endpoint layanan mengikuti aturannya sendiri (ditolak bila role itu tidak berhak di layanan) — perbedaan ini sesuai keputusan (BB6) | ✅ Lewat Arsip 200; endpoint lampiran cuti dan arsip PMK 403 `Forbidden` (filter role layanan), sesuai BB6 |

## F. Jalur privat — FE popup + blob (Tahap 1; AC1-18, F10-37, §9.3)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| F1 | AC1-18 | ⋮ (`arsip-actions-<id AR1>`, aria-label "Aksi untuk <nama arsip>") → Buka, tab Network terbuka | Popup 600×600 `arsip-popup` langsung terbuka dengan "Memuat berkas…", lalu pratinjau PDF (URL `blob:`); permintaan `buka` lewat klien API | ✅ UI (popup disimulasikan, lihat Catatan pelaksanaan): aria-label sesuai; `window.open` `arsip-popup` `width=600,height=600`, isi "Memuat berkas…", `buka` 200 lewat klien API, lalu `blob:` `application/pdf` |
| F2 | AC1-18 | ⋮ → Buka AR2 (publik) | `window.open` URL publik (popup 600×600, `noopener`), tanpa permintaan API | ✅ (argumen `window.open` direkam lewat pembungkus; popup asli tidak terbuka) `window.open` URL publik, `arsip-popup`, `width=600,height=600,noopener`; tanpa permintaan `buka` |
| F3 | AC1-18 | Selama AR1 dimuat, buka ⋮ baris privat lain dan baris publik | Buka privat lain `disabled`; Buka publik tetap aktif (`useBukaBerkasArsip.ts:97-100`) | ✅ (`buka` diperlambat di klien) Buka privat lain `aria-disabled`; Buka publik aktif; AR1 tetap menjadi `blob:` |
| F4 | AC1-18 | ⋮ → Buka AR12 (privat `unduh`) | Tanpa popup; berkas terunduh dengan nama berakhiran `.docx` | ✅ Tanpa `window.open`; `buka` 200; unduhan blob bernama berakhiran `.docx` (klik dicegat, berkas tidak disimpan) |
| F5 | AC1-18 | Blokir popup di pengaturan browser, ⋮ → Buka AR1 | Pesan "Popup diblokir browser. Izinkan popup untuk situs ini." (`arsip-buka-galat`), tanpa permintaan API | ✅ Teramati nyata (browser uji memblokir popup): `arsip-buka-galat` dengan pesan itu; tanpa permintaan `buka` |
| F6 | AC1-18 | Prasyarat: ≥ 60 detik sejak `buka` terakhir akun itu (lihat P12). Buka 7 berkas privat berbeda beruntun cepat; klik berikutnya sesudah berkas sebelumnya tampil (selama satu berkas privat dimuat, Buka privat lain `disabled`, `useBukaBerkasArsip.ts:97-100`) | Ke-7: popup tertutup, pesan 429 di halaman (`UiNotice`, bisa ditutup), bukan JSON mentah | ✅ (popup disimulasikan) `demo.satker`, 7 berkas privat berbeda: 6 × 200 + `blob:`; ke-7 429 → popup ditutup, `UiNotice` pesan 429 dengan "Tutup pesan"; tanpa JSON mentah |
| F7 | AC1-18 | Hapus cookie `access_token` (cookie refresh dibiarkan), ⋮ → Buka AR1 | Refresh berhasil; pratinjau tampil; tanpa JSON 401 mentah | ✅ (popup disimulasikan) `buka` 401 → `auth/refresh` 200 → `buka` 200; pratinjau `blob:`; tanpa pesan galat |
| F8 | AC1-18 | Hapus semua cookie sesi, ⋮ → Buka AR1 | Popup tertutup; halaman dialihkan ke `/login` tanpa pesan tambahan | ✅ (popup disimulasikan; sesi diakhiri lewat `auth/logout`) `buka` 401 → refresh 401 → popup ditutup → `/login?redirect=…`; tanpa notice |
| F9 | F10-37 (6) `:140` dan §9.3 `:521` (v1.5 sudah selaras; docblock K-08) | 403 `password_change_required` saat Buka | Popup tertutup, pesan server atau cadangan "Anda harus mengganti password terlebih dahulu." tampil (tidak memicu handler interceptor). Bila sulit dipicu manual → [test-otomatis] `useBukaBerkasArsip.spec.ts:264`, `:274` | [test-otomatis] sebagian: `useBukaBerkasArsip.spec.ts:264`, `:274` lolos (Vitest 30/30); spec me-mock `ambilBerkas`, jadi premis "tidak memicu handler interceptor" tidak diuji di sana; perilaku interceptor diuji terpisah di `frontend/src/lib/__tests__/axios.spec.ts:187` (tidak dijalankan dalam QA ini) |
| F10 | F10-37 (5) | Buka AR1, tunggu > 60 detik, lalu buka lagi; pindah halaman saat berkas dimuat | Pratinjau tetap tampil sebelum jeda; sesudah pindah halaman popup basi tertutup. Pencabutan object URL dibuktikan [test-otomatis] `useBukaBerkasArsip.spec.ts:168`, `:183`, `:340` | ✅ (popup disimulasikan) Pindah halaman saat dimuat → popup basi ditutup, object URL tidak dibuat; pratinjau masih `blob:` ±30 detik sesudah dibuka; buka ulang sesudah 60 detik tidak dijalankan. Pencabutan [test-otomatis] `:168`, `:183`, `:340` lolos |
| F11 | AC1-18 | Ganti halaman/pencarian sesudah pesan galat tampil | Pesan galat Buka hilang (`ArsipBerkasTable.vue:38-44`) | ✅ Pesan galat hilang saat pencarian diganti dan saat "Halaman berikutnya" |
| F12 | AC1-18 | Tipe blob pratinjau dari daftar tertutup menurut ekstensi, bukan dari tipe respons (respons `text/html` bernama `.pdf` → `application/pdf`; ekstensi lain → `application/octet-stream`; PRD `:569`) | [test-otomatis] `useBukaBerkasArsip.spec.ts:148` | [test-otomatis] `useBukaBerkasArsip.spec.ts:148` lolos (daftar tertutup juga `:85-99`) |

## R. Pratinjau di Chrome, Edge, Firefox (Tahap 1; F10-10, G10-4, §11.3) — R1–R5 [S]; R6 bersama bagian R di server dev

Satu kolom hasil per butir; tuliskan hasil per browser (mis. "C ✅ / E ✅ / F ❌ T-0x").

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| R1 | F10-10 | [S] Jalur publik: ⋮ → Buka AR2 (PDF), AR4 (JPG), AR5 (PNG) | Pratinjau tampil di popup di ketiga browser | ◻ [S] menunggu G10-4 |
| R2 | F10-10 | [S] Jalur privat: ⋮ → Buka AR1 (PDF), AR7 (PNG), AR34 (JPG kunci v2) | Pratinjau tampil (popup + blob) di ketiga browser | ◻ [S] menunggu G10-4 |
| R3 | F10-37 (5) | [S] Popup blob PDF: tombol unduh dan cetak penampil PDF, **sebelum** dan **sesudah** 60 detik | Berfungsi; bila gagal sesudah pencabutan, catat per browser di §T dan di tabel G10-4 | ◻ [S] menunggu G10-4 |
| R4 | R5 | [S] ⋮ → Buka AR28 (`.html`, `.svg`) | Terunduh, tidak dirender, tidak ada `alert` | ◻ [S] menunggu G10-4 |
| R5 | F10-34 (4) | [S] Dalam keadaan login, buka `api/v1/pegawai/<NIP PA>/arsip/berkas/<id AR1>/buka` langsung sebagai tab | Pratinjau tampil (CSP endpoint tidak mematahkan penampil); bila patah, catat nilai CSP yang dipakai (pratinjau didahulukan) | ◻ [S] menunggu G10-4 |
| R6 | §5.1 | Peramban ponsel (atau emulasi): ⋮ → Buka AR2 dan AR1 | Terbuka sebagai tab (bukan bug, BB15) | ◻ bersama bagian R di server dev (tidak dijalankan di lokal) |

## V. Tampilan & viewport (AC1-15, §9.2, §9.3)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| V1 | AC1-15 | Viewport **1440** px: `/arsip`, `/arsip/<NIP PA>`, `/arsip/<NIP PA>/semua` | Tanpa scroll horizontal halaman | ✅ `scrollWidth` ≤ `innerWidth` 1440 di ketiga halaman; grid 3 kolom |
| V2 | AC1-15 | Viewport **390** px: halaman yang sama | Tanpa scroll horizontal halaman; grid folder satu kolom (`ArsipFolderGrid.vue:48`); tabel menggulir di dalam kartu, kolom Aksi **sticky** di kanan (`ArsipBerkasTable.vue:78`, `:109`) | ✅ Ketiga halaman `scrollWidth` 390 = `innerWidth`; grid satu kolom; tabel menggulir di wadah `overflow-x: auto`; th/td Aksi `position: sticky`, `right: 0` |
| V3 | §9.2 | Nama arsip/ringkasan panjang (AR30) | Terpotong rapi + tooltip; `<b>` tampil sebagai teks (tanpa `v-html`) | ✅ Ellipsis + tooltip berisi teks lengkap; elemen `<b>`/`<script>` di tabel 0; `v-html` tidak dipakai di komponen arsip (lihat Catatan pelaksanaan) |
| V4 | §9.3 | Menu ⋮ per role (Tahap 2, `ArsipBerkasTable.vue:48-56`) | Role 4/5: hanya "Buka". Role 1/2/3/6/7: Edit + Buka, ditambah Hubungkan + Hapus pada baris kode 0; kode 37 bagi selain role 1: Edit `hidden`. Buka `disabled` bila hilang/tak dapat dibuka | ✅ UI admin, satker, pegawai, eselon1, menteri, ptt, pppk sesuai; AR8 (37) bagi satker dan pegawai hanya Buka; Buka `disabled` pada AR13–AR18. ptt/pppk: tanpa baris kode 0 tersisa di UI (Edit + Buka saja); Hubungkan/Hapus role 6/7 dicakup W7 (API) |
| V5 | §9.2 | Keadaan memuat / kosong / galat (folder & daftar, putus jaringan) | Skeleton; "Belum ada berkas." / "Tidak ada berkas yang cocok dengan pencarian."; galat daftar + "Coba lagi" (`arsip-coba-lagi`, `ArsipFolderPage.vue:330`); galat folder di halaman tabel `arsip-folder-coba-lagi` (`:302`); grid folder (`ArsipPage.vue:84`) cukup `arsip-failure` tanpa "Coba lagi" (PRD §9.2 tidak mewajibkan) | ✅ galat disimulasikan di klien (permintaan diarahkan ke port lokal yang menolak koneksi; backend tidak disentuh): daftar → `arsip-failure` "Tidak dapat terhubung ke server. …" + `arsip-coba-lagi`; folder di halaman tabel → `arsip-galat-folder` + `arsip-folder-coba-lagi`, sesudah "Coba lagi" judul folder kembali; grid folder → `arsip-failure` tanpa grid. Skeleton saat memuat ulang; "Tidak ada berkas yang cocok dengan pencarian." tampil; "Belum ada berkas." teramati pada URL T-01 |
| V6 | §9.3 (Tahap 2) | Ulangi V1–V2 dengan filter, tombol ekspor, dan dialog Edit/Hubungkan/Hapus terbuka | Tanpa scroll horizontal; dialog terbaca di 390 px | ✅ 1440 dan 390 px: menu Ekspor dan ketiga dialog terbuka, `scrollWidth` = `innerWidth`; dialog 390 px utuh tanpa anak meluber (dibuka lalu Batal, tanpa mutasi). Kosmetik: T-04 |

## M. Matriks role 1–8 (PRD §12.1)

Uji tiap baris dengan API (dan UI bila ada). "sendiri" = NIP akun itu; "lingkup" = PA (dalam) dan PL (luar) untuk `demo.satker`.
Kolom endpoint Tahap 2 dijalankan sesudah MAKE-092/096 di `main` (✔).

| # | Role (akun) | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| M1 | 1 `demo.admin` | Folder/daftar/`buka` PA & PL; T2: ekspor, `opsi-hubungkan`, PATCH (termasuk kode 37), `hubungkan`, DELETE | Semua 200/berhasil; kode 37 boleh diubah | ✅ API: baca/`buka` PA dan PL 200; `opsi-hubungkan` 9 memuat RW1 tanpa RW4; PATCH AR8 (37) 200; `hubungkan`, DELETE, ekspor 200; tiap mutasi 1 audit |
| M2 | 2 `demo.pegawai` | Sendiri vs `demo.pemohon` | Sendiri 200; NIP lain 403. T2: ekspor/PATCH/DELETE sendiri boleh, PATCH kode 37 → 403; `opsi-hubungkan` sendiri `id_riwayat=38` → 200 `{butuh_data: false, items: []}`, kode lain F10-28 → 403 "Data yang sudah disetujui tidak dapat diubah." (`AksiArsip.php:152`; semua Definisi mengunci, `RiwayatDefinisi.php:99-102`), NIP lain → 403 "Anda tidak berhak mengubah data pegawai ini." (`OtorisasiArsip.php:87`); `hubungkan` hanya ke tujuan tidak terkunci (saat ini 38) | ✅ API: opsi 38 200, 9/7 403 kunci, 37 422; PATCH AR8 403 data umum, PATCH lain 200; `hubungkan` AR-H6 → 38 200, ke RW1/RW2 403 kunci; DELETE AR10b 200; ekspor sendiri 200; NIP lain 403 |
| M3 | 3 `demo.satker` | PA (dalam lingkup) vs PL (luar) | Baca/`buka`/ekspor PA 200, PL 403. T2 tulis dan `opsi-hubungkan`: lingkup **ubah**; PATCH kode 37 → 403 | ✅ API: baca/`buka`/ekspor PA 200, PL 403; `opsi-hubungkan` PA 9 200 tanpa kunci, PL 403; PATCH AR8 403 data umum, PATCH AR7 200; `hubungkan` AR10 → RW1 200; DELETE AR-DS 200; semua tulis ke PL 403 |
| M4 | 4 `demo.eselon1` | PA & PL | Baca/`buka`/ekspor 200. T2 tulis + `opsi-hubungkan` 403; item tulis ⋮ `hidden` | ✅ API: baca/`buka`/ekspor PA dan PL 200; tulis + `opsi-hubungkan` 403 `Forbidden`; `aksi` folder/item false. UI: semua baris hanya Buka (V4) |
| M5 | 5 `demo.menteri` | PA & PL | Sama dengan M4 | ✅ Sama dengan M4 (API + UI) |
| M6 | 6 `demo.ptt` | Sendiri vs PA | Sama dengan M2 | ✅ API PB: opsi 38 200, 9 403 kunci; PATCH PB-38 200; `hubungkan` PB-0 → 38 200, ke riwayat jabatan Disetujui sendiri 403 kunci; DELETE PB-0b 200; NIP PA 403 lingkup |
| M7 | 7 `demo.pppk` | Sendiri vs PA | Sama dengan M2 | ✅ API PC: sama dengan M6 (PC-38, PC-0, PC-0b); NIP PA 403 lingkup |
| M8 | 8 `demo.pimpinan` | Semua endpoint F-10 | 403 `Forbidden` (filter route); tanpa menu/tombol | ✅ API: folder, daftar, `buka`, ekspor, `opsi-hubungkan`, PATCH, `hubungkan`, DELETE 403 `Forbidden`. UI: tanpa menu (A9) dan tombol header (A7) |
| M9 | — | URL publik `/berkas/…` berkas legacy [S] | Tanpa cek izin untuk siapa pun (BB1) | ◻ [S] menunggu G10-4 |
| M10 | §11.1 | Baris kontrak role di test | [test-otomatis] `backend/tests/feature/KontrakMatriksRoleTest.php:414-416` (Tahap 1) + `:419-423` (Tahap 2 MAKE-092) | [test-otomatis] isi dibaca: ekspor role 1–7; `opsi-hubungkan`, PATCH, `hubungkan`, DELETE role 1/2/3/6/7; ditegakkan `:572` (kontrak = filter route) dan `:598` (401/403) |

## W. Tahap 2 umum (Tahap 2; AC2-01..AC2-04, AC2-11, F10-38)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| W1 | AC2-01 | Daftar route `php spark routes` (filter `arsip`) | Aksi tulis hanya `PATCH …/arsip/berkas/{id}`, `POST …/hubungkan`, `DELETE …/arsip/berkas/{id}` (`RoutesPegawai.php:103-108`, role 1, 2, 3, 6, 7); tidak ada GET yang mengubah data; tanpa token CSRF terpisah (BB16) | ✅ GET folder/berkas/`buka`/ekspor role 1–7, `opsi-hubungkan` role 1/2/3/6/7; tulis hanya tiga route itu (role 1/2/3/6/7); filter `cors jwt role`, tanpa `csrf` |
| W2 | AC2-02 | Role 4/5/8 → semua aksi tulis + `opsi-hubungkan` | 403; di FE item Edit/Hubungkan/Hapus `hidden` untuk 4/5 | ✅ API: 28/28 cek 403 `Forbidden`, tanpa audit; `aksi` false. UI: role 4/5 hanya Buka (V4) |
| W3 | AC2-03 | Role 2/6/7 → NIP lain (403); `{id}` PL lewat `pegawai/<NIP sendiri>/…` (404) | 403 / 404 (anti-IDOR) | ✅ API 31/31 cek: NIP lain 403 "Anda tidak berhak mengubah data pegawai ini."; `{id}` milik orang lain lewat NIP sendiri 404 "Arsip tidak ditemukan." |
| W4 | AC2-04 | `demo.satker` → aksi tulis PL | 403 | ✅ PATCH, `hubungkan`, DELETE, `opsi-hubungkan` ke PL 403, tanpa audit |
| W5 | AC2-11 | Buka ⋮ baris kode 0 dan baris terhubung (pilih item lewat `data-action`); uji `disabled` lewat Hapus | Urutan Edit → Buka → Hubungkan → Hapus (`danger`, garis pemisah); Hubungkan/Hapus `hidden` pada baris bukan kode 0; selama Hapus diproses (`memproses = menghapus`, `ArsipFolderPage.vue:339`) item tulis lain `disabled` (Edit dan Hubungkan berjalan di dialog modal) | ✅ UI: `data-action` edit → buka → hubungkan → pemisah → hapus (merah); baris kode 9 hanya edit + buka; selama DELETE (diperlambat di klien) edit/hubungkan/hapus baris lain `disabled`, buka aktif |
| W6 | §8.1 | API folder dengan akun role 1–7 | `aksi.tulis` sesuai role; `aksi.jenis_hubungkan` = daftar objek `{id_jenis_rwy, label}` (README `:325-330`, `ArsipService.php:525`): role 1 dan role 3 dalam lingkup 15 objek; role 2/6/7 kode tidak terkunci saja (saat ini `[{id_jenis_rwy: 38, label: …}]`); role 4/5 kosong | ✅ Role 1/3: `tulis: true`, 15 objek berlabel; role 2/6/7: `true`, satu objek 38 "Data Umum"; role 4/5: `false`, `[]` |
| W7 | §8.1 | API daftar: `aksi.edit/hubungkan/hapus` per baris | Sesuai role, lingkup ubah, kode 0, dan kode 37 (role ≠ 1 → `edit: false`) | ✅ Semua baris dibandingkan dengan aturan: 0 selisih (admin, pegawai, satker, eselon1, menteri 73 baris; ptt 7; pppk 9) |

## E. Edit / Ubah nama (Tahap 2; AC2-08, AC2-13, F10-31)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| E1 | AC2-08 | `demo.pegawai` Edit AR2 (terhubung ke riwayat Disetujui) → nama baru | Berhasil [K] (BB12); satu audit `update` `document_attachment`; FE: dialog "Ubah Nama Arsip" (`arsip-edit-dialog`), pesan "Nama arsip diubah." (`arsip-pesan`) | ✅ API: PATCH AR2 200, `id_riwayat` tetap 9, 1 audit `update`. UI (admin, AR30): dialog "Ubah Nama Arsip", PATCH 200, "Nama arsip diubah.", baris langsung diperbarui, 1 audit |
| E2 | AC2-08 | Edit dengan `<b>x</b>`, byte kontrol, spasi di tepi | Tag HTML & byte kontrol dibuang, di-trim; tampil sebagai teks biasa | ✅ API: tag, byte kontrol (BEL/NUL/LF), ZWSP, dan spasi tepi dibuang/di-trim; `<` + LF + `script>` → byte kontrol dibuang lalu tag dibuang. Tag tak tertutup: T-02 |
| E3 | AC2-08 | API: Edit kosong, hanya spasi, hanya `<b></b>`, 256 karakter; UI: dialog Edit dengan isian kosong, spasi, 256 karakter | API 422 `errors.tag` (`AksiArsip.php:185-195`); di UI kosong/spasi/> 255 code point ditolak di klien tanpa permintaan (`ArsipEditDialog.vue:44-51`, pesan sama); hanya `<b></b>` yang sampai ke server; 255 karakter berhasil | ✅ API: 422 `errors.tag` untuk kosong, spasi, `<b></b>`, spasi Unicode, byte kontrol saja, null, 256 ASCII, 256 code point `é`; 255 code point `é` 200. UI: kosong/spasi "Nama arsip wajib diisi.", 256 "Nama arsip maksimal 255 karakter." tanpa permintaan; 255 tersimpan |
| E4 | AC2-13, F10-31 (b) | Edit AR8 (kode 37): `demo.pegawai`, `demo.satker` (UI + API), `demo.ptt`, `demo.pppk` (API lewat NIP PA); lalu `demo.admin` | Role 2/3: item Edit `hidden`, API 403 "Anda tidak berhak mengubah arsip data umum pegawai ini." (`AksiArsip.php:251`). Role 6/7 (AR8 milik PA): API 403 lingkup "Anda tidak berhak mengubah data pegawai ini." (`OtorisasiArsip.php:87`), UI dialihkan ke arsip sendiri; pesan data umum untuk role 6/7 butuh baris kode 37 milik PB/PC (tidak ada di data uji). Role 1 berhasil | ✅ API: pegawai dan satker 403 pesan data umum; ptt/pppk 403 lingkup; admin 200 + 1 audit. UI: AR8 bagi satker dan pegawai hanya Buka, bagi admin Edit + Buka |
| E5 | QF10-8 | Edit AR7 (kode 38) oleh `demo.pegawai` dan `demo.satker` | Berhasil (kode 38 ikut legacy, tanpa `bolehTulis()`) | ✅ API: PATCH 200 oleh keduanya, masing-masing 1 audit `update` |
| E6 | F10-29 | Baris AR19 (Tanpa Jenis) | Edit + Buka; Hubungkan/Hapus `hidden` (`OtorisasiArsip.php:122-126`) | ✅ API: `aksi` `{edit: true, hubungkan: false, hapus: false}`; PATCH 200 (`id_riwayat` tetap NULL); `hubungkan`/DELETE 422. UI: hanya edit + buka |
| E7 | AC2-08 | Form Arsip Data Umum (dialog 37/38) | Batas 100 karakter di form itu tidak berubah | ✅ `ArsipDataUmum.php:79-80`, `:91-92` tetap "maksimal 100 karakter"; API unggah 101 karakter → 422 untuk 37 dan 38 (Lain-lain); tanpa baris baru |

## H. Hubungkan (Tahap 2; AC2-05..AC2-07, AC2-12, AC2-13, F10-28, F10-38)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| H1 | F10-28 | `demo.admin` ⋮ → Hubungkan AR9 (sesudah H2: baris kode 0 lain): daftar jenis | Himpunan 1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, 38 (`OtorisasiArsip.php:62`), ditampilkan sebagai label urut `jenis_rwy.order` (`ArsipService.php:522-525`); tanpa 37, 39–42, 43–50. Cocokkan himpunannya, bukan urutan angka | ✅ API: 15 objek, himpunan sesuai. UI (Y2b): dialog "Hubungkan Arsip" (`arsip-hubungkan-dialog`) 15 label; memilih Jabatan → `opsi-hubungkan?id_riwayat=9` 200 memuat RW1; Batal tanpa POST |
| H2 | AC2-06 | `demo.admin` Hubungkan AR9 → Jabatan RW1 | Berhasil; `id_riwayat` 9, `nama_riwayat` = "Jabatan", `id_entri` = RW1; satu audit `update` | ✅ API 200; DB `id_riwayat` 9, `nama_riwayat` "Jabatan", `id_entri` = RW1; `aksi` hubungkan/hapus menjadi false; 1 audit `update` |
| H3 | AC2-12 | Sesudah H2 | Baris pindah ke folder Jabatan; jumlah folder Belum Terhubung/Jabatan berubah tanpa muat ulang penuh; pesan "Arsip dihubungkan." | ✅ API: Belum Terhubung −1, Jabatan +1. UI (AR28-svg → RW1): "Arsip dihubungkan.", baris hilang dari Belum Terhubung, kartu folder ter-update lewat navigasi SPA tanpa muat ulang penuh |
| H4 | AC2-06 | API `hubungkan` ke RW3 (bukan Disetujui), RW4 (NIP lain), id tak ada; jenis 37 / 43 | 404 untuk RW3/RW4/tak ada; 422 untuk jenis di luar F10-28 | ✅ RW3 (status 2), riwayat pengajuan, RW4, riwayat PL, id tak ada → 404 "Data riwayat tidak ditemukan."; jenis 37/43/0 → 422 `errors.id_riwayat`; `id_entri` tak kanonis → 422 `errors.id_entri`; tanpa audit |
| H5 | AC2-05 | `hubungkan` dan DELETE pada AR2 (sudah terhubung) | 422 `errors.id_attachment` "Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat." | ✅ Admin dan pegawai: 422 `errors.id_attachment` dengan pesan [K] persis (DELETE di X4) |
| H6 | AC2-13 | `demo.pegawai` / `demo.ptt` / `demo.pppk`: dialog Hubungkan; API `opsi-hubungkan?id_riwayat=9`; API `hubungkan` ke RW1 (pegawai) dan ke riwayat jabatan Disetujui milik sendiri (ptt/pppk); ke 38 | Dialog hanya menawarkan Data Umum (38) dengan teks "Arsip Data Umum tidak memerlukan pilihan data riwayat." (`arsip-hubungkan-tanpa-data`); `opsi-hubungkan` 9 → 403 kunci; `hubungkan` ke riwayat Disetujui → 403 "Data yang sudah disetujui tidak dapat diubah."; ptt/pppk ke RW1 lewat NIP sendiri → 404 "Data riwayat tidak ditemukan." (404 tujuan sebelum 403 kunci, `AksiArsip.php:105-106`), lewat NIP PA → 403 lingkup; ke 38 berhasil (`id_entri` NULL) | ✅ API: ketiga akun sesuai (AR-H6, PB-0, PC-0 → 38 200, DB `id_entri` NULL). UI pegawai: dialog hanya "Data Umum", tanpa select Data Riwayat, teks `arsip-hubungkan-tanpa-data` sesuai; Batal tanpa simpan |
| H7 | AC2-13 | `demo.satker` Hubungkan AR10 → RW1 (sesudahnya AR10 kode 9; X5 memakai AR10b) | Berhasil (role 3 tidak terkena kunci) | ✅ API 200; DB kode 9 / "Jabatan" / RW1; 1 audit |
| H8 | AC2-07 | Hitung `siasn_upload_arsip` sebelum/sesudah H2 (SQ) | Jumlah tidak berubah oleh Hubungkan (antre SIASN berikutnya = risiko diterima, BB11) | ✅ 0 → 0 (juga sesudah semua Hubungkan) |
| H9 | F10-30 | `demo.pegawai` `opsi-hubungkan?id_riwayat=7` | Lihat H6 (403 kunci); `demo.admin`: memuat RW2 + ringkasan (BB7) | ✅ Pegawai 403 kunci; admin 200 `butuh_data: true`, memuat RW2 dengan ringkasan |
| H10 | F10-38 (1) | Urutan galat & ulang kunci sesudah `FOR UPDATE` | [test-otomatis] `backend/tests/Kepegawaian/ArsipAksiTest.php:280` (`testHubungkanDiulangSesudahKunciForUpdate`), `:343` (`testBalapanKodeDicekUlangSesudahForUpdate`), `:234` (`testKunciBarisDisetujuiUntukPegawaiF1031`) | [test-otomatis] isi dibaca: status tujuan berubah saat `FOR UPDATE` → 404, baris tetap kode 0; balapan kode → 422 / 403 |

## X. Hapus arsip yatim (Tahap 2; AC2-05, AC2-09, F10-09)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| X1 | AC2-11 | Sesudah bagian N: ⋮ → Hapus baris kode 0 (AR11; bila sudah dihapus, baris kode 0 lain) | `ConfirmDialog` "Hapus arsip?" (tombol Batal / "Ya, hapus") tampil; Batal tidak mengubah apa pun; konfirmasi → "Arsip dihapus." | ✅ UI: AR28-html → Batal, tanpa DELETE, baris tetap; AR33 → "Ya, hapus" → DELETE 200, "Arsip dihapus.", baris hilang, 1 audit `delete` |
| X2 | AC2-09 | Konfirmasi Hapus AR11 | Baris hilang dari DB; audit `delete` dengan snapshot baris; berkas di disk **tetap ada** | ✅ API admin DELETE AR11 200 (`soft_delete: false`); baris hilang; 1 audit `delete` (snapshot di `before_json`); berkas fisik tetap |
| X3 | R7 | [S] `curl -sI` URL publik AR11 (dicatat sebelum X2) | Masih 200 (BB10) | ◻ [S] menunggu G10-4 |
| X4 | AC2-05 | Hapus baris terhubung lewat API | 422 (lihat H5) | ✅ DELETE AR2 (admin dan pegawai) 422 pesan H5; DELETE AR10 sesudah H7 422 |
| X5 | R7 | Hapus AR10b (kode 0, kunci v2; AR10 dipakai H7), lalu `buka` id-nya | 404 "Arsip tidak ditemukan."; berkas fisik tetap di disk | ✅ DELETE 200 + 1 audit; `buka` 404 "Arsip tidak ditemukan."; DELETE kedua 404; berkas fisik tetap |

## Y. Ekspor & filter (Tahap 2; AC2-10, §6.1, F10-27)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| Y1 | AC2-10 | "Semua berkas" → `arsip-ekspor` → Excel, lalu CSV | `.xlsx` dan `.csv` (bukan `.txt`); kolom Nama Arsip, Jenis Riwayat, Data Riwayat; tanpa URL berkas; `Cache-Control: no-store`. Nama berkas `Arsip_<NIP>_<YYYYMMDD>.<format>` memuat NIP (`ArsipService.php:212`) — jangan ditulis di laporan | ✅ API: xlsx dan csv 200, `attachment`, `no-store`, `nosniff`; tiga kolom; 74 baris = daftar, tanpa URL/path; CSV UTF-8 BOM. UI: "Ekspor Excel" / "Ekspor CSV" → `ekspor?format=xlsx` / `csv` 200. Tanggal nama berkas mengikuti `appTimezone` UTC |
| Y2 | AC2-10 | Ekspor dengan baris ber-`tag` `=1+1` | CSV `'=1+1`; XLSX berupa teks | ✅ CSV `'=1+1` (Y2); XLSX sel teks `inlineStr` tanpa rumus; Y2b (berawalan teks) apa adanya |
| Y3 | AC2-10 | Ekspor dengan filter jenis / pencarian aktif | Isi = daftar yang sama dengan filter itu | ✅ API: isi ekspor = daftar untuk 5 kombinasi filter (jenis, `q`, `tanpa_jenis`, `q` + sort, `belum_terhubung`). UI: argumen kueri ekspor = daftar |
| Y4 | F10-26 | `demo.admin` ekspor arsip PA dua kali beruntun | Dua baris `arsip_lihat` `endpoint: "ekspor"` (tanpa dedup); `demo.pegawai` ekspor miliknya → tidak dicatat | ✅ 2 baris `endpoint` "ekspor"; pegawai 0 baris; `filter` tanpa teks `q` |
| Y5 | §6.1 | Centang `arsip-filter-belum-terhubung` sesudah memilih jenis di `arsip-filter-jenis` | Select ter-reset ke "Semua jenis" (`ArsipFolderPage.vue:317`, reset `:90-93`); hanya baris kode 0 | ✅ UI: Pendidikan → centang → select "Semua jenis", kueri `belum_terhubung=1`, 6 baris kode 0 saja. API `belum_terhubung=1` hanya kode 0; `=2` 422 |
| Y6 | §6.1 [K] | Sesudah Y5, pilih jenis Jabatan | Daftar kosong "Tidak ada berkas yang cocok dengan pencarian." (BB13); centang tetap (`:94-96`) | ✅ API 0 baris; UI centang tetap, `arsip-berkas-kosong` dengan teks itu |
| Y7 | §6.1 | Filter hanya di "Semua berkas" | Folder jenis tidak menampilkan filter | ✅ UI: folder jenis (9, 7, 0) tanpa kedua filter; tombol Ekspor tetap ada |

## Z. Regresi & efek yang diterima (§6.6, §7.5)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| Z1 | §7.5 | Tab lampiran B-18 riwayat Jabatan PA: unduh lampiran | Tetap privat (`attachment`, butuh login; BB17); tidak berubah | ✅ Unduh B-18 AR1 dan AR2: tanpa token 401; pemilik 200 `application/pdf` `attachment` |
| Z2 | §7.5 | Dialog "Arsip Data Umum": unggah, lihat, hapus 37/38 | Perilaku lama tetap | ✅ UI: dialog menampilkan seksi 38 dan 37 seperti biasa. API `demo.admin`: unggah 37 (`nama_arsip`) dan 38 (`jenis_arsip` "Lain-lain" + `nama_arsip_lain`) 201 dengan `tag` terisi, unduh 200 `attachment`, hapus 200, jumlah daftar kembali seperti semula; batas 100 tetap (E7) |
| Z3 | §7.5 | Endpoint berkas layanan cuti/TB/IB/PMK | Tetap privat; berkas 43–50 tidak punya URL publik (N6) | ✅ Endpoint lampiran cuti/TB/IB/PMK tanpa token 401; AR25, AR26-43/46/49 privat ber-URL `buka`, rute dev `/berkas/<path>` 404. N6 Nginx ◻ [S] |
| Z4 | §6.6 (Tahap 2) | Sesudah Hubungkan ke kode 11: kartu kelengkapan beranda pegawai PA | Bisa berubah [K] (BB11) | ✅ Kartu "Data Pangkat" 25% → 50% sesudah Hubungkan AR-Z4 ke riwayat KP; kartu lain tetap |
| Z5 | §6.6 (Tahap 2) | Hubungkan ke 9/10/11/32: respons `services/s_stat` | Berkas bisa ikut tampil [K] (BB11); [test-otomatis] bila klien uji tidak tersedia | [test-otomatis] sebagian: `backend/tests/database/Integrasi/StatServiceTest.php` (kode 11 `:72`/`:99`, 32 `:78`/`:101`, 9 `:114`/`:151`, 10 `:203`/`:214`); klien layanan uji tidak tersedia; rantai Hubungkan → `s_stat` tidak diuji ujung-ke-ujung |
| Z6 | §7.5 | Foto / Plt-Plh / Konket berpath `arsip/…` yang berkasnya ada | 200 (koreksi dokumen usang); [test-otomatis] `backend/tests/Kepegawaian/PathArsipLegacyTerbacaTest.php` (MAKE-093, di `main`) | [test-otomatis] `PathArsipLegacyTerbacaTest.php:63` (foto), `:76` (Plt/Plh), `:98` (Konket) |
| Z7 | §11.1 | Test pembanding regex README §1l vs `Config\Arsip` | [test-otomatis] `backend/tests/unit/Deploy/ReadmeBerkasNginxTest.php` (MAKE-093) | [test-otomatis] `ReadmeBerkasNginxTest.php:47`, `:55`, `:64`, `:78`, `:87` |

## BB. "Bukan bug" — sesuai keputusan (PRD §11.3)

Pelaksana QA **tidak** mencatat hal berikut sebagai temuan. Kolom Hasil diisi ✅ bila perilaku teramati sesuai.

| # | Perilaku | Dasar | Butir terkait | Hasil |
|---|---|---|---|---|
| BB1 | URL berkas legacy bisa dibuka tanpa login atau dari akun lain | F10-18 (a) | N3, M9 | ◻ [S] menunggu G10-4 |
| BB2 | Berkas unggahan v2 (termasuk lampiran cuti/TB/IB/PMK dan surat ber-TTE) tidak punya URL publik; butuh login dengan izin melihat pegawai itu | F10-18 (b) | N6, P4 | ✅ lokal: P3, P4, Z3 (rute dev 404 untuk berkas v2); N6 Nginx ◻ [S] |
| BB3 | Lebih dari 6 berkas v2 beruntun → pesan batas; ±10 detik kemudian satu berkas bisa dibuka lagi; berkas legacy tidak dibatasi | F10-36 | P12, P13, P17, F6 | ✅ P12, P13, P17, F6 |
| BB4 | Pembukaan berkas v2 oleh pemiliknya sendiri tercatat di audit | F10-35 | P10 | ✅ P10 |
| BB5 | Pembukaan berkas legacy lewat URL publik tidak tercatat di audit | F10-08 | N3 | ◻ [S] menunggu G10-4 |
| BB6 | Lampiran cuti/TB/IB/PMK v2 bisa dibuka di Arsip walau endpoint layanan menolak role itu; PMK hasil impor legacy lewat URL publik | F10-30, §7.5 | P2, P18, C6, N1, Z3 | ✅ lokal: P2, P18, C6, Z3; N1 ◻ [S] |
| BB7 | Role 2/4/5/6/7 melihat lampiran **dan ringkasan** riwayat yang tersembunyi di B-18 (mis. hukdis), termasuk di opsi Hubungkan | F10-30 | B2, H9 | ✅ B2, H9 |
| BB8 | Pegawai yang muncul di pemilih tetapi di luar lingkup detail role 3 → 403 | §5.1 | A3 | [test-otomatis] sebagian (A3, K-05, T-03) |
| BB9 | Lampiran riwayat status 10 tetap tampil dan bisa dibuka | F10-16 | B3, P9 | ✅ B3, P9 |
| BB10 | Hapus arsip yatim tidak menghapus berkas fisik; URL lama berkas legacy masih terbuka | F10-09, R7 | X2, X3 | ✅ lokal: X2, X5 (berkas fisik tetap); URL lama X3 ◻ [S] |
| BB11 | Hubungkan ke Jabatan/Diklat sertifikasi bisa memicu antre SIASN; ke 9/10/11/32 mengubah `s_stat`; ke 11 mengubah kelengkapan beranda | §6.6 | H8, Z4, Z5 | ✅ H8 (Hubungkan tidak menulis antre), Z4; Z5 [test-otomatis] |
| BB12 | Role 2/6/7 tidak bisa Hubungkan ke riwayat Disetujui (dialog praktis hanya Data Umum 38); role 2/3/6/7 tidak bisa mengubah nama kode 37; tetapi role 2/6/7 tetap bisa mengubah nama arsip yang terhubung ke riwayat Disetujui (selain 37) | F10-31, PB-4 | H6, E1, E4 | ✅ H6, E1, E4 |
| BB13 | Centang "Belum terhubung" + memilih jenis → daftar kosong | §6.1 [K] | Y6 | ✅ Y6 |
| BB14 | Berkas selain pdf/gambar terunduh, tidak dipratinjau | F10-32 | N4, F4, R4 | ✅ lokal: F4, P5; N4 dan R4 ◻ [S] |
| BB15 | Berkas dibuka di popup kecil; di ponsel terbuka sebagai tab | §5.1 | R6 | ◻ bersama bagian R di server dev (popup 600×600 teramati di F1/F2) |
| BB16 | Tidak ada token CSRF terpisah | §6.1 | W1 | ✅ W1 |
| BB17 | Endpoint unduh B-18 lama tetap butuh login | §7.5 | Z1 | ✅ Z1 |
| BB18 | Membuka URL folder langsung (`/arsip/<NIP>/semua` atau `/<kode>`) bisa menghasilkan dua baris `arsip_lihat` (permintaan folder dan daftar bersamaan; dedup cek-lalu-tulis) | AC1-12 (PRD `:200`) | D1, D2 | ✅ D1, D2 (API), D4 (UI): satu baris bila berurutan; skenario membuka URL folder langsung tidak diuji; bila baris ganda muncul tetap sesuai keputusan |

## K. Keputusan / pertanyaan pra-QA

◻ = menunggu keputusan reviewer/Horii; ✔ = diputus.

| # | Temuan / pertanyaan | Keputusan |
|---|---|---|
| K-01 | Bagian N dan R (dan GF4) bergantung pada MAKE-093 di `main` dan G10-4 oleh operator/Horii. Saat checklist disusun MAKE-093 masih di branch. "QA Tahap 1 boleh dilakukan reviewer lebih awal" (PRD §13 `:658`) ada dalam urutan **sesudah** MAKE-093. | ✔ MAKE-093 di `main` (merge `a0418d1f`); bagian N/R dan GF4 menunggu G10-4. Butir lain di lokal: lihat K-11 |
| K-02 | `ACTIVE_PHASE` di `main` masih 5 (`nav.config.ts:97`) walau Fase 7 sudah sign-off 11-11-2026; menu Arsip ber-`phase: 7` (`:173`) tersembunyi. Di server dev konstanta tidak bisa diubah tanpa deploy. | Informasi tetap: `ACTIVE_PHASE` dinaikkan ke 7 hanya saat rilis Fase 7 (`docs/fase7/Laporan_Akhir_Fase7.md:168`, blocker deploy/go-live); F-10 ikut gerbang Fase 7 (QF10-3, PRD `:637`); kenaikan konstanta = keputusan Horii/reviewer di penutup rilis (F8-50, `docs/fase8/PRD_Fase8_HaloSimpeg.md:907`). QA di server dev memakai URL langsung + tombol header; A9 sudah dijalankan di lokal dengan 7 tanpa commit. ◻ Opsional: hanya bila Horii ingin menaikkan konstanta lebih awal |
| K-03 | Data uji F-10 (baris `document_attachment` + berkas fisik sintetis, akun per role) belum punya skrip; di server dev belum jelas akun uji dan izin menanam data sintetis di DB + akar lampiran. | ✔ Lokal: skrip data sintetis `qa_f10.php` disiapkan reviewer di folder demo-seed (di luar repo), idempoten, dengan pengaman DB; tanpa NIP nyata dan tanpa akun baru. ◻ Bagian server dev (akun uji, data/berkas sintetis, pembersihan) tetap untuk Horii/operator |
| K-04 | Butir saklar `privat` (C9, P11) mengubah `.env`. | ✔ Hanya di lokal: C9/P11 lolos, `.env` dikembalikan |
| K-05 | A3 butuh akun role 3 tanpa satker yang jalur daftar ≠ jalur detail (`PegawaiScope.php:30-34`); akun demo `demo.satker` bersatker sehingga kedua jalur sama. | ✔ A3 diterima [test-otomatis] **sebagian**: `PegawaiScopeTest` + `PegawaiEndpointTest` + `OtorisasiArsip` memakai jalur detail. Catatan celah: belum ada test endpoint Arsip role 3 tanpa satker → usulan T-03 |
| K-06 | Tahap 2 dibagi MAKE-092 (BE) dan MAKE-096 (FE); semula PRD §13 menulis MAKE-092 "BE+FE" | ✔ Tercatat di PRD F-10 v1.5 §13 (CR-123) |
| K-07 | Testid/endpoint Tahap 2 diambil dari PRD §8.1 dan §9.3, lalu dicocokkan dengan branch MAKE-092 dan MAKE-096 sebelum merge (`RoutesPegawai.php`: `GET …/arsip/ekspor`, `GET …/arsip/opsi-hubungkan`, `PATCH …/arsip/berkas/(:num)`, `POST …/hubungkan`, `DELETE …/arsip/berkas/(:num)`; filter tulis role 1, 2, 3, 6, 7; `arsip-filter-jenis`, `arsip-filter-belum-terhubung`, `arsip-ekspor`; item ⋮ `edit` → `buka` → `hubungkan` → `hapus` `danger`). | ✔ Dicocokkan ulang terhadap `main` `07d5a7a2`: 100 butir cocok, 36 selisih kelas (a) (rujukan baris/teks usang, dikoreksi di checklist ini), **0 selisih perilaku**. Rujukan `backend/.env.example:119-121` tidak dicocokkan ulang (berkas `.env*` tidak dibuka) |
| K-08 | Docblock `frontend/src/features/kepegawaian/composables/useBukaBerkasArsip.ts:12-13` masih menyebut 403 `password_change_required` "ditangani interceptor tanpa pesan tambahan", sedangkan kode `:62` menampilkan pesan (sesuai F10-37 (6) PRD `:140`). PRD v1.4 §9.3 `:521` semula usang. | Informasi tetap: docblock `:12-13` masih usang (hanya komentar; perbaikan bisa ikut paket FE berikutnya); PRD v1.5 §9.3 `:521` sudah dikoreksi. Acuan F9 = F10-37 (6) `:140` dan §9.3 `:521` |
| K-09 | Butir yang praktis hanya terbukti lewat test (fail-closed audit D5, audit 0 P16, pencabutan object URL F10, urutan kunci H10, regex README Z7, tanpa N+1 B12, tipe blob F12). | ✔ [test-otomatis] disepakati reviewer, dengan rujukan test di gate `main` (D5/P16 diuji di tingkat service) |
| K-10 | Angka D1 (v1.4) dari salinan dev, bukan produksi: 0 baris `assets/upload/…`, 2 baris `id_riwayat` NULL, 2 baris `path` kosong, maksimum 576 baris per pegawai. | Informasi: AR18, AR19, AR31 sintetis; kinerja folder untuk pegawai ±576 baris tidak diuji (opsional) |
| K-11 | PRD menjalankan QA Lapis 1 F-10 di server dev dengan Nginx, **bukan** rute dev (§7.4 `:342`, §11.3 `:575`). Checklist ini mengusulkan butir non-[S] (termasuk C9/P11 saklar `privat` dan pembukaan berkas legacy lewat rute dev `arsip.sajiBerkasDev = true`) di mesin lokal. Laporan Akhir Fase 7 §10.6 menulis G10-4 menahan QA Lapis 1 F-10. | ✔ Horii 13-11-2026: butir non-[S] diuji di lokal dengan rute dev sesudah Tahap 2 di `main`; butir [S] (Nginx, `curl -I`, tiga browser) menyusul di server dev sesudah G10-4 |

## T. Temuan

Satu baris per temuan; kolom Butir memakai nomor berawalan. Tidak ada butir yang gagal; semua temuan berdampak rendah dan
**tidak** menahan sign-off.

| # | Butir | Temuan | Dampak | Usulan / status |
|---|---|---|---|---|
| T-01 | B8 (di luar butir) | URL folder berangka yang bukan `jenis_rwy` (mis. `/arsip/<NIP PA>/2`) menampilkan judul "Folder" + "Belum ada berkas.", bukan "Folder tidak ditemukan."; yang ditolak hanya nilai bukan angka selain `tanpa_jenis`. FE tidak dapat membedakan jenis tak dikenal dari jenis tanpa berkas karena folder 0 berkas tidak dikirim (AC1-05) | Rendah, kosmetik | Catat, tanpa perbaikan. ◻ Horii bila ingin ditangani di paket FE berikutnya |
| T-02 | E2 | Ubah Nama dengan tag tak tertutup (mis. `a <b`) disimpan "a": `strip_tags` membuang sisa teks sesudah `<` yang dianggap awal tag. Sanitasi sesuai F10-38/AC2-08 (tag dibuang), tetapi bisa memotong teks | Rendah | Catat sebagai perilaku sanitasi yang diterima |
| T-03 | A3, K-05 | Celah test: belum ada test endpoint Arsip untuk admin role 3 tanpa satker (jalur daftar ≠ jalur detail) | Rendah; kode memakai jalur detail `OtorisasiArsip` | Tambah test di paket CR berikutnya; tidak menahan sign-off |
| T-04 | V6 | Di viewport 390 px menu Ekspor menempel tepi kiri layar (tidak meluber, tanpa scroll horizontal) | Rendah, kosmetik | Catat |

### Catatan pelaksanaan

Bukan temuan; dicatat supaya hasil di atas dapat dibaca ulang.

- Popup pengumuman Beranda baru bisa ditutup di gambar terakhir (ikut legacy, `PopupAktifDialog`), dan dialog Form Digital
  wajib menutupi halaman role 7 (fitur F7). Keduanya tidak terkait F-10; klik menu A9 untuk role 2 dan 7 disesuaikan.
- F6: batas 429 hanya teramati bila 7 Buka dilakukan beruntun cepat; token bucket mengisi ulang ±10 detik per jatah,
  sehingga klik manual berjarak beberapa detik tidak menghabiskan jatah (sesuai F10-36).
- F1/F3/F6/F7/F8/F10: popup disimulasikan (iframe same-origin) karena browser uji memblokir popup asli; isi "Memuat
  berkas…" dan URL `blob:` tetap dihasilkan kode aplikasi. F2: argumen `window.open` direkam lewat pembungkus (popup asli
  tidak terbuka). F5 justru teramati nyata.
- Y1/Y3: isi berkas ekspor diperiksa lewat API (tahap tulis); uji UI membuktikan permintaan dan argumen kueri (unduhan blob
  dicegat, berkas tidak disimpan).
- V3: teks `<b>` dibuktikan lewat ketiadaan elemen `<b>` dan `v-html`, karena AR30 sudah disanitasi di E2.
- B5: kode 23 tidak ada di data uji lokal; B12 [test-otomatis] mencakup kode 23. Ringkasan AR13 (kode 5) bernilai
  "TEKNIS - " dengan bagian kedua kosong; dugaan karena data sintetis riwayat pelatihan, belum diverifikasi terhadap bentuk
  `previewRwy` diklat.
- D2: menteri → PA kena dedup (sudah tercatat di D6), sehingga diganti menteri → PL.
- Tahap tulis: satu kasus tolakan tambahan (tag tak tertutup, T-02) ternyata diterima sehingga AR2 sempat berganti nama
  sebelum E1 menggantinya lagi.
- Sesudah QA: saklar `.env` demo dan `nav.config.ts` dikembalikan; worktree demo bersih, tanpa commit.
