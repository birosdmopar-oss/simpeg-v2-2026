// Tab riwayat `kp` — tabel `riwayat_kp`, B-08 (pemilik WS-1).
import { A, D, N, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'kp',
  engine: true,
  title: 'Riwayat Pangkat',
  subtitle: 'Riwayat kenaikan pangkat/golongan.',
  task: 'B-08',
  singular: 'kenaikan pangkat',
  primaryKey: 'id_riwayat_kp',
  columns: [
    { key: 'gol_ruang', label: 'Gol/Ruang' },
    { key: 'pangkat', label: 'Pangkat' },
    { key: 'jenis_kp', label: 'Jenis KP' },
    { key: 'no_sk', label: 'No. SK' },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
  ],
  fields: [
    R('id_jenis_kp', 'Jenis Kenaikan Pangkat', 'jenis-kp', { required: true }),
    R('id_pangkat', 'Pangkat/Golongan', 'pangkat', { required: true }),
    T('no_sk', 'No. SK', { max: 100 }),
    D('tgl_sk', 'Tanggal SK'),
    D('tmtsk', 'TMT SK', { required: true }),
    T('no_pertek_bkn', 'No. Pertek BKN', { max: 100 }),
    D('tgl_pertek_bkn', 'Tanggal Pertek BKN'),
    N('mker_th', 'Masa Kerja (tahun)'),
    N('mker_bl', 'Masa Kerja (bulan)'),
    N('gaji_pokok', 'Gaji Pokok'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi kp): aturan lampiran (kode jenis_rwy 11, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
