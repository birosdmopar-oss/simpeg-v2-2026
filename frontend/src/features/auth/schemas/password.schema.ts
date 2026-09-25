/**
 * Kebijakan password (K4, keputusan user 25-09-2026: ikut legacy) — SATU sumber aturan frontend, dipakai bersama oleh
 * schema Zod (ganti password, reset password, form akun admin) dan checklist real-time (PasswordRulesChecklist).
 *
 * Cermin backend `App\Libraries\Auth\PasswordPolicy`: id, urutan, dan pesan WAJIB sama (vektor uji di
 * `__tests__/password.schema.spec.ts` = `backend/tests/unit/Libraries/PasswordPolicyTest.php`). Minimal panjang =
 * `auth.passwordMinLength` backend. Backend tetap final authority; password lama tidak dipaksa diganti (login tidak
 * memeriksa aturan ini).
 */
import { z } from 'zod'

import { loginSchema } from './login.schema'

export const PASSWORD_MIN_LENGTH = 8

export type PasswordRuleId = 'min_length' | 'uppercase' | 'lowercase' | 'digit'

export interface PasswordRule {
  id: PasswordRuleId
  /** Teks di checklist. */
  label: string
  /** Pesan error — sama persis dengan pesan backend. */
  message: string
  test: (value: string) => boolean
}

/** Panjang per karakter (code point) seperti `mb_strlen` backend: emoji dihitung 1, bukan 2 (UTF-16). */
export function passwordLength(value: string): number {
  return [...value].length
}

export const PASSWORD_RULES: readonly PasswordRule[] = [
  {
    id: 'min_length',
    label: `Minimal ${PASSWORD_MIN_LENGTH} karakter`,
    message: `Password minimal ${PASSWORD_MIN_LENGTH} karakter.`,
    test: (value) => passwordLength(value) >= PASSWORD_MIN_LENGTH,
  },
  {
    id: 'uppercase',
    label: 'Minimal 1 huruf besar (A-Z)',
    message: 'Password harus mengandung minimal 1 huruf besar.',
    test: (value) => /[A-Z]/.test(value),
  },
  {
    id: 'lowercase',
    label: 'Minimal 1 huruf kecil (a-z)',
    message: 'Password harus mengandung minimal 1 huruf kecil.',
    test: (value) => /[a-z]/.test(value),
  },
  {
    id: 'digit',
    label: 'Minimal 1 angka (0-9)',
    message: 'Password harus mengandung minimal 1 angka.',
    test: (value) => /[0-9]/.test(value),
  },
]

/** Satu baris checklist real-time (aturan kebijakan atau aturan milik form, mis. konfirmasi cocok). */
export interface PasswordChecklistItem {
  id: string
  label: string
  ok: boolean
}

/** Ringkasan kebijakan untuk hint form. */
export const PASSWORD_POLICY_HINT = `Minimal ${PASSWORD_MIN_LENGTH} karakter, mengandung huruf besar, huruf kecil, dan angka.`

export const PASSWORD_MISMATCH_MESSAGE = 'Konfirmasi password tidak cocok.'
export const PASSWORD_SAME_AS_OLD_MESSAGE = 'Password baru tidak boleh sama dengan password lama.'

/** Id aturan yang belum dipenuhi, urut sesuai PASSWORD_RULES (kosong = valid). */
export function failedPasswordRules(value: string): PasswordRuleId[] {
  return PASSWORD_RULES.filter((rule) => !rule.test(value)).map((rule) => rule.id)
}

/** Password baru: setiap aturan yang gagal menjadi satu issue (pesan pertama tampil di field). */
export const newPasswordSchema = z.string({ message: 'Password wajib diisi.' }).superRefine((value, ctx) => {
  for (const rule of PASSWORD_RULES) {
    if (!rule.test(value)) ctx.addIssue({ code: z.ZodIssueCode.custom, message: rule.message })
  }
})

const confirmation = z
  .string({ message: 'Konfirmasi password wajib diisi.' })
  .min(1, 'Konfirmasi password wajib diisi.')

/** A-06 ganti password: payload POST /auth/change-password. */
export const changePasswordSchema = z
  .object({
    old_password: z.string({ message: 'Password lama wajib diisi.' }).min(1, 'Password lama wajib diisi.'),
    new_password: newPasswordSchema,
    new_password_confirmation: confirmation,
  })
  .superRefine((values, ctx) => {
    if (values.old_password !== '' && values.new_password === values.old_password) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['new_password'], message: PASSWORD_SAME_AS_OLD_MESSAGE })
    }
    if (values.new_password_confirmation !== '' && values.new_password_confirmation !== values.new_password) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['new_password_confirmation'], message: PASSWORD_MISMATCH_MESSAGE })
    }
  })

/**
 * A-07 reset password: payload POST /auth/reset-password. Tanpa aturan "beda dari password lama" — backend
 * (ResetPasswordService) juga tidak memeriksanya karena password lama tidak diketahui.
 */
export const resetPasswordSchema = z
  .object({
    token: z.string({ message: 'Token reset wajib diisi.' }).trim().min(1, 'Token reset wajib diisi.'),
    new_password: newPasswordSchema,
    new_password_confirmation: confirmation,
  })
  .superRefine((values, ctx) => {
    if (values.new_password_confirmation !== '' && values.new_password_confirmation !== values.new_password) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['new_password_confirmation'], message: PASSWORD_MISMATCH_MESSAGE })
    }
  })

/** A-07 permintaan lupa password: username + captcha, aturan sama dengan form login. */
export const forgotPasswordSchema = loginSchema.pick({ username: true, captcha_token: true })

export type ChangePasswordForm = z.infer<typeof changePasswordSchema>
export type ResetPasswordForm = z.infer<typeof resetPasswordSchema>
export type ForgotPasswordForm = z.infer<typeof forgotPasswordSchema>
