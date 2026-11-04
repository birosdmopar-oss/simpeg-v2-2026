# SIASN BKN (H-03, MAKE-067)

Integrasi SIASN BKN Fase 6 Jalur B — **ikut legacy penuh** (PRD Fase 6 §7.3, F6-14). Tanpa UI admin; dijalankan lewat
command terjadwal (crontab runbook, PN-5).

- `SiasnAdapter` — adapter nyata `SiasnGatewayInterface` (kontrak tidak diubah): `GET {bkn.baseUrlApi}/pns/{jalur}/{nip}`
  dengan dua header token (`TokenBkn::kirimSiasn`, 401 → ulang sekali), GET di-retry `KlienHttp`, NIP disensor dari
  `integrasi_log`. Sukses hanya bila HTTP 2xx + `code == 1` + `data` berbentuk benar (data utama = objek, riwayat = daftar
  objek); selain itu `status_code` gagal (`'respons'`, kode HTTP, `'timeout'`, `'koneksi'`, `'token'`), tidak pernah
  dilempar.
- `Tarik/*` — `siasn:tarik` (M1). Dorong (M2) dan arsip/referensi unor (M3) menyusul.

## `php spark siasn:tarik`

```
php spark siasn:tarik --jenis=<data-utama|golongan|pendidikan|pmk|skp|ak> [--limit=N] [--nip=<nip>] [--dry-run]
```

| Opsi | Arti |
|---|---|
| `--jenis` | Wajib. Padanan legacy: `data-utama` = `SS/Data_utama` + `SI::sync_data_utama`/`sync_pns_id`; `golongan` = `SS/Rw_gol` → `riwayat_kp`; `pendidikan` = `SS/Rw_pendidikan`; `pmk` = `SS/Rw_pmk`; `skp` = `SS/Rw_skp` (≥ 2022); `ak` = `SS/Rw_ak` → `riwayat_ak_siasn`. |
| `--limit` | NIP per batch, 1–500 (bawaan 20). Batch berikutnya melanjutkan NIP yang belum diproses (flag/stempel di `siasn_status_pegawai`). |
| `--nip` | Tarik satu NIP tanpa syarat usang. |
| `--dry-run` | Hitung rencana saja: tanpa tulis data, flag, daftar status, maupun ringkasan; tanpa kunci. Permintaan HTTP ke BKN **tetap** dikirim dan tetap tercatat teredaksi di `integrasi_log` oleh klien HTTP. |

**Kode keluar:** `0` berhasil, termasuk bila sebagian NIP gagal di sisi SIASN (dicatat, AC-03.1); `1` konfigurasi BKN
belum lengkap, akun sistem integrasi belum siap (`php spark integrasi:siapkan-akun-sistem`), `integrations.siasn.driver`
bukan `bkn`, jenis yang sama sedang berjalan, atau galat DB/lainnya (dicetak kelas & kode saja); `7` masukan salah.
Keluaran hanya jumlah, tanpa NIP/nama.

**Konfigurasi:** `integrations.siasn.driver = bkn` dan blok `integrations.bkn.*` (`baseUrlApi` sampai `/apisiasn/1.0`,
token gateway + SSO) hanya di `.env` server. Driver lain (mis. `mock`) ditolak `siasn:tarik` karena datanya contoh.

## Alur per batch

1. Pelaku = akun sistem integrasi (`AkunSistemIntegrasi::id()` di awal, Z-1) → `updated_by`/`approved_by`.
2. Kunci `GET_LOCK` per jenis (tunggu 0): run kedua jenis yang sama keluar "sedang berjalan".
3. Daftar status disinkronkan (padanan `sync_dsp`: pegawai `status = 1`, kecuali NIP `-`/`1`; INSERT IGNORE, satu
   transaksi), NIP dipilih: riwayat → flag 0 / stempel > 6 jam / kosong; data utama → `du = 0` dan `du_lu` sebelum hari
   ini (tanggal bisnis WIB), atau `du = 2` macet > 6 jam. Ditandai proses (`2`).
4. API per NIP di luar transaksi (BP-4).
5. Rencana → satu transaksi (sisip → ubah → hapus). Exception apa pun sesudah langkah 3 → flag kembali `0`, dilempar ulang.
6. Flag selesai + satu ringkasan `integrasi_log` (`tarik_<jenis>`, jumlah saja); batch kosong tidak dicatat.

## Pengaman aksi destruktif (F6-14 + putusan reviewer B6 M1)

Lepas pasangan (`id_rw_siasn = NULL`; golongan/pendidikan/PMK) dan hapus fisik SKP **hanya** untuk NIP yang:
jawabannya sukses, datanya tidak kosong, dan tidak ada barisnya yang ditolak/berbentuk tidak dikenal. Hapus fisik SKP juga
hanya bila batch itu punya sisip/ubah SKP (ikut legacy `Rw_skp.php:201`). AK tidak pernah dihapus (legacy dikomentari).

## Ringkasan (`integrasi_log.pesan`)

`nip`, `sukses`, `gagal`, `sisip`, `ubah`, `pasang`, `lepas`, `hapus`, `ganda` (golongan ganda → `double_riwayat_kp`),
`ditolak` (nilai tidak sah per kolom), dan INFO:

| INFO | Arti |
|---|---|
| `data_kosong` | NIP dengan jawaban sukses tetapi data kosong — tanpa aksi destruktif. |
| `pairing_ganda` | PMK: satu `tgl_sk` cocok ke > 1 baris lokal — tidak dipasangkan dan tidak disisipkan. |
| `referensi_tak_terpetakan` | Jenis KP / jenjang SIASN tanpa pemetaan ke master — bawaan legacy (jenis KP 3, jenjang NULL). |
| `ditolak_tanpa_destruktif` | NIP yang punya baris ditolak / berbentuk tidak dikenal — lepas/hapus untuk NIP itu dibatalkan. |

## `php spark siasn:dorong` (M2)

```
php spark siasn:dorong --jenis=<jabatan|diklat|sertifikasi|kursus|skp22> [--limit=N] [--nip=<nip>] [--dry-run]
```

Padanan `upload_*` legacy → `POST {baseUrlApi}/{jalur}/save` (JSON, dua header token), **tidak di-retry otomatis** (BP-3;
gagal diulang lewat siklus berikutnya sesuai kriteria tabel). Kriteria per tabel (CR-090):

| Jenis | Tabel | Dipilih bila | Id dari balasan |
|---|---|---|---|
| `jabatan` | `riwayat_mutasi_jabatan` | belum terkirim (`id_rw_siasn IS NULL` + flag 0), **diubah sesudah terkirim** (`siasn_lu < updated_at`), atau gagal/diproses (flag 2/3) > 6 jam; `jenis_jabatan = 1`, kelompok 1/2/3, TMT ≥ 2020, satker ≠ 171 | `mapData.rwJabatanId` |
| `diklat` | `riwayat_diklat` (`jenis_diklat = 1`, bukan hasil tarik) | belum terkirim, sekali | `mapData.rwDiklatId` |
| `sertifikasi` | `riwayat_diklat` (`jenis_diklat = 5`) | belum terkirim, sekali | `mapData.rwSertifikasiId` |
| `kursus` | `riwayat_seminar` | belum terkirim, sekali | `mapData.rwKursusId` |
| `skp22` | `riwayat_skp` tahun 2022 | belum terkirim, sekali | `mapData` |

- Pegawai aktif berpasangan SIASN (`pegawai.id_pns_siasn`); PNS saja kecuali sertifikasi [K].
- Payload divalidasi sebelum dikirim (mis. tanggal wajib); tidak sah → tidak dikirim, `siasn_flag = 2` (SKP 2022 kuadran
  tidak dikenal: 3 [K]) + `siasn_error` alasan.
- Sukses (HTTP 2xx + `success === true`) → `id_rw_siasn` (bila sah), flag 1, `keterangan` + " **Upload To SIASN**",
  `updated_by` akun sistem; jabatan juga `siasn_lu = updated_at = sekarang`. Gagal → flag 2 (jabatan "Internal Server Error"
  → 3), `siasn_error` = kode + pesan BKN dengan id PNS/NIP disensor, tanpa payload. Hasil ditulis per baris segera sesudah
  POST.
- Jabatan ditandai `siasn_flag = 3` sebelum dikirim tanpa menggeser `updated_at`; exception → penanda dipulihkan.
- Konfigurasi tambahan untuk jabatan (hanya `.env` server): `integrations.bkn.siasnInstansiId`, `siasnSatkerId`,
  `siasnUnorBawaan`.
- `--dry-run`: tanpa POST, tanpa tulis. Kode keluar sama dengan `siasn:tarik`. Ringkasan `integrasi_log` `dorong_<jenis>`:
  `baris`, `terkirim`, `sukses`, `gagal`, `tidak_sah`, `sukses_tanpa_id`, `status_tidak_diketahui` (timeout/koneksi saat
  POST — status di SIASN tidak diketahui; periksa duplikat di BKN sebelum riwayat itu didorong lagi).
- Bentuk permintaan POST (body JSON + `Content-Type: application/json`, `success` boolean) masih **asumsi** sampai bentuk
  `postApi_siasn` legacy dikonfirmasi (B6 M2 Q2); semua ada di `SiasnAdapter::simpanRiwayat()` / `uraiSimpan()`.

## `php spark siasn:tarik --jenis=ref-unor` (M3)

Padanan `SS/Ref_unor::sync` → `GET {baseUrlApi}/referensi/ref-unor` (satu panggilan untuk instansi, tanpa `--nip`/`--limit`)
ke `siasn_ref_unor`: id baru → INSERT dengan pemetaan lokal (`id_jabatan`, `id_unit`, `id_satker`, `lvl_uker`) NULL; berubah
→ UPDATE kolom respons saja, **pemetaan lokal tidak disentuh**; tanpa hapus [K]. Satu transaksi; jawaban gagal → tanpa tulis,
keluar 1. Ringkasan `tarik_ref_unor`: `sisip`, `ubah`, `tetap`, `ditolak`.

## `php spark siasn:unggah-arsip` (M3)

```
php spark siasn:unggah-arsip [--langkah=semua|antre|unggah] [--limit=N] [--dry-run]
```

- **antre** (padanan `set_queue_arsip` + `set_queue_arsip_sertifikasi`): lampiran PDF `jenis_rwy` 9/36/41 riwayat jabatan dan 5
  sertifikasi yang SUDAH terkirim (`id_rw_siasn` terisi) → `siasn_upload_arsip` status 0. Anti-antre-ulang: kunci legacy
  `nip***id_rw***arsip_path` + UNIQUE (`rw_name`,`id_rw`,`id_da`). `arsip_path` = `document_attachment.path` apa adanya; path
  yang tidak lolos normalisasi (`PathLegacy`) tidak diantre.
- **unggah** (padanan `SS/Arsip::upload_siasn`): status 0 urut id, bawaan 1 baris per jalan [K] → status 3 + `updated_at` →
  berkas dibaca lewat storage v2 → wajib PDF ≤ 1 MB → `POST upload-dok-rw` multipart (`id_riwayat` = `id_rw_siasn`,
  `id_ref_dokumen` 872 jabatan / 881 sertifikasi), tanpa retry → status 1 + `resp_*` mentah, atau status 2 + `error_msg`
  (berkas tidak ditemukan / "Invalid file type or file size" / "Failed to upload to SIASN" / "Timeout upload" = status di
  SIASN tidak diketahui). Exception → status 3 kembali ke 0.
- Ringkasan `antre_arsip` (`baru`, `sudah_ada`, `path_tidak_sah`) dan `unggah_arsip` (`diproses`, `sukses`, `gagal`,
  `berkas_hilang`, `tidak_sah`, `status_tidak_diketahui`).
- Bentuk unggah (nama field berkas `file`, balasan `{code, data}`) masih **asumsi** di `SiasnAdapter::unggahDokumen()` sampai
  bentuk `postApi_siasnArsip` legacy dikonfirmasi (B6 M3 Q1).
