/**
 * Isian form Data Umum (B-03, MAKE-010; pemilik WS-2) dalam bentuk FieldDef mesin form riwayat (dipakai ulang lewat
 * `RiwayatFieldForm`). Salinan aturan backend `ValidasiBiodata` (legacy `views/hr/employee/form.php` +
 * `L_employee::validate_param`): isian umum untuk semua role ubah; jenis pegawai untuk role 1/3; nama, gelar, tanggal
 * lahir, jenis status, TMT status hanya role 1. Server tetap yang menegakkan (422).
 */
import { Role, type RoleCode } from '@/features/auth/types'

import { A, D, R, S, T, type FieldDef, type FieldOption, type LampiranDef } from './riwayat/riwayat.config'
import type { Pegawai } from './types'

export const PROVINSI_LAIN = '99'
export const KABUPATEN_LAIN = '9999'
export const KERABAT_LAIN = 'Kerabat Lain'

/** Pilihan jenis kerabat legacy (form :337-341). */
export const JENIS_KERABAT: FieldOption[] = ['Orang Tua', 'Suami/Istri', 'Saudara Kandung', 'Anak', KERABAT_LAIN].map((v) => ({ value: v, label: v }))

/** Foto profil memakai slot lampiran kode 0 di form (bukan kode `jenis_rwy`); dikirim sebagai berkas `foto`. */
export const FOTO_SLOT = 0

export function fotoLampiran(): LampiranDef {
  return { id_riwayat: FOTO_SLOT, label: 'Foto Pegawai', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png'] }
}

const LAIN = (kode: string): FieldOption[] => [{ value: kode, label: 'LAIN-LAIN' }]
const DIGIT = 'Hanya angka; tanda baca dibuang otomatis.'

export function dataUmumFields(role: RoleCode | null): FieldDef[] {
  const superAdmin = role === Role.SUPER_ADMIN
  const fields: FieldDef[] = []

  if (superAdmin) {
    fields.push(
      T('nama', 'Nama', { required: true, max: 100, wide: true }),
      T('glr_awal', 'Gelar Depan', { max: 50 }),
      T('glr_akhir', 'Gelar Belakang', { max: 50 }),
      D('tgl_lahir', 'Tanggal Lahir', { required: true }),
    )
  }

  fields.push(
    R('id_provinsi_lahir', 'Provinsi Tempat Lahir', 'provinsi', { required: true, extraOptions: LAIN(PROVINSI_LAIN) }),
    T('provinsi_lahir_lain', 'Provinsi Tempat Lahir Lainnya', { required: true, showWhen: { field: 'id_provinsi_lahir', in: [PROVINSI_LAIN] } }),
    R('id_kabupaten_kota_lahir', 'Kabupaten/Kota Tempat Lahir', 'kabupaten-kota', { required: true, dependsOn: 'id_provinsi_lahir', extraOptions: LAIN(KABUPATEN_LAIN) }),
    T('kabupaten_kota_lahir_lain', 'Kabupaten/Kota Tempat Lahir Lainnya', { required: true, showWhen: { field: 'id_kabupaten_kota_lahir', in: [KABUPATEN_LAIN] } }),
    R('id_agama', 'Agama', 'agama', { required: true }),
    S('jenis_kelamin', 'Jenis Kelamin', [{ value: '1', label: 'Laki-laki' }, { value: '2', label: 'Perempuan' }], { required: true }),
    S('status_pernikahan', 'Status Pernikahan', [{ value: '1', label: 'Menikah' }, { value: '2', label: 'Tidak Menikah' }], { required: true }),
    T('npwp', 'NPWP', { required: true, max: 30, hint: DIGIT }),
    T('nik', 'NIK', { required: true, max: 30, hint: DIGIT }),
    T('bpjs_kes', 'No. BPJS Kesehatan', { required: true, max: 60, hint: DIGIT }),
    T('bpjs_ket', 'No. BPJS Ketenagakerjaan', { max: 60, hint: DIGIT }),
    T('no_taspen', 'No. TASPEN', { required: true, max: 60, hint: DIGIT }),
    T('email', 'Email', { required: true, max: 150 }),
    T('no_hp', 'Nomor HP', { required: true, max: 30, hint: DIGIT }),
    S('jenis_kerabat', 'Jenis Kerabat', JENIS_KERABAT, { required: true }),
    T('jenis_kerabat_lain', 'Jenis Kerabat Lain', { required: true, max: 100, showWhen: { field: 'jenis_kerabat', in: [KERABAT_LAIN] } }),
    T('no_telp_kerabat', 'Nomor Telepon Kerabat', { required: true, max: 30, hint: DIGIT }),
  )

  if (superAdmin || role === Role.ADMIN_SATKER) {
    fields.push(R('id_jenis_pegawai', 'Jenis Pegawai', 'jenis-pegawai', { required: true }))
  }

  if (superAdmin) {
    fields.push(
      R('id_jenis_status', 'Jenis Status', 'jenis-status', { required: true, hint: 'Harus sesuai status pegawai sekarang.' }),
      D('tmt_status', 'TMT Status'),
    )
  }

  fields.push(A('keterangan', 'Keterangan', { max: 255 }))

  return fields
}

/** Nilai awal form dari baris pegawai + email akun (kerabat bebas data lama → "Kerabat Lain" + teksnya). */
export function dataUmumInitial(pegawai: Pegawai, email: string | null, fields: FieldDef[]): Record<string, string> {
  const raw = pegawai as unknown as Record<string, unknown>
  const out: Record<string, string> = {}
  for (const f of fields) {
    const value = f.name === 'email' ? email : raw[f.name]
    out[f.name] = value === null || value === undefined ? '' : String(value)
  }
  const kerabat = pegawai.jenis_kerabat ?? ''
  if (kerabat !== '' && !JENIS_KERABAT.some((o) => o.value === kerabat)) {
    out.jenis_kerabat = KERABAT_LAIN
    out.jenis_kerabat_lain = kerabat
  }
  return out
}
