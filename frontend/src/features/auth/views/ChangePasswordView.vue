<script setup lang="ts">
/**
 * Ganti password sendiri (A-06, ISSUE-006 / MTC-007). Semua role login.
 *
 * - Validasi bentuk dari changePasswordSchema (kebijakan K4 dari PASSWORD_RULES); checklist diperbarui setiap
 *   kali diketik (computed), tidak menunggu submit.
 * - Sukses: backend mencabut SELURUH refresh token dan menghapus cookie sesi → store mengosongkan sesi lokal lalu
 *   halaman diarahkan ke login dengan pesan sukses. TIDAK memanggil /auth/logout (lihat auth.store changePassword).
 * - 422: pesan backend dipetakan ke field (password lama salah, kebijakan, konfirmasi).
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'

import PasswordField from '../components/PasswordField.vue'
import PasswordRulesChecklist from '../components/PasswordRulesChecklist.vue'
import { changePasswordSchema, type PasswordChecklistItem } from '../schemas/password.schema'
import { useAuthStore } from '../stores/auth.store'

const FIELDS = ['old_password', 'new_password', 'new_password_confirmation'] as const
type Field = (typeof FIELDS)[number]

const auth = useAuthStore()
const router = useRouter()

const submitting = ref(false)
const banner = ref<string | null>(null)
/** Username untuk password manager (input tersembunyi), diambil sekali saat halaman dibuka. */
const username = auth.user?.username ?? ''

const { defineField, handleSubmit, errors, setFieldError } = useForm({
  validationSchema: toTypedSchema(changePasswordSchema),
  initialValues: { old_password: '', new_password: '', new_password_confirmation: '' },
})

const [oldPassword] = defineField('old_password')
const [newPassword] = defineField('new_password')
const [confirmation] = defineField('new_password_confirmation')

const formRules = computed<PasswordChecklistItem[]>(() => {
  const next = newPassword.value ?? ''
  return [
    { id: 'not_same', label: 'Berbeda dari password lama', ok: next !== '' && next !== (oldPassword.value ?? '') },
    { id: 'confirmation', label: 'Konfirmasi password cocok', ok: next !== '' && next === (confirmation.value ?? '') },
  ]
})

function isField(name: string): name is Field {
  return (FIELDS as readonly string[]).includes(name)
}

const onSubmit = handleSubmit(async (values) => {
  banner.value = null
  submitting.value = true
  try {
    await auth.changePassword(values)
    await router.replace({ name: 'login', query: { reason: 'password-changed' } })
  } catch (err) {
    if (!isApiError(err)) {
      banner.value = 'Terjadi kesalahan. Silakan coba lagi.'
      return
    }

    let mapped = false
    if (err.status === 422 && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        if (isField(field) && messages[0]) {
          setFieldError(field, messages[0])
          mapped = true
        }
      }
    }
    if (!mapped) banner.value = err.message
  } finally {
    submitting.value = false
  }
})
</script>

<template>
  <section class="mx-auto max-w-lg space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-slate-800">Ganti Password</h1>
      <!--
        Backend hanya mencabut refresh token; access token yang sudah terbit di perangkat lain tetap sah sampai
        kedaluwarsa (jwt.accessTtl, default 3600 detik). Jangan menjanjikan sesi lain langsung terputus.
      -->
      <p class="mt-1 text-sm text-slate-500" data-testid="change-password-note">
        Setelah password diganti, Anda perlu masuk kembali. Sesi login Anda di perangkat lain tidak bisa diperpanjang
        lagi dan berakhir paling lambat 60 menit kemudian.
      </p>
    </div>

    <form
      class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
      novalidate
      data-testid="change-password-form"
      @submit="onSubmit"
    >
      <div
        v-if="banner"
        class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700"
        role="alert"
        data-testid="change-password-error"
      >
        {{ banner }}
      </div>

      <!-- Untuk password manager: mengaitkan password baru dengan akun ini. -->
      <input
        type="text"
        name="username"
        autocomplete="username"
        :value="username"
        readonly
        tabindex="-1"
        aria-hidden="true"
        class="sr-only"
      />

      <PasswordField
        v-model="oldPassword"
        name="old_password"
        label="Password lama"
        autocomplete="current-password"
        placeholder="Masukkan password saat ini"
        required
        :error="errors.old_password"
      />

      <PasswordField
        v-model="newPassword"
        name="new_password"
        label="Password baru"
        autocomplete="new-password"
        placeholder="Masukkan password baru"
        required
        :error="errors.new_password"
      />

      <PasswordField
        v-model="confirmation"
        name="new_password_confirmation"
        label="Konfirmasi password baru"
        autocomplete="new-password"
        placeholder="Ulangi password baru"
        required
        :error="errors.new_password_confirmation"
      />

      <PasswordRulesChecklist :password="newPassword ?? ''" :extra="formRules" />

      <button
        type="submit"
        class="flex w-full items-center justify-center rounded-md bg-brand-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="submitting"
        data-testid="change-password-submit"
      >
        {{ submitting ? 'Menyimpan...' : 'Simpan Password Baru' }}
      </button>
    </form>
  </section>
</template>
