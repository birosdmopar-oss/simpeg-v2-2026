// Tab riwayat `kp` — tabel `riwayat_kp`, B-08 (pemilik WS-1). Isian dan lampiran disalin dari Definisi backend
// `Riwayat/Definisi/Kp.php` (MAKE-005; legacy `L_kp.php` validate_param()).
import { Role } from '@/features/auth/types'

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
    R('id_pangkat', 'Golongan', 'pangkat', { required: true }),
    D('tmtsk', 'TMT SK', { required: true, hint: 'KP selain pengangkatan CPNS/PNS ber-TMT 1 April atau 1 Oktober.' }),
    T('no_sk', 'Nomor SK', { required: true, max: 100 }),
    D('tgl_sk', 'Tanggal SK', { required: true }),
    N('gaji_pokok', 'Gaji Pokok', { required: true }),
    N('mker_th', 'Masa Kerja (Tahun)', { required: true }),
    N('mker_bl', 'Masa Kerja (Bulan)', { required: true }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Kp::lampiran(): SK wajib (saat tambah, dan saat ubah bila belum ada); 5 MB.
  lampiran: [{ id_riwayat: 11, label: 'SK Kenaikan Pangkat', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  // Kp::rolePilihStatusSaatUbah() — legacy kp/form.php:59-74 (keputusan user 09-10-2026).
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
})
