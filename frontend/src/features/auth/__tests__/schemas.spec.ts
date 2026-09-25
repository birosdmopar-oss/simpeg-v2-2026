/**
 * A-11/A-12 — skema Zod (prioritas testing FE: composables & schemas, ADR-027).
 */
import { describe, expect, it } from 'vitest'

import { loginSchema } from '../schemas/login.schema'
import { userCreateSchema, userUpdateSchema } from '../schemas/user.schema'

describe('loginSchema', () => {
  it('menerima username, password, dan captcha terisi', () => {
    const result = loginSchema.safeParse({ username: '199002152015022002', password: 'Password123!', captcha_token: 'tok' })
    expect(result.success).toBe(true)
  })

  it('menolak captcha kosong dengan pesan jelas', () => {
    const result = loginSchema.safeParse({ username: '199002152015022002', password: 'x', captcha_token: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.map((i) => i.path[0])).toContain('captcha_token')
      expect(result.error.issues[0]?.message).toMatch(/captcha/i)
    }
  })

  it('menolak username kosong dan terlalu panjang', () => {
    expect(loginSchema.safeParse({ username: '   ', password: 'x', captcha_token: 't' }).success).toBe(false)
    expect(loginSchema.safeParse({ username: 'a'.repeat(31), password: 'x', captcha_token: 't' }).success).toBe(false)
  })
})

describe('userCreateSchema', () => {
  const valid = { nip: '200001012024011001', username: '', password: 'AkunBaru2026', user_level: '2', id_unit: 'U01', id_satker: 'S01', status: '1' }

  it('menerima payload valid dan meng-coerce role ke number', () => {
    const result = userCreateSchema.safeParse(valid)
    expect(result.success).toBe(true)
    if (result.success) expect(result.data.user_level).toBe(2)
  })

  it('menolak NIP bukan 18 digit', () => {
    const result = userCreateSchema.safeParse({ ...valid, nip: '12345' })
    expect(result.success).toBe(false)
    if (!result.success) expect(result.error.issues[0]?.message).toContain('18 digit')
  })

  it('menolak password lemah (tanpa angka / < 8)', () => {
    expect(userCreateSchema.safeParse({ ...valid, password: 'pendek1' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...valid, password: 'tanpaangka' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...valid, password: '12345678' }).success).toBe(false)
  })

  it('kebijakan K4 dari PASSWORD_RULES: huruf besar dan huruf kecil wajib (ISSUE-006)', () => {
    const noUpper = userCreateSchema.safeParse({ ...valid, password: 'akunbaru2026' })
    expect(noUpper.success).toBe(false)
    if (!noUpper.success) expect(noUpper.error.issues[0]?.message).toBe('Password harus mengandung minimal 1 huruf besar.')

    expect(userCreateSchema.safeParse({ ...valid, password: 'AKUNBARU2026' }).success).toBe(false)
    expect(userUpdateSchema.safeParse({ username: 'x', password: 'akunbaru2026', user_level: 3, status: '1' }).success).toBe(false)
  })

  it('menolak role di luar 1-8', () => {
    expect(userCreateSchema.safeParse({ ...valid, user_level: '9' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...valid, user_level: '0' }).success).toBe(false)
  })
})

describe('userUpdateSchema', () => {
  it('password opsional saat edit', () => {
    const result = userUpdateSchema.safeParse({ username: 'x', password: '', user_level: 3, id_unit: '', id_satker: 'S01', status: '0' })
    expect(result.success).toBe(true)
  })

  it('password tetap divalidasi kalau diisi', () => {
    expect(userUpdateSchema.safeParse({ username: 'x', password: 'lemah', user_level: 3, status: '1' }).success).toBe(false)
  })
})
