// Tab NON-ENGINE `lkh` — tabel `riwayat_lckh`, B-12b (pemilik WS-2). Datanya lewat endpoint LKH WS-2 (M5), bukan
// `riwayat/{jenis}`; sampai endpoint itu ada RiwayatTabHost menampilkan keterangan "belum tersedia".
import { defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'lkh',
  engine: false,
  title: 'Laporan Kerja Harian',
  subtitle: 'Aktivitas harian yang diverifikasi atasan langsung.',
  task: 'B-12b',
  singular: 'laporan kerja harian',
  primaryKey: 'id_riwayat_lckh',
  columns: [
    { key: 'tgl_laporan', label: 'Tanggal', format: 'date' },
    { key: 'kegiatan', label: 'Kegiatan' },
    { key: 'output', label: 'Output' },
    { key: 'nama_atasan', label: 'Atasan' },
  ],
  fields: [],
  lampiran: [],
})
