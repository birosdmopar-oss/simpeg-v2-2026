<script setup lang="ts">
/**
 * Perpanjangan Tugas Belajar (role 1; `POST tb/{id}/perpanjangan`, `PUT|DELETE tb/perpanjangan/{id}`). Tanggal mulai
 * kosong saat tambah → server memberi bawaan (akhir efektif + 1 hari); FE tidak menghitungnya. Ubah hanya dua tanggal
 * (berkas tidak diubah di sini). Galat 422 per kolom ditampilkan di field masing-masing.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField, UiNotice, UiTextField } from '@/shared/ui'

import type { PerpanjanganStudi, StudiItem, StudiService } from '../services/studi.service'

const props = defineProps<{ open: boolean; induk: StudiItem; row: PerpanjanganStudi | null; service: StudiService }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const KOLOM = ['tgl_mulai_perpan', 'tgl_akhir_perpan', 'file_sperpan']
const edit = computed(() => props.row !== null)
const berkas = ref<File | undefined>(undefined)
const kunciBerkas = ref(0)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)
const isian = reactive({ tgl_mulai_perpan: '', tgl_akhir_perpan: '' })

watch(
  () => props.open,
  (open) => {
    if (!open) return
    errors.value = {}
    umum.value = ''
    berkas.value = undefined
    kunciBerkas.value++
    Object.assign(isian, { tgl_mulai_perpan: props.row?.tgl_mulai_perpan ?? '', tgl_akhir_perpan: props.row?.tgl_akhir_perpan ?? '' })
  },
  { immediate: true },
)

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    if (props.row) {
      await props.service.ubahPerpanjangan(props.row.id, { tgl_mulai_perpan: isian.tgl_mulai_perpan, tgl_akhir_perpan: isian.tgl_akhir_perpan })
    } else {
      const berkasForm = berkas.value ? { file_sperpan: berkas.value } : {}
      await props.service.tambahPerpanjangan(props.induk.id, { tgl_mulai_perpan: isian.tgl_mulai_perpan, tgl_akhir_perpan: isian.tgl_akhir_perpan }, berkasForm)
    }
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    const v = splitValidationErrors(e, KOLOM)
    errors.value = v.fields
    umum.value = v.general.length ? v.general.join(' ') : Object.keys(v.fields).length ? '' : describeApiError(e).message
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const state = (k: string): 'default' | 'error' => (errors.value[k] ? 'error' : 'default')
const pesan = (k: string): string | undefined => errors.value[k] || undefined
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="tb-perpanjangan-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Tambah' }} Perpanjangan</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Perpanjangan untuk Tugas Belajar {{ induk.nama ?? induk.nip }}.</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="tb-perpanjangan-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="tb-perpanjangan-error">{{ umum }}</p>
          <UiNotice v-if="!edit" tone="info" :class="FORM_WIDE_CLASS">Tanggal mulai kosong akan diisi otomatis oleh sistem (akhir masa berlaku + 1 hari).</UiNotice>

          <UiTextField v-model="isian.tgl_mulai_perpan" type="date" label="Tanggal Mulai Perpanjangan" :state="state('tgl_mulai_perpan')" :help-text="pesan('tgl_mulai_perpan')" />
          <UiTextField v-model="isian.tgl_akhir_perpan" type="date" label="Tanggal Akhir Perpanjangan" required :state="state('tgl_akhir_perpan')" :help-text="pesan('tgl_akhir_perpan')" />

          <UiFileField
            v-if="!edit"
            :key="kunciBerkas"
            :class="FORM_WIDE_CLASS"
            label="Surat Perpanjangan"
            :allowed="['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']"
            :max-mb="5"
            required
            :state="state('file_sperpan')"
            :help-text="pesan('file_sperpan')"
            @change="(f: File | null) => (berkas = f ?? undefined)"
          />

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy" data-testid="tb-perpanjangan-simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
