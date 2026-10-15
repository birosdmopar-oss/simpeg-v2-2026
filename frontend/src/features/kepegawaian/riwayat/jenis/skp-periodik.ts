// Tab riwayat `skp-periodik` — tabel `riwayat_skp_periodik`, B-12a (pemilik WS-1). Data hasil tarik e-Kinerja; tanpa
// isian — satu-satunya ubah = unggah arsip (Definisi backend `Riwayat/Definisi/SkpPeriodik.php`, MAKE-008; legacy
// `L_skp.php` periodic()). Daftar hanya baris disetujui.
import { defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'skp-periodik',
  engine: true,
  title: 'SKP Periodik',
  subtitle: 'Penilaian kinerja periodik dari e-Kinerja (2023 dan seterusnya).',
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
  fields: [],
  lampiran: [],
  // SkpPeriodik::kolomBerkas(): arsip PDF 1 MB.
  berkasKolom: [{ kolom: 'f_arsip_1', label: 'Arsip SKP', wajib: true, batas_mb: 1, ekstensi: ['pdf'] }],
})
