// Tab riwayat `organisasi` — tabel `riwayat_organisasi`, B-17 (pemilik WS-1). Isian & lampiran disalin dari Definisi
// backend `Riwayat/Definisi/Organisasi.php` (MAKE-007; legacy `L_organisasi.php`).
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'organisasi',
  engine: true,
  title: 'Riwayat Organisasi',
  subtitle: 'Keanggotaan organisasi.',
  task: 'B-17',
  singular: 'organisasi',
  primaryKey: 'id_riwayat_organisasi',
  columns: [
    { key: 'nama_organisasi', label: 'Organisasi' },
    { key: 'kedudukan', label: 'Jabatan' },
    { key: 'tgl_mulai', label: 'Mulai', format: 'date' },
    { key: 'tgl_akhir', label: 'Selesai', format: 'date' },
  ],
  fields: [
    T('nama_organisasi', 'Nama Organisasi', { required: true, wide: true }),
    T('kedudukan', 'Jabatan dalam Organisasi', { required: true }),
    D('tgl_mulai', 'Tanggal Mulai', { required: true }),
    D('tgl_akhir', 'Tanggal Akhir', { hint: 'Kosongkan bila masih aktif.' }),
    A('keterangan', 'Keterangan'),
  ],
  // Organisasi::lampiran(): sertifikat kode 13 wajib, 5 MB.
  lampiran: [{ id_riwayat: 13, label: 'Sertifikat', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
