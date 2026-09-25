/**
 * Skema form akun pengguna (A-12) — mengikuti aturan validasi backend UserService
 * (NIP 18 digit, username ≤30, password sesuai kebijakan K4 dari password.schema.ts, role 1-8).
 * Backend tetap final authority.
 */
import { z } from 'zod'

import { newPasswordSchema } from './password.schema'

const nip = z
  .string({ message: 'NIP wajib diisi.' })
  .trim()
  .regex(/^\d{18}$/, 'NIP harus 18 digit angka.')

const username = z
  .string()
  .trim()
  .max(30, 'Username maksimal 30 karakter.')
  .optional()
  .or(z.literal(''))

// Satu sumber aturan password (PASSWORD_RULES) — sama dengan halaman ganti/reset password dan backend PasswordPolicy.
const passwordRule = newPasswordSchema

const userLevel = z.coerce.number({ message: 'Role wajib dipilih.' }).int().min(1, 'Role wajib dipilih.').max(8, 'Role tidak valid.')

const optionalCode = z.string().trim().max(10, 'Maksimal 10 karakter.').optional().or(z.literal(''))

export const userCreateSchema = z.object({
  nip,
  username,
  password: passwordRule,
  user_level: userLevel,
  id_unit: optionalCode,
  id_satker: optionalCode,
  // Nilai awal '1' diberikan lewat resetForm di komponen. Catatan: zod dipin ke 3.x karena @vee-validate/zod 4.15
  // belum kompatibel dengan internal Zod 4 (_def.defaultValue / regex checks).
  status: z.enum(['0', '1']),
})

export const userUpdateSchema = z.object({
  username,
  password: passwordRule.optional().or(z.literal('')),
  user_level: userLevel,
  id_unit: optionalCode,
  id_satker: optionalCode,
  status: z.enum(['0', '1']),
})

export type UserCreateForm = z.infer<typeof userCreateSchema>
export type UserUpdateForm = z.infer<typeof userUpdateSchema>
