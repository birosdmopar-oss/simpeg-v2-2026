// Tab riwayat `hukdis` — tabel `riwayat_hukdis`, B-14 (pemilik WS-1). Isian dan lampiran disalin dari Definisi backend
// `Riwayat/Definisi/Hukdis.php` (MAKE-006; legacy `L_hukdis.php` validate_param()/set_param()).
import { A, D, R, T, defineRiwayatJenis } from '../riwayat.config'

const HITUNG_ULANG = ['tmtsk', 'id_jenis_hukdis']

export default defineRiwayatJenis({
  jenis: 'hukdis',
  engine: true,
  title: 'Riwayat Hukdis',
  subtitle: 'Hukuman disiplin.',
  task: 'B-14',
  singular: 'hukuman disiplin',
  primaryKey: 'id_riwayat_hukdis',
  columns: [
    { key: 'jenis_hukdis', label: 'Jenis Hukuman' },
    { key: 'tingkat_hukdis', label: 'Tingkat' },
    { key: 'no_sk', label: 'No. SK' },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
    { key: 'akhir_hukdis', label: 'Berakhir', format: 'date' },
  ],
  fields: [
    R('id_tingkat_hukdis', 'Tingkat Hukuman Disiplin', 'tingkat-hukdis', { required: true }),
    R('id_jenis_hukdis', 'Jenis Hukuman Disiplin', 'jenis-hukdis', { required: true, dependsOn: 'id_tingkat_hukdis' }),
    R('id_pangkat', 'Golongan', 'pangkat', { required: true }),
    D('tmtsk', 'TMT SK', { required: true }),
    T('no_sk', 'Nomor SK', { required: true, max: 100 }),
    D('tgl_sk', 'Tanggal SK', { required: true }),
    // Isian hitung [V2] (SRS-FR-027): dikosongkan = diisi server dari masa sanksi jenis; jenis tanpa masa → wajib diisi.
    // Mengubah TMT atau jenis mengosongkan keduanya supaya dihitung ulang (kecuali diisi lagi).
    A('masa_hukuman', 'Masa Hukuman', { max: 255, hint: 'Kosongkan untuk diisi otomatis dari masa sanksi jenis hukuman.', resetOn: HITUNG_ULANG }),
    D('akhir_hukdis', 'Tanggal Berakhir', { hint: 'Kosongkan untuk dihitung otomatis: TMT + masa sanksi jenis hukuman.', resetOn: HITUNG_ULANG }),
    A('aturan_dilanggar', 'Aturan yang Dilanggar', { required: true, max: 255 }),
    A('alasan_hukuman', 'Alasan Hukuman', { required: true, max: 255 }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Hukdis::lampiran(): SK wajib (saat tambah, dan saat ubah bila belum ada); 5 MB.
  lampiran: [{ id_riwayat: 7, label: 'SK Hukuman Disiplin', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  // Hapus hanya role 1 (Hukdis::izin, ikut legacy) → can_delete role 3 false; tidak perlu kunci per baris.
})
