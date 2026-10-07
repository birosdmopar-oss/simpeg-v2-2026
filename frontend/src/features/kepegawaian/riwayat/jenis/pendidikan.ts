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
  // Ikut legacy L_pendidikan (README kontrak): 14 ijazah (wajib), 39 pencantuman gelar, 40 transkrip nilai — pdf, maks. 5 MB.
  lampiran: [
    { id_riwayat: 14, label: 'Ijazah', wajib: true, batas_mb: 5, ekstensi: ['pdf'] },
    { id_riwayat: 39, label: 'Pencantuman Gelar', wajib: false, batas_mb: 5, ekstensi: ['pdf'] },
    { id_riwayat: 40, label: 'Transkrip Nilai', wajib: false, batas_mb: 5, ekstensi: ['pdf'] },
  ],
})
