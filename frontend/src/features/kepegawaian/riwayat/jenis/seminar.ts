// Tab riwayat `seminar` — tabel `riwayat_seminar`, B-11 (pemilik WS-1). Isian & lampiran disalin dari Definisi backend
// `Riwayat/Definisi/Seminar.php` (MAKE-007; legacy `L_seminar.php`). Bidang & instansi = teks dengan saran nama master
// (legacy: pilih nama master atau "Lain-lain" teks bebas).
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, N, S, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'seminar',
  engine: true,
  title: 'Riwayat Kursus/Seminar',
  subtitle: 'Kursus, seminar, dan kegiatan sejenis.',
  task: 'B-11',
  singular: 'kursus/seminar',
  primaryKey: 'id_riwayat_seminar',
  columns: [
    { key: 'jenis_seminar', label: 'Jenis', options: [{ value: '1', label: 'Seminar' }, { value: '2', label: 'Kursus' }] },
    { key: 'nama_seminar', label: 'Nama Kegiatan' },
    { key: 'bidang_seminar', label: 'Bidang' },
    { key: 'instansi_penyelenggara', label: 'Penyelenggara' },
    { key: 'tgl_sertifikat', label: 'Tanggal Sertifikat', format: 'date' },
  ],
  fields: [
    S('jenis_seminar', 'Jenis', [{ value: '1', label: 'Seminar' }, { value: '2', label: 'Kursus' }], { required: true }),
    T('bidang_seminar', 'Bidang', { required: true, max: 100, suggestEntity: 'bidang-kursem' }),
    T('nama_seminar', 'Nama Kursus/Seminar', { required: true, wide: true }),
    T('no_sertifikat', 'Nomor Sertifikat', { required: true, max: 100 }),
    D('tgl_sertifikat', 'Tanggal Sertifikat', { required: true }),
    N('jumlah_jp', 'Jumlah JP'),
    T('instansi_penyelenggara', 'Instansi Penyelenggara', { required: true, wide: true, suggestEntity: 'instansi-kursem' }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Seminar::lampiran(): sertifikat kode 22 wajib, 1 MB (legacy).
  lampiran: [{ id_riwayat: 22, label: 'Sertifikat', wajib: true, batas_mb: 1, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
