/**
 * Ringkasan lonceng F-08 (kontrak PRD Fase 7 v2.0 §7 C → D) — SATU instance bersama untuk semua pemakai (topbar,
 * dashboard Jalur D). Polling 60 detik selama tab terlihat; berhenti saat pemakai terakhir dilepas. Galat jaringan tidak
 * dilempar: ringkasan terakhir dipertahankan (lonceng tidak boleh mengganggu halaman).
 *
 * Pemakaian: `const { ringkasan, total, muatUlang } = useRingkasanNotifikasi()` di dalam setup komponen.
 */
import { computed, getCurrentScope, onScopeDispose, readonly, ref, type ComputedRef, type DeepReadonly, type Ref } from 'vue'

import { notifikasiService } from '../services/notifikasi.service'
import type { RingkasanNotifikasi } from '../types'

export const INTERVAL_POLLING_MS = 60_000

const ringkasan = ref<RingkasanNotifikasi | null>(null)
let pemakai = 0
let timer: ReturnType<typeof setInterval> | null = null
let berjalan: Promise<void> | null = null

function terlihat(): boolean {
  return typeof document === 'undefined' || document.visibilityState !== 'hidden'
}

async function muatUlang(): Promise<void> {
  if (berjalan) return berjalan

  berjalan = (async () => {
    try {
      ringkasan.value = await notifikasiService.ringkasan()
    } catch {
      // Dipertahankan nilai terakhir; percobaan berikutnya pada siklus polling.
    } finally {
      berjalan = null
    }
  })()

  return berjalan
}

function saatTerlihat(): void {
  if (terlihat()) void muatUlang()
}

function mulai(): void {
  if (timer !== null) return
  void muatUlang()
  timer = setInterval(() => {
    if (terlihat()) void muatUlang()
  }, INTERVAL_POLLING_MS)
  if (typeof document !== 'undefined') document.addEventListener('visibilitychange', saatTerlihat)
}

function berhenti(): void {
  if (timer !== null) clearInterval(timer)
  timer = null
  if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', saatTerlihat)
}

/** Jumlah semua angka lonceng; `null` (sumber belum tersedia) dihitung 0. */
export function totalRingkasan(r: RingkasanNotifikasi | null): number {
  if (!r) return 0
  return r.notifikasi_belum_dibaca + r.butuh_approval + (r.berita_belum_dibaca ?? 0) + (r.tiket_belum_dibaca ?? 0)
}

export function useRingkasanNotifikasi(): {
  ringkasan: DeepReadonly<Ref<RingkasanNotifikasi | null>>
  total: ComputedRef<number>
  muatUlang: () => Promise<void>
} {
  pemakai += 1
  mulai()

  if (getCurrentScope()) {
    onScopeDispose(() => {
      pemakai = Math.max(0, pemakai - 1)
      if (pemakai === 0) berhenti()
    })
  }

  return { ringkasan: readonly(ringkasan), total: computed(() => totalRingkasan(ringkasan.value)), muatUlang }
}

/** Hanya untuk test & saat logout: lupakan ringkasan dan hentikan polling. */
export function resetRingkasanNotifikasi(): void {
  berhenti()
  pemakai = 0
  ringkasan.value = null
  berjalan = null
}
