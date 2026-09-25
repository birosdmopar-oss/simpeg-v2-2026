<script setup lang="ts">
/**
 * Permintaan lupa password (A-07, ISSUE-006 / MTC-007). Tamu saja, di balik VITE_PASSWORD_RESET_ENABLED.
 *
 * - Username/NIP + captcha Turnstile (aturan sama dengan login; backend memeriksa captcha sebelum rate limit).
 * - 200: form diganti pesan generik dari backend (tidak membocorkan apakah username terdaftar).
 * - 429: banner kuning berisi pesan backend; 422: captcha → banner, field → error di field.
 * - Hanya development: kalau backend mengembalikan token (auth.exposeResetTokenInResponse), tampil kotak tautan
 *   langsung ke halaman reset supaya MTC-006/007 bisa diuji tanpa kanal email.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { MailCheck } from 'lucide-vue-next'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'

import AuthCardLayout from '../components/AuthCardLayout.vue'
import TurnstileWidget from '../components/TurnstileWidget.vue'
import { forgotPasswordSchema } from '../schemas/password.schema'
import { authService } from '../services/auth.service'
import type { ForgotPasswordResponse } from '../types'

type BannerKind = 'rate-limit' | 'captcha' | 'network' | 'generic'

const submitting = ref(false)
const banner = ref<{ kind: BannerKind; message: string } | null>(null)
const result = ref<ForgotPasswordResponse | null>(null)
const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null)

const { defineField, handleSubmit, errors, setFieldValue, setFieldError, submitCount } = useForm({
  validationSchema: toTypedSchema(forgotPasswordSchema),
  initialValues: { username: '', captcha_token: '' },
})

const [username] = defineField('username')

const bannerClass = computed(() => {
  switch (banner.value?.kind) {
    case 'rate-limit':
      return 'border-amber-300 bg-amber-50 text-amber-800'
    case 'network':
      return 'border-slate-300 bg-slate-50 text-slate-700'
    default:
      return 'border-red-300 bg-red-50 text-red-700'
  }
})

/** Token hanya dikembalikan backend di development; kotak ini tidak pernah tampil di build production. */
const devToken = computed(() => (import.meta.env.DEV && result.value?.token ? result.value.token : null))

function onCaptchaVerified(token: string): void {
  setFieldValue('captcha_token', token)
  if (banner.value?.kind === 'captcha') banner.value = null
}

function onCaptchaExpired(): void {
  setFieldValue('captcha_token', '')
}

const onSubmit = handleSubmit(async (values) => {
  banner.value = null
  submitting.value = true
  try {
    result.value = await authService.forgotPassword(values)
  } catch (err) {
    // Token captcha sekali pakai: selalu minta verifikasi ulang setelah gagal.
    setFieldValue('captcha_token', '')
    turnstile.value?.reset()

    if (!isApiError(err)) {
      banner.value = { kind: 'generic', message: 'Terjadi kesalahan. Silakan coba lagi.' }
      return
    }

    if (err.status === 429) {
      banner.value = { kind: 'rate-limit', message: err.message }
    } else if (err.status === 422 && err.errors?.captcha_token) {
      banner.value = { kind: 'captcha', message: err.errors.captcha_token[0] ?? 'Verifikasi captcha gagal.' }
    } else if (err.status === 422 && err.errors?.username?.[0]) {
      setFieldError('username', err.errors.username[0])
    } else if (err.isNetworkError) {
      banner.value = { kind: 'network', message: err.message }
    } else {
      banner.value = { kind: 'generic', message: err.message }
    }
  } finally {
    submitting.value = false
  }
})
</script>

<template>
  <AuthCardLayout>
    <div v-if="result" class="space-y-4 rounded-xl bg-white p-6 shadow-xl" data-testid="forgot-result">
      <div class="flex items-start gap-3">
        <MailCheck class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
        <div>
          <h2 class="text-lg font-semibold text-slate-800">Permintaan diterima</h2>
          <p class="mt-1 text-sm text-slate-600" role="status" data-testid="forgot-message">{{ result.message }}</p>
          <p class="mt-2 text-xs text-slate-500">
            Tautan reset hanya berlaku sementara dan hanya bisa dipakai sekali. Jika tidak menerima tautan, hubungi
            Admin Kepegawaian satker Anda.
          </p>
        </div>
      </div>

      <div
        v-if="devToken"
        class="rounded-md border border-dashed border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-900"
        data-testid="forgot-dev-token"
      >
        <p class="font-semibold">Mode pengembangan</p>
        <p class="mt-1">Kanal pengiriman belum aktif; token dikembalikan backend untuk pengujian.</p>
        <RouterLink
          :to="{ name: 'reset-password', query: { token: devToken } }"
          class="mt-1 inline-block font-medium text-brand-primary underline"
          data-testid="forgot-dev-link"
        >
          Buka halaman reset password
        </RouterLink>
      </div>

      <RouterLink
        :to="{ name: 'login' }"
        class="block text-center text-sm font-medium text-brand-primary hover:underline"
        data-testid="forgot-back-login"
      >
        Kembali ke halaman masuk
      </RouterLink>
    </div>

    <form
      v-else
      class="space-y-4 rounded-xl bg-white p-6 shadow-xl"
      novalidate
      data-testid="forgot-form"
      @submit="onSubmit"
    >
      <div>
        <h2 class="text-lg font-semibold text-slate-800">Lupa Password</h2>
        <p class="mt-1 text-sm text-slate-500">
          Masukkan username/NIP akun Anda. Jika terdaftar, tautan untuk membuat password baru akan dikirimkan.
        </p>
      </div>

      <div
        v-if="banner"
        class="rounded-md border px-3 py-2 text-sm"
        :class="bannerClass"
        role="alert"
        :data-testid="`forgot-error-${banner.kind}`"
      >
        {{ banner.message }}
      </div>

      <FormField
        v-model="username"
        name="username"
        label="Username / NIP"
        placeholder="Masukkan NIP"
        autocomplete="username"
        inputmode="numeric"
        required
        :error="errors.username"
      />

      <div class="space-y-1">
        <TurnstileWidget
          ref="turnstile"
          @verified="onCaptchaVerified"
          @expired="onCaptchaExpired"
          @error="onCaptchaExpired"
        />
        <p v-if="submitCount > 0 && errors.captcha_token" class="text-xs text-red-600" role="alert">
          {{ errors.captcha_token }}
        </p>
      </div>

      <button
        type="submit"
        class="flex w-full items-center justify-center rounded-md bg-brand-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="submitting"
        data-testid="forgot-submit"
      >
        {{ submitting ? 'Memproses...' : 'Kirim Tautan Reset' }}
      </button>

      <RouterLink
        :to="{ name: 'login' }"
        class="block text-center text-sm font-medium text-brand-primary hover:underline"
      >
        Kembali ke halaman masuk
      </RouterLink>
    </form>
  </AuthCardLayout>
</template>
