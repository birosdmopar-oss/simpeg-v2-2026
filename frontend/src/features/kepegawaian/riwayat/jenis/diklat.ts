// Tab riwayat `diklat` — tabel `riwayat_diklat`, B-11 (pemilik WS-1). Isian per jenis & lampiran disalin dari Definisi
// backend `Riwayat/Definisi/Diklat.php` (MAKE-007; legacy `L_diklat.php`). Jenis 5 Sertifikasi: rumpun & lembaga diisi
// teks (master rumpun/lembaga sertifikasi belum ada di v2).
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, N, R, S, T, defineRiwayatJenis, type FieldCondition } from '../riwayat.config'

const jenis = (...kode: string[]): FieldCondition => ({ field: 'jenis_diklat', in: kode })

export default defineRiwayatJenis({
  jenis: 'diklat',
  engine: true,
  title: 'Riwayat Diklat',
  subtitle: 'Pendidikan dan pelatihan.',
  task: 'B-11',
  singular: 'diklat',
  primaryKey: 'id_riwayat_diklat',
  // CR-049 D-F12: label baris ⋮ / judul dialog.
  labelKolom: ['jenis_diklat', 'nama_diklat'],
  columns: [
    { key: 'jenis_diklat', label: 'Jenis', options: [{ value: '1', label: 'Struktural' }, { value: '2', label: 'Teknis' }, { value: '3', label: 'Fungsional' }, { value: '4', label: 'Prajabatan' }, { value: '5', label: 'Sertifikasi' }] },
    { key: 'nama_diklat', label: 'Nama Diklat' },
    { key: 'sub_group_jabatan', label: 'Jabatan Fungsional' },
    { key: 'instansi_penyelenggara', label: 'Penyelenggara' },
    { key: 'tgl_sertifikat', label: 'Tanggal Sertifikat', format: 'date' },
  ],
  fields: [
    S('jenis_diklat', 'Jenis Diklat', [
      { value: '4', label: 'Prajabatan' },
      { value: '1', label: 'Struktural' },
      { value: '2', label: 'Teknis' },
      { value: '3', label: 'Fungsional' },
      { value: '5', label: 'Sertifikasi' },
    ], { required: true }),
    R('id_diklat', 'Nama Diklat', 'diklat', { required: true, wide: true, showWhen: jenis('1', '2', '4'), optionsFilter: { param: 'jenis_diklat', field: 'jenis_diklat' } }),
    R('id_sub_group_jabatan', 'Jabatan Fungsional', 'sub-group-jabatan', { required: true, wide: true, showWhen: jenis('3'), optionsParent: '2' }),
    T('nama_diklat', 'Nama Sertifikasi', { required: true, wide: true, showWhen: jenis('5') }),
    T('rumpun_sertifikasi', 'Rumpun Sertifikasi', { required: true, showWhen: jenis('5') }),
    A('deskripsi', 'Deskripsi', { max: 255, hint: 'Wajib untuk diklat Teknis dan Fungsional.' }),
    T('no_sertifikat', 'Nomor Sertifikat', { required: true }),
    D('tgl_sertifikat', 'Tanggal Sertifikat', { required: true }),
    D('mb_sertifikat_awal', 'Masa Berlaku Mulai', { required: true, showWhen: jenis('5') }),
    D('mb_sertifikat_akhir', 'Masa Berlaku Akhir', { required: true, showWhen: jenis('5') }),
    N('jumlah_jp', 'Jumlah JP', { required: true, showWhen: jenis('1', '2', '3', '4') }),
    T('instansi_penyelenggara', 'Instansi Penyelenggara / Lembaga Sertifikasi', { required: true, wide: true }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Diklat::lampiran(): sertifikat kode 5 wajib, 1 MB (legacy).
  lampiran: [{ id_riwayat: 5, label: 'Sertifikat', wajib: true, batas_mb: 1, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
