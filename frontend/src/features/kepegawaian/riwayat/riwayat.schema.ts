/**
 * Skema Zod dinamis untuk form riwayat (ADR-026), dibangun dari FieldDef. Nilai form selalu string (input HTML);
 * angka/tanggal divalidasi formatnya, bukan diubah tipenya (konversi di riwayat.service). Field wajib → tidak boleh
 * kosong; field bersyarat (`showWhen`) hanya diperiksa wajibnya saat tampil; `requiredWhen` = wajib bila syaratnya terpenuhi. Validasi yang mengikat tetap di backend
 * (422 `errors.<kolom>`). Isian `multiselect` (nilai daftar, MAKE-018) tidak ikut skema ini — wajibnya (minimal satu)
 * diperiksa RiwayatFieldForm.
 */
import { z } from 'zod'

import { fieldVisible, fieldWajib, type FieldDef } from './riwayat.config'

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

export function buildRiwayatSchema(semua: readonly FieldDef[]) {
  const fields = semua.filter((f) => f.type !== 'multiselect')
  // Wajib bersyarat: wajib selama tampil (`required` + `showWhen`) atau `requiredWhen` (MAKE-018).
  const conditional = fields.filter((f) => (f.required && f.showWhen) || f.requiredWhen)
  return z.object(Object.fromEntries(fields.map((f) => [f.name, fieldSchema(f)]))).superRefine((values, ctx) => {
    const current = values as Record<string, string | undefined>
    for (const f of conditional) {
      if (fieldVisible(f, current) && fieldWajib(f, current) && String(current[f.name] ?? '').trim() === '') {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: [f.name], message: `${f.label} wajib diisi` })
      }
    }
  })
}
