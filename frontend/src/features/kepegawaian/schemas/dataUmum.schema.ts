/**
 * Skema Zod form "Data Umum" Detail Pegawai (ADR-026) — field bertanda (*) pada Gambar 22 wajib diisi.
 * Aturan format mengikuti kolom legacy/Tech Spec sejauh yang terdokumentasi: NIK 16 digit, NPWP 15–16 digit,
 * BPJS Kesehatan 13 digit, BPJS Ketenagakerjaan 11 digit. Aturan lain ditandai ASUMSI dan perlu dicek saat B-03.
 */
import { z } from 'zod'

const required = (label: string) => z.string().trim().min(1, `${label} wajib diisi`)
const digits = (label: string, min: number, max = min) =>
  z
    .string()
    .trim()
    .min(1, `${label} wajib diisi`)
    .regex(/^\d+$/, `${label} hanya boleh berisi angka`)
    .refine((v) => v.length >= min && v.length <= max, min === max ? `${label} harus ${min} digit` : `${label} harus ${min}–${max} digit`)

const phone = (label: string) =>
  z
    .string()
    .trim()
    .min(1, `${label} wajib diisi`)
    .regex(/^0\d{8,13}$/, `${label} tidak valid (contoh: 0812xxxxxxxx)`) // ASUMSI: format seluler Indonesia

export const dataUmumSchema = z.object({
  nama: required('Nama').max(150, 'Nama maksimal 150 karakter'),
  gelar_awal: z.string().trim().max(50, 'Maksimal 50 karakter'),
  gelar_akhir: z.string().trim().max(50, 'Maksimal 50 karakter'),
  tanggal_lahir: required('Tanggal lahir'),
  provinsi_lahir: required('Provinsi lahir'),
  kota_lahir: required('Kab./Kota lahir'),
  jenis_kelamin: required('Jenis kelamin'),
  status_perkawinan: required('Status perkawinan'),
  nik: digits('NIK', 16),
  npwp: digits('NPWP', 15, 16),
  no_bpjs_kesehatan: digits('No. BPJS Kesehatan', 13),
  no_bpjs_ketenagakerjaan: digits('No. BPJS Ketenagakerjaan', 11),
  no_taspen: z.string().trim().min(1, 'No. Taspen wajib diisi').regex(/^\d+$/, 'No. Taspen hanya boleh berisi angka'),
  agama: required('Agama'),
  email: z.string().trim().min(1, 'Email wajib diisi').email('Format email tidak valid'),
  no_hp: phone('No. HP'),
  jenis_kerabat: required('Jenis kerabat'),
  no_telp_kerabat: phone('No. Telp. Kerabat'),
  jenis_pegawai: required('Jenis pegawai'),
  status_pegawai: required('Status pegawai'),
  jenis_status: required('Jenis status'),
  tmt_status: z.string().trim().max(100, 'Maksimal 100 karakter'),
})

export type DataUmumForm = z.infer<typeof dataUmumSchema>
