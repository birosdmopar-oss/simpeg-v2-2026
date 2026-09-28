<script setup lang="ts">
/**
 * Form tambah/edit akun (A-12) — Radix Vue Dialog + VeeValidate/Zod. Error 422 backend dipetakan ke field.
 *
 * Akun non-pegawai (DBV-010/CR-013): NIP wajib untuk role Pegawai/PTT/PPPK, opsional untuk role lain; akun tanpa NIP
 * wajib nama dan username. Saat edit, NIP yang sudah ada tampil read-only (ganti NIP = fitur B-06); akun tanpa NIP bisa
 * ditautkan ke pegawai dengan mengisi NIP.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { type TypedSchema, useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'

import { PASSWORD_POLICY_HINT } from '../schemas/password.schema'
import { makeUserUpdateSchema, userCreateSchema } from '../schemas/user.schema'
import { usersService } from '../services/users.service'
import { useAuthStore } from '../stores/auth.store'
import { NIP_MAX_DIGITS, Role, ROLE_LABELS, type RoleCode, UL_PEGAWAI, type User, type UserUpdatePayload } from '../types'

const props = defineProps<{ open: boolean; user: User | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [user: User] }>()

const auth = useAuthStore()
const isEdit = computed(() => props.user !== null)
/** NIP akun yang sedang diedit (null = akun tanpa NIP / form tambah). */
const existingNip = computed(() => props.user?.nip ?? null)
const submitting = ref(false)
const formError = ref('')

const roleOptions = computed(() =>
  (Object.keys(ROLE_LABELS).map(Number) as RoleCode[])
    // Admin Satker tidak boleh membuat/memberi role Super Admin (aturan backend UserService).
    .filter((code) => auth.role === Role.SUPER_ADMIN || code !== Role.SUPER_ADMIN)
    .map((code) => ({ value: code, label: `${code} — ${ROLE_LABELS[code]}` })),
)

/** Nilai form (gabungan create/edit); validasi bentuk tetap dari skema Zod yang aktif. */
interface UserFormValues {
  nip?: string
  name?: string
  email?: string
  username?: string
  password?: string
  user_level?: number
  id_unit?: string
  id_satker?: string
  status?: '0' | '1'
}

const schema = computed(
  () =>
    toTypedSchema(isEdit.value ? makeUserUpdateSchema(existingNip.value) : userCreateSchema) as unknown as TypedSchema<UserFormValues>,
)

const { defineField, handleSubmit, errors, resetForm, setFieldError, values: formValues } = useForm<UserFormValues>({
  validationSchema: schema,
})

const [nip] = defineField('nip')
const [name] = defineField('name')
const [email] = defineField('email')
const [username] = defineField('username')
const [password] = defineField('password')
const [user_level] = defineField('user_level')
const [id_unit] = defineField('id_unit')
const [id_satker] = defineField('id_satker')
const [status] = defineField('status')

/** Role terpilih termasuk UL_PEGAWAI → NIP wajib. */
const nipRequired = computed(() => (UL_PEGAWAI as readonly number[]).includes(Number(formValues.user_level)))
/** Akun (hasil akhir) tanpa NIP → nama dan username wajib. */
const withoutNip = computed(() => existingNip.value === null && (formValues.nip ?? '').trim() === '')

const nipHint = `Wajib untuk Pegawai/PTT/PPPK; opsional untuk role lain. Angka, maks. ${NIP_MAX_DIGITS} digit (NIK 16 digit diterima).`

watch(
  () => [props.open, props.user] as const,
  ([open, user]) => {
    if (!open) return
    formError.value = ''
    resetForm({
      values: user
        ? {
            nip: '',
            name: user.name ?? '',
            email: user.email ?? '',
            username: user.username,
            password: '',
            user_level: user.user_level,
            id_unit: user.id_unit ?? '',
            id_satker: user.id_satker ?? '',
            status: user.status,
          }
        : {
            nip: '',
            name: '',
            email: '',
            username: '',
            password: '',
            user_level: undefined,
            id_unit: auth.user?.id_unit ?? '',
            id_satker: auth.role === Role.ADMIN_SATKER ? (auth.user?.id_satker ?? '') : '',
            status: '1',
          },
    })
  },
  { immediate: true },
)

function trimmedOrNull(value: string | undefined): string | null {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? null : trimmed
}

const onSubmit = handleSubmit(async (values) => {
  submitting.value = true
  formError.value = ''
  try {
    let saved: User
    if (isEdit.value && props.user) {
      const payload: UserUpdatePayload = {
        name: trimmedOrNull(values.name),
        email: trimmedOrNull(values.email),
        username: values.username || undefined,
        user_level: values.user_level as RoleCode,
        id_unit: values.id_unit ?? '',
        id_satker: values.id_satker ?? '',
        status: values.status ?? '1',
      }
      // NIP hanya dikirim untuk menautkan akun yang belum punya NIP.
      const linkNip = props.user.nip === null ? trimmedOrNull(values.nip) : null
      if (linkNip !== null) payload.nip = linkNip
      if (values.password) payload.password = values.password
      saved = await usersService.update(props.user.id_pengguna, payload)
    } else {
      saved = await usersService.create({
        nip: trimmedOrNull(values.nip),
        name: trimmedOrNull(values.name),
        email: trimmedOrNull(values.email),
        username: values.username || undefined,
        password: values.password ?? '',
        user_level: values.user_level as RoleCode,
        id_unit: values.id_unit ?? '',
        id_satker: values.id_satker ?? '',
        status: values.status ?? '1',
      })
    }
    emit('saved', saved)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        setFieldError(field as keyof UserFormValues, messages[0])
      }
      formError.value = err.message
    } else if (isApiError(err)) {
      formError.value = err.message
    } else {
      formError.value = 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
})
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-lg font-semibold text-slate-900">{{ isEdit ? 'Edit Akun' : 'Tambah Akun' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{ isEdit ? (user?.nip ? `NIP ${user.nip}` : 'Akun tanpa NIP') : 'Username default sama dengan NIP.' }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form class="space-y-4" novalidate data-testid="user-form" @submit="onSubmit">
          <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {{ formError }}
          </p>

          <FormField v-model="user_level" name="user_level" label="Role" type="select" required :options="roleOptions" :error="errors.user_level" />

          <FormField
            v-if="isEdit && existingNip !== null"
            :model-value="existingNip"
            name="nip_readonly"
            label="NIP"
            disabled
            hint="NIP tidak dapat diubah di sini (fitur ganti NIP)."
          />
          <FormField
            v-else
            v-model="nip"
            name="nip"
            :label="isEdit ? 'Tautkan NIP' : 'NIP'"
            placeholder="mis. 18 digit NIP"
            inputmode="numeric"
            :required="nipRequired"
            :hint="isEdit ? `Isi untuk menautkan akun ke pegawai; NIP tidak bisa diubah setelah terisi. ${nipHint}` : nipHint"
            :error="errors.nip"
          />
          <FormField
            v-model="name"
            name="name"
            label="Nama"
            :required="withoutNip"
            :hint="withoutNip ? 'Wajib untuk akun tanpa NIP.' : ''"
            :error="errors.name"
          />
          <FormField v-model="email" name="email" label="Email" placeholder="opsional" autocomplete="email" :error="errors.email" />
          <FormField
            v-model="username"
            name="username"
            label="Username"
            :placeholder="isEdit ? '' : 'Kosongkan = NIP'"
            :required="withoutNip"
            :hint="!isEdit && withoutNip ? 'Wajib untuk akun tanpa NIP.' : ''"
            :error="errors.username"
          />
          <FormField
            v-model="password"
            name="password"
            label="Password"
            type="password"
            autocomplete="new-password"
            :required="!isEdit"
            :hint="isEdit ? `Kosongkan jika tidak diganti. ${PASSWORD_POLICY_HINT} Mengganti password mencabut seluruh sesi akun.` : PASSWORD_POLICY_HINT"
            :error="errors.password"
          />
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField v-model="id_unit" name="id_unit" label="Unit" placeholder="mis. U01" :error="errors.id_unit" />
            <FormField
              v-model="id_satker"
              name="id_satker"
              label="Satker"
              placeholder="mis. S01"
              :disabled="auth.role === Role.ADMIN_SATKER"
              :hint="auth.role === Role.ADMIN_SATKER ? 'Admin Satker hanya dapat mengelola satkernya sendiri.' : ''"
              :error="errors.id_satker"
            />
          </div>
          <FormField
            v-model="status"
            name="status"
            label="Status"
            type="select"
            :options="[
              { value: '1', label: 'Aktif' },
              { value: '0', label: 'Nonaktif' },
            ]"
            :error="errors.status"
          />

          <div class="flex justify-end gap-2 pt-2">
            <DialogClose class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</DialogClose>
            <button
              type="submit"
              class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90 disabled:opacity-60"
              :disabled="submitting"
            >
              {{ submitting ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
