<script setup lang="ts">
/**
 * Permintaan lupa password (A-07, ISSUE-006 / MTC-007). Tamu saja, di balik VITE_PASSWORD_RESET_ENABLED.
 *
 * - Username/NIP + captcha Turnstile (aturan sama dengan login; backend memeriksa captcha sebelum rate limit).
 * - 200: form diganti pesan generik dari backend (tidak membocorkan apakah username terdaftar).
 * - 429: banner kuning berisi pesan backend; 422: captcha → banner, field → error di field.
 * - Hanya development: kalau backend mengembalikan token (auth.exposeResetTokenInResponse), tampil kotak tautan
 *   langsung ke halaman reset supaya MTC-006/007 bisa diuji tanpa kanal email.
 * - CR-190: tampilan Figma SIMPEG V5 A-11 (Lupa Password); teks, field, dan perilaku tetap seperti `main`.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { ArrowLeft, CircleAlert } from 'lucide-vue-next'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import UiButton from '@/shared/ui/UiButton.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'

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

/**
 * CR-190: nada Alert V5 per jenis banner: batas permintaan (429) = warning; gangguan jaringan (Figma 2130-65378),
 * captcha, dan galat lain = error. Role tetap role="alert" untuk semua banner.
 */
const bannerTone = computed<'danger' | 'warning'>(() => (banner.value?.kind === 'rate-limit' ? 'warning' : 'danger'))

/** CR-190: tautan "Kembali ke halaman masuk" gaya V5 (warna tautan + panah). */
const LINK_KEMBALI =
  'mx-auto inline-flex items-center gap-1 rounded-v5-sm text-[13px] font-medium leading-5 text-v5-link hover:underline focus-visible:ring-2 focus-visible:ring-v5-blue focus-visible:ring-offset-2 focus-visible:ring-offset-white focus-visible:outline-none'

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
    // Token captcha sekali pakai: selalu minta verifikasi ulang setelah gagal. Tanpa validasi ulang (false):
    // validasi skema berjalan async dan akan menimpa error field dari backend (setFieldError di bawah) dengan hasil
    // validasi klien yang lolos, sehingga pesan 422 errors.username hilang.
    setFieldValue('captcha_token', '', false)
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
    <div v-if="result" class="flex flex-col gap-4" data-testid="forgot-result">
      <h2 class="mb-2 text-[17px] font-semibold leading-[26px] tracking-[-0.25px] text-v5-text">Permintaan diterima</h2>

      <!-- CR-190: pola frame Tautan Terkirim (2234-73379): Alert sukses (role="status" di root UiNotice, satu live
           region) berisi pesan backend + catatan masa berlaku. -->
      <UiNotice appearance="v5" tone="success">
        <p data-testid="forgot-message">{{ result.message }}</p>
        <p class="mt-0.5 text-[13px] font-normal leading-5 tracking-[-0.05px] text-v5-text">
          Tautan reset hanya berlaku sementara dan hanya bisa dipakai sekali. Jika tidak menerima tautan, hubungi
          Admin Kepegawaian satker Anda.
        </p>
      </UiNotice>

      <div
        v-if="devToken"
        class="rounded-v5-md border border-dashed border-v5-warning bg-v5-warning-subtle px-3 py-2 text-[13px] leading-5 text-v5-warning-strong"
        data-testid="forgot-dev-token"
      >
        <p class="font-semibold">Mode pengembangan</p>
        <p class="mt-1">Kanal pengiriman belum aktif; token dikembalikan backend untuk pengujian.</p>
        <RouterLink
          :to="{ name: 'reset-password', hash: `#token=${encodeURIComponent(devToken)}` }"
          class="mt-1 inline-block font-medium text-v5-link underline"
          data-testid="forgot-dev-link"
        >
          Buka halaman reset password
        </RouterLink>
      </div>

      <UiButton appearance="v5" variant="secondary" block :to="{ name: 'login' }" data-testid="forgot-back-login">
        Kembali ke halaman masuk
      </UiButton>
    </div>

    <form v-else class="flex flex-col gap-4" novalidate data-testid="forgot-form" @submit="onSubmit">
      <div class="mb-2">
        <h2 class="text-[17px] font-semibold leading-[26px] tracking-[-0.25px] text-v5-text">Lupa Password</h2>
        <p class="mt-1 text-[13px] leading-5 tracking-[-0.05px] text-v5-text-muted">
          Masukkan username/NIP akun Anda. Jika terdaftar, tautan untuk membuat password baru akan dikirimkan.
        </p>
      </div>

      <UiNotice v-if="banner" appearance="v5" :tone="bannerTone" :data-testid="`forgot-error-${banner.kind}`">
        {{ banner.message }}
      </UiNotice>

      <FormField
        v-model="username"
        name="username"
        label="Username / NIP"
        placeholder="Masukkan username atau NIP"
        autocomplete="username"
        required
        appearance="v5"
        :error="errors.username"
      />

      <div class="space-y-1">
        <TurnstileWidget
          ref="turnstile"
          @verified="onCaptchaVerified"
          @expired="onCaptchaExpired"
          @error="onCaptchaExpired"
        />
        <p
          v-if="submitCount > 0 && errors.captcha_token"
          class="flex items-start gap-1 text-[13px] leading-5 tracking-[-0.05px] text-v5-error-strong"
          role="alert"
        >
          <CircleAlert class="mt-[3px] h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {{ errors.captcha_token }}
        </p>
      </div>

      <UiButton type="submit" appearance="v5" block :disabled="submitting" :loading="submitting" data-testid="forgot-submit">
        {{ submitting ? 'Memproses...' : 'Kirim Tautan Reset' }}
      </UiButton>

      <RouterLink :to="{ name: 'login' }" :class="LINK_KEMBALI">
        <ArrowLeft class="h-4 w-4" aria-hidden="true" />
        Kembali ke halaman masuk
      </RouterLink>
    </form>
  </AuthCardLayout>
</template>
