<script setup lang="ts">
/**
 * Rekap respons form digital (F-06, MAKE-083, role 1): tabel berhalaman dengan kolom dinamis dari SKEMA form (bukan dari
 * jawaban pertama), unit/satker, waktu isi (WIB), unduh XLSX (semua baris, audit di server) dan unduh berkas jawaban.
 * Isi jawaban dirender sebagai teks (menutup XSS legacy). Rekap baca-saja: tanpa kolom Aksi ⋮.
 */
import { ArrowLeft, Download, Paperclip } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiSearchInput } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'

import { FORM_DIGITAL_ROUTE } from '../routes'
import { formDigitalService } from '../services/formDigital.service'
import type { BarisRespons, KolomRespons } from '../types'

const props = defineProps<{ idForm: number }>()

const kolom = ref<KolomRespons[]>([])
const items = ref<BarisRespons[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const cari = ref('')
const loading = ref(false)
const mengunduh = ref(false)
const error = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await formDigitalService.respons(props.idForm, { page: page.value, per_page: perPage, q: cari.value.trim() || undefined })
    kolom.value = r.kolom
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal memuat respons.'
  } finally {
    loading.value = false
  }
}

watch(cari, () => {
  page.value = 1
  void load()
})

function goTo(n: number): void {
  page.value = Math.min(Math.max(1, n), totalPages.value)
  void load()
}

function simpanBlob(blob: Blob, nama: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nama
  a.click()
  URL.revokeObjectURL(url)
}

async function unduhXlsx(): Promise<void> {
  mengunduh.value = true
  error.value = ''
  try {
    simpanBlob(await formDigitalService.unduhXlsx(props.idForm), `respons-form-digital-${props.idForm}.xlsx`)
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal mengunduh XLSX.'
  } finally {
    mengunduh.value = false
  }
}

async function unduhBerkas(row: BarisRespons, idPertanyaan: string): Promise<void> {
  error.value = ''
  try {
    simpanBlob(await formDigitalService.unduhBerkas(props.idForm, row.id, idPertanyaan), row.jawaban[idPertanyaan] || 'berkas')
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal mengunduh berkas.'
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="form-digital-respons">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <UiButton appearance="ghost" size="sm" variant="secondary" :to="{ name: FORM_DIGITAL_ROUTE.KELOLA }">
          <ArrowLeft class="h-4 w-4" /> Kembali
        </UiButton>
        <h1 class="mt-1 text-xl font-semibold text-slate-900">Respons Form Digital</h1>
        <p class="text-sm text-slate-500">{{ total }} jawaban masuk.</p>
      </div>
      <UiButton appearance="outline" :loading="mengunduh" data-testid="form-digital-unduh-xlsx" @click="unduhXlsx">
        <Download class="h-4 w-4" /> Unduh XLSX
      </UiButton>
    </div>

    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSearchInput v-model="cari" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP" class="w-64" />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Unit</th>
            <th class="px-4 py-3">Satker</th>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">NIP</th>
            <th v-for="k in kolom" :key="k.id" class="px-4 py-3" :data-testid="`form-digital-kolom-${k.id}`">{{ k.label }}</th>
            <th class="px-4 py-3">Waktu Isi (WIB)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td :colspan="kolom.length + 5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td :colspan="kolom.length + 5" class="px-4 py-8 text-center text-slate-500">Belum ada jawaban.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" :data-testid="`form-digital-respons-${row.id}`">
            <td class="px-4 py-3 text-slate-600">{{ row.unit || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.satker || '—' }}</td>
            <td class="px-4 py-3 text-slate-800">{{ row.nama }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.nip }}</td>
            <td v-for="k in kolom" :key="k.id" class="whitespace-pre-line px-4 py-3 text-slate-700">
              <button
                v-if="row.berkas.includes(k.id)"
                type="button"
                class="inline-flex items-center gap-1 text-brand-tertiary hover:underline"
                :data-testid="`form-digital-berkas-${row.id}-${k.id}`"
                @click="unduhBerkas(row, k.id)"
              >
                <Paperclip class="h-4 w-4" aria-hidden="true" />{{ row.jawaban[k.id] }}
              </button>
              <template v-else>{{ row.jawaban[k.id] || '—' }}</template>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.diisi_at }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span>{{ total }} data · halaman {{ page }} dari {{ totalPages }}</span>
      <div class="flex gap-1">
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">Berikutnya</button>
      </div>
    </div>
  </section>
</template>
