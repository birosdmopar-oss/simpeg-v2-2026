// Tab riwayat `kgb` — tabel `riwayat_kgb`, B-09 (pemilik WS-1).
import { A, D, N, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'kgb',
  engine: true,
  title: 'Riwayat KGB',
  subtitle: 'Riwayat kenaikan gaji berkala.',
  task: 'B-09',
  singular: 'KGB',
  primaryKey: 'id_riwayat_kgb',
  columns: [
    { key: 'gaji_pokok', label: 'Gaji Pokok' },
    { key: 'mker_gol_th', label: 'Masa Kerja Gol. (thn)' },
    { key: 'no_sk', label: 'No. SK' },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
  ],
  fields: [
    T('no_sk', 'No. SK', { max: 100 }),
    D('tgl_sk', 'Tanggal SK', { required: true }),
    D('tmtsk', 'TMT SK', { required: true }),
    N('mker_gol_th', 'Masa Kerja Golongan (tahun)'),
    N('gaji_pokok', 'Gaji Pokok'),
    T('jym', 'Pejabat yang Menetapkan'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi kgb): aturan lampiran (kode jenis_rwy 10, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
