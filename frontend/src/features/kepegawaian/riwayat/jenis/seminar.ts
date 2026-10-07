// Tab riwayat `seminar` — tabel `riwayat_seminar`, B-11 (pemilik WS-1).
import { A, D, N, S, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'seminar',
  engine: true,
  title: 'Kursus/Seminar',
  subtitle: 'Kursus, seminar, dan lokakarya.',
  task: 'B-11',
  singular: 'kursus/seminar',
  primaryKey: 'id_riwayat_seminar',
  columns: [
    { key: 'nama_seminar', label: 'Nama Kegiatan' },
    { key: 'instansi_penyelenggara', label: 'Penyelenggara' },
    { key: 'jumlah_jp', label: 'JP' },
    { key: 'tgl_sertifikat', label: 'Tanggal Sertifikat', format: 'date' },
  ],
  fields: [
    // jenis_seminar ikut COMMENT DDL: 1 Seminar, 2 Kursus.
    S('jenis_seminar', 'Jenis', [{ value: '1', label: 'Seminar' }, { value: '2', label: 'Kursus' }], { required: true }),
    T('nama_seminar', 'Nama Kegiatan', { required: true, wide: true }),
    T('bidang_seminar', 'Bidang', { max: 100 }),
    T('instansi_penyelenggara', 'Instansi Penyelenggara'),
    T('no_sertifikat', 'No. Sertifikat', { max: 100 }),
    D('tgl_sertifikat', 'Tanggal Sertifikat'),
    N('jumlah_jp', 'Jumlah JP'),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi seminar): aturan lampiran (kode jenis_rwy 22, wajib, batas MB, ekstensi) ditetapkan bersama Definisi backend.
  lampiran: [],
})
