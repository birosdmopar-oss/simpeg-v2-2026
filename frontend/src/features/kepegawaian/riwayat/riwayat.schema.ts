/**
 * Skema Zod dinamis untuk form riwayat (ADR-026), dibangun dari FieldDef. Nilai form selalu string (input HTML);
 * angka/tanggal divalidasi formatnya, bukan diubah tipenya (konversi di riwayat.service). Field wajib → tidak boleh
 * kosong; field bersyarat (`showWhen`) hanya diperiksa wajibnya saat tampil. Validasi yang mengikat tetap di backend
 * (422 `errors.<kolom>`).
 */
import { z } from 'zod'

import { fieldVisible, type FieldDef } from './riwayat.config'

function fieldSchema(f: FieldDef): z.ZodTypeAny {
  const max = f.max ?? (f.type === 'textarea' ? 1000 : 255)
  let base: z.ZodTypeAny
  if (f.type === 'number') base = z.string().trim().regex(/^-?\d*(\.\d+)?$/, `${f.label} harus berupa angka`).max(20, `${f.label} terlalu panjang`)
  else if (f.type === 'date') base = z.string().trim().regex(/^(\d{4}-\d{2}-\d{2})?$/, `${f.label} tidak valid`)
  else if (f.type === 'select') {
    const allowed = (f.options ?? []).map((o) => o.value)
    base = z.string().trim().refine((v) => v === '' || allowed.includes(v), `${f.label} tidak valid`)
  } else if (f.type === 'ref') base = z.string().trim().max(50, `${f.label} tidak valid`)
  else base = z.string().trim().max(max, `${f.label} maksimal ${max} karakter`)
  return f.required && !f.showWhen ? z.string().trim().min(1, `${f.label} wajib diisi`).pipe(base) : base
}

export function buildRiwayatSchema(fields: readonly FieldDef[]) {
  const conditional = fields.filter((f) => f.required && f.showWhen)
  return z.object(Object.fromEntries(fields.map((f) => [f.name, fieldSchema(f)]))).superRefine((values, ctx) => {
    const current = values as Record<string, string | undefined>
    for (const f of conditional) {
      if (fieldVisible(f, current) && String(current[f.name] ?? '').trim() === '') {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: [f.name], message: `${f.label} wajib diisi` })
      }
    }
  })
}
