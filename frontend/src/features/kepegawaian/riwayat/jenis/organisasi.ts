// Tab riwayat `organisasi` — tabel `riwayat_organisasi`, B-17 (pemilik WS-1).
import { A, D, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'organisasi',
  engine: true,
  title: 'Riwayat Organisasi',
  subtitle: 'Keanggotaan dan kedudukan dalam organisasi.',
  task: 'B-17',
  singular: 'organisasi',
  primaryKey: 'id_riwayat_organisasi',
  columns: [
    { key: 'nama_organisasi', label: 'Organisasi' },
    { key: 'kedudukan', label: 'Kedudukan' },
    { key: 'tgl_mulai', label: 'Mulai', format: 'date' },
    { key: 'tgl_akhir', label: 'Selesai', format: 'date' },
  ],
  fields: [
    T('nama_organisasi', 'Nama Organisasi', { required: true, wide: true }),
    T('kedudukan', 'Kedudukan'),
    D('tgl_mulai', 'Tanggal Mulai'),
    D('tgl_akhir', 'Tanggal Selesai'),
    A('keterangan', 'Keterangan'),
  ],
  // TODO(Definisi organisasi): aturan lampiran (kode jenis_rwy 13, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
