// Tab riwayat `ak` — tabel `riwayat_ak`, B-15 (pemilik WS-1). Isian & berkas disalin dari Definisi backend
// `Riwayat/Definisi/Ak.php` (MAKE-008; legacy `L_ak.php`). Nama, jabatan, pangkat, TMT, masa kerja, dan AK kumulatif
// diisi SERVER dari data pegawai saat ini ([V2]); jumlah AK = AK lama + AK baru (isian hitung, dihitung ulang server),
// kecuali baris Current AK legacy (`jenis_ak` 1) yang tidak pernah dihitung ulang — form menampilkan nilai tersimpannya.
import { A, D, N, S, T, defineRiwayatJenis } from '../riwayat.config'

const angka = (v: string | undefined): number => {
  const n = Number(String(v ?? '').replace(',', '.'))
  return Number.isFinite(n) ? n : 0
}

export default defineRiwayatJenis({
  jenis: 'ak',
  engine: true,
  title: 'Angka Kredit',
  subtitle: 'Penetapan angka kredit (PAK) jabatan fungsional. Jabatan, pangkat, dan masa kerja diisi otomatis dari data pegawai.',
  task: 'B-15',
  singular: 'angka kredit',
  primaryKey: 'id_riwayat_ak',
  columns: [
    { key: 'no_hpak', label: 'No. PAK' },
    { key: 'tgl_pak', label: 'Tanggal PAK', format: 'date' },
    { key: 'periode_awal', label: 'Periode Awal', format: 'date' },
    { key: 'periode_akhir', label: 'Periode Akhir', format: 'date' },
    { key: 'jabatan', label: 'Jabatan' },
    { key: 'gol_ruang', label: 'Gol. Ruang' },
    { key: 'nilai_ak', label: 'Jumlah AK' },
  ],
  fields: [
    T('no_hpak', 'No. PAK', { required: true, max: 50 }),
    D('tgl_pak', 'Tanggal PAK', { required: true }),
    D('periode_awal', 'Periode Awal Penilaian', { required: true }),
    D('periode_akhir', 'Periode Akhir Penilaian', { required: true }),
    S('flag_instansi_ym', 'Instansi yang Menetapkan', [
      { value: '0', label: 'Kementerian' },
      { value: '1', label: 'Instansi Lain' },
    ], { required: true }),
    T('instansi_ym', 'Nama Instansi', { required: true, max: 256 }),
    T('nama_pym', 'Nama Pejabat yang Menetapkan', { required: true, max: 256 }),
    T('jab_pym', 'Jabatan yang Menetapkan', { required: true, max: 256 }),
    N('ak_terakhir', 'AK Lama', { required: true }),
    N('pengajuan_ak', 'AK Baru', { required: true }),
    N('nilai_ak', 'Jumlah AK', {
      compute: (v, baris) => {
        if (String(baris?.jenis_ak ?? '') === '1') return String(baris?.nilai_ak ?? '')
        if (String(v.ak_terakhir ?? '').trim() === '' && String(v.pengajuan_ak ?? '').trim() === '') return ''
        return String(Math.round((angka(v.ak_terakhir) + angka(v.pengajuan_ak)) * 1000) / 1000)
      },
    }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  lampiran: [],
  // Ak::kolomBerkas(): file PAK wajib, 1 MB.
  berkasKolom: [{ kolom: 'file_pak', label: 'File PAK', wajib: true, batas_mb: 1, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'] }],
})
