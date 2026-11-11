# Modul F-08 — Pusat Notifikasi & Inbox (`api/v1/notifikasi*`)

Fase 7 Jalur C, MAKE-085. Acuan: PRD Fase 7 v2.0 §5.9, §6, §7 (`docs/fase7/PRD_Fase7_v2_Dashboard-Konten-Notifikasi.md`).
Padanan legacy: `hr/notification/{index,need_approval,ah,delete}` (`controllers/hr/Notification.php` = `NC`,
`libraries/hr/L_notification.php` = `LN`) dan penghitung lonceng `core/MY_Controller.php:20-175`.

Kode: controller `NotifikasiController`, route `Config/RoutesNotifikasi.php`, logika `Libraries/Notifikasi/Pusat/`,
antarmuka `Interfaces/Notifikasi/`.

## Prinsip
- **Agregator baca-saja.** Modul ini tidak membuat notifikasi. Ia membaca sumber yang sudah ditulis modul lain dan hanya
  menulis penanda baca/hapus pada kolom yang sudah ada.
- **Sumber:**
  - S1 `inbox`/`inbox_msg` v2: layanan cuti/TB/IB/PMK (Fase 4), termasuk tembusan Ketua Tim (Q7-32).
  - S2 `show_notif` tabel sumber: riwayat, biodata (`pegawai_hist`), LKH (`riwayat_lckh`), Konket (`absen_ijin`).
  - S3 antrean approval.
  - S4 histori approval.
  - Sumber modul lain lewat `SumberNotifikasiInterface`: berita F-07, binding `Services::sumberNotifikasiBerita()`
    (`SumberBerita`, aktif sejak DBV-032 + F-07; `berita_belum_dibaca` = angka, bukan null).
- **BP8-1:** tiket Halo Simpeg hanya angka lewat `RingkasanTiketInterface` (`Services::ringkasanTiket()`, NoOp/null sampai
  Fase 8). Kode modul ini dilarang memuat nama tabel tiket atau tabel berita (`PusatNotifikasiArsitekturTest`).
- **Waktu** dikirim UTC ISO `YYYY-MM-DDTHH:MM:SSZ`.

## Endpoint
| Method & path | Role | Isi |
|---|---|---|
| `GET notifikasi/ringkasan` | semua role login [K `MY_Controller:27-28,117-123`] | Kontrak lonceng. Lihat bagian di bawah |
| `GET notifikasi?status=belum\|semua&page=&per_page=` | 2, 3, 6, 7 [K `NC:12`] | Daftar gabungan, terbaru dulu (`per_page` maks 50). Respons `{ items, total, belum_dibaca, page, per_page }` |
| `POST notifikasi/baca` | 2, 3, 6, 7 | Body `{ ids: ["<sumber>:<id>", ...] }` (1–100) atau `{ semua: true }` → `{ diubah }` |
| `DELETE notifikasi/{sumber}/{id}` | 1, 2, 6, 7 [K `NC:120`] | S2 → `show_notif` 2 [K] → `{ dihapus: true }`. S1 layanan / bukan milik / tidak ada → 404 (Q7-33) |
| `GET notifikasi/butuh-approval?page=&per_page=` | 1, 2, 3 [K `NC:39`] | `{ total, grup, riwayat }`. Rincian di bawah tabel |
| `GET notifikasi/histori-approval?bulan=YYYY-MM&page=&per_page=` | 1, 2, 3 [K `NC:68`] | `{ bulan, pilihan_bulan (12 bulan terakhir, bulan ini dulu), items, total, page, per_page }`. Bulan di luar daftar → 422 |

Rincian `GET notifikasi`:
- Bentuk item: `{ id: "<sumber>:<id>", sumber, judul, keterangan, status_label, reason_note, url_fe, dibaca, waktu, dapat_dihapus }`.
- Nilai `sumber`: `layanan` (S1), slug jenis riwayat (`alamat`, `kp`, …), `konket`, `lkh`, `biodata`, `berita`.
- Gabungan dibatasi 500 notifikasi terbaru [V2].

Rincian `GET notifikasi/butuh-approval`:
- `grup`: daftar `{ kode, label, jumlah, url_fe }`. Grup bernilai 0 tidak dikirim.
- `riwayat`: daftar item riwayat lintas pegawai yang menunggu proses (role 1/3; `null` untuk role 2). Bentuk item = item
  daftar + `nip`, `nama`.

### Kontrak lonceng (Jalur D dan FE)
`{ notifikasi_belum_dibaca: int, butuh_approval: int, berita_belum_dibaca: int|null, tiket_belum_dibaca: int|null, diperbarui_at }`.
- `null` = sumber belum tersedia, bukan 0.
- `notifikasi_belum_dibaca` hanya untuk role 2/3/6/7 dan `butuh_approval` hanya untuk role 1/2/3; role lain bernilai 0
  [K `MY_Controller:127-171`].
- Field baru hanya aditif.

## Role & sumber per role
- **Daftar notifikasi:**
  - UL 2/7: jenis riwayat daftar legacy (`LN:2464-2482`).
  - UL 6: jabatan, pendidikan, alamat (`LN:2765`).
  - Semua role di atas juga mendapat Konket, LKH, biodata, dan S1 (bila akun ber-NIP).
  - Role 3: berita (adapter) [K `LN:2886-3050`].
- **Butuh approval:**
  - Role 1: riwayat self-service status 0/3, usulan Karpeg/Karis, biodata, Konket `W` induk, berita.
  - Role 3: riwayat status 0, biodata, Konket, dalam lingkup jalur proses PegawaiScope.
  - Role 2: LKH atasan, cuti AL/PyB, TB/IB/PMK PIC, dihitung lewat service antrean modul masing-masing (kondisi tunggal
    dengan halaman modul).
- **Histori approval:**
  - Role 1/3: kolom `show_ua_biro` / `show_ua_upt` / `show_ua_deputi` + bulan `notif_date` [K].
  - Role 2: LKH yang diverifikasi dan cuti yang diputus sebagai AL/PyB.

## Deviasi [V2]
| # | Legacy | v2 |
|---|---|---|
| 1 | Membuka halaman daftar menandai semua dibaca (`LN:3247-3266`) | Dibaca saat item dibuka / "Tandai dibaca" (Q7-31) |
| 2 | Angka lonceng (preview) berbeda kondisi dengan halaman (`LN:183-239`) | Satu kondisi untuk angka dan daftar |
| 3 | OR tanpa kurung di histori (`LN:4002,4241`) | Diperbaiki |
| 4 | UL 7 memakai whitelist PTT untuk hapus (`NC:153-160`) | UL 7 = daftar UL 2 untuk daftar dan hapus |
| 5 | Cuti/TB/IB/PMK lewat `show_notif` tabel layanan | Lewat `inbox` v2 (S1, Fase 4) |
| 6 | Notifikasi layanan bisa dihapus | Tanpa hapus (Q7-33) |
| 7 | Histori cuti difilter `notif_date` | Bulan `updated_at` (alur cuti v2 tidak mengisi `notif_date`) |
| 8 | Histori PIC TB/IB (`show_pl`) / PMK | Belum: alur v2 tidak mengisi penanda pemroses (tindak lanjut adapter) |
| 9 | `pegawai_hist` histori admin satker memakai `show_ua_deputi` (`LN:3812`, bug) | Kolom sesuai admin (`show_ua_upt`/`show_ua_deputi`) |
| 10 | Baris dihapus fisik | Baris status 10 (hapus lunak v2) tidak tampil |
