// Tab riwayat `ak` — tabel `riwayat_ak`, B-15 (pemilik WS-1; WS-1 menambah `ak-siasn.ts` sendiri).
import { A, D, N, T, defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'ak',
  engine: true,
  title: 'Angka Kredit',
  subtitle: 'Penetapan angka kredit jabatan fungsional.',
  task: 'B-15',
  singular: 'angka kredit',
  primaryKey: 'id_riwayat_ak',
  columns: [
    { key: 'no_hpak', label: 'No. PAK' },
    { key: 'tgl_pak', label: 'Tanggal PAK', format: 'date' },
    { key: 'periode_awal', label: 'Periode Awal', format: 'date' },
    { key: 'periode_akhir', label: 'Periode Akhir', format: 'date' },
    { key: 'nilai_ak', label: 'Nilai AK' },
  ],
  fields: [
    T('no_hpak', 'No. PAK', { max: 50 }),
    D('tgl_pak', 'Tanggal PAK'),
    D('periode_awal', 'Periode Awal'),
    D('periode_akhir', 'Periode Akhir'),
    N('kredit_utama_baru', 'Kredit Utama Baru'),
    N('kredit_penunjang_baru', 'Kredit Penunjang Baru'),
    N('nilai_ak', 'Nilai AK', { required: true }),
    T('nama_pym', 'Pejabat yang Menetapkan', { max: 256 }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // TODO(Definisi ak): aturan lampiran ditetapkan bersama Definisi backend.
  lampiran: [],
})
