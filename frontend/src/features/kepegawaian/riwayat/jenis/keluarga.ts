// Tab riwayat `keluarga` — tabel `riwayat_keluarga`, B-16 (pemilik WS-1).
import { A, D, N, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'keluarga',
  engine: true,
  title: 'Data Keluarga',
  subtitle: 'Riwayat perkawinan dan jumlah anak.',
  task: 'B-16',
  singular: 'data keluarga',
  primaryKey: 'id_riwayat_keluarga',
  columns: [
    { key: 'urutan_perkawinan', label: 'Perkawinan ke-' },
    { key: 'nama_pasangan', label: 'Nama Pasangan' },
    { key: 'tgl_perkawinan', label: 'Tanggal Perkawinan', format: 'date' },
    { key: 'jumlah_anak', label: 'Jumlah Anak' },
  ],
  fields: [
    T('nama_pasangan', 'Nama Pasangan', { required: true }),
    N('urutan_perkawinan', 'Perkawinan ke-', { required: true }),
    D('tgl_perkawinan', 'Tanggal Perkawinan', { required: true }),
    T('kota_perkawinan', 'Kota Perkawinan'),
    N('jumlah_anak', 'Jumlah Anak'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi keluarga): aturan lampiran (kode jenis_rwy 20, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
