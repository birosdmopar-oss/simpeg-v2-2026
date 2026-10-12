// Tab riwayat `tanda-jasa` — tabel `riwayat_tanda_jasa`, B-17 (pemilik WS-1). Isian & lampiran disalin dari Definisi
// backend `Riwayat/Definisi/TandaJasa.php` (MAKE-007; legacy `L_tj.php`). Kode 44 = LAIN-LAIN (nama diisi teks).
import { Role } from '@/features/auth/types'

import { A, D, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'tanda-jasa',
  engine: true,
  title: 'Riwayat Tanda Jasa',
  subtitle: 'Tanda jasa dan penghargaan.',
  task: 'B-17',
  singular: 'tanda jasa',
  primaryKey: 'id_riwayat_tanda_jasa',
  columns: [
    { key: 'tanda_jasa', label: 'Tanda Jasa' },
    { key: 'tanda_jasa_lain', label: 'Lainnya' },
    { key: 'no_sertifikat', label: 'No. Sertifikat' },
    { key: 'tgl_sertifikat', label: 'Tanggal', format: 'date' },
    { key: 'negara', label: 'Negara' },
  ],
  fields: [
    R('id_tanda_jasa', 'Tanda Jasa', 'tanda-jasa', { required: true, wide: true }),
    T('tanda_jasa_lain', 'Tanda Jasa Lainnya', { required: true, wide: true, showWhen: { field: 'id_tanda_jasa', in: ['44'] } }),
    T('no_sertifikat', 'Nomor Sertifikat', { required: true, max: 100 }),
    D('tgl_sertifikat', 'Tanggal Sertifikat', { required: true }),
    T('negara', 'Negara'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TandaJasa::lampiran(): sertifikat kode 23 wajib, 5 MB.
  lampiran: [{ id_riwayat: 23, label: 'Sertifikat', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER],
})
