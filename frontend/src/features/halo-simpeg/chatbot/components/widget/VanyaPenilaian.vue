<script setup lang="ts">
/**
 * Penilaian 👍/👎 untuk jawaban yang cocok (PRD §7.10.3, `dev:VP:283-314`): hanya bila `bisa_feedback`. Sesudah menilai, label
 * berganti teks terima kasih dan hanya tombol yang dipilih tersisa (bertanda); gagal → pesan server (atau "Penilaian gagal
 * terkirim.") + "Coba lagi:" dan kedua tombol aktif kembali. Tombol ber-`aria-label` + `UiTooltip` (dev memakai atribut
 * `title`; AGENTS.md §3 melarangnya).
 */
import { ThumbsDown, ThumbsUp } from 'lucide-vue-next'
import { computed } from 'vue'

import { UiTooltip } from '@/shared/ui'

import { TEKS_VANYA } from '../../constants'
import type { Penilaian } from '../../stores/percakapan'
import type { NilaiFeedback } from '../../types'

const props = defineProps<{ penilaian: Penilaian; idPesan: number }>()
const emit = defineEmits<{ nilai: [nilai: NilaiFeedback] }>()

const TOMBOL = [
  { nilai: 1 as const, label: TEKS_VANYA.membantu, icon: ThumbsUp, aksi: 'suka', kelasTerpilih: 'border-success bg-success-soft text-green-700' },
  { nilai: -1 as const, label: TEKS_VANYA.tidakMembantu, icon: ThumbsDown, aksi: 'tidak-suka', kelasTerpilih: 'border-danger bg-danger-soft text-red-700' },
]

const terkirim = computed(() => props.penilaian.status === 'terkirim')
const tombol = computed(() => (terkirim.value ? TOMBOL.filter((t) => t.nilai === props.penilaian.nilai) : TOMBOL))
const label = computed(() => {
  const p = props.penilaian
  if (p.status === 'terkirim') return p.nilai === 1 ? TEKS_VANYA.terimaKasihNaik : TEKS_VANYA.terimaKasihTurun
  if (p.status === 'gagal') return `${p.galat} ${TEKS_VANYA.cobaLagi}`
  return TEKS_VANYA.penilaian
})
</script>

<template>
  <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500" :data-testid="`vanya-penilaian-${idPesan}`" :data-status="penilaian.status">
    <span :role="penilaian.status === 'gagal' ? 'alert' : penilaian.status === 'terkirim' ? 'status' : undefined" data-testid="vanya-penilaian-label">{{ label }}</span>
    <UiTooltip v-for="t in tombol" :key="t.nilai" :content="t.label" :disabled="terkirim">
      <button
        type="button"
        class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 hover:bg-slate-100 hover:text-brand-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary disabled:cursor-default"
        :class="terkirim ? t.kelasTerpilih : ''"
        :aria-label="t.label"
        :aria-pressed="terkirim ? 'true' : undefined"
        :data-action="t.aksi"
        :disabled="terkirim || penilaian.status === 'mengirim'"
        @click="emit('nilai', t.nilai)"
      >
        <component :is="t.icon" class="h-3.5 w-3.5" aria-hidden="true" />
      </button>
    </UiTooltip>
  </div>
</template>
