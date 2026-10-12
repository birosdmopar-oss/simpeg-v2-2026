// Tab riwayat `skp` — tabel `riwayat_skp`, B-12a (pemilik WS-1). Isian & lampiran disalin dari Definisi backend
// `Riwayat/Definisi/Skp.php` (MAKE-008; legacy `L_skp.php`). Skema menurut tahun: ≤2021 nilai angka (SKP 60% + perilaku
// 40%), ≥2022 rating. Persentase, nilai prestasi, dan kategori/predikat = isian hitung (rumus form legacy
// `views/hr/employee/rwy/skp/form.php:722-843`); server menerima nilainya seperti legacy.
import { Role, UL_PEGAWAI } from '@/features/auth/types'

import { A, D, N, S, T, defineRiwayatJenis, type FieldCondition } from '../riwayat.config'

const NILAI: FieldCondition = { field: 'tahun', max: 2021 }
const RATING: FieldCondition = { field: 'tahun', min: 2022 }
const RATING_OPSI = [
  { value: '3', label: 'Di Atas Ekspektasi' },
  { value: '2', label: 'Sesuai Ekspektasi' },
  { value: '1', label: 'Di Bawah Ekspektasi' },
]

const angka = (v: string | undefined): number | null => {
  const n = Number(String(v ?? '').replace(',', '.'))
  return String(v ?? '').trim() === '' || !Number.isFinite(n) ? null : n
}
const dua = (n: number): string => String(Math.round(n * 100) / 100)

/** Kategori nilai prestasi ≤2021 (legacy: skala 120 mulai 2021, 100 sebelumnya; nilai dibulatkan ke bawah). */
function kategoriNilai(tahun: number, total: number): string {
  const t = Math.trunc(total)
  const batas = tahun >= 2021 ? [110, 90, 70, 50] : [91, 76, 61, 51]
  if (t >= batas[0]!) return 'Sangat Baik'
  if (t >= batas[1]!) return 'Baik'
  if (t >= batas[2]!) return 'Cukup'
  if (t >= batas[3]!) return 'Kurang'
  return 'Sangat Kurang'
}

/** Predikat ≥2022 dari matriks rating hasil kerja × perilaku (legacy `updatePredikat`). */
const PREDIKAT: Record<string, string> = {
  '1/1': 'Sangat Kurang',
  '2/1': 'Kurang / Miss Conduct',
  '3/1': 'Kurang / Miss Conduct',
  '1/2': 'Butuh Perbaikan',
  '2/2': 'Baik',
  '3/2': 'Baik',
  '1/3': 'Butuh Perbaikan',
  '2/3': 'Baik',
  '3/3': 'Sangat Baik',
}

export default defineRiwayatJenis({
  jenis: 'skp',
  engine: true,
  title: 'Riwayat SKP',
  subtitle: 'Sasaran Kinerja Pegawai tahunan (s.d. 2022).',
  task: 'B-12a',
  singular: 'SKP',
  primaryKey: 'id_riwayat_skp',
  columns: [
    { key: 'tahun', label: 'Tahun' },
    { key: 'tgl_mulai', label: 'Mulai', format: 'date' },
    { key: 'tgl_akhir', label: 'Akhir', format: 'date' },
    { key: 'nama_penilai', label: 'Pejabat Penilai' },
    { key: 'nilai_prestasi_kerja', label: 'Nilai' },
    { key: 'kategori_nilai_prestasi', label: 'Kategori/Predikat' },
  ],
  fields: [
    N('tahun', 'Tahun', { required: true, hint: 'SKP 2023 dan seterusnya berasal dari e-Kinerja (tab SKP Periodik).' }),
    D('tgl_mulai', 'Tanggal Mulai', { required: true }),
    D('tgl_akhir', 'Tanggal Akhir', { required: true }),
    T('nip_penilai', 'NIP Pejabat Penilai', { max: 18 }),
    T('nama_penilai', 'Nama Pejabat Penilai', { max: 150 }),
    T('jabatan_penilai', 'Jabatan Pejabat Penilai', { max: 150, wide: true }),
    T('nip_atasan_penilai', 'NIP Atasan Pejabat Penilai', { max: 18 }),
    T('nama_atasan_penilai', 'Nama Atasan Pejabat Penilai', { max: 150 }),
    T('jabatan_atasan_penilai', 'Jabatan Atasan Pejabat Penilai', { max: 150, wide: true }),
    N('nilai_skp', 'Nilai SKP', { required: true, showWhen: NILAI }),
    N('nilai_skp_60_persen', 'Nilai SKP (60%)', {
      showWhen: NILAI,
      compute: (v) => (angka(v.nilai_skp) === null ? '' : dua(angka(v.nilai_skp)! * 0.6)),
    }),
    N('nilai_perilaku', 'Nilai Perilaku', { required: true, showWhen: NILAI }),
    N('nilai_perilaku_40_persen', 'Nilai Perilaku (40%)', {
      showWhen: NILAI,
      compute: (v) => (angka(v.nilai_perilaku) === null ? '' : dua(angka(v.nilai_perilaku)! * 0.4)),
    }),
    N('nilai_prestasi_kerja', 'Nilai Prestasi Kerja', {
      showWhen: NILAI,
      compute: (v) => {
        const skp = angka(v.nilai_skp)
        const perilaku = angka(v.nilai_perilaku)
        return skp === null || perilaku === null ? '' : dua(skp * 0.6 + perilaku * 0.4)
      },
    }),
    S('rating_skp', 'Rating Hasil Kerja', RATING_OPSI, { required: true, showWhen: RATING }),
    S('rating_perilaku', 'Rating Perilaku Kerja', RATING_OPSI, { required: true, showWhen: RATING }),
    T('kategori_nilai_prestasi', 'Kategori / Predikat', {
      required: true,
      max: 50,
      compute: (v) => {
        const tahun = angka(v.tahun)
        if (tahun === null) return ''
        if (tahun >= 2022) return PREDIKAT[`${v.rating_skp ?? ''}/${v.rating_perilaku ?? ''}`] ?? ''
        const skp = angka(v.nilai_skp)
        const perilaku = angka(v.nilai_perilaku)
        return skp === null || perilaku === null ? '' : kategoriNilai(tahun, skp * 0.6 + perilaku * 0.4)
      },
    }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ],
  // Skp::lampiran(): kode 32 wajib, 5 MB.
  lampiran: [{ id_riwayat: 32, label: 'Dokumen SKP', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }],
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER, ...UL_PEGAWAI],
  kunciUbahDisetujuiRole: UL_PEGAWAI,
})
