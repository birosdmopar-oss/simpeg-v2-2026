/**
 * Registry tab riwayat Detail Pegawai: memuat semua `jenis/<slug>.ts` lewat `import.meta.glob` (eager). Menambah
 * jenis = menambah satu berkas; tidak ada daftar terpusat yang perlu diedit (pemilik loader: WS-1).
 * Nama berkas wajib sama dengan `jenis` di dalamnya dan merupakan slug engine/non-engine yang beku.
 */
import { JENIS_ENGINE, JENIS_NON_ENGINE, JENIS_USULAN, type JenisTab, type RiwayatTabDescriptor } from '../types'

import type { RiwayatJenisConfig } from './riwayat.config'

const modules = import.meta.glob<{ default: RiwayatJenisConfig }>('./jenis/*.ts', { eager: true })

const KNOWN: readonly string[] = [...JENIS_ENGINE, ...JENIS_NON_ENGINE]

function build(): Record<string, RiwayatJenisConfig> {
  const registry: Record<string, RiwayatJenisConfig> = {}
  for (const [path, mod] of Object.entries(modules)) {
    const slug = path.replace(/^.*\/([^/]+)\.ts$/, '$1')
    const config = mod.default
    if (config.jenis !== slug) throw new Error(`riwayat/jenis/${slug}.ts berisi jenis "${config.jenis}" (harus sama dengan nama berkas)`)
    if (!KNOWN.includes(slug)) throw new Error(`riwayat/jenis/${slug}.ts: slug tidak ada di daftar slug beku`)
    if ((JENIS_USULAN as readonly string[]).includes(slug)) throw new Error(`riwayat/jenis/${slug}.ts: ${slug} adalah usulan mandiri, bukan tab`)
    registry[slug] = config
  }
  return registry
}

export const RIWAYAT_REGISTRY: Readonly<Record<string, RiwayatJenisConfig>> = build()

export function configFor(jenis: JenisTab | string): RiwayatJenisConfig | undefined {
  return RIWAYAT_REGISTRY[jenis]
}

/** Pasangan descriptor (urutan backend) × konfigurasi; hanya tab `can_view` yang punya berkas registry. */
export function tabsFromDescriptors(tabs: RiwayatTabDescriptor[]): Array<{ descriptor: RiwayatTabDescriptor; config: RiwayatJenisConfig }> {
  return tabs.flatMap((descriptor) => {
    if (!descriptor.can_view) return []
    const config = configFor(descriptor.jenis)
    if (!config) {
      if (import.meta.env.DEV) console.warn(`[riwayat] tab "${descriptor.jenis}" dari descriptor dibuang: belum ada berkas riwayat/jenis/${descriptor.jenis}.ts`)
      return []
    }
    return [{ descriptor, config }]
  })
}
