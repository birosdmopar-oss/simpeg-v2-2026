/**
 * Skema Zod form master generik (ADR-026), dibangun dari metadata master (GET /master/meta).
 * Mengikuti rules backend BaseMasterController::rules(); keunikan & induk aktif divalidasi backend (422 → field).
 */
import { z } from 'zod'

import type { MasterMeta } from '../types'

/** Sama dengan regex backend: huruf/angka/titik/strip/garis bawah, tanpa spasi. */
export const MASTER_CODE_PATTERN = /^[A-Za-z0-9._-]+$/

export function buildMasterSchema(meta: MasterMeta, isEdit: boolean) {
  const shape: Record<string, z.ZodTypeAny> = {
    [meta.name_field]: z
      .string({ required_error: `${meta.name_label} wajib diisi.` })
      .trim()
      .min(1, `${meta.name_label} wajib diisi.`)
      .max(meta.name_max_length, `${meta.name_label} maksimal ${meta.name_max_length} karakter.`),
    order: z
      .union([z.literal(''), z.coerce.number().int('Urutan harus bilangan bulat.').min(1, 'Urutan minimal 1.')])
      .optional(),
  }

  if (!isEdit) {
    shape[meta.primary_key] = z
      .string({ required_error: 'Kode wajib diisi.' })
      .trim()
      .min(1, 'Kode wajib diisi.')
      .max(meta.id_max_length, `Kode maksimal ${meta.id_max_length} karakter.`)
      .regex(MASTER_CODE_PATTERN, 'Kode hanya boleh huruf, angka, titik, strip, atau garis bawah (tanpa spasi).')
  }

  if (meta.parent) {
    shape[meta.parent.field] = z.string({ required_error: 'Induk wajib dipilih.' }).min(1, 'Induk wajib dipilih.')
  }

  return z.object(shape)
}

/**
 * Rantai leluhur master (dari akar ke induk langsung), mis. kelurahan → [provinsi, kabupaten-kota, kecamatan].
 * Dipakai untuk dropdown berjenjang di form & filter.
 */
export function ancestorChain(meta: MasterMeta, all: MasterMeta[]): MasterMeta[] {
  const byKey = new Map(all.map((m) => [m.key, m]))
  const chain: MasterMeta[] = []
  let current = meta.parent ? byKey.get(meta.parent.entity) : undefined
  const seen = new Set<string>()
  while (current && !seen.has(current.key)) {
    seen.add(current.key)
    chain.unshift(current)
    current = current.parent ? byKey.get(current.parent.entity) : undefined
  }
  return chain
}
