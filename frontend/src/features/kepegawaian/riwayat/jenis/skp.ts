// Tab riwayat `skp` — tabel `riwayat_skp`, B-12a (pemilik WS-1).
import { A, D, N, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'skp',
  engine: true,
  title: 'SKP Tahunan',
  subtitle: 'Sasaran kinerja pegawai tahunan.',
  task: 'B-12a',
  singular: 'SKP',
  primaryKey: 'id_riwayat_skp',
  columns: [
    { key: 'tahun', label: 'Tahun' },
    { key: 'nama_penilai', label: 'Penilai' },
    { key: 'nilai_prestasi_kerja', label: 'Nilai' },
    { key: 'kategori_nilai_prestasi', label: 'Kategori' },
  ],
  fields: [
    N('tahun', 'Tahun', { required: true }),
    D('tgl_mulai', 'Tanggal Mulai'),
    D('tgl_akhir', 'Tanggal Akhir'),
    T('nip_penilai', 'NIP Penilai', { max: 18 }),
    T('nama_penilai', 'Nama Penilai', { max: 150 }),
    T('jabatan_penilai', 'Jabatan Penilai', { max: 150 }),
    N('nilai_skp', 'Nilai SKP'),
    N('nilai_perilaku', 'Nilai Perilaku'),
    N('nilai_prestasi_kerja', 'Nilai Prestasi Kerja'),
    T('kategori_nilai_prestasi', 'Kategori', { max: 50 }),
    A('keterangan', 'Keterangan'),
  ],
  // TODO(Definisi skp): aturan lampiran (kode jenis_rwy 32, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
