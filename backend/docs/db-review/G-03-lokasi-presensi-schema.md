# G-03 Master Lokasi Presensi — DBV-007

Status dokumen: diajukan untuk review DB Validator (01-10-2026). Key kode: CR-031.

## Cakupan

Migration `2026-10-01-000001_CreateLokasiPresensi` membuat `lokasi_presensi` dan `dm_user_lokasi_presensi` dengan nama/tipe kolom legacy, `utf8mb4_unicode_ci`, audit `created_*`/`updated_*`, dan status v2 `1` Aktif, `2` Tidak Aktif, `10` Dihapus. Kode API menggunakan soft delete.

`user_lokasi_presensi` tidak dibuat pada fase ini karena FK `pegawai(nip)` baru tersedia di Fase 3 (B-01). `target_peg` dan `excl_peg` dipertahankan pada skema dan tidak ditampilkan/diubah form Fase 2.

## Keputusan implementasi

- K-1: status aturan ditambah [V2] agar soft delete konsisten; default 1.
- K-2: latitude/longitude tetap `VARCHAR(50)` [K], validasi aplikasi −90..90 dan −180..180.
- K-3: radius divalidasi aplikasi minimal 10 meter; tidak ada CHECK agar impor legacy bermasalah dapat dilaporkan.
- K-4: koordinat unik secara aplikasi untuk baris status bukan 10; baris terhapus boleh memiliki koordinat yang sama.
- K-5: pemeriksaan referensi aturan aktif disiapkan untuk fase berikutnya saat relasi normalisasi tersedia.
- K-6: tidak ada kolom `order`.
- K-7: target disimpan sebagai JSON di MEDIUMTEXT demi kompatibilitas impor.
- K-8: jenis pegawai id 7 tetap mengikuti legacy; validasi daftar pegawai lengkap menunggu Fase 3.

## Aturan impor

Impor mempertahankan ID eksplisit dan menaikkan AUTO_INCREMENT. Terapkan `stripslashes` pada JSON legacy, pertahankan status lokasi 1/2, beri status aturan 1 bila kolom status legacy tidak ada, dan laporkan radius <10, koordinat tidak valid, serta JSON rusak tanpa menolak batch secara diam-diam. `user_lokasi_presensi` diimpor bersama `pegawai` pada B-01.

## Checklist DB Validator

- [ ] DDL dan collation sesuai [K]/[V2].
- [ ] `migrate → rollback → migrate` berhasil pada DB scratch eksplisit.
- [ ] Rollback hanya membatalkan batch DBV-007.
- [ ] Keputusan K-1 sampai K-8 disetujui.
- [ ] Runbook impor dan penundaan X-1/X-2 dicatat.
