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
