# Modul C — Layanan & Approval (Fase 4)

Controller REST API modul ini (`App\Controllers\Api\Layanan\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Tugas Belajar — C-07 (MAKE-024 M5, PRD Fase 4 §5.2, §6.7)

Route: `Config/RoutesLayananStudi.php` ([V2] berkas route terpisah dari cuti `RoutesLayanan.php`, satu berkas per workstream —
disetujui reviewer CR 27-10-2026). Filter `jwt` + `role:1,2,3,6` (`TbService::ROLE_ROUTE`); **role 7 → 403** (DoD 070-4),
role 4/5/8 → 403. Aturan rinci di service (`Libraries/Layanan/Tb/*`). Urutan galat §7.10: 401 → 403 role → 404 baris tidak
terlihat → 403 bukan pelaku → 422 (`errors.status` untuk status/tanggal yang tidak mengizinkan).

| Method & path | Role | Isi |
|---|---|---|
| `GET tb?mode=saya\|pic\|admin&status=&search=&page=&per_page=` | 1,2,3,6 | `{items, total, page, per_page, mode, can_create, can_process}`. Bawaan role 1/3 `admin` (3 = lingkup `terapkanKeQuery`), lainnya `saya`. `pic` = semua status 0 tanpa batas unit (D14) bila PIC TB aktif, selain itu 403; `admin` untuk role 2/6 → 403 |
| `GET tb/opsi?nip=` | 1,2,3,6 | `{jenjang_pendidikan[] (status 1, id > 3), up, pegawai {nip + snapshot} \| null, slot_berkas {file_sp…file_3: label}, berkas_wajib, can_create}`; `nip` hanya role 1/3 (3: lingkup) |
| `POST tb` (multipart) | 1,2,3,6 | 201 + detail. Role 2/6 diri sendiri (isian `nip` diabaikan), status 0; role 3 `nip` dalam lingkup, status 0; role 1 `nip` PNS (`id_jenis_pegawai = 1`, 422 `errors.nip`), `status` 0\|1 (1 → data SK wajib + efek final, TB-07). Berkas slot `file_sp`, `file_1`, `file_2`, `file_3` wajib (role 1 bebas), 5 MB, `jpg jpeg png gif pdf doc docx` |
| `GET tb/{id}` | 1,2,3,6 | Pemilik, PIC TB aktif, role 1, role 3 lingkup; lainnya 404. Kolom DDL + `label_status`, `nada_status`, `tahap[]`, `aksi {ubah, hapus, proses, unduh_surat, perpanjangan}`, `lampiran[]` (ber-`tag` slot), `surat[]` (SK kode 47), `perpanjangan[]` (+`lampiran[]`), `status_penuh` |
| `PUT tb/{id}` (multipart `POST` + `_method=PUT`) | 1,2,3,6 | Pemilik: status 0 & `tgl_mulai > hari ini`; role 3: status 0 + lingkup; role 1: semua kecuali 2 (status 0 → 1 boleh; final tidak bisa dikembalikan). Berkas per slot mengganti lampiran slot itu; wajib bila slot belum berisi (lampiran v2 ber-`tag` atau kolom `file_*` impor) |
| `DELETE tb/{id}` | 1,2,3,6 | Pemilik & role 3 (lingkup): status 0; role 1: semua. Hapus fisik + audit + perpanjangan & semua berkas; TB disetujui dalam rentang → `pegawai.id_jenis_status` kembali 1 |
| `POST tb/{id}/process` | 1,2,3,6 | PIC TB aktif; status 0. `{aksi: setujui\|tolak, reason_note, no_sk, tgl_sk, ds_tb, no_ds_tb, tgl_ds_tb?, perihal_ds_tb?}` + berkas opsional `file_surat_tb` (PDF 5 MB → kode 47). Setujui: data SK wajib, efek final; tolak: `reason_note` wajib ≤ 255 byte. Selain status 0 → 422 `errors.status` |
| `POST tb/{id}/perpanjangan` | 1,2,3,6 | Role 1 saja (lain 403); induk status 1. `tgl_mulai_perpan` (bawaan akhir efektif + 1 hari), `tgl_akhir_perpan`, berkas `file_sperpan` wajib → kode 48. 201 + detail induk |
| `PUT tb/perpanjangan/{id}`, `DELETE tb/perpanjangan/{id}` | 1,2,3,6 | Role 1 saja; `PUT` dua tanggal saja [K]. Ringkasan `tgl_*_perpan` induk diperbarui di transaksi yang sama |
| `GET tb/{id}/lampiran/{id_attachment}` | 1,2,3,6 | Hak lihat detail; lampiran harus milik baris (kode 46/47 id itu, atau 48 milik perpanjangannya), selain itu 404. `attachment`, `nosniff`, `no-store` |
| `GET tb/{id}/surat` | 1,2,3,6 | Hak lihat detail; hanya status 1 (lain 404). SK unggahan PIC bila ada, kalau tidak PDF dirender dari `Views/pdf/surat_tb.php` (kop kementerian, penanda tangan Kabiro kepegawaian dari WebConfig) |

Contoh proses: `POST api/v1/tb/12/process` body `{"aksi": "tolak", "reason_note": "Berkas BAN PT belum ada"}` → 200 detail
(`status` 2, `label_status` "Ditolak"). Galat: `{"status": "error", "message": "Validasi gagal.", "errors": {"reason_note": ["Alasan ditolak tidak boleh kosong."]}}`.

## Izin Belajar — C-08 (MAKE-024 M5, PRD Fase 4 §5.3, §6.8)

Route: `Config/RoutesLayananStudi.php`, filter `jwt` + `role:1,2,3,7` (`IbService::ROLE_ROUTE`); **role 6 → 403** (DoD 071-3),
role 4/5/8 → 403. Pola sama dengan TB kecuali yang disebut di bawah.

| Method & path | Role | Isi |
|---|---|---|
| `GET ib?mode=saya\|pic\|admin&status=&search=&page=&per_page=` | 1,2,3,7 | Seperti TB; `pic` = PIC IB aktif (`petugas_layanan` jenis 4), tanpa batas unit |
| `GET ib/opsi?nip=` | 1,2,3,7 | Seperti TB; `slot_berkas` = `file_sp`, `file_1`, `file_2`; `berkas_wajib` selalu `true` |
| `POST ib` (multipart) | 1,2,3,7 | 201 + detail. Role 2/7 diri sendiri; role 1 & 3 `nip` (3 lingkup) — input admin berfungsi dengan berkas (IB-01). Status **selalu 0** (`status` ≠ 0 dari role 1 → 422 `errors.status`). Tiga slot wajib **semua role** termasuk role 1 [K] |
| `GET ib/{id}` | 1,2,3,7 | Pemilik, PIC IB aktif, role 1, role 3 lingkup; lainnya 404. Seperti TB tanpa `status_penuh`/`perpanjangan[]`; `surat[]` = Surat IB kode 50 |
| `PUT ib/{id}`, `DELETE ib/{id}` | 1,2,3,7 | Aturan sama TB (pemilik status 0 & `tgl_mulai > hari ini`; role 3 status 0 + lingkup; role 1 kecuali 2 / hapus semua), tanpa ubah status oleh admin. Hapus fisik + audit + berkas |
| `POST ib/{id}/process` | 1,2,3,7 | PIC IB aktif; status 0. `{aksi: setujui\|tolak, reason_note, no_sk, tgl_sk, ds_ib, no_ds_ib, tgl_ds_ib?, perihal_ds_ib?}`. Setujui → **Surat IB PDF dibuat otomatis** (`Views/pdf/surat_ib.php`, penanda tangan Kabiro) dan disimpan kode 50 dalam transaksi yang sama; tanpa efek pegawai/presensi/tukin |
| `GET ib/{id}/lampiran/{id_attachment}` | 1,2,3,7 | Hak lihat detail; lampiran kode 49/50 milik baris itu, selain itu 404 |
| `GET ib/{id}/surat` | 1,2,3,7 | Hak lihat detail; Surat IB tersimpan terbaru (hanya status 1), selain itu 404 |

## PMK — C-09 (MAKE-024 M5, PRD Fase 4 §5.4, §6.9)

Route: `Config/RoutesLayananStudi.php`, filter `jwt` + `role:1,2,3` (`DasarPmkService::ROLE_ROUTE`); **role 4–8 → 403**
(DoD 072-4). Role 3 lihat saja (tulis → 403). PMK hanya PNS (P-17).

| Method & path | Role | Isi |
|---|---|---|
| `GET pmk?nip=&page=&per_page=` | 1,2,3 | Riwayat PMK final (`riwayat_pmk`, impor/SIASN): role 2 diri sendiri, 3 lingkup, 1 semua; item + `arsip[]` (kode 42); `can_edit` |
| `PUT pmk/{id}` (multipart `arsip`) | 1,2,3 | Role 1 saja: tambah arsip SK (wajib, 2 MB, `jpg jpeg png gif pdf`) → daftar arsip |
| `DELETE pmk/{id}` | 1,2,3 | Role 1 saja: hapus fisik + audit + arsip |
| `DELETE pmk/{id}/arsip/{id_attachment}` | 1,2,3 | Role 1 saja; minimal satu arsip tersisa (422 `errors.arsip`); arsip bukan milik baris → 404 |
| `GET pmk/pengajuan?mode=saya\|pic\|admin&status=&search=` | 1,2,3 | `saya` (role 2), `pic` (PIC PMK aktif: status 0 dan 3, tanpa batas unit [K P-19]), `admin` (role 1 semua, role 3 lingkup lihat saja); bawaan role 1/3 `admin` |
| `GET pmk/opsi?nip=` | 1,2,3 | `{jenis_pmk[] (Swasta, Negeri), pegawai, tmt_cpns (pegawai_cpns.tmtsk), tgl_awal_maks (TMT − 1 hari), berkas {kolom: label}, can_create}` |
| `POST pmk/hitung-mker` | 1,2,3 | `{tgl_awal, tgl_akhir}` → `{mker_th, mker_bl, peringatan}` (diff y/m, hari diabaikan [K]) |
| `POST pmk/pengajuan` (multipart) | 1,2,3 | Role 2 PNS diri sendiri; role 1 `nip` PNS; role 3 → 403. `jenis_pmk`, `instansi`, `tgl_awal`, `tgl_akhir`, `mker_th`, `mker_bl` (dari klien [K P-4]) + berkas kolom `file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji` wajib (2 MB, `jpg jpeg png gif pdf`). Status 0. 201 + detail |
| `GET pmk/pengajuan/{id}` | 1,2,3 | Pemilik, PIC PMK aktif, role 1, role 3 lingkup; lainnya 404 (status 0 boleh dibuka, Q-16). Kolom berkas = penanda `true/false` (path internal tidak dikirim), `label_status`, `nada_status`, `tahap[]`, `aksi {ubah, hapus, proses}`, `aksi_tersedia[]` |
| `PUT pmk/pengajuan/{id}` (multipart `POST` + `_method=PUT`) | 1,2,3 | Pemilik (role 2) / role 1, tanpa syarat status; status tidak berubah [K D19]. Berkas pengganti per kolom; wajib bila kolom kosong |
| `DELETE pmk/pengajuan/{id}` | 1,2,3 | Pemilik / role 1; status 1 → 422 `errors.status` [K]; hapus fisik + audit + berkas (P-9) |
| `POST pmk/pengajuan/{id}/process` | 1,2,3 | PIC PMK aktif; status 0/3. `{aksi: proses\|setujui\|tolak, reason_note}`: 0 → 3, 0/3 → 1, 0/3 → 2 (alasan wajib ≤ 255 byte); 1/2 → 422 `errors.status`. Tanpa salin ke `riwayat_pmk`, tanpa ubah masa kerja (D16) |

| `GET pmk/pengajuan/{id}/berkas/{kolom}` | 1,2,3 | **[V2] gap PRD §5.4** (disetujui Horii 27-10-2026; legacy membuka lewat tautan langsung `views/hr/employee/rwy/pmk/req/admin/form.php:142-214`, `pathToSrc()` `helpers/function_helper.php:314`). Hak = hak lihat detail (404 bila tak terlihat). `{kolom}` hanya `file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji` (lain → 404). Berkas dari kolom lewat KolomBerkas di dalam akar storage; kolom kosong, path impor legacy yang belum ada di storage v2, atau path yang ditolak storage (traversal) → 404. Isi dialirkan dengan mime dari isi, nama `pmk_{id}_{kolom}.{ext}` (tanpa path internal), `attachment` (`inline=1` → inline), `nosniff`, `no-store` |
