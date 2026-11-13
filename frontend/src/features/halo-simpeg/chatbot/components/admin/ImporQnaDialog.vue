<script setup lang="ts">
/**
 * Impor QnA dari Excel (PRD Fase 8 v1.1 F8-37, §7.10.4): hanya `.xlsx` ≤ 2 MB (BE memeriksa isi, baris, dan judul kolom).
 * Hasil: ringkasan ditambah / kategori baru / tag baru + daftar baris dilewati dan galat. Ada galat → tidak ada baris
 * tersimpan (semua-atau-tidak); ringkasan galat datang sebagai data hasil atau sebagai 422 dengan `errors`.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { FORM_ALERT_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField, UiNotice } from '@/shared/ui'

import { qnaChatbotService } from '../../services/chatbot.service'
import type { HasilImpor } from '../../types'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; imported: [] }>()

const berkas = ref<File | null>(null)
const mengunggah = ref(false)
const hasil = ref<HasilImpor | null>(null)
const galat = ref('')
const galatKolom = ref<string[]>([])
const kunciField = ref(0)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    berkas.value = null
    hasil.value = null
    galat.value = ''
    galatKolom.value = []
    kunciField.value += 1
  },
)

const berhasil = computed(() => hasil.value !== null && hasil.value.galat.length === 0)

async function unggah(): Promise<void> {
  if (!berkas.value || mengunggah.value) return
  mengunggah.value = true
  galat.value = ''
  galatKolom.value = []
  hasil.value = null
  try {
    hasil.value = await qnaChatbotService.impor(berkas.value)
    if (hasil.value.galat.length === 0) emit('imported')
  } catch (e) {
    galat.value = isApiError(e) ? e.message : 'Gagal mengimpor berkas.'
    if (isApiError(e) && e.errors) galatKolom.value = Object.values(e.errors).flat()
  } finally {
    mengunggah.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="chatbot-impor-dialog"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Impor Excel</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Gunakan template impor (4 kolom). Satu baris galat membatalkan seluruh impor.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <div class="flex flex-col gap-4">
          <UiFileField :key="kunciField" label="Berkas Excel" :allowed="['xlsx']" :max-mb="2" hint="Hanya .xlsx, maksimal 2 MB dan 1.000 baris." :disabled="mengunggah" @change="berkas = $event" />
          <p v-if="galat" :class="FORM_ALERT_CLASS" role="alert" data-testid="chatbot-impor-galat">
            {{ galat }}
            <template v-for="(m, i) in galatKolom" :key="i"><br />{{ m }}</template>
          </p>

          <div v-if="hasil" class="space-y-3" data-testid="chatbot-impor-hasil">
            <UiNotice :tone="berhasil ? 'success' : 'danger'">
              {{ berhasil ? 'Data QnA beserta Kategori dan Tag berhasil diimpor!' : 'Impor dibatalkan karena ada baris galat. Tidak ada data yang tersimpan.' }}
            </UiNotice>
            <dl v-if="berhasil" class="grid grid-cols-3 gap-2 text-center text-sm">
              <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">QnA ditambah</dt><dd class="text-h5 font-semibold" data-testid="impor-ditambah">{{ hasil.ditambah }}</dd></div>
              <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Kategori baru</dt><dd class="text-h5 font-semibold">{{ hasil.kategori_baru }}</dd></div>
              <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Tag baru</dt><dd class="text-h5 font-semibold">{{ hasil.tag_baru }}</dd></div>
            </dl>
            <div v-if="hasil.galat.length > 0">
              <p class="text-sm font-medium text-red-700">Baris galat</p>
              <ul class="list-disc pl-5 text-sm text-slate-700" data-testid="impor-daftar-galat">
                <li v-for="b in hasil.galat" :key="`g${b.baris}`">Baris {{ b.baris }}: {{ b.alasan }}</li>
              </ul>
            </div>
            <div v-if="hasil.dilewati.length > 0">
              <p class="text-sm font-medium text-slate-700">Baris dilewati</p>
              <ul class="list-disc pl-5 text-sm text-slate-600" data-testid="impor-daftar-dilewati">
                <li v-for="b in hasil.dilewati" :key="`l${b.baris}`">Baris {{ b.baris }}: {{ b.alasan }}</li>
              </ul>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">{{ berhasil ? 'Tutup' : 'Batal' }}</UiButton>
            </DialogClose>
            <UiButton :disabled="!berkas || mengunggah" data-testid="chatbot-impor-kirim" @click="unggah">{{ mengunggah ? 'Mengimpor...' : 'Impor' }}</UiButton>
          </div>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
