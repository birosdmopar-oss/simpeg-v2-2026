// Tab riwayat `jabatan` — tabel `riwayat_mutasi_jabatan`, B-07 (pemilik WS-2).
import { A, D, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'jabatan',
  engine: true,
  title: 'Riwayat Jabatan',
  subtitle: 'Riwayat mutasi jabatan struktural, fungsional, dan pelaksana.',
  task: 'B-07',
  singular: 'jabatan',
  primaryKey: 'id_riwayat_mutasi_jabatan',
  columns: [
    { key: 'jabatan', label: 'Jabatan' },
    { key: 'unit', label: 'Unit' },
    { key: 'satker', label: 'Satker' },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
    { key: 'no_sk', label: 'No. SK' },
  ],
  fields: [
    R('id_unit', 'Unit', 'unit', { required: true }),
    R('id_satker', 'Satker', 'satker', { required: true }),
    R('id_jabatan', 'Jabatan', 'jabatan', { required: true, wide: true }),
    D('tmtsk', 'TMT SK', { required: true }),
    T('no_sk', 'No. SK', { max: 100 }),
    D('tgl_sk', 'Tanggal SK'),
    A('keterangan', 'Keterangan'),
  ],
  // Kode lampiran jabatan: 9 jabatan, 36 jabatan_pjft, 41 perjanjian_kerja.
  // TODO(Definisi jabatan): wajib/batas MB/ekstensi per kode ditetapkan bersama Definisi backend.
  lampiran: [],
})
