/**
 * Dropdown berjenjang (mis. provinsi → kabupaten/kota → kecamatan → kelurahan) untuk form & filter master.
 * Setiap level memuat options (entri aktif) dari induk yang dipilih di level sebelumnya. Composable hanya mengelola
 * state pilihan; data & urutan sepenuhnya dari backend (ADR-025).
 */
import { ref, type Ref } from 'vue'

import { masterService } from '../services/master.service'
import type { MasterMeta, MasterOption } from '../types'

export interface CascadeLevel {
  meta: MasterMeta
  options: MasterOption[]
  value: string
  loading: boolean
}

export function useCascadeOptions(chain: Ref<MasterMeta[]>) {
  const levels = ref<CascadeLevel[]>([])

  async function loadLevel(index: number): Promise<void> {
    const level = levels.value[index]
    if (!level) return
    const parentValue = index === 0 ? null : (levels.value[index - 1]?.value ?? '')
    if (index > 0 && !parentValue) {
      level.options = []
      return
    }
    level.loading = true
    try {
      level.options = await masterService.options(level.meta.key, parentValue)
    } finally {
      level.loading = false
    }
  }

  /** Bangun ulang level dari chain; `path` = nilai terpilih per level (akar → induk langsung). */
  async function init(path: string[] = []): Promise<void> {
    levels.value = chain.value.map((meta, i) => ({ meta, options: [], value: path[i] ?? '', loading: false }))
    for (let i = 0; i < levels.value.length; i++) {
      await loadLevel(i)
    }
  }

  /** Ganti pilihan satu level: level di bawahnya dikosongkan lalu dimuat ulang. */
  async function select(index: number, value: string): Promise<void> {
    const level = levels.value[index]
    if (!level) return
    level.value = value
    for (let i = index + 1; i < levels.value.length; i++) {
      const next = levels.value[i]
      if (next) {
        next.value = ''
        next.options = []
      }
    }
    if (index + 1 < levels.value.length) await loadLevel(index + 1)
  }

  /** Nilai level terakhir (induk langsung), '' kalau belum lengkap. */
  function leafValue(): string {
    return levels.value[levels.value.length - 1]?.value ?? ''
  }

  return { levels, init, select, leafValue }
}

/**
 * Susun path leluhur dari id induk langsung dengan menelusuri ke atas (GET detail tiap level).
 * Contoh kelurahan: id kecamatan → [id_provinsi, id_kabupaten_kota, id_kecamatan].
 */
export async function resolveAncestorPath(chain: MasterMeta[], directParentId: string): Promise<string[]> {
  const path: string[] = new Array<string>(chain.length).fill('')
  let currentId = directParentId
  for (let i = chain.length - 1; i >= 0 && currentId; i--) {
    const meta = chain[i]
    if (!meta) break
    path[i] = currentId
    if (i === 0 || !meta.parent) break
    const row = await masterService.get(meta.key, currentId)
    currentId = String(row[meta.parent.field] ?? '')
  }
  return path
}
