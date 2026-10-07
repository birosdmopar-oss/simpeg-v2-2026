// Tab riwayat `diklat` — tabel `riwayat_diklat`, B-11 (pemilik WS-1).
import { A, D, N, R, S, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'diklat',
  engine: true,
  title: 'Riwayat Pelatihan',
  subtitle: 'Diklat struktural, teknis, dan fungsional.',
  task: 'B-11',
  singular: 'pelatihan',
  primaryKey: 'id_riwayat_diklat',
  columns: [
    { key: 'nama_diklat', label: 'Nama Diklat' },
    { key: 'instansi_penyelenggara', label: 'Penyelenggara' },
    { key: 'jumlah_jp', label: 'JP' },
    { key: 'tgl_sertifikat', label: 'Tanggal Sertifikat', format: 'date' },
  ],
  fields: [
    // jenis_diklat ikut COMMENT DDL: 1 Struktural, 2 Teknis, 3 Fungsional, 4 Prajabatan, 5 Sertifikasi.
    S('jenis_diklat', 'Jenis Diklat', [
      { value: '1', label: 'Struktural' },
      { value: '2', label: 'Teknis' },
      { value: '3', label: 'Fungsional' },
      { value: '4', label: 'Prajabatan' },
      { value: '5', label: 'Sertifikasi' },
    ], { required: true }),
    R('id_diklat', 'Diklat', 'diklat', { required: true, wide: true }),
    T('nama_diklat_lain', 'Nama Diklat Lain'),
    T('instansi_penyelenggara', 'Instansi Penyelenggara'),
    T('no_sertifikat', 'No. Sertifikat'),
    D('tgl_sertifikat', 'Tanggal Sertifikat'),
    N('jumlah_jp', 'Jumlah JP'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi diklat): aturan lampiran (kode jenis_rwy 5, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
