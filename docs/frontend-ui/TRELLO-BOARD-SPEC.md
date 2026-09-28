# Spesifikasi Board Trello "Frontend-UI" (untuk dibuat ulang)

> ⚠️ **Board ini BELUM ADA di akun Trello user.** Sesi 28-09-2026 membuatnya lewat konektor MCP Trello, tapi
> konektor itu login sebagai akun lain (**"Fajrin F"**, workspace "Trello Workspace" /
> `userworkspace28232935`), bukan akun yang user pakai di browser. Board salah-akun itu ada di
> https://trello.com/b/hC81acIV/frontend-ui — **jangan dipakai**; tanyakan user dulu sebelum mengarsipkannya.
>
> **Cara yang benar:** buat board di akun user lewat tab trello.com yang sudah login di browser user
> (REST `fetch('/1/...')` dengan param `dsc` dari cookie — pola yang sama dipakai untuk board
> "SIMPEG v2 — Timeline Pengembangan"), **bukan** lewat konektor MCP Trello. Taruh di workspace yang sama
> dengan board "SIMPEG v2 — Timeline Pengembangan". Konfirmasi ke user dulu workspace-nya.

Seluruh isi di bawah = isi final yang harus direplikasi. Status kartu mengikuti keadaan kode per commit
`9dd8b1e` di branch `feat/frontend-ui-redesign` — perbarui dulu kalau sudah ada pekerjaan baru.

---

## 1. Board

- Nama: `Frontend-UI`
- Visibilitas: workspace (ORG), latar biru, card count aktif.

## 2. List (urut kiri → kanan) = Implementation Status

1. `📌 Panduan dan Legenda`
2. `⚪ Not Started`
3. `🟡 In Progress`
4. `🟢 UI Complete`
5. `🟢 UI Complete — Temporary Asset`
6. `🟠 Needs Revision`
7. `⛔ Blocked`

(Catatan: karakter `&` di nama list sempat ter-escape jadi `&amp;` lewat MCP — pakai kata "dan".)

## 3. Label

Di akun user, **beri nama label** (lewat REST bisa, lewat MCP tidak bisa):

| Warna | Nama label |
| --- | --- |
| purple | Design System / Fondasi |
| blue | Halaman Admin (§4.1) |
| green | Halaman Pengguna (§4.2) |
| yellow | Reusable Component |
| orange | Asset: Temporary |
| red | Needs Final Asset |

## 4. Kartu

Format deskripsi wajib (semua kartu):

```
Status: <nama list>
Page / Screen:
Component:
Redesign Reference: Laporan Redesign §x.y, Gambar N (hal. cetak M / PDF hal. P)
Implementation Status: Not Started | In Progress | Complete
Asset Status: N/A | Temporary | Final
Asset Source:
Needs Final Asset: Ya | Tidak
Notes / Remaining Work:
Files:
```

### 📌 Panduan dan Legenda

#### 📖 Cara Pakai Board Frontend-UI (baca dulu)
Label: —

```
Board ini = single source of truth progres redesign frontend SIMPEG v2.

## 1. List = Implementation Status
- ⚪ Not Started — belum dikerjakan.
- 🟡 In Progress — sedang dikerjakan.
- 🟢 UI Complete — UI sesuai redesign, aset sudah final (atau tidak butuh aset).
- 🟢 UI Complete — Temporary Asset — UI sudah sesuai redesign, tapi masih memakai aset online/placeholder.
  Ini bukan "belum selesai"; pekerjaan UI-nya dianggap tuntas.
- 🟠 Needs Revision — sudah dikerjakan tapi belum sesuai redesign.
- ⛔ Blocked — tertahan dependency (keputusan/data/API/aset wajib).

## 2. Label
- 🟣 Ungu = Design System / Fondasi
- 🔵 Biru = Halaman Admin (§4.1)
- 🟢 Hijau = Halaman Pengguna (§4.2)
- 🟡 Kuning = Reusable Component
- 🟠 Oranye = Asset: Temporary (masih placeholder online)
- 🔴 Merah = Needs Final Asset (wajib diganti sebelum rilis)
Status UI dan status aset sengaja dipisah: kartu boleh 🟢 UI Complete sekaligus 🟠+🔴.

## 3. Format deskripsi kartu (wajib) — lihat template.
Saat status berubah: pindahkan kartu DAN ubah baris `Status:`
(aturan sama dengan board "SIMPEG v2 — Timeline Pengembangan").

## 4. Rujukan
- Dokumen: Bahan Baku SIMPEG\FInal-16926\Laporan Redesign Simpeg Kemenpar rev1_logo.pdf
- Mockup resolusi penuh: Bahan Baku SIMPEG\FInal-16926\redesign-extract\screens\
- Figma: https://www.figma.com/design/eYZLmyDsebI8ZRy871BJHn/Simpeg-Kemenpar?node-id=211-48021
- Titik lanjut teknis: docs/frontend-ui/HANDOVER.md di branch feat/frontend-ui-redesign
```

### 🟢 UI Complete

#### DS-001 Design token: palet, tipografi Public Sans, base CSS
Label: purple

```
Status: UI Complete
Page / Screen: — (fondasi, dipakai seluruh halaman)
Component: tailwind.config.js (theme.extend), index.html (Google Fonts), assets/main.css
Redesign Reference: Laporan Redesign §3.1 Typhography (PDF hal. 15), §3.2 Palet Warna (PDF hal. 16)
Implementation Status: Complete
Asset Status: N/A
Asset Source: —
Needs Final Asset: Tidak
Notes / Remaining Work:
- Palet primer: #1C3964 primary, #FFC043 secondary, #217AFF tertiary.
- Semantik: success #28C76F, warning #FF9F43, danger #EA5455, info #00BAD1, muted #82868B (+ tint `soft`).
- fontSize h1..overline persis tabel §3.1 (46/68/500 … 12/14/400).
- Base: :focus-visible ring (WCAG 2.1 AA §1.2), prefers-reduced-motion, .app-canvas, .scrollbar-slim.
- ⚠️ npm run check BELUM dijalankan di branch ini.
Files: frontend/tailwind.config.js, frontend/index.html, frontend/src/assets/main.css
Commit: 9dd8b1e (branch feat/frontend-ui-redesign)
```

#### DS-002 UI Kit bab 3: 16 komponen reusable (button, input, dropdown, checkbox, avatar, dll)
Label: purple, yellow

```
Status: UI Complete
Page / Screen: — (reusable, dipakai seluruh halaman bab 4)
Component: UiButton, UiTextField, UiSelect, UiCheckbox, UiAvatar, UiCard, UiBadge, UiBreadcrumb,
  UiPagination, UiSearchInput, UiProgressBar, UiSparkline, UiDonutChart, UiTabs, UiStatTile, UiStepper
Redesign Reference: §3.3 Tombol, §3.4 Input, §3.5 Dropdown, §3.6 Checkbox, §3.7 Avatars (PDF hal. 16–18)
  + pola berulang bab 4
Implementation Status: Complete
Asset Status: N/A
Asset Source: —
Needs Final Asset: Tidak
Notes / Remaining Work:
- UiButton: 6 varian warna x solid/outline/ghost, 3 ukuran, loading/disabled, bisa jadi RouterLink/<a>.
  Tombol "Primary" di §3.3 = BIRU #217AFF (bukan navy). Navy = varian `brand`.
- UiTextField/UiSelect: state Default/Error/Success/Disabled; UiTextField tampilan `outlined` & `stacked`.
- UiSparkline & UiDonutChart: SVG inline tanpa library; donut punya tabel sr-only (aksesibilitas).
- UiStepper: kolom "Status Layanan" §4.1.4.
- Belum ada unit test komponen baru. ⚠️ npm run check BELUM dijalankan.
Files: frontend/src/shared/ui/*.vue, frontend/src/shared/ui/index.ts
Commit: 9dd8b1e
```

### 🟢 UI Complete — Temporary Asset

#### DS-003 Registry aset placeholder (Unsplash / Popsy / DiceBear)
Label: purple, orange, red

```
Status: UI Complete — Temporary Asset
Page / Screen: — (fondasi aset: Dashboard, Portal Berita, Halo Simpeg, empty state)
Component: placeholderAssets.ts (NEWS_PHOTOS, ILLUSTRATIONS, placeholderAvatar())
Redesign Reference: aset visual Gambar 12 (§4.1.1), 25 (§4.2.1), 28 (§4.2.3), 30 (§4.2.5)
Implementation Status: Complete
Asset Status: Temporary
Asset Source:
- Foto berita → Unsplash: Adrian Hartanto SspHIqF_tUA, Ruben Sukatendel VsPGJqafmTk, UX Hours xPbN9bOPO8g
  (Unsplash License)
- Ilustrasi → Popsy Illustrations illustrations.popsy.co (gratis pribadi + komersial)
- Avatar → DiceBear 9.x koleksi notionists (kode MIT, koleksi CC0 1.0)
Needs Final Asset: Ya
Notes / Remaining Work:
- Tidak ada gambar dikomit; semua lewat URL → penggantian cukup menyentuh satu berkas.
- BELUM ada placeholder: (a) banner "Business People" §4.2.1 (rencana banner gradasi CSS + Popsy);
  (b) logo/lambang Kemenpar di kepala sidebar.
- Saat aset final ada: ganti jadi import @/assets/..., pindahkan kartu terkait ke "UI Complete", lepas 🟠/🔴.
Files: frontend/src/shared/ui/placeholderAssets.ts
Commit: 9dd8b1e
```

### ⚪ Not Started (urut dari atas)

#### CHK-001 Jalankan npm run check di branch feat/frontend-ui-redesign ⟵ LANGKAH PERTAMA
Label: —

```
Status: Not Started
Component: seluruh berkas commit 9dd8b1e
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work:
16 komponen src/shared/ui/ + token DITULIS TAPI BELUM DIVERIFIKASI.
  cd frontend && npm ci && npm run check   (jangan pipe outputnya)
Perbaiki error lint/TS, lalu buka app untuk memastikan Public Sans termuat dan token warna terpakai.
Perhatian: UiTabs pakai ref callback di v-for; UiSparkline/UiDonutChart pakai useId() untuk id gradient SVG;
UiCard/UiBadge/UiStepper mendeklarasikan konstanta peta kelas di <script setup>.
Files: frontend/**
```

#### SHELL-001 Shell redesign: sidebar mengambang + topbar + breadcrumb ⟵ KERJAKAN DULUAN
Label: yellow, red

```
Status: Not Started
Page / Screen: kerangka semua halaman bab 4
Component: RedesignShell.vue, AppSidebar.vue, AppTopbar.vue, nav.config.ts (rencana: src/shared/layouts/)
Redesign Reference: seluruh Gambar 12–32; paling jelas Gambar 12 (§4.1.1, PDF hal. 20) & Gambar 18 (§4.1.5, PDF hal. 25)
Implementation Status: Not Started
Asset Status: Temporary (logo Kemenpar belum ada)
Needs Final Asset: Ya (lambang/logo di kepala sidebar)
Notes / Remaining Work:
- Sidebar putih MENGAMBANG (radius besar, bayangan) di atas .app-canvas. Tombol « untuk collapse ke mode ikon.
- Susunan: logo SIMPEG → blok profil (avatar + nama + NIP) → Dashboards → KEPEGAWAIAN (Daftar Pegawai,
  Kelola Data, Arsip, Struktur Organisasi, Peta Jabatan, Kamus Kepegawaian, Laporan›, Layanan›, Presensi›)
  → KARIER (E-Kinerja›, E-Talenta›) → BERITA (Portal Berita + badge merah) → PUSAT BANTUAN (Halo Simpeg, FAQ).
- Item aktif: latar navy #1C3964 penuh, teks putih. Judul grup: overline abu.
- Submenu mengembang di tempat; anak menu ber-bullet lingkaran (Gambar 15: Laporan → Unit Kerja / Jenis Kelamin / Struktural).
- Topbar: batang navy membulat "Sistem Informasi Kepegawaian" + lonceng ber-dot merah.
- Breadcrumb di bawah topbar (UiBreadcrumb siap).
- < lg: sidebar off-canvas + overlay; wajib dites 375px.
- JANGAN ubah src/shared/components/AppShell.vue (AppShell.spec.ts mengunci menu lama).
Files: (belum ada)
```

#### DS-004 Halaman styleguide /ui-kit (etalase komponen bab 3)
Label: purple

```
Status: Not Started
Page / Screen: /ui-kit (halaman internal)
Component: UiKitView.vue
Redesign Reference: bab 3 seluruhnya (PDF hal. 15–18)
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work: tampilkan semua komponen src/shared/ui/ + semua state, skala tipografi h1..overline,
swatch palet + hex. Gunanya bukti visual untuk review & QA UI.
Files: (belum ada)
```

#### ADM-001 Dashboard Admin (§4.1.1)
Label: blue, red (+ orange)

```
Status: Not Started
Page / Screen: Dashboard Admin
Component: AdminDashboardView + UiStatTile, UiProgressBar, UiDonutChart, UiCard, UiAvatar
Redesign Reference: §4.1.1, Gambar 12 (hal. cetak 14 / PDF hal. 20) → screens/p20_0_1638x2048.png
Implementation Status: Not Started
Asset Status: Temporary (foto berita + ilustrasi kartu "Berita Terbaru")
Asset Source: DS-003 (Unsplash + Popsy)
Needs Final Asset: Ya
Notes / Remaining Work: 4 kartu tren + sparkline (Pensiun/Naik Pangkat/Tanda Jasa/KGB); 6 metrik progres
(PNS, PPPK, PTT, Non PNS, Staff Khusus, Tenaga Ahli) + kartu Total Pegawai & donat; strip 4 angka (Tugas
Belajar, CLTN, Cuti Online, Presensi Online); carousel "Masa Kerja"; 2 kartu ulang tahun (aksen oranye/hijau);
carousel "Berita Terbaru". Data dummy dulu — API Fase 7.
Files: (belum ada)
```

#### ADM-002 Struktur Organisasi + popup Export (§4.1.2)
Label: blue, red (+ orange)

```
Status: Not Started
Page / Screen: Struktur Organisasi
Component: OrgChart (kartu pejabat + konektor), UiSelect filter unit, tombol + dialog Export
Redesign Reference: §4.1.2, Gambar 13 & 14 (hal. cetak 15 / PDF hal. 21) → screens/p21_0, p21_1, p21_2
Implementation Status: Not Started
Asset Status: Temporary (avatar pejabat → DiceBear)
Needs Final Asset: Ya
Notes / Remaining Work: kartu pejabat = avatar bulat + nama besar + nama lengkap abu + jabatan rata kanan
bergaris navy/kuning. Konektor siku navy; garis putus oranye = relasi koordinatif. Dokumen menyebut "plugin
Organization Chart" → putuskan: library atau render sendiri. Perlu pan/zoom.
Files: (belum ada)
```

#### ADM-003 Laporan Unit Kerja + popup ekspor/grafik (§4.1.3)
Label: blue

```
Status: Not Started
Page / Screen: Laporan › Unit Kerja (submenu lain: Jenis Kelamin, Struktural)
Component: BarChart PNS vs PPPK, 2x UiDonutChart, tabel + UiProgressBar per baris, baris Subtotal krem,
  Export & Export Table
Redesign Reference: §4.1.3, Gambar 15 & 16 (hal. cetak 16–17 / PDF hal. 22–23) → screens/p22_0, p23_0
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work: bar berpasangan (navy = PNS, biru muda = PPPK), label angka di atas batang, legenda
di bawah. Putuskan: SVG sendiri atau library. Membuka submenu sidebar bertingkat.
Files: (belum ada)
```

#### ADM-004 Status Layanan (§4.1.4)
Label: blue

```
Status: Not Started
Page / Screen: Layanan › Status Layanan
Component: UiStepper (siap), filter Unit/Satker + Status Layanan, UiSearchInput, UiPagination, aksi ⋮
Redesign Reference: §4.1.4, Gambar 17 (hal. cetak 18 / PDF hal. 24) → screens/p24_0
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work: alur per jenis: Izin Belajar (Verifikasi Dokumen → Review Ketua Tim → Proses TTE →
Penerbitan SK), Tugas Belajar (Verifikasi Dokumen → Validasi Kabid → Penerbitan SK), Cuti (Review Ketua Tim →
Penerbitan Izin Cuti). Warna: toska/biru normal, oranye menunggu. Stepper di layar sempit perlu perlakuan khusus.
Files: (belum ada)
```

#### ADM-005 Daftar Pegawai + hide filter + filter kolom (§4.1.5)
Label: blue, red (+ orange)

```
Status: Not Started
Page / Screen: Daftar Pegawai
Component: DataTable + filter panel (Periode SKP, Unit/Satker, Status Pegawai, Jenis Pegawai, Group/Sub Group
  Jabatan), toggle filter, "Filter Kolom", filter per kolom, UiPagination
Redesign Reference: §4.1.5, Gambar 18–21 (hal. cetak 19–20 / PDF hal. 25–26) → screens/p25_0, p25_1, p26_0, p26_1
Implementation Status: Not Started
Asset Status: Temporary (avatar pegawai → DiceBear)
Needs Final Asset: Ya
Notes / Remaining Work: tombol corong untuk sembunyikan panel filter (Gbr 19), dropdown "Filter Kolom",
baris filter di header tabel (Gbr 20–21, termasuk opsi 2 kolom). Tombol "+ Data Pegawai" biru + "Export".
Tabel lebar → scroll horizontal + kolom aksi ⋮ menempel.
Files: (belum ada)
```

#### ADM-006 Detail Pegawai: header, tab riwayat, form Data Umum, arsip (§4.1.6)
Label: blue, red (+ orange)

```
Status: Not Started
Page / Screen: Daftar Pegawai › Data Pegawai
Component: header profil (pita navy+oranye), UiTabs `pill` (aktif kuning #FFC043), popover "Semua Menu" +
  pencarian menu, form Data Umum, tabel Arsip
Redesign Reference: §4.1.6, Gambar 22–24 (hal. cetak 21–22 / PDF hal. 27–28) → screens/p27_0, p28_0, p28_1
Implementation Status: Not Started
Asset Status: Temporary (foto pegawai)
Needs Final Asset: Ya
Notes / Remaining Work: perbaikan yang DITEGASKAN dokumen (anotasi Gbr 23–24):
1. Pengelompokan Menu Riwayat → tab yang bisa digulir di atas kartu + tombol "Semua Menu".
2. Pengelompokan Menu Cetak → tombol tersendiri ("Arsip Kepegawaian" biru + "Cetak").
3. Menu Hapus Pegawai → ikon ⋯ tersendiri (jarang dipakai, berisiko).
Form: label di atas, (*) merah, upload file dengan keterangan jenis & batas 5 MB, "Batalkan Perubahan" +
"Simpan Perubahan" (hijau).
Files: (belum ada)
```

#### USR-001 Dashboard Pengguna/Pimpinan + Kehadiran Tim (§4.2.1)
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: Dashboard Pengguna/Pimpinan
Component: sambutan + progres KGB, banner, 4 UiStatTile outlined, kartu IPASN, tabel AK Bawahan, panel jam +
  Rekam Masuk/Keluar, UiTabs (Kehadiran Saya / Kehadiran Tim), Berita Terbaru, ulang tahun
Redesign Reference: §4.2.1, Gambar 25 & 26 (hal. cetak 23–24 / PDF hal. 29–30) → screens/p29_0, p29_1
Implementation Status: Not Started
Asset Status: Temporary (banner, ilustrasi sambutan, avatar, foto berita)
Needs Final Asset: Ya
Notes / Remaining Work: "Rekam Masuk" gradasi biru→toska; jadi "Rekam Keluar" gradasi merah muda setelah
absen (Gbr 26). Tab "Kehadiran Tim" hanya pimpinan. Riwayat: badge hijau jam masuk, merah jam pulang, baris
Cuti berikon lain. Banner "Business People" belum ada pengganti.
Files: (belum ada)
```

#### USR-002 Popup Notifikasi/Pengumuman (§4.2.2)
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: modal di atas Dashboard saat login
Component: AnnouncementDialog (radix-vue Dialog) + titik carousel
Redesign Reference: §4.2.2, Gambar 27 (hal. cetak 24 / PDF hal. 30) → screens/p30_0
Implementation Status: Not Started
Asset Status: Temporary (gambar pengumuman)
Needs Final Asset: Ya
Notes / Remaining Work: gambar penuh di atas, judul + isi + "Link Terlampir", titik carousel, tombol × di
pojok. Fokus terkunci, Esc menutup; "jangan tampilkan lagi" belum ada di dokumen → konfirmasi.
Files: (belum ada)
```

#### USR-003 News Portal (§4.2.3)
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: Portal Berita
Component: hero pencarian, kartu berita (thumbnail + judul + penulis/kategori + tanggal + cuplikan),
  sidebar Berita Terbaru + Kategori Berita (UiBadge dot), UiPagination
Redesign Reference: §4.2.3, Gambar 28 (hal. cetak 25 / PDF hal. 31) → screens/p31_0
Implementation Status: Not Started
Asset Status: Temporary (thumbnail berita)
Asset Source: DS-003 (Unsplash)
Needs Final Asset: Ya
Notes / Remaining Work: detail berita tidak ada di dokumen → konfirmasi lingkup.
Files: (belum ada)
```

#### USR-004 FAQ redesign (§4.2.4) — halaman sudah ada, perlu di-restyle
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: FAQ (Pusat Bantuan)
Component: hero biru muda + pencarian, UiTabs (Pertanyaan Umum / Panduan Pengguna), kategori+artikel kiri,
  isi artikel kanan, 2 kartu kontak (email + Chat Admin)
Redesign Reference: §4.2.4, Gambar 29 (hal. cetak 26 / PDF hal. 32) → screens/p32_0
Implementation Status: Not Started
Asset Status: Temporary (pola latar hero)
Needs Final Asset: Ya
Notes / Remaining Work: fitur FAQ (G-10) sudah jalan (FaqPage.vue + FaqView.vue) dan lolos QA (QAFUNC-003,
MTC-013/014). Murni restyle — JANGAN ubah perilaku service & FaqRatingWidget; jalankan ulang FaqView.spec.ts
+ FaqRatingWidget.spec.ts.
Files: frontend/src/features/master-data/views/FaqPage.vue, FaqView.vue
```

#### USR-005 Halo Simpeg: drawer chat pegawai (§4.2.5)
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: Halo Simpeg (drawer di atas halaman Pusat Bantuan)
Component: ChatDrawer — header admin + avatar online, kartu tiket (#ticket4401) yang bisa ditutup, gelembung
  kirim/terima, centang ganda, kotak pesan + Kirim
Redesign Reference: §4.2.5, Gambar 30 & 31 (hal. cetak 27 / PDF hal. 33) → screens/p33_0, p33_1
Implementation Status: Not Started
Asset Status: Temporary (avatar admin, ilustrasi hero)
Needs Final Asset: Ya
Notes / Remaining Work: drawer kanan biru muda; kirim = biru #217AFF kanan, terima = putih kiri. Backend
chat/tiket belum ada → data dummy + state kosong jelas.
Files: (belum ada)
```

#### USR-006 Admin Halo Simpeg: inbox admin (§4.2.6)
Label: green, red (+ orange)

```
Status: Not Started
Page / Screen: Halo Simpeg — Admin View (tanpa sidebar aplikasi)
Component: daftar percakapan "Perlu dibalas (N)" + "Chat Lainnya", pencarian + filter, panel percakapan,
  Logout / Beranda Saya, peringatan "Anda memiliki N pesan tersisa untuk dibalas"
Redesign Reference: §4.2.6, Gambar 32 (hal. cetak 28 / PDF hal. 34) → screens/p34_0
Implementation Status: Not Started
Asset Status: Temporary (avatar pengirim)
Needs Final Asset: Ya
Notes / Remaining Work: hanya topbar navy + aksi kanan atas; percakapan terpilih disorot biru; belum dibalas =
titik merah di avatar. Putuskan route & layout tersendiri (mis. /halo-simpeg/admin).
Files: (belum ada)
```

#### MIG-001 Migrasi halaman lama (Login, Manajemen Akun, Master Data, Ganti Password) ke shell redesign
Label: —

```
Status: Not Started
Page / Screen: LoginView, UserManagementPage, MasterDataPage, ChangePasswordPage, ForbiddenView
Component: AppShell.vue (lama) → RedesignShell.vue; FormField.vue → UiTextField/UiSelect/UiCheckbox
Redesign Reference: tidak ada mockup khusus (login tidak ada di dokumen) — ikuti token bab 3 + pola shell bab 4
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work: setelah SHELL-001 terbukti. AppShell.spec.ts harus diperbarui (bukan dihapus) saat
AppShell lama dipensiunkan. Halaman ini sudah lolos QA Fase 1 & 2 → jaga semua data-testid & perilaku form
(VeeValidate + Zod), jalankan ulang test. Desain login: keputusan user (buat sendiri / tunggu Figma).
Files: frontend/src/features/auth/**, frontend/src/features/master-data/**, frontend/src/shared/components/AppShell.vue
```

### ⛔ Blocked

#### BLK-001 Pemetaan role → menu sidebar redesign (butuh keputusan user)
Label: —

```
Status: Blocked
Page / Screen: Shell redesign (semua halaman)
Component: nav.config.ts
Redesign Reference: sidebar Gambar 12 (Administrator) vs Gambar 25 (Pengguna/Pimpinan) — isinya tampak sama
Implementation Status: Not Started
Asset Status: N/A
Needs Final Asset: Tidak
Notes / Remaining Work:
Pemblokir: dokumen menampilkan ~15 menu sidebar, sedangkan modul SIMPEG v2 yang sudah ada baru Auth, Master
Data (Modul G), dan FAQ (G-10). Dokumen tidak menyatakan menu per role.
Butuh keputusan user:
1. Menu mana tampil per role (Super Admin / Admin / Pimpinan / Pegawai)?
2. Menu yang modulnya belum dibangun: sembunyikan, atau tampil dengan badge "Segera hadir"?
3. Menu lama (Manajemen Akun, Master Data) masuk grup mana?
Sementara: SHELL-001 dikerjakan dengan menu statis penuh (tanpa gating role).
Files: (belum ada)
```
