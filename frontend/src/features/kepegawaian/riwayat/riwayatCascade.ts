/**
 * Opsi isian rujukan master (`ref`) form riwayat lewat `useCascadeOptions` (re-export `@/shared/composables`): isian
 * ber-`dependsOn` membentuk rantai berjenjang (mis. tingkat → jenis hukdis, bidang → jurusan pendidikan, provinsi →
 * kabupaten/kota → kecamatan → kelurahan); isian ref tanpa induk = rantai satu level. Setiap level memuat
 * `GET master/{entity}/options?parent=<nilai induk>`.
 *
 * Metadata master lengkap (`GET master/meta`) hanya untuk role 1, jadi rantai dibangun dari FieldDef: composable cukup
 * membutuhkan key entity per level (`metaRujukan`). Satu induk hanya boleh punya satu anak (rantai lurus).
 *
 * Isian ref ber-`optionsParent` (induk tetap) atau `optionsFilter` (filter dari nilai isian lain, mis. diklat per jenis)
 * dimuat sendiri lewat `masterOptions` dan dimuat ulang saat isian pemicunya berubah (`pemicu()`). `extraOptions`
 * ditambahkan di akhir daftar (mis. LAIN-LAIN wilayah yang tidak ikut `options`). Isian teks ber-`suggestEntity` memuat
 * saran nama master (`saran()`).
 */
import { reactive, ref } from 'vue'

import { useCascadeOptions } from '@/shared/composables/useCascadeOptions'
import { masterOptions } from '@/shared/services/masterOptions'

import type { FieldDef, FieldOption } from './riwayat.config'

type MetaRujukan = Parameters<typeof useCascadeOptions>[0]['value'][number]

/** Metadata minimal satu level cascade: hanya `key` (entity) yang dipakai `useCascadeOptions`. */
export function metaRujukan(entity: string): MetaRujukan {
  return {
    key: entity,
    label: entity,
    primary_key: '',
    id_max_length: 50,
    id_digits: null,
    auto_increment: true,
    name_field: '',
    name_label: '',
    name_max_length: 0,
    has_order: false,
    has_status: false,
    parent: null,
    fields: [],
  }
}

const mandiri = (f: FieldDef): boolean => f.optionsParent !== undefined || f.optionsFilter !== undefined

/** Rantai isian ref: akar (tanpa `dependsOn` ke isian ref lain) diikuti anak-anaknya berurutan. */
export function refChains(fields: readonly FieldDef[]): FieldDef[][] {
  const refs = fields.filter((f) => f.type === 'ref' && f.entity && !mandiri(f))
  const names = new Set(refs.map((f) => f.name))
  return refs
    .filter((f) => !f.dependsOn || !names.has(f.dependsOn))
    .map((root) => {
      const chain = [root]
      for (let child = refs.find((f) => f.dependsOn === root.name); child; child = refs.find((f) => f.dependsOn === chain.at(-1)?.name)) {
        chain.push(child)
      }
      return chain
    })
}

/**
 * State opsi semua isian ref satu form. `init(values)` memuat ulang dari nilai awal; `select(name, value)` mengganti
 * pilihan satu isian berjenjang dan mengembalikan nama isian turunan yang harus dikosongkan; `pemicu(name, values)`
 * memuat ulang isian ber-`optionsFilter` dari isian itu dan mengembalikan namanya (dikosongkan). Gagal memuat → opsi
 * kosong (form tetap bisa dibuka; server memvalidasi).
 */
export function useRiwayatRefOptions(fields: readonly FieldDef[]) {
  const chains = refChains(fields).map((chain) => ({
    chain,
    cascade: useCascadeOptions(ref(chain.map((f) => metaRujukan(f.entity as string)))),
  }))
  const posisi = new Map<string, { c: number; i: number }>()
  chains.forEach(({ chain }, c) => chain.forEach((f, i) => posisi.set(f.name, { c, i })))

  const sendiri = fields.filter((f) => f.type === 'ref' && f.entity && mandiri(f))
  const opsiSendiri = reactive<Record<string, FieldOption[]>>({})
  const saranTeks = reactive<Record<string, string[]>>({})

  const extra = (name: string): FieldOption[] => [...(fields.find((f) => f.name === name)?.extraOptions ?? [])]

  function options(name: string): FieldOption[] {
    if (name in opsiSendiri) return [...(opsiSendiri[name] ?? []), ...extra(name)]
    const p = posisi.get(name)
    const level = p ? chains[p.c]?.cascade.levels.value[p.i] : undefined
    return [...(level?.options ?? []).map((o) => ({ value: String(o.id), label: o.nama })), ...extra(name)]
  }

  /** Token request terakhir per isian: respons yang lebih lama dari request terbaru diabaikan (tidak menimpa). */
  const token = new Map<string, number>()

  async function muatSendiri(f: FieldDef, values: Record<string, string>): Promise<void> {
    const t = (token.get(f.name) ?? 0) + 1
    token.set(f.name, t)
    opsiSendiri[f.name] = []
    const nilaiFilter = f.optionsFilter ? (values[f.optionsFilter.field] ?? '') : ''
    if (f.optionsFilter && nilaiFilter === '') return
    let hasil: FieldOption[] = []
    try {
      const filters = f.optionsFilter ? { [f.optionsFilter.param]: nilaiFilter } : undefined
      hasil = (await masterOptions(f.entity as string, f.optionsParent ?? null, filters)).map((o) => ({ value: String(o.id), label: o.nama }))
    } catch {
      hasil = []
    }
    if (token.get(f.name) === t) opsiSendiri[f.name] = hasil
  }

  async function init(values: Record<string, string>): Promise<void> {
    for (const f of sendiri) opsiSendiri[f.name] = []
    await Promise.all([
      ...chains.map(({ chain, cascade }) => cascade.init(chain.map((f) => values[f.name] ?? '')).catch(() => undefined)),
      ...sendiri.map((f) => muatSendiri(f, values)),
      ...fields
        .filter((f) => f.suggestEntity)
        .map(async (f) => {
          try {
            saranTeks[f.name] = (await masterOptions(f.suggestEntity as string)).map((o) => o.nama)
          } catch {
            saranTeks[f.name] = []
          }
        }),
    ])
  }

  async function select(name: string, value: string): Promise<string[]> {
    const p = posisi.get(name)
    const entry = p ? chains[p.c] : undefined
    if (!p || !entry) return []
    const turunan = entry.chain.slice(p.i + 1).map((f) => f.name)
    await entry.cascade.select(p.i, value).catch(() => undefined)
    return turunan
  }

  /**
   * Isian ber-`optionsFilter` dari isian $name: opsinya langsung dikosongkan lalu dimuat ulang (tanpa ditunggu); nama
   * isiannya dikembalikan SINKRON supaya pemanggil mengosongkan nilainya sebelum opsi baru datang.
   */
  function pemicu(name: string, values: Record<string, string>): string[] {
    const terpicu = sendiri.filter((f) => f.optionsFilter?.field === name)
    for (const f of terpicu) void muatSendiri(f, values)
    return terpicu.map((f) => f.name)
  }

  const saran = (name: string): string[] => saranTeks[name] ?? []

  return { options, init, select, pemicu, saran }
}
