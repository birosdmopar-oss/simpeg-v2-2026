// Tab riwayat `kgb` — tabel `riwayat_kgb`, B-09 (pemilik WS-1). Isian dan lampiran disalin dari Definisi backend
// `Riwayat/Definisi/Kgb.php` (MAKE-006; legacy `L_kgb.php` vp()/sp()).
import { Role } from '@/features/auth/types'

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
    { key: 'tmtsk', label: 'TMT', format: 'date' },
    { key: 'gaji_pokok', label: 'Gaji Pokok' },
    { key: 'mker_gol_th', label: 'Masa Kerja Gol. (thn)' },
    { key: 'no_sk', label: 'No. SK' },
    { key: 'jym', label: 'Yang Menandatangani' },
  ],
  fields: [
    D('tmtsk', 'TMT SK', { required: true, hint: 'Minimal 2 tahun dari TMT KGB/KP terakhir.' }),
    T('no_sk', 'Nomor SK', { required: true, max: 100 }),
    D('tgl_sk', 'Tanggal SK', { required: true }),
    N('mker_gol_th', 'Masa Kerja Golongan (Tahun)'),
    N('gaji_pokok', 'Gaji Pokok', { required: true }),
    T('jym', 'Jabatan yang Menandatangani', { required: true }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Kgb::lampiran(): SK wajib (saat tambah, dan saat ubah bila belum ada); 5 MB.
  lampiran: [{ id_riwayat: 10, label: 'SK KGB', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  // Kgb::rolePilihStatusSaatUbah() — legacy kgb/form.php:59-74.
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
})
