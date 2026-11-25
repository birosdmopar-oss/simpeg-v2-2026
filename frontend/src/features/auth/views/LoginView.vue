<script setup lang="ts">
/**
 * Halaman login (A-11): VeeValidate + Zod, Turnstile, error state jelas
 * (kredensial salah 401, akun terkunci 423, captcha gagal 422) — MTC-002.
 * Referensi visual: Figma SIMPEG V5 A-11 (CR-190): banner lewat UiNotice V5, tombol UiButton V5, field FormField V5.
 * Hanya tampilan; label, pesan, flag, dan perilaku mengikuti workflow `main`.
 *
 * ISSUE-006: `?reason=password-changed|password-reset` → banner hijau setelah ganti/reset password. Tautan
 * "Lupa password?" hanya tampil kalau VITE_PASSWORD_RESET_ENABLED=true; selain itu tetap "Hubungi Admin".
 */
import { toTypedSchema } from '@vee-validate/zod'
import { CircleAlert, Eye, EyeOff } from 'lucide-vue-next'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import UiButton from '@/shared/ui/UiButton.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'

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

/** CR-190: nada Alert V5 per jenis banner (warna dan role sama dengan sebelumnya: semua banner role="alert"). */
const bannerTone = computed<'danger' | 'warning'>(() =>
  banner.value?.kind === 'locked' || banner.value?.kind === 'network' ? 'warning' : 'danger',
)

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
    <form class="flex flex-col gap-4" novalidate data-testid="login-form" @submit="onSubmit">
      <h2 class="text-[17px] font-semibold leading-[26px] tracking-[-0.25px] text-v5-text">Masuk</h2>

      <UiNotice v-if="notice && !banner" appearance="v5" tone="success" data-testid="login-notice">
        {{ notice }}
      </UiNotice>

      <UiNotice v-if="banner" appearance="v5" :tone="bannerTone" :data-testid="`login-error-${banner.kind}`">
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

      <FormField
        v-model="password"
        name="password"
        label="Password"
        :type="showPassword ? 'text' : 'password'"
        placeholder="Masukkan password"
        autocomplete="current-password"
        required
        appearance="v5"
        :error="errors.password"
      >
        <template #suffix>
          <button
            type="button"
            class="absolute inset-y-0 right-3 my-auto flex h-7 w-7 items-center justify-center rounded-v5-sm text-v5-text-muted hover:text-v5-text focus-visible:shadow-v5-focus focus-visible:outline-none"
            :aria-label="showPassword ? 'Sembunyikan password' : 'Tampilkan password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-[18px] w-[18px]" />
            <Eye v-else class="h-[18px] w-[18px]" />
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
        <p
          v-if="submitCount > 0 && errors.captcha_token"
          class="flex items-start gap-1 text-[13px] leading-5 tracking-[-0.05px] text-v5-error-strong"
          role="alert"
        >
          <CircleAlert class="mt-[3px] h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {{ errors.captcha_token }}
        </p>
      </div>

      <UiButton type="submit" appearance="v5" block :disabled="submitting" :loading="submitting" data-testid="login-submit">
        {{ submitting ? 'Memproses...' : 'Masuk' }}
      </UiButton>

      <p v-if="resetEnabled" class="text-center text-[13px] leading-5">
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="rounded-v5-sm font-medium text-v5-link hover:underline focus-visible:shadow-v5-focus focus-visible:outline-none"
          data-testid="login-forgot-link"
        >
          Lupa password?
        </RouterLink>
      </p>
      <p v-else class="text-center text-[13px] leading-5 text-v5-text-muted" data-testid="login-forgot-contact-admin">
        Lupa password? Hubungi Admin Kepegawaian satker Anda.
      </p>
    </form>
  </AuthCardLayout>
</template>
