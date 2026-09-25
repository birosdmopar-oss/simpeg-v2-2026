<script setup lang="ts">
/**
 * Halaman login (A-11): VeeValidate + Zod, Turnstile, error state jelas
 * (kredensial salah 401, akun terkunci 423, captcha gagal 422) — MTC-002.
 * Referensi visual: palet & font Laporan Redesign (layar login sendiri tidak ada di Redesign PDF; Figma menyusul).
 *
 * ISSUE-006: `?reason=password-changed|password-reset` → banner hijau setelah ganti/reset password. Tautan
 * "Lupa password?" hanya tampil kalau VITE_PASSWORD_RESET_ENABLED=true; selain itu tetap "Hubungi Admin".
 */
import { toTypedSchema } from '@vee-validate/zod'
import { Eye, EyeOff } from 'lucide-vue-next'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'

import AuthCardLayout from '../components/AuthCardLayout.vue'
import TurnstileWidget from '../components/TurnstileWidget.vue'
import { passwordResetEnabled } from '../config'
import { loginSchema } from '../schemas/login.schema'
import { useAuthStore } from '../stores/auth.store'

type BannerKind = 'credentials' | 'locked' | 'captcha' | 'network' | 'generic'

/** Pesan sukses dari halaman lain yang mengarahkan ke login (query `reason`). */
const REASON_NOTICES: Record<string, string> = {
  'password-changed': 'Password berhasil diubah. Silakan masuk kembali dengan password baru.',
  'password-reset': 'Password berhasil direset. Silakan masuk dengan password baru.',
}

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const showPassword = ref(false)
const submitting = ref(false)
const banner = ref<{ kind: BannerKind; message: string } | null>(null)
const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null)
const resetEnabled = passwordResetEnabled()
const notice = ref<string | null>(
  typeof route.query.reason === 'string' ? (REASON_NOTICES[route.query.reason] ?? null) : null,
)

const { defineField, handleSubmit, errors, setFieldValue, setFieldError, submitCount } = useForm({
  validationSchema: toTypedSchema(loginSchema),
  initialValues: { username: '', password: '', captcha_token: '' },
})

const [username] = defineField('username')
const [password] = defineField('password')

const bannerClass = computed(() => {
  switch (banner.value?.kind) {
    case 'locked':
      return 'border-amber-300 bg-amber-50 text-amber-800'
    case 'network':
      return 'border-slate-300 bg-slate-50 text-slate-700'
    default:
      return 'border-red-300 bg-red-50 text-red-700'
  }
})

function onCaptchaVerified(token: string): void {
  setFieldValue('captcha_token', token)
  if (banner.value?.kind === 'captcha') banner.value = null
}

function onCaptchaExpired(): void {
  setFieldValue('captcha_token', '')
}

const onSubmit = handleSubmit(async (values) => {
  banner.value = null
  notice.value = null
  submitting.value = true
  try {
    await auth.login(values)
    const redirect =
      typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
        ? route.query.redirect
        : '/'
    await router.replace(redirect)
  } catch (err) {
    setFieldValue('password', '')
    setFieldValue('captcha_token', '')
    turnstile.value?.reset()

    if (!isApiError(err)) {
      banner.value = { kind: 'generic', message: 'Terjadi kesalahan. Silakan coba lagi.' }
      return
    }

    if (err.status === 423) {
      banner.value = { kind: 'locked', message: err.message }
    } else if (err.status === 422 && err.errors?.captcha_token) {
      banner.value = { kind: 'captcha', message: err.errors.captcha_token[0] ?? 'Verifikasi captcha gagal.' }
    } else if (err.status === 422 && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        if (field === 'username' || field === 'password') setFieldError(field, messages[0])
      }
      banner.value = { kind: 'generic', message: err.message }
    } else if (err.status === 401) {
      // Pesan generik dari backend: tidak membocorkan field mana yang salah (MTC-002).
      banner.value = { kind: 'credentials', message: err.message }
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
    <form
      class="space-y-4 rounded-xl bg-white p-6 shadow-xl"
      novalidate
      data-testid="login-form"
      @submit="onSubmit"
    >
      <h2 class="text-lg font-semibold text-slate-800">Masuk</h2>

      <div
        v-if="notice && !banner"
        class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
        role="status"
        data-testid="login-notice"
      >
        {{ notice }}
      </div>

      <div
        v-if="banner"
        class="rounded-md border px-3 py-2 text-sm"
        :class="bannerClass"
        role="alert"
        :data-testid="`login-error-${banner.kind}`"
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

      <FormField
        v-model="password"
        name="password"
        label="Password"
        :type="showPassword ? 'text' : 'password'"
        placeholder="Masukkan password"
        autocomplete="current-password"
        required
        :error="errors.password"
      >
        <template #suffix>
          <button
            type="button"
            class="absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-slate-600"
            :aria-label="showPassword ? 'Sembunyikan password' : 'Tampilkan password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </template>
      </FormField>

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
        data-testid="login-submit"
      >
        {{ submitting ? 'Memproses...' : 'Masuk' }}
      </button>

      <p v-if="resetEnabled" class="text-center text-sm">
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="font-medium text-brand-primary hover:underline"
          data-testid="login-forgot-link"
        >
          Lupa password?
        </RouterLink>
      </p>
      <p v-else class="text-center text-xs text-slate-500" data-testid="login-forgot-contact-admin">
        Lupa password? Hubungi Admin Kepegawaian satker Anda.
      </p>
    </form>
  </AuthCardLayout>
</template>
