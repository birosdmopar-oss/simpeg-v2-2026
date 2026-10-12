// Tab riwayat `ak-siasn` — tabel `riwayat_ak_siasn`, B-15 (pemilik WS-1). Data hasil tarik SIASN; isian tambah/ubah
// (bila ada) dan lampiran ditetapkan bersama Definisi backend-nya di M5 (MAKE-008). Tanpa isian, Tambah/Edit tidak
// ditampilkan walaupun descriptor mengizinkan.
import { defineRiwayatJenis } from '../riwayat.config'

export default defineRiwayatJenis({
  jenis: 'ak-siasn',
  engine: true,
  title: 'Angka Kredit SIASN',
  subtitle: 'Penetapan angka kredit dari SIASN.',
  task: 'B-15',
  singular: 'angka kredit SIASN',
  primaryKey: 'id_riwayat_ak_siasn',
  columns: [
    { key: 'no_sk', label: 'No. SK' },
    { key: 'tgl_sk', label: 'Tanggal SK', format: 'date' },
    { key: 'nama_jabatan', label: 'Jabatan' },
    { key: 'tahun_mulai_penilaian', label: 'Tahun Mulai' },
    { key: 'tahun_selesai_penilaian', label: 'Tahun Selesai' },
    { key: 'kredit_baru_total', label: 'Total AK' },
  ],
  fields: [],
  lampiran: [],
})
