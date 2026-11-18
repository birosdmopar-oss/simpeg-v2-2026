# Modul E — Halo Simpeg (Fase 8)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — state domain modul ini (state tingkat modul, bukan Pinia; lihat bagian State)
- `schemas.ts` — skema Zod kontrak + form (ADR-026); folder `schemas/` kosong

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Helpdesk E-01..E-09 — frontend (MAKE-073, MAKE-074 FE)

Rencana jalur: `docs/fase8/8_2.md` B1–B5. Progres: `backend/docs/progress/08-HaloSimpeg.md` bagian helpdesk. Chatbot "Vanya" punya
README sendiri: `chatbot/README.md` (MAKE-095).

### Kontrak & fixture

- Kontrak beku: README `backend/app/Controllers/Api/HaloSimpeg` (master MAKE-070, tiket MAKE-071, ekspor MAKE-074). Setiap respons
  di-parse Zod (`schemas.ts`); di luar kontrak → `KontrakHaloError`. Interceptor `@/lib/axios` sudah membuka amplop (jangan
  `res.data.data`); kode galat bisnis dibaca lewat `kodeGalat()` (`services/master.service.ts`).
- Fixture `__tests__/fixtures/*.json` = salinan `backend/tests/_support/HaloSimpeg/*.json` dari `main` (ekspor @947b94fd); salin ulang
  bila kontrak berubah lewat CR. Test amplop (`*.amplop.spec.ts`) memakai `api` asli + adapter tiruan.

### Peta berkas

| Berkas | Isi |
|---|---|
| `services/master.service.ts` | kategori, petugas (+ `kandidat`, `saya`), kontak (`kontak/aktif` array, `kontak` berhalaman) |
| `services/tiket.service.ts` | daftar, ringkasan, detail, buat, balas, baca, tutup, hapus, lampiran (E-04 blob), ekspor (E-09) |
| `stores/haloSimpeg.ts` | state modul (bukan Pinia): `petugasSaya`, `ringkasan`, `bisaEkspor`, `petugasAktif`; penjaga generasi + urutan permintaan |
| `stores/tiket.ts` | pabrik daftar per peran (`daftarSaya`, `daftarAntrean`, `daftarPerluTindak`) + detail dan aksi bersama |
| `composables/useRingkasanHalo.ts` | satu instance polling `tiket/ringkasan` tiap 60 dtk untuk badge menu |
| `navBadge.ts`, `entriTiket.ts` | sisip badge item `halo` di menu; tujuan entri "Tiket Halo Simpeg (n)" di halaman Notifikasi |
| `routes.halo.ts` | `/halo-simpeg`, `/halo-simpeg/tiket/:id`, `/halo-simpeg-admin/:bagian` |
| `views/HaloSimpegPage.vue` | layar pemilik + petugas (tab Tiket Saya / Antrean) |
| `views/HaloSimpegAdminPage.vue`, `views/admin/MasterHaloView.vue` | master Kategori / Petugas / Kontak (role 1) |
| `components/` | `DaftarTiket`, `ThreadTiket`, `TiketBaruDialog`, `PanelPerluTindak`, `KontakHelpdeskCard`, `EksporRekapDialog`, `admin/*FormDialog` |

### State, badge, dan reset

- State tingkat modul dikosongkan `resetHaloSimpeg()` dari `clearSession()` (`features/auth/stores/auth.store.ts`); respons yang
  tiba sesudah reset dibuang (generasi).
- Badge menu (AC-E08.11/12): `useRingkasanHalo` aktif hanya bila `ACTIVE_PHASE >= 8`, role 1–7, dan tidak wajib ganti password;
  `RedesignShell` menyisipkan angka `badge` ke item `halo`; sidebar ciut menampilkan titik penanda. Lonceng tetap memakai ringkasan
  notifikasi (`total`).
- Notifikasi (H8-15 (b)): entri "Tiket Halo Simpeg (n)" menuju Halo Simpeg, tab Antrean bila sisi petugas > 0.

### Layar pemilik (B2)

- Master-detail (AC-E08.10): daftar kiri, thread kanan; < 1024 px satu panel dengan "Kembali ke daftar" (fokus kembali ke ⋮ baris).
- ⋮ Lihat detail → Tutup → Unduh lampiran → Hapus (AGENTS.md §1); `hidden`/`disabled` dari flag BE `bisa_*`; ConfirmDialog teks
  legacy. Baris tidak dapat diklik.
- "Tambah Pertanyaan" hanya role 2/6/7. Bawaan filter baca tab "Tiket saya" = `semua` **[V2]** (D8 (a)); kolom + filter kategori di
  tab ini **[V2]** (PRD §7.4; legacy hanya untuk petugas multi-kategori). Tiket kategori NULL (H8-16 (a1)): form balas diganti catatan.
- Thread: label pengirim apa adanya, isi sebagai teks (tanpa `v-html`); lampiran hanya lewat E-04 (blob, nama dari
  `Content-Disposition`); POST baca sekali saat thread dibuka (BP8-7). 422/429 tampil per kolom dari amplop.

### Layar petugas (B3)

- Tab "Antrean" hanya bila `petugas/saya` aktif (bukan role), deep-link `?tab=antrean`; selama `petugas/saya` dimuat → "Memuat...";
  galat tanpa data petugas → notice + "Coba lagi" dengan tab tetap bisa dipilih; galat dengan cache petugas aktif → antrean dari cache.
  Non-petugas dengan `?tab=antrean` mendapat tab "Tiket saya" + kartu Kontak Helpdesk.
- Antrean: bawaan baca `belum` [K], kolom Pemilik [K], filter kategori hanya bila ≥ 2 kategori [K `VI:31-36`].
- Panel "Perlu ditindak" = daftar server `perlu_tindak=1` (5 baris; kosong tetap tampil, pengganti popup login legacy F8-12).
  "Lihat semua" = `perlu_tindak=1` + `baca=semua` tanpa filter lain; "Hapus filter" → bawaan antrean. Panel dimuat ulang saat
  `Ringkasan.perlu_tindak` berubah (satu pemicu per aksi); bila ringkasan belum ada sebelum baca, panel disegarkan sesudah baca.
- Snapshot pelapor di header thread [K `VD:21-26`]: desktop = tombol "Data pelapor" + `UiTooltip` (hover, fokus, atau klik), isi
  hanya lewat `aria-describedby`; layar sempit = teks kecil.
- Centang "Ingatkan Saya Kembali?" hanya bila `pengingat !== null`; pengingat aktif → petunjuk pembatalan + jatuh tempo; label
  berganti ke "Pengingat jatuh tempo" saat waktunya lewat (detak per menit).

### Admin master (B3, role 1)

- `/halo-simpeg-admin/:bagian` (Kategori | Petugas | Kontak); `:bagian` tidak dikenal → Kategori. Menu `halo-admin` (`phase: 8`).
- ⋮ Edit → Aktifkan/Nonaktifkan → Hapus; Status hanya badge. Hapus `disabled` dari `bisa_hapus`, alasannya =
  `RowAction.keterangan` (`alasan_tidak_bisa_hapus` BE untuk Petugas, teks FE **[V2]** untuk Kategori); item itu tetap bisa difokus
  panah agar alasannya terbaca. `peringatan` Nonaktifkan/PUT petugas (S8 (b)) → notice peringatan; 422 `petugas_terakhir`,
  `kategori_dipakai`, `petugas_duplikat` → pesan BE (per kolom bila ada `errors`).
- Kandidat petugas: `petugas/kandidat?search=` ber-jeda 300 ms, 2..50 karakter; hasil server tanpa saring lokal (`UiCombobox`
  `saringLokal=false`), dipilih lewat indeks → `id_user`; saran teks lama disembunyikan selama jeda kueri baru; `peringatan` satker
  kosong → notice. Kategori lama Tidak Aktif tetap terpilih berbadge.

### Ekspor rekap petugas (B4, MAKE-074)

- Tombol "Ekspor Rekap" hanya di tab Antrean dan hanya bila `bisaEkspor` (`petugas/saya.bisa_ekspor`, bukan role) →
  `EksporRekapDialog`: dua `UiTextField type="date"` (nilai `YYYY-MM-DD`, kalender lokal).
- Batas picker maks = hari ini **WIB** (`hariIniWib()`, `Asia/Jakarta`, tanpa `toISOString()`) [K `endDate: '0'`]; `ds` ≤ `de`
  dijaga silang antarpicker [K]. Ketikan di luar batas ditolak `UiDatePicker` bersama (nilai dikosongkan + pesan rentang), jadi
  tidak terkirim (deviasi dari rancangan B4 "dikirim apa adanya"; komponen bersama tidak diubah).
- Teks validasi klien = teks BE ("Tanggal awal dan akhir wajib diisi." / "Tanggal awal tidak boleh melebihi tanggal akhir.")
  **[V2]** terhadap teks legacy.
- `GET tiket/ekspor?ds&de&format=xlsx` lewat `ambilBerkas` (tidak diubah): 200 `Content-Type` JSON = periode kosong → notice pesan BE
  tanpa unduhan; selain itu berkas → `simpanBerkas`. 403 `bukan_petugas` → pesan BE + `petugas/saya` dimuat ulang paksa; 422 per
  kolom → galat kolom; 422 `ekspor_terlalu_besar` → pesan BE.

### Berkas bersama yang diubah (aditif)

- `shared/components/RowActionsMenu.vue` + `rowActions.ts`: `RowAction.keterangan`; item disabled berketerangan tetap bisa difokus
  (`aria-disabled`, nama = label lewat `aria-labelledby`, keterangan lewat `aria-describedby`, memilih ditolak). Helper test membaca
  `disabled` dari `data-disabled` atau `aria-disabled`.
- `shared/ui/UiCombobox.vue` + `UiTextField.vue`: prop `saringLokal` (bawaan `true`) + event `pilih` (indeks).
- `shared/ui/UiTooltip.vue`: prop `open` opsional (`v-model:open`; tanpa prop tetap tak terkendali).
- `shared/layouts/nav.config.ts` (menu `halo-admin`), `RedesignShell.vue` (badge), `AppSidebar.vue` (titik penanda),
  `router/index.ts`, `auth.store.ts` (`resetHaloSimpeg`), `notifikasi/views/NotifikasiPage.vue` (entri tiket).

### Tanpa frame Figma (H8-9, AC-E08.7)

Frame Figma helpdesk belum ada. Setiap layar mengikuti pola redesign yang sudah sesuai Figma (AGENTS.md §3.6); AC-E08.7 diuji di QA
Lapis 1 (checklist §HV), tanpa test Vitest:

| Layar | Figma | Acuan |
|---|---|---|
| Pemilik `/halo-simpeg`, `/halo-simpeg/tiket/:id` | belum ada | Gambar 33 (frontend redesign `67fa7678`), master-detail (bukan drawer) |
| Petugas (tab Antrean, panel Perlu ditindak, thread sisi petugas) | belum ada | Gambar 34, daftar tabel + ⋮ (baris tidak dapat diklik) |
| Master Kategori / Petugas / Kontak | belum ada | pola Master Data / PIC layanan (`page-fill`, ⋮, paginasi) |
| Dialog Tambah Pertanyaan, form master, dialog Ekspor Rekap | belum ada | pola dialog form redesign (`FORM_GRID_CLASS`, `UiNotice`) |

### Test

`__tests__/`: amplop (`haloSimpeg`, `tiket`, `ekspor`), skema (`schemas.master`, `schemas.tiket`, `kontrakTipe`), store
(`haloSimpeg.store`, `tiket.store`), `ringkasanHalo`, `navBadge`, `entriTiket`, `HaloShell`, layar (`HaloSimpegPage`, `HaloAdmin`,
`EksporRekapDialog`). Test bersama: `shared/components/__tests__/RowActionsMenu.spec.ts`, `shared/ui/__tests__/UiCombobox.spec.ts`,
`UiTooltip.spec.ts`, `shared/layouts/__tests__/AppSidebar.badge.spec.ts`, `nav.config.spec.ts`, `router/__tests__/router.halo.spec.ts`,
`notifikasi/__tests__/notifikasi.spec.ts`.
