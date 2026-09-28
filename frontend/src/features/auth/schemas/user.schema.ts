/**
 * Skema form akun pengguna (A-12) — mengikuti aturan validasi backend UserService (DBV-010/CR-013):
 * - NIP opsional di bentuk (angka, maks. 18 digit — NIK 16 digit Non-PNS diterima), tetapi WAJIB untuk role
 *   Pegawai/PTT/PPPK (UL_PEGAWAI 2/6/7).
 * - Akun tanpa NIP (role 1/3/4/5/8) wajib punya nama dan username.
 * - Email opsional, harus valid; username ≤ 100; password sesuai kebijakan K4 (password.schema.ts); role 1-8.
 * - Edit: NIP hanya bisa diisi untuk akun yang belum punya NIP (makeUserUpdateSchema); NIP yang sudah ada tetap.
 * Backend tetap final authority.
 */
import { z } from 'zod'

import { ACCOUNT_EMAIL_MAX, ACCOUNT_NAME_MAX, NIP_MAX_DIGITS, UL_PEGAWAI, USERNAME_MAX } from '../types'
import { newPasswordSchema } from './password.schema'

export const NIP_REQUIRED_MESSAGE = 'NIP wajib diisi untuk role Pegawai/PTT/PPPK.'
export const NAME_REQUIRED_MESSAGE = 'Nama wajib diisi untuk akun tanpa NIP.'
export const USERNAME_REQUIRED_MESSAGE = 'Username wajib diisi untuk akun tanpa NIP.'

const nip = z
  .string()
  .trim()
  .regex(new RegExp(`^\\d{1,${NIP_MAX_DIGITS}}$`), `NIP harus berupa angka, maksimal ${NIP_MAX_DIGITS} digit.`)
  .optional()
  .or(z.literal(''))

const name = z.string().trim().max(ACCOUNT_NAME_MAX, `Nama maksimal ${ACCOUNT_NAME_MAX} karakter.`).optional().or(z.literal(''))

const email = z
  .string()
  .trim()
  .max(ACCOUNT_EMAIL_MAX, `Email maksimal ${ACCOUNT_EMAIL_MAX} karakter.`)
  .email('Email tidak valid.')
  .optional()
  .or(z.literal(''))

const username = z
  .string()
  .trim()
  .max(USERNAME_MAX, `Username maksimal ${USERNAME_MAX} karakter.`)
  .optional()
  .or(z.literal(''))

// Satu sumber aturan password (PASSWORD_RULES) — sama dengan halaman ganti/reset password dan backend PasswordPolicy.
const passwordRule = newPasswordSchema

const userLevel = z.coerce.number({ message: 'Role wajib dipilih.' }).int().min(1, 'Role wajib dipilih.').max(8, 'Role tidak valid.')

const optionalCode = z.string().trim().max(10, 'Maksimal 10 karakter.').optional().or(z.literal(''))

interface AccountValues {
  nip?: string
  name?: string
  username?: string
  user_level: number
}

/**
 * Invarian akun (sama dengan UserService::validateAccountInvariant): role 2/6/7 wajib NIP; akun tanpa NIP wajib nama
 * dan username. `effectiveNip` = NIP akun hasil akhir (NIP lama saat edit akun ber-NIP).
 */
function accountRules(values: AccountValues, ctx: z.RefinementCtx, effectiveNip: string | undefined): void {
  const hasNip = (effectiveNip ?? '').trim() !== ''
  if (hasNip) return

  if ((UL_PEGAWAI as readonly number[]).includes(values.user_level)) {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['nip'], message: NIP_REQUIRED_MESSAGE })
  }
  if ((values.name ?? '').trim() === '') {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['name'], message: NAME_REQUIRED_MESSAGE })
  }
  if ((values.username ?? '').trim() === '') {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['username'], message: USERNAME_REQUIRED_MESSAGE })
  }
}

export const userCreateSchema = z
  .object({
    nip,
    name,
    email,
    username,
    password: passwordRule,
    user_level: userLevel,
    id_unit: optionalCode,
    id_satker: optionalCode,
    // Nilai awal '1' diberikan lewat resetForm di komponen. Catatan: zod dipin ke 3.x karena @vee-validate/zod 4.15
    // belum kompatibel dengan internal Zod 4 (_def.defaultValue / regex checks).
    status: z.enum(['0', '1']),
  })
  .superRefine((values, ctx) => accountRules(values, ctx, values.nip))

/**
 * Skema edit untuk akun tertentu: `existingNip` = NIP akun saat ini (null untuk akun tanpa NIP). Akun ber-NIP tidak
 * bisa mengubah NIP di form ini (B-06), jadi isian `nip` hanya dinilai untuk akun tanpa NIP (menautkan ke pegawai).
 */
export function makeUserUpdateSchema(existingNip: string | null) {
  return z
    .object({
      nip,
      name,
      email,
      username,
      password: passwordRule.optional().or(z.literal('')),
      user_level: userLevel,
      id_unit: optionalCode,
      id_satker: optionalCode,
      status: z.enum(['0', '1']),
    })
    .superRefine((values, ctx) => accountRules(values, ctx, existingNip ?? values.nip))
}

export type UserCreateForm = z.infer<typeof userCreateSchema>
export type UserUpdateForm = z.infer<ReturnType<typeof makeUserUpdateSchema>>
