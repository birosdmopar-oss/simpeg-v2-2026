/**
 * Skema form login (A-11) — Zod, co-located per feature (ADR-026).
 * Hanya validasi bentuk; kebenaran kredensial/captcha/lockout tetap authoritative dari backend.
 */
import { z } from 'zod'

import { USERNAME_MAX } from '../types'

export const loginSchema = z.object({
  // Username = NIP (akun pegawai) atau username bebas (akun non-pegawai, DBV-010); kolom legacy VARCHAR(100).
  username: z
    .string({ message: 'Username / NIP wajib diisi.' })
    .trim()
    .min(1, 'Username / NIP wajib diisi.')
    .max(USERNAME_MAX, `Username maksimal ${USERNAME_MAX} karakter.`),
  password: z.string({ message: 'Password wajib diisi.' }).min(1, 'Password wajib diisi.'),
  captcha_token: z
    .string({ message: 'Selesaikan verifikasi captcha terlebih dahulu.' })
    .min(1, 'Selesaikan verifikasi captcha terlebih dahulu.'),
})

export type LoginForm = z.infer<typeof loginSchema>
