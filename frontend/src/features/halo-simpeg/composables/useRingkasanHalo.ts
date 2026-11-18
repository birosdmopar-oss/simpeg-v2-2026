/**
 * Ringkasan helpdesk Halo Simpeg untuk badge menu (AC-E08.11, AC-E08.12; 8_2 §3a) — SATU instance bersama (pola lonceng
 * `useRingkasanNotifikasi`): polling `GET tiket/ringkasan` tiap 60 detik selama tab terlihat + muat ulang lembut tiap
 * pindah halaman (dipanggil `RedesignShell`). Berhenti saat pemakai terakhir dilepas.
 *
 * **Gerbang:** aktif hanya bila `ACTIVE_PHASE >= 8` **dan** role sesi ∈ 1–7 **dan** `!auth.mustChangePassword` (preseden
 * lonceng dan widget Vanya). `watch` atas syarat: begitu syarat tidak terpenuhi (logout, ganti role, wajib ganti password)
 * polling dihentikan dan angka dikosongkan lewat `resetHaloSimpeg()`; begitu terpenuhi lagi, polling dimulai.
 * Lonceng tetap memakai `tiket_belum_dibaca` ringkasan notifikasi (= `Ringkasan.total`) dan memuat ulang sendiri.
 */
import { computed, effectScope, getCurrentScope, onScopeDispose, watch, type ComputedRef, type EffectScope } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

import { FASE_HALO, HALO_ROLES, INTERVAL_RINGKASAN_MS } from '../constants'
import { haloState, muatRingkasan, resetHaloSimpeg } from '../stores/haloSimpeg'

let pemakai = 0
let timer: ReturnType<typeof setInterval> | null = null
/** Scope terlepas: watch gerbang tidak ikut mati saat shell pertama dilepas (shell dipasang ulang per route). */
let lingkup: EffectScope | null = null
let aktif = false

type Auth = ReturnType<typeof useAuthStore>
const bolehAktif = (auth: Auth): boolean =>
  ACTIVE_PHASE >= FASE_HALO && auth.role !== null && HALO_ROLES.includes(auth.role) && !auth.mustChangePassword

function terlihat(): boolean {
  return typeof document === 'undefined' || document.visibilityState !== 'hidden'
}

function saatTerlihat(): void {
  if (aktif && terlihat()) void muatRingkasan()
}

function mulaiPolling(): void {
  if (timer !== null) return
  aktif = true
  void muatRingkasan()
  timer = setInterval(() => {
    if (terlihat()) void muatRingkasan()
  }, INTERVAL_RINGKASAN_MS)
  if (typeof document !== 'undefined') document.addEventListener('visibilitychange', saatTerlihat)
}

function hentikanPolling(): void {
  aktif = false
  if (timer !== null) clearInterval(timer)
  timer = null
  if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', saatTerlihat)
}

export function useRingkasanHalo(): { badge: ComputedRef<number>; aktif: ComputedRef<boolean>; muatUlang: () => void } {
  const auth = useAuthStore()
  const syarat = computed(() => bolehAktif(auth))

  pemakai += 1
  if (lingkup === null) {
    lingkup = effectScope(true)
    lingkup.run(() => {
      watch(
        () => bolehAktif(auth),
        (boleh, sebelumnya) => {
          if (boleh) mulaiPolling()
          else {
            hentikanPolling()
            // Syarat berubah dari terpenuhi → tidak: kosongkan angka sesi itu.
            if (sebelumnya) resetHaloSimpeg()
          }
        },
        { immediate: true },
      )
    })
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      pemakai = Math.max(0, pemakai - 1)
      if (pemakai === 0) resetPolling()
    })
  }

  return {
    badge: computed(() => (syarat.value ? (haloState.ringkasan?.badge ?? 0) : 0)),
    aktif: syarat,
    /** Muat ulang lembut (pindah halaman); tanpa efek bila gerbang tertutup. */
    muatUlang: () => {
      if (syarat.value) void muatRingkasan()
    },
  }
}

/** Hentikan polling dan lepaskan watch (pemakai terakhir dilepas, atau test). Angka di store tidak dihapus. */
export function resetPolling(): void {
  hentikanPolling()
  lingkup?.stop()
  lingkup = null
  pemakai = 0
}
