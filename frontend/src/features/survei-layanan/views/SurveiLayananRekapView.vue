<script setup lang="ts">
/**
 * Rekap Survei Kepuasan Layanan (F-11, MAKE-084, role 1) — padanan legacy `hr/survey/layanan`: kartu ringkasan per jenis
 * (jumlah + rata-rata, Q7-26), tabel berhalaman dengan filter jenis/rating/periode/cari, unduh XLSX (audit di server).
 * Kritik/saran dirender sebagai TEKS (menutup XSS legacy). Rekap baca-saja: tanpa kolom Aksi ⋮.
 */
import { Download } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import type { SelectOption } from '@/shared/ui'
import { UiSearchInput, UiSelect } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'

import { surveiLayananService } from '../services/surveiLayanan.service'
import { LABEL_RATING, type BarisSkl, type QuerySkl, type RingkasanSkl } from '../types'

const JENIS: SelectOption[] = [
  { value: '1', label: 'Cuti' },
  { value: '2', label: 'KARIS/KARSU' },
  { value: '3', label: 'KARPEG' },
  { value: '4', label: 'Tugas Belajar' },
  { value: '5', label: 'Izin Belajar' },
]
const RATING: SelectOption[] = [5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} — ${LABEL_RATING[n]}` }))

const ringkasan = ref<RingkasanSkl | null>(null)
const items = ref<BarisSkl[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const jenis = ref('')
const rating = ref('')
const dari = ref('')
const sampai = ref('')
const cari = ref('')
const loading = ref(false)
const mengunduh = ref(false)
const error = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const pesan = (e: unknown, c: string): string => (isApiError(e) ? e.message : c)

function query(): QuerySkl {
  return {
    jenis_rwy: jenis.value ? Number(jenis.value) : undefined,
    rate: rating.value ? Number(rating.value) : undefined,
    dari: dari.value || undefined,
    sampai: sampai.value || undefined,
    q: cari.value.trim() || undefined,
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await surveiLayananService.rekap({ ...query(), page: page.value, per_page: perPage })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = pesan(e, 'Gagal memuat rekap survei.')
  } finally {
    loading.value = false
  }
}

async function loadRingkasan(): Promise<void> {
  try {
    ringkasan.value = await surveiLayananService.ringkasan({ dari: dari.value || undefined, sampai: sampai.value || undefined })
  } catch (e) {
    error.value = pesan(e, 'Gagal memuat ringkasan survei.')
  }
}

watch([jenis, rating, cari], () => {
  page.value = 1
  void load()
})
watch([dari, sampai], () => {
  page.value = 1
  void load()
  void loadRingkasan()
})

function goTo(n: number): void {
  page.value = Math.min(Math.max(1, n), totalPages.value)
  void load()
}

async function unduh(): Promise<void> {
  mengunduh.value = true
  error.value = ''
  try {
    const blob = await surveiLayananService.unduhXlsx(query())
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'survei-layanan.xlsx'
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    error.value = pesan(e, 'Gagal mengunduh XLSX.')
  } finally {
    mengunduh.value = false
  }
}

onMounted(() => {
  void load()
  void loadRingkasan()
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="skl-rekap">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Survei Kepuasan Layanan</h1>
        <p class="text-sm text-slate-500">
          Penilaian pegawai atas layanan kepegawaian yang telah selesai.
          <template v-if="ringkasan">Periode ringkasan {{ ringkasan.periode.dari }} s.d. {{ ringkasan.periode.sampai }}.</template>
        </p>
      </div>
      <UiButton appearance="outline" :loading="mengunduh" data-testid="skl-unduh-xlsx" @click="unduh"><Download class="h-4 w-4" /> Unduh XLSX</UiButton>
    </div>

    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div v-if="ringkasan" class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6" data-testid="skl-ringkasan">
      <div v-for="j in ringkasan.per_jenis" :key="j.jenis_rwy" class="rounded-lg border border-slate-200 bg-white p-3" :data-testid="`skl-ringkasan-${j.jenis_rwy}`">
        <p class="text-xs text-slate-500">{{ j.jenis_layanan }}</p>
        <p class="text-lg font-semibold text-slate-900">{{ j.rata_rata === null ? '—' : j.rata_rata.toFixed(1) }}</p>
        <p class="text-xs text-slate-500">{{ j.jumlah }} penilaian</p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-slate-50 p-3" data-testid="skl-ringkasan-total">
        <p class="text-xs text-slate-500">Seluruh layanan</p>
        <p class="text-lg font-semibold text-slate-900">{{ ringkasan.total.rata_rata === null ? '—' : ringkasan.total.rata_rata.toFixed(1) }}</p>
        <p class="text-xs text-slate-500">{{ ringkasan.total.jumlah }} penilaian</p>
      </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSelect v-model="jenis" :options="JENIS" clearable size="sm" placeholder="Semua layanan" aria-label="Filter jenis layanan" class="w-48" />
      <UiSelect v-model="rating" :options="RATING" clearable size="sm" placeholder="Semua rating" aria-label="Filter rating" class="w-56" />
      <div class="w-44"><FormField v-model="dari" type="date" label="Dari" name="dari" /></div>
      <div class="w-44"><FormField v-model="sampai" type="date" label="Sampai" name="sampai" /></div>
      <UiSearchInput v-model="cari" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP" class="w-64" />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Jenis Layanan</th>
            <th class="px-4 py-3">NIP</th>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Unit Es.I</th>
            <th class="px-4 py-3">Unit Es.II</th>
            <th class="px-4 py-3">Kritik &amp; Saran</th>
            <th class="px-4 py-3">Tgl. Penilaian</th>
            <th class="px-4 py-3">Rating</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Belum ada penilaian yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" :data-testid="`skl-row-${row.id}`">
            <td class="px-4 py-3 text-slate-700">{{ row.jenis_layanan }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.nip }}</td>
            <td class="px-4 py-3 text-slate-800">{{ row.nama }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.unit || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.satker || '—' }}</td>
            <td class="whitespace-pre-line px-4 py-3 text-slate-700">{{ row.kritik_saran || '—' }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.dinilai_at }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ row.rate }} — {{ LABEL_RATING[row.rate] }}</td>
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
