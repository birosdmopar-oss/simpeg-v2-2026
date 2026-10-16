<script setup lang="ts">
/**
 * Tambah/Ubah penugasan Plt/Plh (B-07, MAKE-012; role 1). Isian `penugasanFields(jenis)` lewat RiwayatFieldForm;
 * lampiran opsional di kolom `lampiran` (multipart). Galat 422 per kolom diteruskan ke form (`lampiran` → berkas kolom).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'

import { LABEL_PENUGASAN, LAMPIRAN_PENUGASAN, penugasanFields, penugasanInitial } from '../penugasan.config'
import RiwayatFieldForm from '../riwayat/RiwayatFieldForm.vue'
import type { BerkasMap } from '../riwayat/riwayat.service'
import { describeApiError, splitValidationErrors, type RiwayatValidationErrors } from '../services/apiErrors'
import { penugasanService } from '../services/penugasan.service'
import type { JenisPenugasan, PenugasanItem } from '../types'

const props = defineProps<{ open: boolean; jenis: JenisPenugasan; row: PenugasanItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: PenugasanItem] }>()

const fields = computed(() => penugasanFields(props.jenis))
const initial = computed(() => penugasanInitial(props.jenis, props.row))
const busy = ref(false)
const serverErrors = ref<RiwayatValidationErrors | null>(null)
const formKey = ref(0)

watch(
  () => props.open,
  (open) => {
    serverErrors.value = null
    if (open) formKey.value++
  },
)

const judul = computed(() => `${props.row ? 'Ubah' : 'Tambah'} ${LABEL_PENUGASAN[props.jenis]}`)

async function kirim(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  busy.value = true
  serverErrors.value = null
  const lampiran = berkas[LAMPIRAN_PENUGASAN.kolom] ?? null
  try {
    const row = props.row
      ? await penugasanService.update(props.jenis, props.row.id, values, lampiran)
      : await penugasanService.create(props.jenis, values, lampiran)
    emit('saved', row)
    emit('update:open', false)
  } catch (error) {
    const failure = describeApiError(error)
    const split = splitValidationErrors(error, fields.value.map((f) => f.name))
    const berkasError = isApiError(error) ? error.errors?.lampiran?.[0] : undefined
    serverErrors.value = {
      fields: berkasError ? { ...split.fields, 'berkas.lampiran': berkasError } : split.fields,
      berkas: {},
      general: failure.kind === 'validation' ? split.general.filter((m) => m !== berkasError) : [failure.message],
    }
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="penugasan-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ judul }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Penugasan berlaku tanpa persetujuan. Kolom bertanda (*) wajib diisi.
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <RiwayatFieldForm
          :key="formKey"
          :fields="fields"
          :initial="initial"
          :mode="row ? 'edit' : 'create'"
          :berkas-kolom="[LAMPIRAN_PENUGASAN]"
          :kolom-terisi="{ lampiran: row?.ada_lampiran ?? false }"
          :server-errors="serverErrors"
          :busy="busy"
          submit-label="Simpan"
          cancel-label="Batal"
          testid="penugasan"
          @submit="(values, berkas) => void kirim(values, berkas)"
          @cancel="tutup"
        />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
