/**
 * Skema Zod dinamis untuk form riwayat (ADR-026), dibangun dari FieldDef. Nilai form selalu string (input HTML);
 * angka/tanggal divalidasi formatnya, bukan diubah tipenya. Field wajib → tidak boleh kosong.
 * ASUMSI: batas panjang & format mengikuti tebakan konfigurasi; cocokkan dengan kontrak API B-07…B-18.
 */
import { z } from 'zod'

import type { FieldDef } from './riwayat.config'

function fieldSchema(f: FieldDef): z.ZodTypeAny {
  const max = f.max ?? (f.type === 'textarea' ? 1000 : 255)
  let base: z.ZodTypeAny
  if (f.type === 'number') base = z.string().trim().regex(/^\d*(\.\d+)?$/, `${f.label} harus berupa angka`).max(20, `${f.label} terlalu panjang`)
  else if (f.type === 'date') base = z.string().trim().regex(/^(\d{4}-\d{2}-\d{2})?$/, `${f.label} tidak valid`)
  else if (f.type === 'select') base = z.string().trim().refine((v) => v === '' || (f.options ?? []).includes(v), `${f.label} tidak valid`)
  else base = z.string().trim().max(max, `${f.label} maksimal ${max} karakter`)
  return f.required ? z.string().trim().min(1, `${f.label} wajib diisi`).pipe(base) : base
}

export function buildRiwayatSchema(fields: FieldDef[]) {
  return z.object(Object.fromEntries(fields.map((f) => [f.name, fieldSchema(f)])))
}
