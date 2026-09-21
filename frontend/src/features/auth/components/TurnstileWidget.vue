<script setup lang="ts">
/**
 * Widget Cloudflare Turnstile (A-03/A-11). Site key dari VITE_TURNSTILE_SITE_KEY.
 * Tanpa site key (lokal, backend auth.captchaDriver=mock) → fallback checkbox yang menghasilkan token mock,
 * supaya alur "captcha kosong → ditolak" tetap bisa diuji tanpa akun Cloudflare.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

declare global {
  interface Window {
    turnstile?: {
      render: (
        el: HTMLElement,
        opts: {
          sitekey: string
          callback: (token: string) => void
          'expired-callback'?: () => void
          'error-callback'?: () => void
          theme?: 'light' | 'dark' | 'auto'
          language?: string
        },
      ) => string
      reset: (id?: string) => void
      remove: (id?: string) => void
    }
  }
}

const emit = defineEmits<{
  verified: [token: string]
  expired: []
  error: []
}>()

const siteKey = (import.meta.env.VITE_TURNSTILE_SITE_KEY as string | undefined) ?? ''
const container = ref<HTMLDivElement | null>(null)
const widgetId = ref<string | null>(null)
const mockChecked = ref(false)
const loadFailed = ref(false)

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

function loadScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT_SRC}"]`)
    if (existing) {
      existing.addEventListener('load', () => resolve())
      existing.addEventListener('error', () => reject(new Error('turnstile load error')))
      return
    }
    const script = document.createElement('script')
    script.src = SCRIPT_SRC
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('turnstile load error'))
    document.head.appendChild(script)
  })
}

function reset(): void {
  if (siteKey && window.turnstile && widgetId.value) {
    window.turnstile.reset(widgetId.value)
  }
  mockChecked.value = false
}

defineExpose({ reset })

onMounted(async () => {
  if (!siteKey || !container.value) return
  try {
    await loadScript()
    if (!window.turnstile || !container.value) throw new Error('turnstile unavailable')
    widgetId.value = window.turnstile.render(container.value, {
      sitekey: siteKey,
      theme: 'light',
      language: 'id',
      callback: (token) => emit('verified', token),
      'expired-callback': () => emit('expired'),
      'error-callback': () => emit('error'),
    })
  } catch {
    loadFailed.value = true
    emit('error')
  }
})

onBeforeUnmount(() => {
  if (siteKey && window.turnstile && widgetId.value) {
    window.turnstile.remove(widgetId.value)
  }
})

function onMockChange(event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  mockChecked.value = checked
  if (checked) emit('verified', 'mock-dev-token')
  else emit('expired')
}
</script>

<template>
  <div>
    <div v-if="siteKey" ref="container" class="min-h-[65px]" data-testid="turnstile-container"></div>
    <p v-if="siteKey && loadFailed" class="mt-1 text-xs text-red-600">
      Widget captcha gagal dimuat. Periksa koneksi lalu muat ulang halaman.
    </p>

    <label
      v-else-if="!siteKey"
      class="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-600"
      data-testid="captcha-mock"
    >
      <input type="checkbox" :checked="mockChecked" class="h-4 w-4 accent-brand-primary" @change="onMockChange" />
      <span>Saya bukan robot <span class="text-xs text-slate-400">(mode pengembangan, Turnstile belum dikonfigurasi)</span></span>
    </label>
  </div>
</template>
