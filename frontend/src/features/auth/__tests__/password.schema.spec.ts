/**
 * ISSUE-006 / K4 — satu sumber aturan password (PASSWORD_RULES) untuk schema Zod dan checklist real-time.
 * Vektor `vectors` SAMA dengan backend/tests/unit/Libraries/PasswordPolicyTest.php (PasswordPolicy): kalau salah
 * satu diubah, ubah keduanya.
 */
import { describe, expect, it } from 'vitest'

import {
  changePasswordSchema,
  failedPasswordRules,
  forgotPasswordSchema,
  newPasswordSchema,
  PASSWORD_MIN_LENGTH,
  PASSWORD_RULES,
  type PasswordRuleId,
  resetPasswordSchema,
} from '../schemas/password.schema'

const vectors: Array<[string, string, PasswordRuleId[]]> = [
  ['memenuhi semua aturan', 'Password1', []],
  ['terlalu pendek', 'Pass1', ['min_length']],
  ['tanpa huruf besar', 'password123', ['uppercase']],
  ['tanpa huruf kecil', 'PASSWORD123', ['lowercase']],
  ['tanpa angka', 'PasswordAja', ['digit']],
  ['kosong', '', ['min_length', 'uppercase', 'lowercase', 'digit']],
  // Per karakter seperti mb_strlen: 7 karakter walau .length UTF-16 = 11.
  ['emoji dihitung per karakter', 'Aa1😀😀😀😀', ['min_length']],
  // Pola ASCII legacy: huruf beraksen bukan huruf besar/kecil.
  ['huruf non-ASCII tidak dihitung', 'ÉéÉé1234', ['uppercase', 'lowercase']],
  ['spasi dan simbol boleh', 'Kata Sandi 2026!', []],
]

describe('PASSWORD_RULES (K4, cermin backend PasswordPolicy)', () => {
  it('urutan id dan pesan sama persis dengan backend', () => {
    expect(PASSWORD_MIN_LENGTH).toBe(8)
    expect(PASSWORD_RULES.map((r) => [r.id, r.message])).toEqual([
      ['min_length', 'Password minimal 8 karakter.'],
      ['uppercase', 'Password harus mengandung minimal 1 huruf besar.'],
      ['lowercase', 'Password harus mengandung minimal 1 huruf kecil.'],
      ['digit', 'Password harus mengandung minimal 1 angka.'],
    ])
  })

  it.each(vectors)('%s', (_label, password, expected) => {
    expect(failedPasswordRules(password)).toEqual(expected)
  })

  it('newPasswordSchema: satu issue per aturan gagal, pesan = pesan aturan', () => {
    const result = newPasswordSchema.safeParse('password')
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.map((i) => i.message)).toEqual([
        'Password harus mengandung minimal 1 huruf besar.',
        'Password harus mengandung minimal 1 angka.',
      ])
    }
    expect(newPasswordSchema.safeParse('Password1').success).toBe(true)
  })
})

describe('changePasswordSchema', () => {
  const valid = { old_password: 'LamaSekali1', new_password: 'BaruSekali2', new_password_confirmation: 'BaruSekali2' }

  it('menerima payload valid; password lama tidak dicek kebijakan (tidak dipaksa ganti)', () => {
    expect(changePasswordSchema.safeParse(valid).success).toBe(true)
    expect(changePasswordSchema.safeParse({ ...valid, old_password: 'lemah' }).success).toBe(true)
  })

  it('password baru sama dengan lama → error di new_password', () => {
    const result = changePasswordSchema.safeParse({ ...valid, new_password: 'LamaSekali1', new_password_confirmation: 'LamaSekali1' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues).toEqual([
        expect.objectContaining({ path: ['new_password'], message: 'Password baru tidak boleh sama dengan password lama.' }),
      ])
    }
  })

  it('konfirmasi berbeda → error di new_password_confirmation', () => {
    const result = changePasswordSchema.safeParse({ ...valid, new_password_confirmation: 'BaruSekali3' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues).toEqual([
        expect.objectContaining({ path: ['new_password_confirmation'], message: 'Konfirmasi password tidak cocok.' }),
      ])
    }
  })

  it('password lama wajib diisi dan password baru wajib memenuhi kebijakan', () => {
    const result = changePasswordSchema.safeParse({ old_password: '', new_password: 'barusekali2', new_password_confirmation: 'barusekali2' })
    expect(result.success).toBe(false)
    if (!result.success) {
      const byPath = result.error.issues.map((i) => [i.path[0], i.message])
      expect(byPath).toContainEqual(['old_password', 'Password lama wajib diisi.'])
      expect(byPath).toContainEqual(['new_password', 'Password harus mengandung minimal 1 huruf besar.'])
    }
  })
})

describe('resetPasswordSchema', () => {
  it('token wajib; tanpa aturan "beda dari password lama"', () => {
    expect(resetPasswordSchema.safeParse({ token: 'abc', new_password: 'BaruSekali2', new_password_confirmation: 'BaruSekali2' }).success).toBe(true)

    const noToken = resetPasswordSchema.safeParse({ token: '  ', new_password: 'BaruSekali2', new_password_confirmation: 'BaruSekali2' })
    expect(noToken.success).toBe(false)
    if (!noToken.success) expect(noToken.error.issues[0]?.message).toBe('Token reset wajib diisi.')
  })

  it('konfirmasi berbeda ditolak', () => {
    const result = resetPasswordSchema.safeParse({ token: 'abc', new_password: 'BaruSekali2', new_password_confirmation: 'x' })
    expect(result.success).toBe(false)
    if (!result.success) expect(result.error.issues[0]?.path).toEqual(['new_password_confirmation'])
  })
})

describe('forgotPasswordSchema', () => {
  it('username dan captcha wajib, sama dengan form login', () => {
    expect(forgotPasswordSchema.safeParse({ username: '199002152015022002', captcha_token: 'tok' }).success).toBe(true)
    expect(forgotPasswordSchema.safeParse({ username: '199002152015022002', captcha_token: '' }).success).toBe(false)
    expect(forgotPasswordSchema.safeParse({ username: ' ', captcha_token: 'tok' }).success).toBe(false)
  })
})
