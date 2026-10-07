// Tab riwayat `hukdis` — tabel `riwayat_hukdis`, B-14 (pemilik WS-1).
import { A, D, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'hukdis',
  engine: true,
  title: 'Riwayat Hukdis',
  subtitle: 'Hukuman disiplin.',
  task: 'B-14',
  singular: 'hukuman disiplin',
  primaryKey: 'id_riwayat_hukdis',
  columns: [
    { key: 'tingkat_hukdis', label: 'Tingkat' },
    { key: 'jenis_hukdis', label: 'Jenis Hukuman' },
    { key: 'no_sk', label: 'No. SK' },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
    { key: 'akhir_hukdis', label: 'Berakhir', format: 'date' },
  ],
  fields: [
    R('id_tingkat_hukdis', 'Tingkat Hukuman', 'tingkat-hukdis', { required: true }),
    R('id_jenis_hukdis', 'Jenis Hukuman', 'jenis-hukdis', { required: true }),
    T('no_sk', 'No. SK', { max: 100 }),
    D('tgl_sk', 'Tanggal SK'),
    D('tmtsk', 'TMT SK', { required: true }),
    T('masa_hukuman', 'Masa Hukuman'),
    D('akhir_hukdis', 'Tanggal Berakhir'),
    A('aturan_dilanggar', 'Aturan yang Dilanggar', { max: 255 }),
    A('alasan_hukuman', 'Alasan Hukuman', { max: 255 }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi hukdis): aturan lampiran (kode jenis_rwy 7, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
