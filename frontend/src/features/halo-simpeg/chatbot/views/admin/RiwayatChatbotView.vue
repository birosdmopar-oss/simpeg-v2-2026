<script setup lang="ts">
/**
 * Chatbot Manager → Riwayat (role 1; PRD Fase 8 v1.1 §7.10.5, F8-42; `dev:V/list_log.php:11-43`): Waktu (WIB), Pesan pengguna,
 * QnA teratas ("(Tidak ada kecocokan)" bila kosong), Skor, Status ("Cocok"/"Fallback"), Feedback (ikon + teks pembaca layar).
 * Paginasi dan filter (rentang tanggal WIB inklusif, status cocok, feedback) di server; tanpa aksi baris dan tanpa ekspor.
 * Pesan pengguna selalu teks ter-escape (A12, AC-E10.18).
 */
import { ThumbsDown, ThumbsUp } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { formatApiDateTime } from '@/shared/utils/dateTime'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSelect, UiTextField, type SelectOption } from '@/shared/ui'

import { galatPerKolom } from '../../galat'
import { statistikChatbotService } from '../../services/chatbot.service'
import type { FilterRiwayat, NilaiFeedback, RiwayatRow } from '../../types'

const PER_PAGE = 25
const OPSI_COCOK: SelectOption[] = [
  { value: '1', label: 'Cocok' },
  { value: '0', label: 'Fallback' },
]
const OPSI_FEEDBACK: SelectOption[] = [
  { value: '1', label: 'Membantu' },
  { value: '-1', label: 'Tidak membantu' },
  { value: '0', label: 'Belum dinilai' },
]
const WIB: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }

const items = ref<RiwayatRow[]>([])
const total = ref(0)
const page = ref(1)
const dari = ref('')
const sampai = ref('')
const cocok = ref('')
const feedback = ref('')
const memuat = ref(false)
const failure = ref('')
const fieldErrors = ref<Record<string, string>>({})

const waktu = (v: string): string => formatApiDateTime(v, WIB) ?? v
const skor = (n: number): string => n.toLocaleString('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
const nomor = (i: number): number => (page.value - 1) * PER_PAGE + i + 1

function filter(): FilterRiwayat {
  return {
    page: page.value,
    per_page: PER_PAGE,
    dari: dari.value || undefined,
    sampai: sampai.value || undefined,
    cocok: cocok.value === '' ? undefined : (Number(cocok.value) as 0 | 1),
    feedback: feedback.value === '' ? undefined : (Number(feedback.value) as NilaiFeedback | 0),
  }
}

/** Penjaga urutan (pola FaqView `searchSeq`, M-3): hanya respons permintaan terakhir yang ditulis. */
let urutanMuat = 0

async function muat(): Promise<void> {
  const n = ++urutanMuat
  memuat.value = true
  failure.value = ''
  fieldErrors.value = {}
  try {
    const r = await statistikChatbotService.riwayat(filter())
    if (n !== urutanMuat) return
    items.value = r.items
    total.value = r.total
  } catch (e) {
    if (n !== urutanMuat) return
    items.value = []
    total.value = 0
    if (isApiError(e) && e.status === 422) fieldErrors.value = galatPerKolom(e)
    failure.value = isApiError(e) ? e.message : 'Gagal memuat riwayat chat.'
  } finally {
    if (n === urutanMuat) memuat.value = false
  }
}

watch([dari, sampai, cocok, feedback], () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

function reset(): void {
  dari.value = ''
  sampai.value = ''
  cocok.value = ''
  feedback.value = ''
}

onMounted(() => void muat())
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="chatbot-riwayat-page">
    <div>
      <h1 class="text-h2 font-semibold text-slate-900">Riwayat Chat</h1>
      <p class="text-body2 text-slate-500">Chatbot Manager · log percakapan anonim Vanya (waktu WIB)</p>
    </div>

    <UiNotice v-if="failure" tone="danger"><span role="alert">{{ failure }}</span></UiNotice>

    <UiCard title="Log Chat" flush fill class="flex min-h-0 flex-1 flex-col">
      <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <UiTextField
          v-model="dari"
          type="date"
          label="Dari"
          class="w-44"
          :max="sampai || undefined"
          :state="fieldErrors.dari ? 'error' : 'default'"
          :help-text="fieldErrors.dari"
          data-testid="chatbot-riwayat-dari"
        />
        <UiTextField
          v-model="sampai"
          type="date"
          label="Sampai"
          class="w-44"
          :min="dari || undefined"
          :state="fieldErrors.sampai ? 'error' : 'default'"
          :help-text="fieldErrors.sampai"
          data-testid="chatbot-riwayat-sampai"
        />
        <UiSelect v-model="cocok" :options="OPSI_COCOK" clearable size="sm" placeholder="Semua status" aria-label="Filter status cocok" class="w-40" />
        <UiSelect v-model="feedback" :options="OPSI_FEEDBACK" clearable size="sm" placeholder="Semua feedback" aria-label="Filter feedback" class="w-44" />
        <UiButton variant="secondary" appearance="ghost" size="sm" data-testid="chatbot-riwayat-reset" @click="reset">Atur ulang</UiButton>
      </div>
      <div class="table-fill scrollbar-slim overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="w-14 px-4 py-3">No</th>
              <th class="px-4 py-3">Waktu</th>
              <th class="px-4 py-3">Pesan pengguna</th>
              <th class="px-4 py-3">QnA teratas</th>
              <th class="px-4 py-3 text-right">Skor</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Feedback</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="memuat">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada riwayat yang cocok.</td>
            </tr>
            <tr v-for="(row, i) in items" v-else :key="row.id_log" class="align-top hover:bg-slate-50" :data-testid="`chatbot-riwayat-row-${row.id_log}`">
              <td class="px-4 py-3 text-slate-500">{{ nomor(i) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-slate-600" data-kolom="waktu">{{ waktu(row.waktu) }}</td>
              <td class="max-w-sm whitespace-pre-wrap break-words px-4 py-3 text-slate-800" data-kolom="pesan">{{ row.pesan_user }}</td>
              <td class="max-w-sm px-4 py-3 text-slate-700">
                <span v-if="row.pertanyaan_teratas">{{ row.pertanyaan_teratas }}</span>
                <span v-else class="text-slate-400">(Tidak ada kecocokan)</span>
              </td>
              <td class="px-4 py-3 text-right tabular-nums">{{ skor(row.skor) }}</td>
              <td class="px-4 py-3">
                <UiBadge :tone="row.cocok ? 'success' : 'warning'" size="sm">{{ row.cocok ? 'Cocok' : 'Fallback' }}</UiBadge>
              </td>
              <td class="px-4 py-3" data-kolom="feedback">
                <span v-if="row.feedback === 1" class="inline-flex items-center gap-1 text-green-700"><ThumbsUp class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Membantu</span></span>
                <span v-else-if="row.feedback === -1" class="inline-flex items-center gap-1 text-red-700"><ThumbsDown class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Tidak membantu</span></span>
                <span v-else class="text-slate-400">—<span class="sr-only">Belum dinilai</span></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="total > PER_PAGE" class="p-4"><UiPagination v-model:page="page" :per-page="PER_PAGE" :total="total" /></div>
    </UiCard>
  </section>
</template>
