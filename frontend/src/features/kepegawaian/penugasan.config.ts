/**
 * Isian form penugasan Plt/Plh (B-07, MAKE-012) — salinan aturan backend `Struktur/PenugasanService.php` (legacy
 * validate_param_off_plt / vp_off_plh). Dipakai PenugasanFormDialog lewat RiwayatFieldForm (dropdown berjenjang master).
 * Batasan M4: jabatan koordinasi belum punya master `options`, jadi diisi ID-nya; pegawai Plt/Plh diisi NIP.
 * TODO(kartu lanjutan F4 review MAKE-012): combobox pencarian pegawai + master options `jabatan-koordinasi`.
 */
import { A, D, N, R, S, T, type BerkasKolomDef, type FieldDef } from './riwayat/riwayat.config'
import type { JenisPenugasan, PenugasanItem } from './types'

export const LABEL_PENUGASAN: Record<JenisPenugasan, string> = { plt: 'Plt', plh: 'Plh' }

export const STATUS_PENUGASAN = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]

const NORMAL = { field: 'jenis_jabatan', in: ['1'] }
const KOORDINASI = { field: 'jenis_jabatan', in: ['2'] }

export function penugasanFields(jenis: JenisPenugasan): FieldDef[] {
  const label = LABEL_PENUGASAN[jenis]
  return [
    T(`nip_${jenis}`, `NIP Pegawai ${label}`, { required: true, max: 30, hint: 'NIP pegawai yang ditugaskan.' }),
    S('status', 'Status', STATUS_PENUGASAN, { required: true }),
    D('tgl_mulai', 'Tanggal Mulai', { required: true }),
    D('tgl_akhir', 'Tanggal Akhir', { required: true }),
    S('jenis_jabatan', 'Jenis Jabatan', [
      { value: '1', label: 'Jabatan Normal' },
      { value: '2', label: 'Jabatan Koordinasi' },
    ], { required: true }),
    R('id_group_jabatan', 'Group Jabatan', 'group-jabatan', { required: true, showWhen: NORMAL }),
    R('id_sub_group_jabatan', 'Sub Group Jabatan', 'sub-group-jabatan', { required: true, dependsOn: 'id_group_jabatan', showWhen: NORMAL }),
    R('id_jabatan', 'Jabatan', 'jabatan', {
      required: true,
      wide: true,
      showWhen: NORMAL,
      optionsFilter: { param: 'id_sub_group_jabatan', field: 'id_sub_group_jabatan' },
    }),
    S('jenis_jabatan_koord', 'Jenis Koordinasi', [
      { value: '1', label: 'Koordinator' },
      { value: '2', label: 'Subkoordinator' },
    ], { required: true, showWhen: KOORDINASI }),
    N('id_jabatan_koord', 'ID Jabatan Koordinasi', { required: true, showWhen: KOORDINASI }),
    R('id_unit', 'Unit Kerja Jabatan', 'unit', { required: true }),
    R('id_satker', 'Satuan Kerja Jabatan', 'satker', { required: true, dependsOn: 'id_unit' }),
    R(`id_group_jabatan_${jenis}`, `Group Jabatan ${label}`, 'group-jabatan', { required: true }),
    R(`id_sub_group_jabatan_${jenis}`, `Sub Group Jabatan ${label}`, 'sub-group-jabatan', { required: true, dependsOn: `id_group_jabatan_${jenis}` }),
    R(`id_unit_${jenis}`, `Unit Kerja ${label}`, 'unit', { required: true }),
    R(`id_satker_${jenis}`, `Satuan Kerja ${label}`, 'satker', { required: true, dependsOn: `id_unit_${jenis}` }),
    A('keterangan', 'Keterangan', { max: 255 }),
  ]
}

/** PenugasanService::aturanLampiran(): opsional, 5 MB. */
export const LAMPIRAN_PENUGASAN: BerkasKolomDef = {
  kolom: 'lampiran',
  label: 'Lampiran (SK/Nota Dinas)',
  wajib: false,
  batas_mb: 5,
  ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx'],
}

/** Nilai awal form edit dari baris (kolom tak dikenal diabaikan). */
export function penugasanInitial(jenis: JenisPenugasan, row: PenugasanItem | null): Record<string, string> {
  if (!row) return {}
  return Object.fromEntries(
    penugasanFields(jenis).map((f) => {
      const v = row[f.name]
      return [f.name, v === null || v === undefined ? '' : String(v)]
    }),
  )
}
