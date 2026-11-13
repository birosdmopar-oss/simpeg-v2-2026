<script setup lang="ts">
/**
 * Chatbot Manager → Statistik (role 1; PRD Fase 8 v1.1 §7.10.5; `dev:V/dashboard_statistik.php:44-110`): KPI (total chat, chat
 * hari ini WIB, rata-rata skor semua log dan log cocok, 4 desimal), kepuasan (membantu, tidak membantu, total, persen puas 1
 * desimal), dan tiga tabel top-10. QnA yang sudah tidak ada tampil `#id`. Semua teks ter-escape (tanpa `v-html`, A12).
 */
import { MessageSquare, MessagesSquare, Target, ThumbsUp } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiCard, UiNotice, UiStatTile } from '@/shared/ui'

import { statistikChatbotService } from '../../services/chatbot.service'
import type { StatistikChatbot } from '../../types'

const data = ref<StatistikChatbot | null>(null)
const memuat = ref(false)
const failure = ref('')

const des = (n: number, d: number): string => n.toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d })
const bulat = (n: number): string => n.toLocaleString('id-ID')
const qna = (r: { id_qna: number; pertanyaan: string | null }): string => r.pertanyaan ?? `#${r.id_qna}`

const tabel = computed(() =>
  data.value
    ? [
        { key: 'pesan', judul: 'Top 10 pesan paling sering', kolom: 'Pesan', baris: data.value.top_pesan.map((r) => ({ teks: r.pesan_user, jumlah: r.jumlah })) },
        { key: 'cocok', judul: 'Top 10 QnA paling sering cocok', kolom: 'QnA', baris: data.value.top_qna_cocok.map((r) => ({ teks: qna(r), jumlah: r.jumlah })) },
        { key: 'negatif', judul: 'Top 10 QnA dengan 👎 terbanyak', kolom: 'QnA', baris: data.value.top_qna_negatif.map((r) => ({ teks: qna(r), jumlah: r.jumlah })) },
      ]
    : [],
)

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = ''
  try {
    data.value = await statistikChatbotService.statistik()
  } catch (e) {
    failure.value = isApiError(e) ? e.message : 'Gagal memuat statistik chatbot.'
  } finally {
    memuat.value = false
  }
}

onMounted(() => void muat())
</script>

<template>
  <section class="flex flex-col gap-4" data-testid="chatbot-statistik-page">
    <div>
      <h1 class="text-h2 font-semibold text-slate-900">Statistik Chatbot</h1>
      <p class="text-body2 text-slate-500">Chatbot Manager · ringkasan interaksi pegawai dengan Vanya (waktu WIB)</p>
    </div>

    <UiNotice v-if="failure" tone="danger"><span role="alert">{{ failure }}</span></UiNotice>
    <p v-if="memuat" class="text-sm text-slate-500">Memuat...</p>

    <template v-if="data">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="chatbot-statistik-kpi">
        <UiStatTile label="Total chat" :value="bulat(data.kpi.total_chat)" :icon="MessagesSquare" />
        <UiStatTile label="Chat hari ini" :value="bulat(data.kpi.chat_hari_ini)" :icon="MessageSquare" tone="info" />
        <UiStatTile label="Rata-rata skor" :value="des(data.kpi.rata_skor, 4)" :hint="`Log cocok: ${des(data.kpi.rata_skor_cocok, 4)}`" :icon="Target" tone="warning" />
        <UiStatTile
          label="Kepuasan"
          :value="`${des(data.kepuasan.persen_puas, 1)}%`"
          :hint="`${bulat(data.kepuasan.membantu)} membantu · ${bulat(data.kepuasan.tidak_membantu)} tidak membantu · ${bulat(data.kepuasan.total)} penilaian`"
          :icon="ThumbsUp"
          tone="success"
        />
      </div>

      <div class="grid gap-4 xl:grid-cols-3">
        <UiCard v-for="t in tabel" :key="t.key" :title="t.judul" flush :data-testid="`chatbot-statistik-${t.key}`">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="w-12 px-4 py-2">No</th>
                <th class="px-4 py-2">{{ t.kolom }}</th>
                <th class="px-4 py-2 text-right">Jumlah</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="t.baris.length === 0">
                <td colspan="3" class="px-4 py-6 text-center text-slate-500">Belum ada data.</td>
              </tr>
              <tr v-for="(b, i) in t.baris" :key="i">
                <td class="px-4 py-2 text-slate-500">{{ i + 1 }}</td>
                <td class="break-words px-4 py-2 text-slate-800">{{ b.teks }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ bulat(b.jumlah) }}</td>
              </tr>
            </tbody>
          </table>
        </UiCard>
      </div>
    </template>
  </section>
</template>
