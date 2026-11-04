<script setup lang="ts">
/**
 * Verifikasi QR kaki surat cuti (PRD Fase 6 v1.0f Q6-23, AC-02.8; pola slip D-14 / ADR-032): halaman publik tanpa login &
 * tanpa layout aplikasi; memanggil hanya `GET publik/surat-cuti/{token}` (tanpa jwt). Selama aktif memasang
 * `<meta name="robots" content="noindex, nofollow">`. Keadaan: memuat / "Dokumen Asli" (jenis surat, penanda tangan, tanggal
 * tanda tangan, status TTE "ditandatangani"/"belum" — tanpa NIP dan tanpa unduh PDF) / tidak ditemukan (pesan seragam) /
 * terlalu banyak permintaan (429). Satu-satunya tautan ke halaman berlogin = tombol "Masuk".
 */
import { BadgeCheck, CircleX, Clock, ShieldCheck } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteUpdate, useRoute } from 'vue-router'

import { isApiError } from '@/lib/axios'
import { UiButton } from '@/shared/ui'

import { suratCutiPublikService, type VerifikasiSuratCuti } from '../services/suratCutiPublik.service'

const route = useRoute()
const hasil = ref<VerifikasiSuratCuti | null>(null)
const keadaan = ref<'memuat' | 'asli' | 'tidak-ditemukan' | 'terlalu-banyak' | 'galat'>('memuat')
const pesan = ref('')

const TIDAK_DITEMUKAN = 'Dokumen tidak ditemukan atau tidak valid.'
let meta: HTMLMetaElement | null = null
/** Nomor permintaan terakhir: respons permintaan yang sudah digantikan token lain dibuang. */
let urutan = 0

async function verifikasi(token: string): Promise<void> {
  const saya = ++urutan
  keadaan.value = 'memuat'
  try {
    const data = await suratCutiPublikService.verifikasi(token)
    if (saya !== urutan) return
    hasil.value = data
    keadaan.value = 'asli'
  } catch (error) {
    if (saya !== urutan) return
    const status = isApiError(error) ? error.status : null
    if (status === 429) {
      keadaan.value = 'terlalu-banyak'
      pesan.value = isApiError(error) && error.message ? error.message : 'Terlalu banyak permintaan. Coba lagi beberapa saat lagi.'
    } else if (status !== null && status >= 400 && status < 500) {
      keadaan.value = 'tidak-ditemukan'
    } else {
      keadaan.value = 'galat'
      pesan.value = status === null ? 'Tidak dapat terhubung ke server. Periksa koneksi Anda.' : 'Terjadi kesalahan. Coba lagi.'
    }
  }
}

onMounted(() => {
  meta = document.createElement('meta')
  meta.name = 'robots'
  meta.content = 'noindex, nofollow'
  document.head.appendChild(meta)
  void verifikasi(String(route.params.token ?? ''))
})

// CR-082 (T5A-01): token lain pada rute yang sama (navigasi SPA) memuat ulang verifikasi; rute lain tidak memicu muat.
onBeforeRouteUpdate((tujuan, dari) => {
  const token = String(tujuan.params.token ?? '')
  if (token !== String(dari.params.token ?? '')) void verifikasi(token)
})

onBeforeUnmount(() => {
  meta?.remove()
  meta = null
})
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-primary to-[#0f2447] px-4 py-10">
    <div class="w-full max-w-md">
      <div class="mb-6 text-center text-white">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-white/10">
          <ShieldCheck class="h-6 w-6" aria-hidden="true" />
        </div>
        <h1 class="text-2xl font-semibold">Verifikasi Surat Cuti</h1>
        <p class="mt-1 text-sm text-white/80">SIMPEG v2 · Kementerian Pariwisata</p>
      </div>

      <section class="rounded-xl bg-white p-6 shadow-xl" aria-live="polite" data-testid="verifikasi-surat-cuti" :aria-busy="keadaan === 'memuat'">
        <p v-if="keadaan === 'memuat'" class="py-6 text-center text-slate-500">Memeriksa dokumen…</p>

        <div v-else-if="keadaan === 'asli' && hasil" data-testid="verifikasi-asli">
          <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-success-soft text-green-700">
              <BadgeCheck class="h-7 w-7" aria-hidden="true" />
            </span>
            <div>
              <h2 class="text-h5 font-semibold text-slate-900">Dokumen Asli</h2>
              <p class="text-body2 text-slate-500">Surat cuti ini diterbitkan oleh SIMPEG.</p>
            </div>
          </div>
          <dl class="mt-5 grid grid-cols-[8rem_minmax(0,1fr)] gap-x-3 gap-y-2 text-body1">
            <dt class="text-slate-500">Jenis surat</dt>
            <dd class="font-semibold text-slate-900" data-testid="verifikasi-jenis">{{ hasil.jenis_surat }}{{ hasil.jenis_cuti ? ` — ${hasil.jenis_cuti}` : '' }}</dd>
            <dt class="text-slate-500">Penanda tangan</dt>
            <dd class="text-slate-900" data-testid="verifikasi-penanda">{{ hasil.penanda_tangan ?? '-' }}</dd>
            <dt class="text-slate-500">Tanggal tanda tangan</dt>
            <dd class="text-slate-900">{{ hasil.tanggal_tanda_tangan ?? '-' }}</dd>
            <dt class="text-slate-500">Status</dt>
            <dd class="text-slate-900" data-testid="verifikasi-status-tte">
              {{ hasil.status_tte === 'ditandatangani' ? 'Ditandatangani elektronik' : 'Belum ditandatangani elektronik' }}
            </dd>
          </dl>
        </div>

        <div v-else-if="keadaan === 'tidak-ditemukan'" class="flex items-center gap-3" data-testid="verifikasi-tidak-ditemukan">
          <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-danger-soft text-red-700">
            <CircleX class="h-7 w-7" aria-hidden="true" />
          </span>
          <p class="text-body1 font-semibold text-slate-900">{{ TIDAK_DITEMUKAN }}</p>
        </div>

        <div v-else class="flex items-center gap-3" :data-testid="keadaan === 'terlalu-banyak' ? 'verifikasi-429' : 'verifikasi-galat'">
          <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-warning-soft text-amber-700">
            <Clock class="h-7 w-7" aria-hidden="true" />
          </span>
          <p class="text-body1 text-slate-900">{{ pesan }}</p>
        </div>

        <div class="mt-6 border-t border-slate-200 pt-4 text-center">
          <UiButton variant="secondary" appearance="outline" :to="{ name: 'login' }">Masuk</UiButton>
        </div>
      </section>
    </div>
  </main>
</template>
