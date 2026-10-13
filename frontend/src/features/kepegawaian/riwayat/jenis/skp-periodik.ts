// Tab riwayat `skp-periodik` — tabel `riwayat_skp_periodik`, B-12a (pemilik WS-1). Data hasil tarik SIASN.
import { defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'skp-periodik',
  engine: true,
  title: 'SKP Periodik',
  subtitle: 'Penilaian kinerja periodik (data SIASN).',
  task: 'B-12a',
  singular: 'SKP periodik',
  primaryKey: 'id_riwayat_skp_periodik',
  columns: [
    { key: 'tahun_skp', label: 'Tahun' },
    { key: 'periode_awal_skp', label: 'Periode Awal', format: 'date' },
    { key: 'periode_akhir_skp', label: 'Periode Akhir', format: 'date' },
    { key: 'hasil_kerja', label: 'Hasil Kerja' },
    { key: 'perilaku_kerja', label: 'Perilaku Kerja' },
    { key: 'hasil_akhir', label: 'Hasil Akhir' },
  ],
  // TODO(Definisi skp-periodik): isian tambah/ubah (bila ada) ditetapkan bersama Definisi backend; tanpa isian, tombol
  // Tambah/Edit tidak ditampilkan walaupun descriptor mengizinkan.
  fields: [],
  lampiran: [],
})
