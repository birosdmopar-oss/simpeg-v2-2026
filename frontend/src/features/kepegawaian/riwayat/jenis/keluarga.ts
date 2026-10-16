// Tab riwayat `keluarga` — tabel `riwayat_keluarga`, B-16 (pemilik WS-1). Isian & lampiran disalin dari Definisi
// backend `Riwayat/Definisi/Keluarga.php` (MAKE-007; legacy `L_keluarga.php`). Anak (`detail_anak`) dikelola lewat item ⋮
// "Anak" (sub-resource `…/keluarga/{id}/anak`, akte lahir kode 3 per anak); `jumlah_anak` dihitung server.
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, N, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'keluarga',
  engine: true,
  title: 'Riwayat Keluarga',
  subtitle: 'Data perkawinan dan anak.',
  task: 'B-16',
  singular: 'keluarga',
  primaryKey: 'id_riwayat_keluarga',
  // CR-049 D-F12: label baris ⋮ / judul dialog.
  labelKolom: ['nama_pasangan'],
  columns: [
    { key: 'urutan_perkawinan', label: 'Perkawinan ke-' },
    { key: 'nama_pasangan', label: 'Nama Pasangan' },
    { key: 'tgl_perkawinan', label: 'Tanggal Perkawinan', format: 'date' },
    { key: 'kota_perkawinan', label: 'Kota' },
    { key: 'jumlah_anak', label: 'Jumlah Anak' },
  ],
  fields: [
    T('nama_pasangan', 'Nama Pasangan', { required: true }),
    N('urutan_perkawinan', 'Perkawinan Ke-', { required: true }),
    D('tgl_perkawinan', 'Tanggal Perkawinan', { required: true }),
    T('kota_perkawinan', 'Kota Perkawinan', { required: true }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Keluarga::lampiran(): buku nikah kode 20 wajib, 5 MB (akte anak kode 3 per baris anak, bukan di sini).
  lampiran: [{ id_riwayat: 20, label: 'Buku Nikah', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
  kelolaAnak: true,
})
