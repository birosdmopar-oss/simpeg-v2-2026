<script setup lang="ts">
/**
 * Stepper mendatar status layanan — kolom "Status Layanan" §4.1.4: lingkaran bercentang (selesai),
 * lingkaran cincin (sedang berjalan), lingkaran pudar (belum), dihubungkan garis.
 * Warna mengikuti jenis layanan pada mockup (biru/toska untuk proses normal, oranye untuk menunggu).
 *
 * Reusable.
 */
import { Check } from 'lucide-vue-next'

export type Step = { label: string; state: 'done' | 'current' | 'todo' }

withDefaults(defineProps<{ steps: Step[]; tone?: 'info' | 'primary' | 'warning' }>(), { tone: 'info' })

const TONES = {
  info: { done: 'bg-info text-white', current: 'border-info text-info', line: 'bg-info', faint: 'border-info/30' },
  primary: {
    done: 'bg-brand-tertiary text-white',
    current: 'border-brand-tertiary text-brand-tertiary',
    line: 'bg-brand-tertiary',
    faint: 'border-brand-tertiary/30',
  },
  warning: { done: 'bg-warning text-white', current: 'border-warning text-warning', line: 'bg-warning', faint: 'border-warning/30' },
} as const
</script>

<template>
  <ol class="flex items-start">
    <li v-for="(step, index) in steps" :key="step.label" class="flex min-w-0 flex-1 items-start last:flex-none">
      <div class="flex w-24 flex-col items-center gap-1.5 text-center">
        <span
          class="flex h-6 w-6 items-center justify-center rounded-full border-2 transition"
          :class="[
            step.state === 'done'
              ? `border-transparent ${TONES[tone].done}`
              : step.state === 'current'
                ? `bg-white ${TONES[tone].current}`
                : `bg-white ${TONES[tone].faint} opacity-60`,
          ]"
        >
          <Check v-if="step.state === 'done'" class="h-3.5 w-3.5" stroke-width="3" aria-hidden="true" />
          <span v-else class="h-2 w-2 rounded-full" :class="step.state === 'current' ? TONES[tone].line : 'bg-transparent'" />
        </span>
        <span class="text-caption leading-tight" :class="step.state === 'todo' ? 'text-slate-400' : 'text-slate-600'">
          {{ step.label }}
        </span>
      </div>

      <span
        v-if="index < steps.length - 1"
        class="mt-3 h-0.5 min-w-4 flex-1 rounded-full"
        :class="steps[index + 1].state === 'todo' ? 'bg-slate-200' : TONES[tone].line"
        aria-hidden="true"
      />
    </li>
  </ol>
</template>
