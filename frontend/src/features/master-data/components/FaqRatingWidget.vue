<script setup lang="ts">
/**
 * G-10 — widget rating artikel FAQ (DBV-002/CR-003 §6, alur legacy detail.php:119-171).
 * Tampil hanya bila `rating.can_rate` (role 2/6/7 yang belum menilai); bila sudah menilai → ucapan terima kasih
 * tanpa tombol. Membantu = rate 1; Kurang Membantu = pilih alasan (4 teks baku legacy / "Lainnya" + teks bebas)
 * lalu kirim rate 2. Rating tidak bisa diubah/dihapus; hak menilai ditegakkan backend.
 */
import { CircleCheck, ThumbsDown, ThumbsUp } from 'lucide-vue-next'
import { computed, ref, useId } from 'vue'

import { isApiError } from '@/lib/axios'

import { FAQ_RATE_REASONS, FAQ_REASON_OTHER, faqReasonSchema } from '../schemas/faq.schema'
import { faqService } from '../services/faq.service'
import type { FaqRate, FaqRating } from '../types'

const props = defineProps<{ articleId: string; rating: FaqRating }>()
const emit = defineEmits<{ rated: [rate: FaqRate] }>()

const THANKS = 'Terima kasih atas penilaian Anda.'

type Step = 'ask' | 'reason' | 'done'

const step = ref<Step>(props.rating.rated ? 'done' : 'ask')
const visible = computed(() => props.rating.rated || props.rating.can_rate || step.value === 'done')
const doneMessage = ref(THANKS)

const choice = ref('')
const other = ref('')
const fieldErrors = ref<{ choice?: string; other?: string }>({})
const serverError = ref('')
const submitting = ref(false)

const radioName = `faq-rate-reason-${useId()}`

async function send(rate: FaqRate, reason?: string): Promise<void> {
  submitting.value = true
  serverError.value = ''
  try {
    const result = await faqService.rate(props.articleId, rate === 2 ? { rate, reason } : { rate })
    doneMessage.value = THANKS
    step.value = 'done'
    emit('rated', result.rate)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors?.rate?.[0]) {
      // Nilai rate yang dikirim selalu 1/2, sehingga 422 pada `rate` berarti artikel sudah dinilai (mis. dari tab lain).
      doneMessage.value = err.errors.rate[0]
      step.value = 'done'
    } else if (isApiError(err)) {
      serverError.value = err.errors?.reason?.[0] ?? err.message
    } else {
      serverError.value = 'Penilaian gagal dikirim. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
}

function openReason(): void {
  serverError.value = ''
  step.value = 'reason'
}

function cancelReason(): void {
  choice.value = ''
  other.value = ''
  fieldErrors.value = {}
  serverError.value = ''
  step.value = 'ask'
}

async function submitReason(): Promise<void> {
  const parsed = faqReasonSchema.safeParse({ choice: choice.value, other: other.value })
  if (!parsed.success) {
    const errors: { choice?: string; other?: string } = {}
    for (const issue of parsed.error.issues) {
      const key = issue.path[0] === 'other' ? 'other' : 'choice'
      errors[key] ??= issue.message
    }
    fieldErrors.value = errors
    return
  }
  fieldErrors.value = {}
  await send(2, parsed.data)
}
</script>

<template>
  <section v-if="visible" class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite" data-testid="faq-rating">
    <p v-if="step === 'done'" class="flex items-center gap-2 text-sm font-medium text-[#1c7a4a]" data-testid="faq-rating-done">
      <CircleCheck class="h-5 w-5 shrink-0" /> {{ doneMessage }}
    </p>

    <div v-else-if="step === 'ask'">
      <p class="font-medium text-slate-800">Apakah artikel ini membantu?</p>
      <p class="text-xs text-slate-500">Penilaian Anda membantu kami meningkatkan kualitas artikel ini.</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-lg border border-success/40 bg-white px-3 py-2 text-sm font-medium text-[#1c7a4a] hover:bg-success-soft disabled:opacity-60"
          :disabled="submitting"
          data-testid="faq-rate-yes"
          @click="send(1)"
        >
          <ThumbsUp class="h-4 w-4" /> Membantu
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200 disabled:opacity-60"
          :disabled="submitting"
          data-testid="faq-rate-no"
          @click="openReason"
        >
          <ThumbsDown class="h-4 w-4" /> Kurang Membantu
        </button>
      </div>
    </div>

    <form v-else class="space-y-3" novalidate data-testid="faq-reason-form" @submit.prevent="submitReason">
      <fieldset class="space-y-2" :aria-describedby="fieldErrors.choice ? `${radioName}-error` : undefined">
        <legend class="mb-1 font-medium text-slate-800">Apa yang kurang dari artikel ini?</legend>
        <label v-for="reason in FAQ_RATE_REASONS" :key="reason" class="flex items-start gap-2 text-sm text-slate-700">
          <input v-model="choice" type="radio" :name="radioName" :value="reason" class="mt-1" />
          <span>{{ reason }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-slate-700">
          <input v-model="choice" type="radio" :name="radioName" :value="FAQ_REASON_OTHER" class="mt-1" data-testid="faq-reason-other-radio" />
          <span>Lainnya</span>
        </label>
        <div v-if="choice === FAQ_REASON_OTHER" class="pl-6">
          <input
            v-model="other"
            type="text"
            maxlength="255"
            placeholder="Tuliskan alasan Anda"
            aria-label="Alasan lainnya"
            class="block w-full rounded-lg border px-3 py-2 text-sm shadow-sm outline-none focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/40"
            :class="fieldErrors.other ? 'border-danger bg-white' : 'border-slate-300 bg-white'"
            :aria-invalid="Boolean(fieldErrors.other)"
            :aria-describedby="fieldErrors.other ? `${radioName}-other-error` : undefined"
            data-testid="faq-reason-other"
          />
          <p v-if="fieldErrors.other" :id="`${radioName}-other-error`" class="mt-1 text-caption text-danger" role="alert">{{ fieldErrors.other }}</p>
        </div>
        <p v-if="fieldErrors.choice" :id="`${radioName}-error`" class="text-caption text-danger" role="alert">{{ fieldErrors.choice }}</p>
      </fieldset>
      <div class="flex gap-2">
        <button
          type="submit"
          class="rounded-lg bg-brand-tertiary text-white transition hover:bg-[#1667e0] px-4 py-2 text-sm font-medium disabled:opacity-60"
          :disabled="submitting"
          data-testid="faq-reason-submit"
        >
          {{ submitting ? 'Mengirim...' : 'Kirim' }}
        </button>
        <button type="button" class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200" :disabled="submitting" @click="cancelReason">
          Batal
        </button>
      </div>
    </form>

    <p v-if="serverError" class="mt-3 rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-[#a52b2c]" role="alert" data-testid="faq-rating-error">
      {{ serverError }}
    </p>
  </section>
</template>
