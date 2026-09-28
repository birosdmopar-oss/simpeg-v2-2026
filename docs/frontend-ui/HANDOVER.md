# Frontend-UI Redesign — Handover / Titik Lanjut

Status per **28 September 2026**. Sesi dihentikan atas permintaan user (hemat token); dokumen ini supaya
sesi berikutnya **tidak perlu mengulang riset dari awal**.

Branch kerja: **`feat/frontend-ui-redesign`** (dicabang dari `main` @ `3f1be3e`).

---

## 1. Sumber kebenaran (source of truth)

| Hal | Lokasi |
| --- | --- |
| Dokumen redesign (PDF, 35 hal, 24 MB) | `C:\Users\USER\Downloads\MenParekRaf\Bahan Baku SIMPEG\FInal-16926\Laporan Redesign Simpeg Kemenpar rev1_logo.pdf` |
| Link Figma | `…\FInal-16926\Link Figma.txt` → https://www.figma.com/design/eYZLmyDsebI8ZRy871BJHn/Simpeg-Kemenpar?node-id=211-48021 |
| **Hasil ekstraksi PDF (sudah disiapkan, jangan ulang)** | `…\FInal-16926\redesign-extract\` |
| Board tracking | Trello **Frontend-UI** → https://trello.com/b/hC81acIV/frontend-ui |

### Cara ekstraksi PDF (kalau perlu diulang)

PDF hampir seluruhnya gambar; `Read` tool gagal (butuh poppler untuk render), tapi **`pdftotext` dan
`pymupdf` tersedia di mesin ini**.

```bash
pdftotext -layout "<pdf>" out.txt                      # teks bab/narasi
python -c "import pymupdf; ..."                        # render halaman / ekstrak gambar tertanam
```

Hasilnya sudah disalin ke `redesign-extract/`:
- `redesign-teks.txt` — seluruh teks naratif + daftar isi + daftar gambar.
- `screens/p15..p18.png` — halaman **bab 3 (UI Components)**: typography, palet, tombol, input, dropdown,
  checkbox, avatar.
- `screens/p20_0..p34_0.png` — **gambar mockup asli beresolusi penuh** (2048 px) yang tertanam di bab 4.

Peta halaman PDF → isi (nomor = halaman PDF, bukan nomor cetak):

| PDF | Isi |
| --- | --- |
| 15–18 | Bab 3 UI Components (§3.1 Typography … §3.7 Avatars) |
| 20 | §4.1.1 Dashboard Admin |
| 21 | §4.1.2 Struktur Organisasi + popup Export |
| 22–23 | §4.1.3 Laporan Unit Kerja + popup ekspor & desain grafik |
| 24 | §4.1.4 Status Layanan |
| 25–26 | §4.1.5 Daftar Pegawai + hide filter + filter kolom |
| 27–28 | §4.1.6 Detail Pegawai + menu riwayat & popup riwayat |
| 29–30 | §4.2.1 Dashboard Pengguna/Pimpinan + Kehadiran Tim + §4.2.2 Popup Notifikasi |
| 31 | §4.2.3 News Portal |
| 32 | §4.2.4 FAQ |
| 33 | §4.2.5 Halo Simpeg (chat drawer) |
| 34 | §4.2.6 Admin Halo Simpeg (inbox admin) |

---

## 2. Design token yang sudah dikunci dari dokumen

**§3.1 Typography — font `Public Sans`** (dimuat via Google Fonts di `index.html`):

| Token | Size / Line height / Weight |
| --- | --- |
| h1 | 46 / 68 / 500 |
| h2 | 38 / 56 / 500 |
| h3 | 28 / 42 / 500 |
| h4 | 24 / 38 / 500 |
| h5 | 18 / 28 / 500 |
| h6 | 15 / 22 / 500 |
| subtitle1 · body1 | 15 / 22 / 400 |
| subtitle2 · body2 | 13 / 20 / 400 |
| caption | 13 / 18 / 400 |
| overline | 12 / 14 / 400 |

**§3.2 Palet**

- Primer: `#1C3964` (primary/navy), `#FFC043` (secondary/kuning), `#217AFF` (tertiary/biru).
- Semantik: success `#28C76F`, warning `#FF9F43`, danger `#EA5455`, info `#00BAD1`, secondary/abu `#82868B`.
- Catatan penting: **tombol "Primary" pada §3.3 memakai biru `#217AFF`, bukan navy.** Navy dipakai untuk
  topbar + item sidebar aktif; di `UiButton` tersedia sebagai varian `brand`.

**§3.3–3.6 State komponen**: tombol punya tampilan Default (solid) & Outline untuk 6 warna;
input/dropdown punya state Default / Error / Success / Disabled dengan label + help text ikut berwarna;
checkbox biru `#217AFF` dengan state disabled-checked pudar.

**§3.7 Avatar**: inisial (latar biru muda) atau gambar, dengan titik status hijau/merah, 3 baris varian.

---

## 3. Yang SUDAH dikerjakan (ada di branch)

| Berkas | Isi |
| --- | --- |
| `frontend/tailwind.config.js` | Token lengkap: palet primer + semantik (+`soft` tint), skala `fontSize` bab 3.1, radius `card`, shadow `card/panel/float`. |
| `frontend/index.html` | `<link>` Google Fonts Public Sans (400/500/600/700) + preconnect. |
| `frontend/src/assets/main.css` | Base typography, `:focus-visible` ring (WCAG AA §1.2), `prefers-reduced-motion`, utility `.app-canvas` (gradasi latar sidebar mengambang) dan `.scrollbar-slim`. |
| `frontend/src/shared/ui/*` | **16 komponen design system** (lihat di bawah) + `index.ts` barrel. |
| `frontend/src/shared/ui/placeholderAssets.ts` | Registry aset sementara (Unsplash / Popsy / DiceBear) dengan `source`, `credit`, `license` per entri. |

Komponen di `src/shared/ui/`: `UiButton`, `UiTextField`, `UiSelect`, `UiCheckbox`, `UiAvatar`, `UiCard`,
`UiBadge`, `UiBreadcrumb`, `UiPagination`, `UiSearchInput`, `UiProgressBar`, `UiSparkline`,
`UiDonutChart`, `UiTabs`, `UiStatTile`, `UiStepper`.

> ⚠️ **Belum dijalankan**: `npm run check` (lint + type-check + test + build). Itu **langkah pertama**
> sesi berikutnya. Perhatikan worktree deps bisa basi — jalankan `npm ci` dulu bila perlu.

---

## 4. Yang BELUM dikerjakan (urutan yang disarankan)

1. **`npm ci && npm run check`** di `frontend/` → perbaiki error lint/TS pada 16 komponen baru.
2. **Halaman styleguide** `src/features/ui-kit/views/UiKitView.vue` + route `/ui-kit` — cara tercepat
   me-review seluruh komponen bab 3 dalam satu layar (dan bukti visual untuk board).
3. **Shell redesign** — `src/shared/layouts/`: `RedesignShell.vue` + `AppSidebar.vue` + `AppTopbar.vue` +
   `nav.config.ts`.
   - Sidebar putih mengambang di atas `.app-canvas`, radius besar, tombol `«` untuk collapse.
   - Blok profil (avatar + nama + NIP) di atas, lalu grup: **KEPEGAWAIAN** (Daftar Pegawai, Kelola Data,
     Arsip, Struktur Organisasi, Peta Jabatan, Kamus Kepegawaian, Laporan›, Layanan›, Presensi›),
     **KARIER** (E-Kinerja›, E-Talenta›), **BERITA** (Portal Berita + badge angka),
     **PUSAT BANTUAN** (Halo Simpeg, FAQ). Item aktif = navy `#1C3964` penuh, teks putih.
   - Topbar navy membulat: "Sistem Informasi Kepegawaian" + lonceng notifikasi ber-dot merah.
   - Baris breadcrumb di bawah topbar.
   - Mobile: sidebar jadi off-canvas + overlay.
4. **Dashboard Admin** (§4.1.1) dan **Dashboard Pengguna** (§4.2.1) memakai shell + komponen di atas.
5. Sisanya sesuai kartu di board: Struktur Organisasi, Laporan, Status Layanan, Daftar Pegawai,
   Detail Pegawai, Popup Notifikasi, News Portal, FAQ, Halo Simpeg, Admin Halo Simpeg.

### Keputusan arsitektur yang sudah diambil

- **`AppShell.vue` lama TIDAK diubah.** `src/shared/components/__tests__/AppShell.spec.ts` mengunci daftar
  menu lama (`['Beranda','Manajemen Akun','Master Data','FAQ']` + kelas `flex-wrap`). Shell redesign dibuat
  sebagai komponen baru di `src/shared/layouts/`; halaman lama (login, Manajemen Akun, Master Data, FAQ)
  tetap jalan. Migrasi bertahap dilacak sebagai kartu tersendiri di board.
- **Aset**: tidak ada berkas gambar dikomit. Semua placeholder dimuat lewat URL dan terdaftar di
  `placeholderAssets.ts`, supaya penggantian aset final cukup menyentuh satu berkas.
- Pemetaan role → menu sidebar redesign **belum ditentukan** (menu redesign jauh lebih banyak daripada
  modul yang sudah ada). Perlu konfirmasi user; sementara jadikan kartu **Blocked**.

---

## 5. Aset sementara yang dipakai (harus diganti sebelum rilis)

| Kebutuhan | Sumber | Lisensi |
| --- | --- | --- |
| Foto berita (Portal Berita §4.2.3, kartu Berita Terbaru §4.1.1) | Unsplash — Adrian Hartanto `SspHIqF_tUA`, Ruben Sukatendel `VsPGJqafmTk`, UX Hours `xPbN9bOPO8g` | Unsplash License |
| Ilustrasi (berita, sambutan, pusat bantuan, empty state) | Popsy Illustrations (`illustrations.popsy.co`) | Gratis pribadi + komersial |
| Avatar pegawai | DiceBear 9.x koleksi `notionists` | Kode MIT, koleksi CC0 1.0 |
| Banner "Business People" pada mockup §4.2.1 | **belum ada penggantinya** — rencana: banner gradasi CSS + ilustrasi Popsy | — |
| Logo/lambang Kemenpar di sidebar | **belum ada** — mockup memakai lambang negara | — |

---

## 6. Board Trello "Frontend-UI" — ⚠️ BELUM DIBUAT DI AKUN USER

**Status: board ini dianggap BELUM ADA.** Sesi 28-09-2026 membuatnya lewat konektor MCP Trello, tapi konektor
itu login sebagai akun **"Fajrin F"** (workspace "Trello Workspace" / `userworkspace28232935`) — bukan akun
yang user pakai di browser. User mengonfirmasi: salah akun/workspace.

- Board salah-akun: https://trello.com/b/hC81acIV/frontend-ui — **jangan dipakai, jangan diisi lagi**.
  Jangan arsipkan/hapus tanpa izin user (bukan akun user).
- **Isi lengkap board (list, label, 21 kartu beserta deskripsinya) sudah disimpan di
  [`TRELLO-BOARD-SPEC.md`](TRELLO-BOARD-SPEC.md)** — pakai itu untuk membuat ulang, tidak perlu menyusun dari nol.

### Cara membuat ulang dengan benar

1. **Jangan pakai konektor MCP Trello** untuk board ini (akunnya salah). Pakai tab trello.com yang sudah login
   di browser user, lalu REST `fetch('/1/...')` dengan param `dsc` dari cookie (form-urlencoded) — pola yang
   sama dengan board "SIMPEG v2 — Timeline Pengembangan" (lihat memory `simpeg-v2-trello-sync`).
2. Tanyakan user workspace tujuannya (kemungkinan sama dengan board "SIMPEG v2 — Timeline Pengembangan",
   id `6a9f7a8923c331ca107e0ccd`).
3. Buat board `Frontend-UI` → 7 list → **beri nama** 6 label (lewat REST bisa) → 21 kartu sesuai SPEC, termasuk
   label oranye "Asset: Temporary" di kartu yang Asset Status-nya Temporary.
4. Sebelum membuat, sesuaikan status kartu dengan kondisi kode terbaru di branch (SPEC mengikuti commit `9dd8b1e`).

Kalau status kartu berubah: **pindahkan kartu ke list yang sesuai DAN ubah baris `Status:`**.
