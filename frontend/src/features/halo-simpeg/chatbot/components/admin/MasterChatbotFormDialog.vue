<script setup lang="ts">
/**
 * Form tambah/ubah kategori atau tag chatbot (PRD Fase 8 v1.1 §7.10.4, `dev:V/form_kategori.php:41-50`): judul wajib; kategori
 * punya "Bubble Vanya" Ya/Tidak; status 1/2 hanya saat ubah (status awal 1). Keunikan judul ditegakkan BE (422, F8-40).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiRadioGroup } from '@/shared/ui'

import { kategoriChatbotService, tagChatbotService } from '../../services/chatbot.service'
import type { KategoriRow, StatusUbah, TagRow } from '../../types'

export type JenisMasterChatbot = 'kategori' | 'tag'

const props = defineProps<{ open: boolean; jenis: JenisMasterChatbot; row: KategoriRow | TagRow | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const NAMA = { kategori: 'Kategori', tag: 'Tag' } as const
const MAKS = { kategori: 150, tag: 100 } as const
const YA_TIDAK = [
  { value: '0', label: 'Tidak' },
  { value: '1', label: 'Ya' },
]
const STATUS = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]

const isEdit = computed(() => props.row !== null)
const form = reactive({ judul: '', bubble: '0', status: '1' })
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})

watch(
  () => [props.open, props.row] as const,
  ([open, row]) => {
    if (!open) return
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    form.judul = row?.judul ?? ''
    form.bubble = row && 'tampil_bubble' in row && row.tampil_bubble ? '1' : '0'
    form.status = row?.status === 2 ? '2' : '1'
  },
  { immediate: true },
)

const errors = computed(() => {
  const e: Record<string, string> = {}
  const judul = form.judul.trim()
  if (judul === '') e.judul = `Judul ${props.jenis} tidak boleh kosong!`
  else if ([...judul].length > MAKS[props.jenis]) e.judul = `Judul ${props.jenis} maksimal ${MAKS[props.jenis]} karakter.`
  return e
})

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? errors.value[f] : undefined)
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(errors.value).length > 0 || submitting.value) return
  submitting.value = true
  const id = props.row?.id ?? null
  const status = Number(form.status) as StatusUbah
  try {
    if (props.jenis === 'kategori') await kategoriChatbotService.simpan(id, { judul: form.judul, tampil_bubble: form.bubble === '1', status })
    else await tagChatbotService.simpan(id, { judul: form.judul, status })
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    if (isApiError(e) && e.errors) for (const [f, m] of Object.entries(e.errors)) serverErrors.value[f === 'judul_kategori' || f === 'judul_tag' ? 'judul' : f] = m[0] ?? ''
    formError.value = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? `Ubah ${NAMA[jenis]}` : `Tambah ${NAMA[jenis]}` }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{ jenis === 'kategori' ? 'Kategori mengelompokkan QnA Vanya.' : 'Tag membantu Vanya mencocokkan pertanyaan.' }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate :data-testid="`chatbot-${jenis}-form`" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>
          <FormField v-model="form.judul" name="judul" :label="NAMA[jenis]" required :class="FORM_WIDE_CLASS" :error="err('judul')" />
          <div v-if="jenis === 'kategori'" :class="FORM_WIDE_CLASS" data-testid="chatbot-kategori-bubble">
            <UiRadioGroup v-model="form.bubble" name="tampil_bubble" label="Bubble Vanya" :options="YA_TIDAK" />
            <p class="mt-1 text-caption text-slate-500">Jika "Ya", kategori ini tampil sebagai bubble topik di chat Vanya (mis. Cuti, SIMPEG, Kinerja, MT).</p>
            <p v-if="err('tampil_bubble')" class="mt-1 text-body2 text-danger" role="alert">{{ err('tampil_bubble') }}</p>
          </div>
          <div v-if="isEdit" :class="FORM_WIDE_CLASS" :data-testid="`chatbot-${jenis}-status`">
            <UiRadioGroup v-model="form.status" name="status" label="Status" :options="STATUS" />
            <p v-if="err('status')" class="mt-1 text-body2 text-danger" role="alert">{{ err('status') }}</p>
          </div>
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
