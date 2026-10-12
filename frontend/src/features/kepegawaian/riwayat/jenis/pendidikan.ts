// Tab riwayat `pendidikan` — tabel `riwayat_pendidikan`, B-10 (pemilik WS-1). Isian, syarat, dan lampiran disalin dari
// Definisi backend `Riwayat/Definisi/Pendidikan.php` (MAKE-005; legacy `L_pendidikan.php` vp()/sp()).
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, N, R, T, defineRiwayatJenis, type FieldCondition } from '../riwayat.config'

/** Jenjang tanpa bidang/jurusan (SD, SMP, SMA sederajat) = `Pendidikan::JENJANG_TANPA_JURUSAN`. */
const TANPA_JURUSAN = ['1', '2', '3']
const BERJURUSAN: FieldCondition = { field: 'id_jenjang_pendidikan', notIn: TANPA_JURUSAN }
const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf']

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
    R('id_bidang_pendidikan', 'Bidang Pendidikan', 'bidang-pendidikan', { required: true, showWhen: BERJURUSAN }),
    // Kode "lainnya" legacy: bidang 98, jurusan 1185 (Pendidikan::BIDANG_LAIN / JURUSAN_LAIN).
    T('bidang_pendidikan_lain', 'Bidang Pendidikan Lain', { required: true, max: 100, showWhen: { field: 'id_bidang_pendidikan', in: ['98'] } }),
    R('id_jurusan_pendidikan', 'Jurusan Pendidikan', 'jurusan-pendidikan', { required: true, dependsOn: 'id_bidang_pendidikan', showWhen: BERJURUSAN }),
    T('jurusan_pendidikan_lain', 'Jurusan Pendidikan Lain', { required: true, showWhen: { field: 'id_jurusan_pendidikan', in: ['1185'] } }),
    T('institusi_pendidikan', 'Nama Institusi', { required: true, wide: true }),
    T('no_ijazah', 'Nomor Ijazah', { required: true, max: 100 }),
    D('tgl_lulus', 'Tanggal Dikeluarkan Ijazah', { required: true }),
    T('no_sk_penc_glr', 'Nomor SK Pencantuman Gelar', { max: 100 }),
    N('nem', 'NEM', { showWhen: { field: 'id_jenjang_pendidikan', in: TANPA_JURUSAN } }),
    N('ipk', 'IPK', { showWhen: BERJURUSAN }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Pendidikan::lampiran(): Ijazah wajib (saat tambah, dan saat ubah bila belum ada), lainnya opsional; 5 MB.
  lampiran: [
    { id_riwayat: 14, label: 'Ijazah', wajib: true, batas_mb: 5, ekstensi: EKSTENSI },
    { id_riwayat: 39, label: 'SK Pencantuman Gelar', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
    { id_riwayat: 40, label: 'Transkrip Nilai', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
  ],
  // Pendidikan::rolePilihStatusSaatUbah() — legacy pendidikan/form.php:59-76 (keputusan user 12-10-2026).
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  // Baris Disetujui: roleKunciHapusDisetujui() bawaan [3] + kunciBarisDisetujui() untuk UL_PEGAWAI (ubah & hapus).
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
