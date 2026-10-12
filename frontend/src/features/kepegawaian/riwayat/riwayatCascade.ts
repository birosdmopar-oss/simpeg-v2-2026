/**
 * Opsi isian rujukan master (`ref`) form riwayat lewat `useCascadeOptions` (re-export `@/shared/composables`): isian
 * ber-`dependsOn` membentuk rantai berjenjang (mis. tingkat → jenis hukdis, bidang → jurusan pendidikan, provinsi →
 * kabupaten/kota → kecamatan → kelurahan); isian ref tanpa induk = rantai satu level. Setiap level memuat
 * `GET master/{entity}/options?parent=<nilai induk>`.
 *
 * Metadata master lengkap (`GET master/meta`) hanya untuk role 1, jadi rantai dibangun dari FieldDef: composable cukup
 * membutuhkan key entity per level (`metaRujukan`). Satu induk hanya boleh punya satu anak (rantai lurus).
 */
import { ref } from 'vue'

import { useCascadeOptions } from '@/shared/composables/useCascadeOptions'

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

/** Rantai isian ref: akar (tanpa `dependsOn` ke isian ref lain) diikuti anak-anaknya berurutan. */
export function refChains(fields: readonly FieldDef[]): FieldDef[][] {
  const refs = fields.filter((f) => f.type === 'ref' && f.entity)
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
 * pilihan satu isian dan mengembalikan nama isian turunan yang harus dikosongkan. Gagal memuat → opsi kosong (form
 * tetap bisa dibuka; server memvalidasi).
 */
export function useRiwayatRefOptions(fields: readonly FieldDef[]) {
  const chains = refChains(fields).map((chain) => ({
    chain,
    cascade: useCascadeOptions(ref(chain.map((f) => metaRujukan(f.entity as string)))),
  }))
  const posisi = new Map<string, { c: number; i: number }>()
  chains.forEach(({ chain }, c) => chain.forEach((f, i) => posisi.set(f.name, { c, i })))

  function options(name: string): FieldOption[] {
    const p = posisi.get(name)
    const level = p ? chains[p.c]?.cascade.levels.value[p.i] : undefined
    return (level?.options ?? []).map((o) => ({ value: String(o.id), label: o.nama }))
  }

  async function init(values: Record<string, string>): Promise<void> {
    await Promise.all(chains.map(({ chain, cascade }) => cascade.init(chain.map((f) => values[f.name] ?? '')).catch(() => undefined)))
  }

  async function select(name: string, value: string): Promise<string[]> {
    const p = posisi.get(name)
    const entry = p ? chains[p.c] : undefined
    if (!p || !entry) return []
    const turunan = entry.chain.slice(p.i + 1).map((f) => f.name)
    await entry.cascade.select(p.i, value).catch(() => undefined)
    return turunan
  }

  return { options, init, select }
}
