/**
 * A-11/A-12 — skema Zod (prioritas testing FE: composables & schemas, ADR-027).
 * DBV-010/CR-013 — akun non-pegawai: NIP wajib role 2/6/7, opsional role lain (nama + username wajib bila tanpa NIP),
 * NIP angka maks. 18 digit, email opsional, username ≤ 100.
 */
import { describe, expect, it } from 'vitest'

import { loginSchema } from '../schemas/login.schema'
import {
  makeUserUpdateSchema,
  NAME_REQUIRED_MESSAGE,
  NIP_REQUIRED_MESSAGE,
  USERNAME_REQUIRED_MESSAGE,
  userCreateSchema,
} from '../schemas/user.schema'

/** Pesan error per field (path[0]) dari hasil safeParse. */
function errorsOf(result: { success: boolean; error?: { issues: Array<{ path: Array<string | number>; message: string }> } }): Record<string, string[]> {
  const out: Record<string, string[]> = {}
  for (const issue of result.error?.issues ?? []) {
    const key = String(issue.path[0])
    ;(out[key] ??= []).push(issue.message)
  }
  return out
}

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

  it('menolak username kosong dan lebih dari 100 karakter (legacy VARCHAR(100))', () => {
    expect(loginSchema.safeParse({ username: '   ', password: 'x', captcha_token: 't' }).success).toBe(false)
    expect(loginSchema.safeParse({ username: 'a'.repeat(100), password: 'x', captcha_token: 't' }).success).toBe(true)
    const tooLong = loginSchema.safeParse({ username: 'a'.repeat(101), password: 'x', captcha_token: 't' })
    expect(errorsOf(tooLong).username).toEqual(['Username maksimal 100 karakter.'])
  })
})

describe('userCreateSchema', () => {
  const valid = {
    nip: '200001012024011001',
    name: '',
    email: '',
    username: '',
    password: 'AkunBaru2026',
    user_level: '2',
    id_unit: 'U01',
    id_satker: 'S01',
    status: '1',
  }

  it('menerima payload valid dan meng-coerce role ke number', () => {
    const result = userCreateSchema.safeParse(valid)
    expect(result.success).toBe(true)
    if (result.success) expect(result.data.user_level).toBe(2)
  })

  it('NIP ikut legacy: angka maks. 18 digit (NIK 16 digit diterima); 19 digit / huruf ditolak', () => {
    expect(userCreateSchema.safeParse({ ...valid, nip: '3171012345678901' }).success).toBe(true)
    for (const nip of ['1234567890123456789', '19900215A015022002', '1990-0215']) {
      expect(errorsOf(userCreateSchema.safeParse({ ...valid, nip })).nip).toEqual(['NIP harus berupa angka, maksimal 18 digit.'])
    }
  })

  it('role Pegawai/PTT/PPPK wajib NIP', () => {
    for (const role of ['2', '6', '7']) {
      const result = userCreateSchema.safeParse({ ...valid, nip: '', name: 'Nama', username: 'akun', user_level: role })
      expect(errorsOf(result).nip).toEqual([NIP_REQUIRED_MESSAGE])
    }
  })

  it('role 1/3/4/5/8 boleh tanpa NIP, tetapi nama dan username wajib', () => {
    for (const role of ['1', '3', '4', '5', '8']) {
      expect(userCreateSchema.safeParse({ ...valid, nip: '', name: 'Admin Pusat', username: 'admin.pusat', user_level: role }).success).toBe(true)
    }

    const errors = errorsOf(userCreateSchema.safeParse({ ...valid, nip: '', user_level: '5' }))
    expect(errors.name).toEqual([NAME_REQUIRED_MESSAGE])
    expect(errors.username).toEqual([USERNAME_REQUIRED_MESSAGE])
    expect(errors.nip).toBeUndefined()
  })

  it('akun ber-NIP tidak wajib nama; username default NIP (boleh kosong)', () => {
    expect(userCreateSchema.safeParse({ ...valid, user_level: '1' }).success).toBe(true)
  })

  it('email opsional tetapi harus valid; nama maks. 150; username maks. 100', () => {
    expect(userCreateSchema.safeParse({ ...valid, email: 'pegawai@example.go.id' }).success).toBe(true)
    expect(errorsOf(userCreateSchema.safeParse({ ...valid, email: 'bukan-email' })).email).toEqual(['Email tidak valid.'])
    expect(errorsOf(userCreateSchema.safeParse({ ...valid, name: 'n'.repeat(151) })).name).toEqual(['Nama maksimal 150 karakter.'])
    expect(userCreateSchema.safeParse({ ...valid, username: 'u'.repeat(100) }).success).toBe(true)
    expect(errorsOf(userCreateSchema.safeParse({ ...valid, username: 'u'.repeat(101) })).username).toEqual(['Username maksimal 100 karakter.'])
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
    expect(makeUserUpdateSchema('199002152015022002').safeParse({ username: 'x', password: 'akunbaru2026', user_level: 3, status: '1' }).success).toBe(false)
  })

  it('menolak role di luar 1-8', () => {
    expect(userCreateSchema.safeParse({ ...valid, user_level: '9' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...valid, user_level: '0' }).success).toBe(false)
  })
})

describe('makeUserUpdateSchema', () => {
  const withNip = makeUserUpdateSchema('199002152015022002')
  const withoutNip = makeUserUpdateSchema(null)

  it('password opsional saat edit', () => {
    const result = withNip.safeParse({ username: 'x', password: '', user_level: 3, id_unit: '', id_satker: 'S01', status: '0' })
    expect(result.success).toBe(true)
  })

  it('password tetap divalidasi kalau diisi', () => {
    expect(withNip.safeParse({ username: 'x', password: 'lemah', user_level: 3, status: '1' }).success).toBe(false)
  })

  it('akun ber-NIP: NIP lama tetap berlaku (ubah ke role 2 tanpa isian NIP diterima, nama tidak wajib)', () => {
    expect(withNip.safeParse({ nip: '', name: '', username: 'x', password: '', user_level: 2, status: '1' }).success).toBe(true)
  })

  it('akun tanpa NIP: nama wajib; role 2/6/7 hanya bila NIP ditautkan', () => {
    expect(errorsOf(withoutNip.safeParse({ nip: '', name: '', username: 'admin', password: '', user_level: 1, status: '1' })).name).toEqual([
      NAME_REQUIRED_MESSAGE,
    ])
    expect(errorsOf(withoutNip.safeParse({ nip: '', name: 'Admin', username: 'admin', password: '', user_level: 2, status: '1' })).nip).toEqual([
      NIP_REQUIRED_MESSAGE,
    ])
    expect(withoutNip.safeParse({ nip: '199002152015022099', name: '', username: 'admin', password: '', user_level: 2, status: '1' }).success).toBe(true)
    expect(errorsOf(withoutNip.safeParse({ nip: '12AB', name: 'Admin', username: 'admin', password: '', user_level: 1, status: '1' })).nip).toEqual([
      'NIP harus berupa angka, maksimal 18 digit.',
    ])
  })
})
