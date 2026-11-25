<script setup lang="ts">
/**
 * Reset password dengan token dari tautan (A-07, ISSUE-006 / MTC-007). Tamu saja, di balik VITE_PASSWORD_RESET_ENABLED.
 *
 * - Token dibaca dari fragment `#token=` (tautan dari backend: auth.resetLinkBase#token=…; fragment tidak dikirim
 *   browser ke server sehingga tidak masuk access log web server maupun Referer), atau dari query `?token=` sebagai
 *   cadangan gaya legacy. Token disimpan di memori, lalu fragment/query token dihapus dari URL (router.replace)
 *   supaya tidak tertinggal di riwayat browser. Tanpa token → field tempel manual.
 * - Password baru + konfirmasi memakai checklist real-time yang sama (tanpa aturan "beda dari password lama").
 * - 422 errors.token (tidak valid / kedaluwarsa / sudah dipakai / tidak berlaku lagi) → banner, form dinonaktifkan,
 *   tombol minta tautan baru. 5xx → token belum terpakai, sarankan coba lagi. Sukses → login dengan pesan sukses.
 * - Tidak ada endpoint cek token: token tidak valid baru ketahuan saat submit.
 * - CR-190: tampilan Figma SIMPEG V5 A-11 (Atur Ulang Sandi); teks, field, dan perilaku tetap seperti `main`.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { ArrowLeft } from 'lucide-vue-next'
import { useForm } from 'vee-validate'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import UiButton from '@/shared/ui/UiButton.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'

import AuthCardLayout from '../components/AuthCardLayout.vue'
import PasswordField from '../components/PasswordField.vue'
import PasswordRulesChecklist from '../components/PasswordRulesChecklist.vue'
import { type PasswordChecklistItem, resetPasswordSchema } from '../schemas/password.schema'
import { authService } from '../services/auth.service'

const route = useRoute()
const router = useRouter()

const hashParams = new URLSearchParams(route.hash.replace(/^#/, ''))
const queryToken = typeof route.query.token === 'string' ? route.query.token : null
const linkToken = (hashParams.get('token') ?? queryToken ?? '').trim()
const fromLink = linkToken !== ''

const submitting = ref(false)
const banner = ref<string | null>(null)
/** Pesan backend kalau token ditolak; form dikunci karena token yang sama tidak akan berhasil. */
const tokenRejected = ref<string | null>(null)

const { defineField, handleSubmit, errors, setFieldError } = useForm({
  validationSchema: toTypedSchema(resetPasswordSchema),
  initialValues: { token: linkToken, new_password: '', new_password_confirmation: '' },
})

const [token] = defineField('token')
const [newPassword] = defineField('new_password')
const [confirmation] = defineField('new_password_confirmation')

const formRules = computed<PasswordChecklistItem[]>(() => {
  const next = newPassword.value ?? ''
  return [{ id: 'confirmation', label: 'Konfirmasi password cocok', ok: next !== '' && next === (confirmation.value ?? '') }]
})

onMounted(() => {
  if (hashParams.has('token') || 'token' in route.query) {
    const query = { ...route.query }
    delete query.token
    void router.replace({ name: 'reset-password', query, hash: '' })
  }
})

const onSubmit = handleSubmit(async (values) => {
  banner.value = null
  submitting.value = true
  try {
    await authService.resetPassword(values)
    await router.replace({ name: 'login', query: { reason: 'password-reset' } })
  } catch (err) {
    if (!isApiError(err)) {
      banner.value = 'Terjadi kesalahan. Silakan coba lagi.'
      return
    }

    if (err.status === 422 && err.errors?.token?.[0]) {
      tokenRejected.value = err.errors.token[0]
    } else if (err.status === 422 && err.errors) {
      let mapped = false
      for (const field of ['new_password', 'new_password_confirmation'] as const) {
        const message = err.errors[field]?.[0]
        if (message) {
          setFieldError(field, message)
          mapped = true
        }
      }
      if (!mapped) banner.value = err.message
    } else if (err.status !== null && err.status >= 500) {
      banner.value = 'Password belum tersimpan karena gangguan server. Tautan reset masih berlaku, silakan coba lagi.'
    } else {
      banner.value = err.message
    }
  } finally {
    submitting.value = false
  }
})
</script>

<template>
  <AuthCardLayout>
    <form class="flex flex-col gap-4" novalidate data-testid="reset-form" @submit="onSubmit">
      <div>
        <h2 class="text-[17px] font-semibold leading-[26px] tracking-[-0.25px] text-v5-text">Buat Password Baru</h2>
        <!-- Lihat ChangePasswordView: access token lain tetap sah sampai kedaluwarsa (jwt.accessTtl, default 60 menit). -->
        <p class="mt-1 text-[13px] leading-5 tracking-[-0.05px] text-v5-text-muted" data-testid="reset-note">
          Setelah berhasil, masuk dengan password baru. Sesi login akun ini di perangkat lain tidak bisa diperpanjang
          lagi dan berakhir paling lambat 60 menit kemudian.
        </p>
      </div>

      <UiNotice v-if="tokenRejected" appearance="v5" tone="danger" data-testid="reset-token-error">
        <p>{{ tokenRejected }}</p>
        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="mt-1 inline-block rounded-v5-sm text-[13px] font-medium leading-5 text-v5-link underline focus-visible:shadow-v5-focus focus-visible:outline-none"
          data-testid="reset-request-new"
        >
          Minta tautan reset baru
        </RouterLink>
      </UiNotice>

      <UiNotice v-else-if="banner" appearance="v5" tone="danger" data-testid="reset-error">
        {{ banner }}
      </UiNotice>

      <fieldset class="flex flex-col gap-4" :disabled="tokenRejected !== null" data-testid="reset-fields">
        <div v-if="!fromLink" class="space-y-1">
          <FormField
            v-model="token"
            name="token"
            label="Token reset"
            placeholder="Tempel token dari tautan reset"
            autocomplete="off"
            required
            appearance="v5"
            :error="errors.token"
          />
          <p class="text-[13px] leading-5 tracking-[-0.05px] text-v5-text-muted">
            Tidak punya tautan?
            <RouterLink
              :to="{ name: 'forgot-password' }"
              class="rounded-v5-sm font-medium text-v5-link hover:underline focus-visible:shadow-v5-focus focus-visible:outline-none"
            >
              Minta tautan baru
            </RouterLink>
          </p>
        </div>

        <PasswordField
          v-model="newPassword"
          name="new_password"
          label="Password baru"
          autocomplete="new-password"
          placeholder="Masukkan password baru"
          required
          appearance="v5"
          :disabled="tokenRejected !== null"
          :error="errors.new_password"
        />

        <PasswordField
          v-model="confirmation"
          name="new_password_confirmation"
          label="Konfirmasi password baru"
          autocomplete="new-password"
          placeholder="Ulangi password baru"
          required
          appearance="v5"
          :disabled="tokenRejected !== null"
          :error="errors.new_password_confirmation"
        />

        <PasswordRulesChecklist :password="newPassword ?? ''" :extra="formRules" appearance="v5" />

        <UiButton
          type="submit"
          appearance="v5"
          block
          :disabled="submitting || tokenRejected !== null"
          :loading="submitting"
          data-testid="reset-submit"
        >
          {{ submitting ? 'Menyimpan...' : 'Simpan Password Baru' }}
        </UiButton>
      </fieldset>

      <RouterLink
        :to="{ name: 'login' }"
        class="mx-auto inline-flex items-center gap-1 rounded-v5-sm text-[13px] font-medium leading-5 text-v5-link hover:underline focus-visible:shadow-v5-focus focus-visible:outline-none"
      >
        <ArrowLeft class="h-4 w-4" aria-hidden="true" />
        Kembali ke halaman masuk
      </RouterLink>
    </form>
  </AuthCardLayout>
</template>
