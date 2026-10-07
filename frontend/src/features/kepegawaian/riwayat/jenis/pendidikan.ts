// Tab riwayat `pendidikan` — tabel `riwayat_pendidikan`, B-10 (pemilik WS-1).
import { A, D, N, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'pendidikan',
  engine: true,
  title: 'Riwayat Pendidikan',
  subtitle: 'Pendidikan formal beserta ijazah.',
  task: 'B-10',
  singular: 'pendidikan',
  primaryKey: 'id_riwayat_pendidikan',
  columns: [
    { key: 'jenjang_pendidikan_singkat', label: 'Jenjang' },
    { key: 'jurusan_pendidikan', label: 'Jurusan' },
    { key: 'institusi_pendidikan', label: 'Institusi' },
    { key: 'tgl_lulus', label: 'Tanggal Lulus', format: 'date' },
  ],
  fields: [
    R('id_jenjang_pendidikan', 'Jenjang Pendidikan', 'jenjang-pendidikan', { required: true }),
    R('id_jurusan_pendidikan', 'Jurusan', 'jurusan-pendidikan'),
    T('institusi_pendidikan', 'Institusi Pendidikan', { wide: true }),
    D('tgl_lulus', 'Tanggal Lulus', { required: true }),
    T('no_ijazah', 'No. Ijazah', { max: 100 }),
    T('glr_awal', 'Gelar Depan', { max: 50 }),
    T('glr_akhir', 'Gelar Belakang', { max: 50 }),
    T('no_sk_penc_glr', 'No. SK Pencantuman Gelar', { max: 100 }),
    N('ipk', 'IPK'),
    N('nem', 'NEM'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(WS-1 Definisi pendidikan): aturan lampiran disalin dari Definisi backend; legacy: 14/39/40 opsional, jpg|jpeg|png|gif|pdf, 5 MB
  lampiran: [],
})
