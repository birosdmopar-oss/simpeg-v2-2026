# Modul C — Layanan & Approval (Fase 4)

Controller REST API modul ini (`App\Controllers\Api\Layanan\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Cuti (MAKE-022 M3, PRD Fase 4 §5.1, §6.2–§6.4)

Prefix `api/v1/`, envelope ADR-001 (`{status, data}` / `{status: error, message, errors}`). Filter `jwt` di grup; role
route **1, 2, 3, 6, 7** kecuali `POST cuti/hitung-lama` (semua login). Role di luar route → **403**. Hak per baris (D5)
di `CutiService`: baris yang tidak boleh dilihat → **404**; terlihat tetapi tidak berhak → **403**. Rute didefinisikan di
`Config/RoutesLayanan.php`; kontrak role di `tests/feature/KontrakMatriksRoleTest.php`.

| Method & path | Role route | Isi |
|---|---|---|
| `GET cuti?mode=saya\|persetujuan\|admin&status=&id_jenis_cuti=&tahun=&search=&id_unit=&id_satker=&page=&per_page=` | 1,2,3,6,7 | `{items, total, page, per_page, mode, can_create}`. Bawaan: role 1/3 `admin`, lainnya `saya`. `mode=admin` untuk role 2/6/7 → 403 (role 3 dibatasi lingkup, role 1 semua; data `YEAR(tgl_mulai) >= tahun−2`). `persetujuan`: NIP pemanggil = AL (status 0, dua langkah) atau PyB (status 3 / status 0 langkah tunggal). Item admin memuat `perlu_atas_nama` |
| `GET cuti/opsi?nip=` | 1,2,3,6,7 | `{jenis_cuti[], atasan_langsung {nip,nama,jabatan,bisa_memproses}\|null, pyb[] (+bisa_memproses), langkah_tunggal, eselon_i, saldo, tgl_akhir_maks, ketua_tim[], can_create}`. `nip` hanya dibaca untuk role 1. Role 3/6: `can_create=false`. `bisa_memproses` hanya informasi (PRD §6.4.10) |
| `POST cuti/hitung-lama` | semua login | Body JSON `{id_jenis_cuti, tgl_mulai, tgl_akhir?, durasi?, nip?, id_riwayat_cuti?}` → `{lama_cuti, satuan_lama_cuti, tgl_akhir, hari_libur[{tanggal, keterangan}], kuota\|null}`. Tanpa efek tulis; galat → 422 per kolom. `nip` hanya role 1. `kuota` hanya untuk jenis 1 |
| `POST cuti` | 1,2,3,6,7 (3, 6 → 403) | Multipart/JSON → **201** item. Role 2/7: untuk diri sendiri, status dipaksa 0. Role 1: `nip` + `status` 0\|1 (1 = langsung disetujui dan memotong saldo). Field: `id_jenis_cuti`, `tgl_mulai`, `tgl_akhir` (jenis 2: `durasi` 1–3), `no_telp_saat_cuti`, `alamat_saat_cuti`, `keterangan` (≥ 10), `nip_atasan_langsung`, `nip_yang_menyetujui` (kecuali eselon I), `ketua_tim[]`, `file_bukti` (5 MB jpg/jpeg/png/gif/pdf). `lama_cuti` dan snapshot **tidak** diterima dari klien |
| `GET cuti/{id}` | 1,2,3,6,7 | Item + `ketua_tim_detail[]`, `lampiran[]`, `surat[]`, `riwayat_approval`. Boleh melihat: pemilik, AL, PyB, ketua tim, role 1, role 3 dalam lingkup; lainnya 404 |
| `PUT cuti/{id}` (multipart: `POST` + `_method=PUT`) | 1,2,3,6,7 | Pemilik (2/7): status 0 dan `tgl_mulai >= hari ini`; role 1: status 0. Status ≠ 0 → 422 `errors.status`; tanggal lewat → 422 `errors.tgl_mulai`; bukan pemilik → 403 |
| `DELETE cuti/{id}` | 1,2,3,6,7 | Hapus **fisik** (D6): pemilik status 0/2, role 1 semua status, role 3 → 403. Refund saldo jenis 1 status 1/4 (`KuotaCuti::kembalikan` dengan snapshot), CLTN berjalan dipulihkan, lampiran dihapus, audit `before`. `{deleted: true, soft_delete: false, id}` |
| `GET cuti/{id}/lampiran`, `GET cuti/{id}/surat` | 1,2,3,6,7 | Unduh (`attachment`, `nosniff`, `no-store`); surat hanya status 1/4 (ber-TTE bila ada, kalau tidak versi belum TTE). 404 bila tidak ada |

Kode galat 422 (`errors.<field>`): `id_jenis_cuti`, `tgl_mulai`, `tgl_akhir`, `durasi`, `no_telp_saat_cuti`, `alamat_saat_cuti`,
`keterangan`, `nip_atasan_langsung`, `nip_yang_menyetujui`, `ketua_tim`, `file_bukti`, `lama_cuti` (kuota), `status`. Semua
galat dikumpulkan dalam satu respons (A-09). Audit tidak memuat isi berkas.
