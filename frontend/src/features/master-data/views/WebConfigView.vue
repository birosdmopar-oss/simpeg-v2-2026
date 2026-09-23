<script setup lang="ts">
/**
 * G-09 — Web Config (role 1): parameter sistem key-value dengan tipe data per key (MTC-012).
 * Nilai divalidasi sesuai tipe di form (Zod) dan sekali lagi di backend; perubahan langsung dipakai modul lain.
 * Key tidak bisa diubah setelah dibuat karena dipakai sebagai identitas di kode.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { useForm } from 'vee-validate'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import FormField from '@/shared/components/FormField.vue'

import { webConfigSchema, WEB_CONFIG_VALUE_RULES } from '../schemas/master.schema'
import { webConfigService } from '../services/web-config.service'
import { WEB_CONFIG_TYPES, type WebConfig, type WebConfigType } from '../types'

const items = ref<WebConfig[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const typeFilter = ref<WebConfigType | ''>('')

const formOpen = ref(false)
const editing = ref<WebConfig | null>(null)
const submitting = ref(false)
const formError = ref('')
const confirm = ref<{ open: boolean; row: WebConfig | null; loading: boolean }>({ open: false, row: null, loading: false })

const typeOptions = WEB_CONFIG_TYPES.map((t) => ({ value: t, label: t }))

const { values, handleSubmit, errors, resetForm, setFieldError, setFieldValue } = useForm({
  validationSchema: toTypedSchema(webConfigSchema),
})

const valueHint = computed(() => WEB_CONFIG_VALUE_RULES[(values.tipe_data as WebConfigType) ?? 'string'].message)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await webConfigService.list({ search: search.value.trim(), tipe_data: typeFilter.value })
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat konfigurasi.'
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 300)
})
watch(typeFilter, () => void load())

function openForm(row: WebConfig | null): void {
  editing.value = row
  formError.value = ''
  resetForm({
    values: {
      config_name: row?.config_name ?? '',
      tipe_data: row?.tipe_data ?? 'string',
      config_value: row?.config_value ?? '',
      keterangan: row?.keterangan ?? '',
    },
  })
  formOpen.value = true
}

const onSubmit = handleSubmit(async (formValues) => {
  submitting.value = true
  formError.value = ''
  try {
    const saved = editing.value
      ? await webConfigService.update(editing.value.config_name, {
          config_value: formValues.config_value,
          tipe_data: formValues.tipe_data,
          keterangan: formValues.keterangan ?? '',
        })
      : await webConfigService.create({ ...formValues, keterangan: formValues.keterangan ?? '' })
    notice.value = `Konfigurasi "${saved.config_name}" ${editing.value ? 'diperbarui' : 'ditambahkan'}; nilai baru langsung dipakai modul terkait.`
    formOpen.value = false
    await load()
  } catch (err) {
    if (isApiError(err) && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        if (messages[0]) setFieldError(field as 'config_value', messages[0])
      }
      formError.value = err.message
    } else {
      formError.value = isApiError(err) ? err.message : 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
})

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await webConfigService.remove(row.config_name)
    notice.value = `Konfigurasi "${row.config_name}" dihapus.`
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal menghapus.'
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Web Config</h1>
        <p class="text-sm text-slate-500">Parameter sistem (tarif, jam kerja, header dokumen) yang bisa diubah tanpa deploy ulang.</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="web-config-add"
        @click="openForm(null)"
      >
        <Plus class="h-4 w-4" /> Tambah Key
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari key / keterangan"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
        />
      </label>
      <select v-model="typeFilter" class="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Filter tipe data">
        <option value="">Semua tipe</option>
        <option v-for="t in WEB_CONFIG_TYPES" :key="t" :value="t">{{ t }}</option>
      </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Key</th>
            <th class="px-4 py-3">Tipe</th>
            <th class="px-4 py-3">Nilai</th>
            <th class="px-4 py-3">Keterangan</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada konfigurasi yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.config_name" class="hover:bg-slate-50" :data-testid="`web-config-row-${row.config_name}`">
            <td class="px-4 py-3 font-mono text-xs font-medium text-slate-800">{{ row.config_name }}</td>
            <td class="px-4 py-3">
              <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ row.tipe_data }}</span>
            </td>
            <td class="max-w-xs truncate px-4 py-3 text-slate-700">{{ row.config_value }}</td>
            <td class="px-4 py-3 text-slate-500">{{ row.keterangan ?? '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex justify-end gap-1">
                <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary" title="Edit" @click="openForm(row)">
                  <Pencil class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-red-600"
                  title="Hapus"
                  @click="confirm = { open: true, row, loading: false }"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <DialogRoot :open="formOpen" @update:open="formOpen = $event">
      <DialogPortal>
        <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
        <DialogContent
          class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl focus:outline-none"
        >
          <div class="mb-4 flex items-start justify-between">
            <div>
              <DialogTitle class="text-lg font-semibold text-slate-900">{{ editing ? 'Edit Konfigurasi' : 'Tambah Konfigurasi' }}</DialogTitle>
              <DialogDescription class="text-sm text-slate-500">
                {{ editing ? `Key ${editing.config_name} (key tidak dapat diubah)` : 'Key dipakai sebagai identitas di kode; tidak bisa diubah setelah dibuat.' }}
              </DialogDescription>
            </div>
            <DialogClose class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
              <X class="h-5 w-5" />
            </DialogClose>
          </div>

          <form class="space-y-4" novalidate data-testid="web-config-form" @submit="onSubmit">
            <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>

            <FormField
              :model-value="values.config_name"
              name="config_name"
              label="Key"
              required
              :disabled="editing !== null"
              hint="Huruf, angka, titik, garis bawah — tanpa spasi."
              :error="errors.config_name"
              @update:model-value="setFieldValue('config_name', $event)"
            />
            <FormField
              :model-value="values.tipe_data"
              name="tipe_data"
              label="Tipe Data"
              type="select"
              required
              :options="typeOptions"
              :error="errors.tipe_data"
              @update:model-value="setFieldValue('tipe_data', $event as WebConfigType)"
            />
            <FormField
              :model-value="values.config_value"
              name="config_value"
              label="Nilai"
              :type="values.tipe_data === 'text' ? 'textarea' : 'text'"
              required
              :hint="valueHint"
              :error="errors.config_value"
              @update:model-value="setFieldValue('config_value', $event)"
            />
            <FormField
              :model-value="values.keterangan"
              name="keterangan"
              label="Keterangan"
              :error="errors.keterangan"
              @update:model-value="setFieldValue('keterangan', $event)"
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

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus konfigurasi &quot;${confirm.row?.config_name ?? ''}&quot;?`"
      description="Berbeda dengan master lain, konfigurasi dihapus permanen. Modul yang membacanya akan memakai nilai default di kode."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
