// Tab riwayat `alamat` — tabel `riwayat_alamat`, B-16 (pemilik WS-1).
import { A, R, S, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'alamat',
  engine: true,
  title: 'Data Alamat',
  subtitle: 'Alamat rumah dan kantor.',
  task: 'B-16',
  singular: 'alamat',
  primaryKey: 'id_riwayat_alamat',
  columns: [
    { key: 'jenis_alamat', label: 'Jenis', options: [{ value: '1', label: 'KTP' }, { value: '2', label: 'Domisili' }, { value: '3', label: 'Kantor' }] },
    { key: 'alamat', label: 'Alamat' },
    { key: 'kabupaten_kota', label: 'Kabupaten/Kota' },
    { key: 'provinsi', label: 'Provinsi' },
    { key: 'kd_pos', label: 'Kode Pos' },
  ],
  // Kode ikut COMMENT DDL: jenis_alamat 1 KTP / 2 Domisili / 3 Kantor; alamat_utama 1 Ya / 2 Tidak.
  // TODO(Definisi alamat): isian khusus alamat kantor (nama_kantor, lantai, telp, faks) ditetapkan bersama Definisi.
  fields: [
    S('jenis_alamat', 'Jenis Alamat', [
      { value: '1', label: 'Alamat KTP' },
      { value: '2', label: 'Alamat Domisili' },
      { value: '3', label: 'Kantor' },
    ], { required: true }),
    S('alamat_utama', 'Alamat Utama', [
      { value: '1', label: 'Ya' },
      { value: '2', label: 'Tidak' },
    ], { required: true }),
    A('alamat', 'Alamat', { required: true, max: 255 }),
    R('id_provinsi', 'Provinsi', 'provinsi'),
    R('id_kabupaten_kota', 'Kabupaten/Kota', 'kabupaten-kota'),
    R('id_kecamatan', 'Kecamatan', 'kecamatan'),
    R('id_kelurahan', 'Kelurahan', 'kelurahan'),
    T('kd_pos', 'Kode Pos', { max: 50 }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi alamat): aturan lampiran (kode jenis_rwy 1, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
