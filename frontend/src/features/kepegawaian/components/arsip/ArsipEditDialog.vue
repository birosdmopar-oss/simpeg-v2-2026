<script setup lang="ts">
/**
 * Dialog "Ubah Nama Arsip" (F-10 Tahap 2, MAKE-096; PRD §6.1, AC2-08): satu isian (kolom `tag`), wajib diisi, 1..255
 * karakter sesudah dipangkas. Sanitasi tag HTML dilakukan server; nama selalu ditampilkan sebagai teks. Memanggil
 * `PATCH pegawai/{nip}/arsip/berkas/{id}`; galat 422 `errors.tag` tampil di isian, 403/404 sebagai pesan di dialog. Dialog
 * tidak bisa ditutup selama proses.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref, watch } from 'vue'

import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { urainGalatAksi } from '../../arsip.galat'
import type { ArsipBerkas } from '../../arsip.types'
import { arsipService } from '../../services/arsip.service'

const NAMA_ARSIP_MAKS = 255

const props = defineProps<{ open: boolean; nip: string; item: ArsipBerkas | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; done: [pesan: string]; basi: [] }>()

const nama = ref('')
const busy = ref(false)
const galatKolom = ref('')
const galatUmum = ref('')

watch(
  () => [props.open, props.item?.id_attachment] as const,
  ([terbuka]) => {
    if (!terbuka) return
    nama.value = props.item?.tag?.trim() ? props.item.tag : (props.item?.nama_arsip ?? '')
    galatKolom.value = ''
    galatUmum.value = ''
  },
  { immediate: true },
)

async function simpan(): Promise<void> {
  const item = props.item
  if (!item || busy.value) return
  const bersih = nama.value.trim()
  galatUmum.value = ''
  if (bersih === '') {
    galatKolom.value = 'Nama arsip wajib diisi.'
    return
  }
  if ([...bersih].length > NAMA_ARSIP_MAKS) {
    galatKolom.value = `Nama arsip maksimal ${NAMA_ARSIP_MAKS} karakter.`
    return
  }
  galatKolom.value = ''
  busy.value = true
  try {
    await arsipService.ubahNama(props.nip, item.id_attachment, bersih)
    emit('update:open', false)
    emit('done', 'Nama arsip diubah.')
  } catch (error) {
    const galat = urainGalatAksi(error, ['tag'])
    galatKolom.value = galat.kolom.tag ?? ''
    galatUmum.value = galat.kolom.tag ? '' : galat.umum
    if (galat.basi) emit('basi')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !busy && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="arsip-edit-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Ubah Nama Arsip</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ item?.jenis_riwayat }}</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form novalidate @submit.prevent="simpan">
          <UiTextField
            v-model="nama"
            label="Nama Arsip"
            required
            autocomplete="off"
            :disabled="busy"
            :state="galatKolom ? 'error' : 'default'"
            :help-text="galatKolom || `Wajib diisi, maksimal ${NAMA_ARSIP_MAKS} karakter.`"
            data-testid="arsip-edit-nama"
          />
          <UiNotice v-if="galatUmum" tone="danger" class="mt-3" data-testid="arsip-edit-galat">{{ galatUmum }}</UiNotice>
          <div class="mt-6 flex justify-end gap-3">
            <UiButton variant="secondary" appearance="soft" :disabled="busy" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="busy" data-testid="arsip-edit-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
