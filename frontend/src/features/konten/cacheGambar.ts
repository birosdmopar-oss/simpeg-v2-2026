/**
 * Cache object URL gambar konten per path selama komponen pemakai (carousel banner / dialog popup) hidup (CR-117 lanjutan, T-08).
 * Endpoint gambar sengaja `Cache-Control: private, no-store` (privasi), jadi tanpa cache ini setiap pergantian slide mengunduh ulang
 * berkas (bisa 5 MB). Cache hidup di komponen penyedia: `sediakanCacheGambar()` dipanggil di setup-nya, `GambarKonten` anak
 * memakainya lewat inject, dan semua object URL di-revoke saat penyedia dilepas. Tanpa penyedia (mis. kolom gambar di tabel kelola)
 * `GambarKonten` tetap memuat sendiri seperti sebelumnya.
 */
import { type InjectionKey, inject, onBeforeUnmount, provide } from 'vue'

import { kontenService } from './services/konten.service'

export interface CacheGambar {
  /** Object URL gambar `path`; diunduh sekali per path (permintaan yang sedang jalan dipakai bersama). */
  ambil(path: string): Promise<string>
  /** Revoke semua object URL dan tolak pemuatan yang belum selesai. */
  lepasSemua(): void
}

const KUNCI: InjectionKey<CacheGambar> = Symbol('cacheGambarKonten')

export function buatCacheGambar(muat: (path: string) => Promise<Blob> = (p) => kontenService.gambarBlob(p)): CacheGambar {
  const peta = new Map<string, Promise<string>>()
  let dilepas = false

  return {
    ambil(path) {
      const ada = peta.get(path)
      if (ada) return ada

      const p = muat(path).then((blob) => {
        const url = URL.createObjectURL(blob)
        if (dilepas) {
          URL.revokeObjectURL(url) // penyedia sudah dilepas saat unduhan selesai
          throw new Error('cache gambar dilepas')
        }

        return url
      })
      // Gagal → buang dari cache supaya percobaan berikutnya mengunduh lagi.
      p.catch(() => {
        if (peta.get(path) === p) peta.delete(path)
      })
      peta.set(path, p)

      return p
    },
    lepasSemua() {
      dilepas = true
      for (const p of peta.values()) void p.then((url) => URL.revokeObjectURL(url), () => undefined)
      peta.clear()
    },
  }
}

/** Dipanggil di setup komponen penyedia (carousel/dialog): membuat cache, membagikannya ke anak, dan melepasnya saat unmount. */
export function sediakanCacheGambar(): CacheGambar {
  const cache = buatCacheGambar()
  provide(KUNCI, cache)
  onBeforeUnmount(() => cache.lepasSemua())

  return cache
}

export function pakaiCacheGambar(): CacheGambar | null {
  return inject(KUNCI, null)
}
