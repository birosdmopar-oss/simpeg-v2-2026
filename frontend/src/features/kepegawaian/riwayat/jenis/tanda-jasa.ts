// Tab riwayat `tanda-jasa` — tabel `riwayat_tanda_jasa`, B-17 (pemilik WS-1).
import { A, D, R, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'tanda-jasa',
  engine: true,
  title: 'Tanda Jasa',
  subtitle: 'Penghargaan satyalancana dan tanda jasa lainnya.',
  task: 'B-17',
  singular: 'tanda jasa',
  primaryKey: 'id_riwayat_tanda_jasa',
  columns: [
    { key: 'tanda_jasa', label: 'Tanda Jasa' },
    { key: 'no_sertifikat', label: 'No. Sertifikat' },
    { key: 'tgl_sertifikat', label: 'Tanggal', format: 'date' },
    { key: 'negara', label: 'Negara' },
  ],
  fields: [
    R('id_tanda_jasa', 'Tanda Jasa', 'tanda-jasa', { required: true, wide: true }),
    T('tanda_jasa_lain', 'Tanda Jasa Lain'),
    T('no_sertifikat', 'No. Sertifikat', { max: 100 }),
    D('tgl_sertifikat', 'Tanggal Sertifikat', { required: true }),
    T('negara', 'Negara'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi tanda-jasa): aturan lampiran (kode jenis_rwy 23, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
