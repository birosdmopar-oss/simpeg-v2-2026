/**
 * Skema Zod form master generik (ADR-026), dibangun dari metadata master (GET /master/meta).
 * Mengikuti rules backend BaseMasterController::rules() + MasterField::validationRules();
 * keunikan, induk aktif, dan aturan khusus (radius ≥10 m, overlap tanggal) tetap divalidasi backend (422 → field).
 */
import { z } from 'zod'

import type { MasterFieldMeta, MasterMeta, WebConfigType } from '../types'

/** Sama dengan regex backend: huruf/angka/titik/strip/garis bawah, tanpa spasi. */
export const MASTER_CODE_PATTERN = /^[A-Za-z0-9._-]+$/

function fieldSchema(field: MasterFieldMeta): z.ZodTypeAny {
  const label = field.label

  if (field.type === 'select') {
    const values = (field.options ?? []).map((o) => o.value)
    const base = z.string().refine((v) => values.includes(v), { message: `${label} tidak valid.` })
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  if (field.type === 'int' || field.type === 'decimal') {
    // Nilai form selalu string: divalidasi sebagai teks agar '' tidak ter-coerce jadi 0.
    // Pola mengikuti rules backend: is_natural (bilangan bulat tak-negatif) dan decimal (boleh negatif,
    // dipakai latitude/longitude).
    const pattern = field.type === 'int' ? /^\d+$/ : /^-?\d+(\.\d+)?$/
    const message = field.type === 'int' ? `${label} harus bilangan bulat (tanpa desimal).` : `${label} harus angka.`
    const base = z.string().trim().min(1, `${label} wajib diisi.`).regex(pattern, message)
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  if (field.type === 'date') {
    const base = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, `${label} harus tanggal (YYYY-MM-DD).`)
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  const text = z.string().trim()
  return field.required ? text.min(1, `${label} wajib diisi.`) : text.optional()
}

export function buildMasterSchema(meta: MasterMeta, isEdit: boolean) {
  const shape: Record<string, z.ZodTypeAny> = {
    [meta.name_field]: z
      .string({ required_error: `${meta.name_label} wajib diisi.` })
      .trim()
      .min(1, `${meta.name_label} wajib diisi.`)
      .max(meta.name_max_length, `${meta.name_label} maksimal ${meta.name_max_length} karakter.`),
  }

  if (meta.has_order) {
    shape.order = z
      .union([z.literal(''), z.coerce.number().int('Urutan harus bilangan bulat.').min(1, 'Urutan minimal 1.')])
      .optional()
  }

  // Kode hanya diinput saat tambah dan hanya untuk master ber-PK string.
  if (!isEdit && !meta.auto_increment) {
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

  for (const field of meta.fields) {
    shape[field.name] = fieldSchema(field)
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

// ------------------------------------------------------------------
// G-08 Hari Libur
// ------------------------------------------------------------------

/** tgl_mulai <= tgl_akhir (DoD G-08); larangan overlap hanya bisa dicek backend. */
export const hariLiburSchema = z
  .object({
    id_jenis_libur: z.string().min(1, 'Jenis hari libur wajib dipilih.'),
    nama: z.string().trim().min(1, 'Nama hari libur wajib diisi.').max(150, 'Nama maksimal 150 karakter.'),
    tgl_mulai: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal mulai wajib diisi.'),
    tgl_akhir: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal akhir wajib diisi.'),
    keterangan: z.string().max(255, 'Keterangan maksimal 255 karakter.').optional(),
  })
  .refine((v) => v.tgl_akhir >= v.tgl_mulai, {
    path: ['tgl_akhir'],
    message: 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
  })

// ------------------------------------------------------------------
// G-09 Web Config
// ------------------------------------------------------------------

/** Pola nilai per tipe — sama dengan WebConfigService backend, dipakai agar error muncul sebelum submit. */
export const WEB_CONFIG_VALUE_RULES: Record<WebConfigType, { pattern?: RegExp; message: string }> = {
  integer: { pattern: /^-?\d+$/, message: 'Nilai harus bilangan bulat (contoh: 30000).' },
  decimal: { pattern: /^-?\d+(\.\d+)?$/, message: 'Nilai harus angka desimal dengan titik (contoh: 0.5).' },
  time: { pattern: /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/, message: 'Nilai harus jam HH:MM (contoh: 07:30).' },
  boolean: { pattern: /^[01]$/, message: 'Nilai harus 0 atau 1.' },
  string: { message: 'Nilai wajib diisi.' },
  text: { message: 'Nilai wajib diisi.' },
}

export const webConfigSchema = z
  .object({
    config_name: z
      .string()
      .trim()
      .min(1, 'Nama key wajib diisi.')
      .max(100, 'Nama key maksimal 100 karakter.')
      .regex(/^[A-Za-z0-9._]+$/, 'Nama key hanya boleh huruf, angka, titik, dan garis bawah (tanpa spasi).'),
    tipe_data: z.enum(['string', 'text', 'integer', 'decimal', 'time', 'boolean']),
    config_value: z.string().min(1, 'Nilai wajib diisi.'),
    keterangan: z.string().max(255, 'Keterangan maksimal 255 karakter.').optional(),
  })
  .superRefine((v, ctx) => {
    const rule = WEB_CONFIG_VALUE_RULES[v.tipe_data]
    if (rule.pattern && !rule.pattern.test(v.config_value)) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['config_value'], message: rule.message })
    }
  })
