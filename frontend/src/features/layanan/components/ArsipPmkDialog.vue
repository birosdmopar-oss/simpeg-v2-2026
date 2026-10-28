<script setup lang="ts">
/**
 * Kelola arsip SK pada riwayat PMK final (role 1; `PUT pmk/{id}` tambah arsip, `DELETE pmk/{id}/arsip/{id}` hapus).
 * Batas 2 MB, jpg/jpeg/png/gif/pdf; minimal satu arsip harus tersisa (server menegakkan: 422 `errors.arsip`).
 * Tombol di dalam dialog (bukan baris tabel); hapus arsip memakai ConfirmDialog.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import { UiButton, UiFileField } from '@/shared/ui'

import { pmkService } from '../services/pmk.service'
import type { ArsipPmk, RiwayatPmkItem } from '../types'

const props = defineProps<{ open: boolean; row: RiwayatPmkItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; berubah: [id: number, arsip: ArsipPmk[]] }>()

const BERKAS = { allowed: ['jpg', 'jpeg', 'png', 'gif', 'pdf'], maxMb: 2 } as const

const arsip = ref<ArsipPmk[]>([])
const berkas = ref<File | null>(null)
const kunci = ref(0)
const busy = ref(false)
const galat = ref('')
const hapusId = ref<number | null>(null)
const hapusOpen = ref(false)

watch(
  () => [props.open, props.row] as const,
  ([open, row]) => {
    if (!open) return
    arsip.value = row ? [...row.arsip] : []
    berkas.value = null
    kunci.value++
    galat.value = ''
  },
  { immediate: true },
)

function pesanGalat(e: unknown): string {
  const v = splitValidationErrors(e, ['arsip'])
  return v.fields.arsip ?? (v.general.length ? v.general.join(' ') : describeApiError(e).message)
}

async function unggah(): Promise<void> {
  if (!props.row || !berkas.value) {
    galat.value = 'Pilih berkas arsip terlebih dahulu.'
    return
  }
  busy.value = true
  galat.value = ''
  try {
    arsip.value = await pmkService.tambahArsip(props.row.id, berkas.value)
    berkas.value = null
    kunci.value++
    emit('berubah', props.row.id, arsip.value)
  } catch (e) {
    galat.value = pesanGalat(e)
  } finally {
    busy.value = false
  }
}

async function hapus(): Promise<void> {
  const id = hapusId.value
  hapusOpen.value = false
  if (!props.row || id === null) return
  busy.value = true
  galat.value = ''
  try {
    await pmkService.hapusArsip(props.row.id, id)
    arsip.value = arsip.value.filter((a) => a.id_attachment !== id)
    emit('berubah', props.row.id, arsip.value)
  } catch (e) {
    galat.value = pesanGalat(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (!busy ? emit('update:open', v) : undefined)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="arsip-pmk-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Arsip SK PMK</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ row ? `SK ${row.no_sk}` : '' }} — maksimal 2 MB, minimal satu arsip tersisa.</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="galat" role="alert" class="mb-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="arsip-pmk-error">{{ galat }}</p>

        <ul class="mb-4 divide-y divide-slate-200 text-body2" data-testid="arsip-pmk-list">
          <li v-for="a in arsip" :key="a.id_attachment" class="flex items-center justify-between gap-3 py-2" :data-testid="`arsip-pmk-${a.id_attachment}`">
            <span class="min-w-0 truncate text-slate-900">{{ a.display_name ?? `Arsip ${a.id_attachment}` }}</span>
            <UiButton size="sm" appearance="soft" variant="error" :disabled="busy" :data-testid="`arsip-pmk-hapus-${a.id_attachment}`" @click="((hapusId = a.id_attachment), (hapusOpen = true))">Hapus</UiButton>
          </li>
          <li v-if="arsip.length === 0" class="py-2 text-slate-500">Belum ada arsip.</li>
        </ul>

        <UiFileField :key="kunci" label="Tambah Arsip SK" :allowed="[...BERKAS.allowed]" :max-mb="BERKAS.maxMb" @change="(f) => (berkas = f)" />
        <div class="mt-4 flex justify-end gap-2">
          <UiButton type="button" appearance="soft" variant="secondary" :disabled="busy" @click="emit('update:open', false)">Tutup</UiButton>
          <UiButton type="button" :loading="busy" data-testid="arsip-pmk-unggah" @click="unggah">Unggah</UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>

  <ConfirmDialog
    :open="hapusOpen"
    title="Hapus arsip SK?"
    description="Arsip dihapus permanen. Minimal satu arsip harus tersisa."
    confirm-label="Ya, hapus"
    danger
    @update:open="(v) => (hapusOpen = v)"
    @confirm="hapus"
  />
</template>
