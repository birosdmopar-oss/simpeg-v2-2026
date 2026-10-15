// Tab riwayat `ak-siasn` — tabel `riwayat_ak_siasn`, B-15 (pemilik WS-1). Data hasil tarik SIASN; satu-satunya ubah =
// pasangkan jabatan SIMPEG (jabatan fungsional tertentu, group 2) — Definisi backend `Riwayat/Definisi/AkSiasn.php`
// (MAKE-008; legacy `L_ak.php` form_siasn_ad()).
import { R, defineRiwayatJenis } from '../riwayat.config'

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
    { key: 'nama_jabatan', label: 'Jabatan SIASN' },
    { key: 'tahun_mulai_penilaian', label: 'Tahun Mulai' },
    { key: 'tahun_selesai_penilaian', label: 'Tahun Selesai' },
    { key: 'kredit_baru_total', label: 'Total AK' },
  ],
  fields: [R('id_jabatan', 'Jabatan SIMPEG', 'jabatan', { required: true, wide: true, optionsQuery: { id_group_jabatan: '2' } })],
  lampiran: [],
})
