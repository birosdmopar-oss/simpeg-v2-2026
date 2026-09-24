/**
 * Skema Zod form master generik (ADR-026), dibangun dari metadata master (GET /master/meta).
 * Mengikuti rules backend BaseMasterController::rules() + MasterField::validationRules(); keunikan & induk aktif
 * tetap divalidasi backend (422 → field).
 */
import { z } from 'zod'

import { utf8ByteLength } from '@/shared/utils/byteLength'

import type { MasterFieldMeta, MasterMeta, MasterRow } from '../types'

/** Sama dengan regex backend: huruf/angka/titik/strip/garis bawah, tanpa spasi. */
export const MASTER_CODE_PATTERN = /^[A-Za-z0-9._-]+$/

/** Batas bawaan field `html` bila meta tidak menyebut `max_bytes` (SPEC DBV-002 E3: 1.000.000 byte). */
export const MASTER_HTML_MAX_BYTES = 1_000_000

function fieldSchema(field: MasterFieldMeta): z.ZodTypeAny {
  const label = field.label

  if (field.type === 'select') {
    const values = (field.options ?? []).map((o) => o.value)
    const base = z
      .string({ required_error: `${label} wajib dipilih.` })
      .refine((v) => values.includes(v), { message: field.required ? `${label} wajib dipilih.` : `${label} tidak valid.` })
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  if (field.type === 'int' || field.type === 'decimal') {
    // Nilai form selalu string: divalidasi sebagai teks agar '' tidak ter-coerce jadi 0.
    const pattern = field.type === 'int' ? /^\d+$/ : /^-?\d+(\.\d+)?$/
    const message = field.type === 'int' ? `${label} harus bilangan bulat (tanpa desimal).` : `${label} harus angka.`
    const base = z.string().trim().min(1, `${label} wajib diisi.`).regex(pattern, message)
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  if (field.type === 'date') {
    const base = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, `${label} harus tanggal (YYYY-MM-DD).`)
    return field.required ? base : z.union([z.literal(''), base]).optional()
  }

  // text, textarea, html. Batas byte (TINYTEXT/HTML) dihitung UTF-8 seperti strlen() rule backend max_byte_length.
  const maxBytes = field.max_bytes ?? (field.type === 'html' ? MASTER_HTML_MAX_BYTES : null)
  const text = z.string({ required_error: `${label} wajib diisi.` }).trim()
  const base = field.required ? text.min(1, `${label} wajib diisi.`) : text
  const limited: z.ZodTypeAny =
    maxBytes === null
      ? base
      : base.refine((v) => utf8ByteLength(v) <= maxBytes, {
          message: `${label} maksimal ${maxBytes.toLocaleString('id-ID')} byte.`,
        })
  return field.required ? limited : limited.optional()
}

/**
 * Field yang tidak ikut terkirim di baris list admin (opsi backend `listExclude`, mis. isi artikel FAQ) — saat edit,
 * nilainya harus diambil dulu dari detail (GET master/{entity}/{id}) agar tidak terkirim kosong.
 */
export function fieldsMissingFromRow(meta: MasterMeta, row: MasterRow | null): MasterFieldMeta[] {
  if (row === null) return []
  return meta.fields.filter((field) => !Object.prototype.hasOwnProperty.call(row, field.name))
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

  // Kode hanya diinput saat tambah dan hanya untuk master ber-PK string (AUTO_INCREMENT diberikan database).
  if (!isEdit && !meta.auto_increment) {
    const code = z.string({ required_error: 'Kode wajib diisi.' }).trim().min(1, 'Kode wajib diisi.')
    shape[meta.primary_key] =
      meta.id_digits !== null
        ? // Kode wilayah legacy: tepat N digit angka tanpa titik (sama dengan rules backend).
          code.regex(new RegExp(`^[0-9]{${meta.id_digits}}$`), `Kode harus tepat ${meta.id_digits} digit angka.`)
        : code
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
