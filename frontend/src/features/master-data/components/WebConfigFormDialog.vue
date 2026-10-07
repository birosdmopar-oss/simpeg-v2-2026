<script setup lang="ts">
/**
 * Form ubah nilai Web Config (G-09, DBV-006/CR-030) — Radix Vue Dialog + Zod per tipe key (webConfigSchema). Input
 * mengikuti tipe katalog: teks satu baris, textarea (teks panjang/HTML/daftar email), angka sebagai teks (tanpa koersi
 * float). Error 422 backend dipetakan ke field; nilai kosong ditolak (kembali ke bawaan lewat aksi Hapus di tabel).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import UiButton from '@/shared/ui/UiButton.vue'

import { webConfigSchema } from '../schemas/webConfig.schema'
import { webConfigService } from '../services/webConfig.service'
import { WEB_CONFIG_TYPE_LABELS, type WebConfigItem } from '../webConfig.types'

const props = defineProps<{ open: boolean; item: WebConfigItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [item: WebConfigItem] }>()

const value = ref('')
const remark = ref('')
const errors = ref<{ config_value?: string; remark?: string }>({})
const formError = ref('')
const submitting = ref(false)

const multiline = computed(() => ['textarea', 'html', 'email_list'].includes(props.item?.type ?? ''))
const numeric = computed(() => ['integer', 'decimal', 'id_ref'].includes(props.item?.type ?? ''))

const hint = computed(() => {
  const item = props.item
  if (!item?.type) return ''
  const c = item.constraints
  const parts = [`Tipe: ${WEB_CONFIG_TYPE_LABELS[item.type]}.`]
  if (c.min !== undefined && c.max !== undefined) parts.push(`Rentang ${c.min}–${c.max}.`)
  if (c.scale !== undefined) parts.push(`Maksimal ${c.scale} digit desimal (pakai titik).`)
  if (c.max_length !== undefined) parts.push(`Maksimal ${c.max_length} karakter.`)
  if (c.ref) parts.push(`ID baris tabel ${c.ref}.`)
  if (item.type === 'html') parts.push('Tag di luar daftar aman dibuang saat disimpan.')
  if (item.default !== null && item.default !== '') parts.push(`Bawaan: ${item.default}.`)
  return parts.join(' ')
})

watch(
  () => [props.open, props.item] as const,
  ([open, item]) => {
    if (!open || !item) return
    value.value = item.config_value ?? item.default ?? ''
    remark.value = item.remark ?? ''
    errors.value = {}
    formError.value = ''
  },
  { immediate: true },
)

async function onSubmit(event: Event): Promise<void> {
  event.preventDefault()
  const item = props.item
  if (!item || submitting.value) return

  const parsed = webConfigSchema(item).safeParse({ config_value: value.value, remark: remark.value })
  if (!parsed.success) {
    const next: typeof errors.value = {}
    for (const issue of parsed.error.issues) {
      const field = issue.path[0] as 'config_value' | 'remark'
      next[field] ??= issue.message
    }
    errors.value = next
    return
  }

  submitting.value = true
  errors.value = {}
  formError.value = ''
  try {
    const saved = await webConfigService.save(item.config_name, { config_value: value.value, remark: remark.value.trim() })
    emit('saved', saved)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.errors) {
      errors.value = {
        config_value: err.errors.config_value?.[0],
        remark: err.errors.remark?.[0],
      }
    }
    formError.value = isApiError(err) ? err.message : 'Terjadi kesalahan. Silakan coba lagi.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Ubah {{ item?.label }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              <code class="text-xs">{{ item?.config_name }}</code> — {{ item?.description }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="web-config-form" @submit="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>

          <FormField
            v-model="value"
            name="config_value"
            label="Nilai"
            :type="multiline ? 'textarea' : 'text'"
            :inputmode="numeric ? 'numeric' : undefined"
            required
            :hint="hint"
            :class="FORM_WIDE_CLASS"
            :error="errors.config_value"
          />

          <FormField v-model="remark" name="remark" label="Keterangan" hint="Opsional, maksimal 255 karakter." :class="FORM_WIDE_CLASS" :error="errors.remark" />

          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting">
              {{ submitting ? 'Menyimpan...' : 'Simpan' }}
            </UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
