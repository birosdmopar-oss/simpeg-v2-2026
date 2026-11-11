<script setup lang="ts">
/**
 * Daftar penerima form digital (F-06, MAKE-083, role 1): siapa yang sudah dan belum mengisi (Q7-20: belum = target − sudah).
 * Baca-saja; berhalaman; filter sudah/belum.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import type { SelectOption } from '@/shared/ui'
import { UiSelect } from '@/shared/ui'

import { formDigitalService } from '../services/formDigital.service'
import type { BarisTarget, FormRingkas } from '../types'

const props = defineProps<{ row: FormRingkas | null }>()
const emit = defineEmits<{ close: [] }>()

const FILTER: SelectOption[] = [
  { value: 'belum', label: 'Belum mengisi' },
  { value: 'sudah', label: 'Sudah mengisi' },
]

const items = ref<BarisTarget[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const isi = ref('')
const loading = ref(false)
const error = ref('')

const open = computed({
  get: () => props.row !== null,
  set: (v: boolean) => {
    if (!v) emit('close')
  },
})
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

async function load(): Promise<void> {
  if (!props.row) return
  loading.value = true
  error.value = ''
  try {
    const r = await formDigitalService.target(props.row.id, { page: page.value, per_page: perPage, isi: (isi.value || undefined) as 'sudah' | 'belum' | undefined })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal memuat target.'
  } finally {
    loading.value = false
  }
}

watch(
  () => props.row?.id,
  (id) => {
    if (id === undefined) return
    page.value = 1
    isi.value = ''
    void load()
  },
)
watch(isi, () => {
  page.value = 1
  void load()
})

function goTo(n: number): void {
  page.value = Math.min(Math.max(1, n), totalPages.value)
  void load()
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="form-digital-target"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Target: {{ row?.judul_form }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{ row?.sudah_mengisi ?? 0 }} dari {{ row?.jumlah_target ?? 0 }} pegawai sudah mengisi.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>
        <UiSelect v-model="isi" :options="FILTER" clearable size="sm" placeholder="Semua penerima" aria-label="Filter pengisian" class="mb-3 w-52" />
        <p v-if="error" class="mb-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">NIP</th>
              <th class="px-3 py-2">Nama</th>
              <th class="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading">
              <td colspan="3" class="px-3 py-6 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="3" class="px-3 py-6 text-center text-slate-500">Tidak ada penerima.</td>
            </tr>
            <tr v-for="t in items" v-else :key="t.nip" :data-testid="`form-digital-target-${t.nip}`">
              <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ t.nip }}</td>
              <td class="px-3 py-2 text-slate-700">{{ t.nama }}</td>
              <td class="px-3 py-2">
                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="t.sudah_mengisi ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'">
                  {{ t.sudah_mengisi ? 'Sudah mengisi' : 'Belum mengisi' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="mt-3 flex items-center justify-between text-sm text-slate-600">
          <span>{{ total }} penerima · halaman {{ page }} dari {{ totalPages }}</span>
          <div class="flex gap-1">
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">Berikutnya</button>
          </div>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
