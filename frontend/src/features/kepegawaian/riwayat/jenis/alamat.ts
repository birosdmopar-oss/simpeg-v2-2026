// Tab riwayat `alamat` — tabel `riwayat_alamat`, B-16 (pemilik WS-1). Isian, syarat & lampiran disalin dari Definisi
// backend `Riwayat/Definisi/Alamat.php` (MAKE-007; legacy `L_alamat.php`). Wilayah berjenjang + LAIN-LAIN (kode sistem
// 99/9999/9999999/9999999999 tidak ikut `options`, ditambahkan lewat `extraOptions`).
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, R, S, T, defineRiwayatJenis, type FieldCondition } from '../riwayat.config'

const LAIN = (kode: string) => [{ value: kode, label: 'LAIN-LAIN' }]
const KANTOR: FieldCondition = { field: 'jenis_alamat', in: ['3'] }
const BUKAN_KANTOR: FieldCondition = { field: 'jenis_alamat', notIn: ['3'] }

export default defineRiwayatJenis({
  jenis: 'alamat',
  engine: true,
  title: 'Data Alamat',
  subtitle: 'Alamat KTP, domisili, dan kantor.',
  task: 'B-16',
  singular: 'alamat',
  primaryKey: 'id_riwayat_alamat',
  // CR-049 D-F12: label baris ⋮ / judul dialog.
  labelKolom: ['jenis_alamat', 'alamat'],
  columns: [
    { key: 'jenis_alamat', label: 'Jenis', options: [{ value: '1', label: 'KTP' }, { value: '2', label: 'Domisili' }, { value: '3', label: 'Kantor' }] },
    { key: 'alamat_utama', label: 'Utama', options: [{ value: '1', label: 'Ya' }, { value: '2', label: 'Tidak' }] },
    { key: 'alamat', label: 'Alamat' },
    { key: 'kabupaten_kota', label: 'Kabupaten/Kota' },
    { key: 'provinsi', label: 'Provinsi' },
  ],
  fields: [
    S('jenis_alamat', 'Jenis Alamat', [
      { value: '1', label: 'Alamat KTP' },
      { value: '2', label: 'Alamat Domisili' },
      { value: '3', label: 'Kantor' },
    ], { required: true }),
    S('alamat_utama', 'Alamat Utama', [
      { value: '1', label: 'Ya' },
      { value: '2', label: 'Tidak' },
    ], { required: true, showWhen: BUKAN_KANTOR }),
    T('nama_kantor', 'Nama Kantor', { showWhen: KANTOR, wide: true }),
    A('alamat', 'Alamat', { required: true, max: 255 }),
    R('id_provinsi', 'Provinsi', 'provinsi', { required: true, extraOptions: LAIN('99') }),
    T('provinsi_lain', 'Provinsi Lainnya', { required: true, showWhen: { field: 'id_provinsi', in: ['99'] } }),
    R('id_kabupaten_kota', 'Kabupaten/Kota', 'kabupaten-kota', { required: true, dependsOn: 'id_provinsi', extraOptions: LAIN('9999') }),
    T('kabupaten_kota_lain', 'Kabupaten/Kota Lainnya', { required: true, showWhen: { field: 'id_kabupaten_kota', in: ['9999'] } }),
    R('id_kecamatan', 'Kecamatan', 'kecamatan', { required: true, dependsOn: 'id_kabupaten_kota', extraOptions: LAIN('9999999') }),
    T('kecamatan_lain', 'Kecamatan Lainnya', { required: true, showWhen: { field: 'id_kecamatan', in: ['9999999'] } }),
    R('id_kelurahan', 'Kelurahan/Desa', 'kelurahan', { required: true, dependsOn: 'id_kecamatan', extraOptions: LAIN('9999999999') }),
    T('kelurahan_lain', 'Kelurahan/Desa Lainnya', { required: true, showWhen: { field: 'id_kelurahan', in: ['9999999999'] } }),
    T('kd_pos', 'Kode Pos', { max: 5, hint: '5 digit angka.' }),
    T('lantai', 'Lantai', { max: 50, showWhen: KANTOR }),
    T('telp', 'Telepon', { max: 50, showWhen: KANTOR }),
    T('faks', 'Faks', { max: 50, showWhen: KANTOR }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Alamat::lampiran() + lampiranWajib(): KTP kode 1 wajib kecuali alamat kantor; 5 MB.
  lampiran: [{ id_riwayat: 1, label: 'KTP', wajib: true, wajibBila: BUKAN_KANTOR, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
