<script setup lang="ts">
/**
 * Halo Simpeg (pegawai, §4.2.5, Gambar 33): drawer chat dengan admin, dibuka lewat ?chat=1 pada halaman FAQ.
 * DATA CONTOH: pesan disimpan di memori (halo.store.ts) — belum tersambung ke backend.
 */
import { Send, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref } from 'vue'

import { UiButton } from '@/shared/ui'
import { ILLUSTRATIONS } from '@/shared/ui/placeholderAssets'

import { haloStore } from '../halo.store'

defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const draft = ref('')
const messages = computed(() => haloStore.mine()?.messages ?? [])
const time = (iso: string): string => new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })

function submit(): void {
  if (!draft.value.trim()) return
  haloStore.send(draft.value)
  draft.value = ''
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/40" />
      <DialogContent
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-panel"
        data-testid="halo-drawer"
      >
        <header class="flex items-start justify-between gap-3 bg-brand-primary p-5 text-white">
          <div>
            <DialogTitle class="text-h4 font-semibold">Halo Simpeg</DialogTitle>
            <DialogDescription class="mt-1 text-body2 text-white/80">Tanyakan langsung kepada admin kepegawaian.</DialogDescription>
          </div>
          <DialogClose class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-white/10" aria-label="Tutup Halo Simpeg">
            <X class="h-4 w-4" aria-hidden="true" />
          </DialogClose>
        </header>

        <div class="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-5" data-testid="halo-messages">
          <div v-if="messages.length === 0" class="flex flex-col items-center gap-3 pt-8 text-center">
            <img :src="ILLUSTRATIONS.support.url" alt="" class="h-32" />
            <p class="text-body1 text-slate-600">Belum ada percakapan. Tulis pertanyaan Anda di bawah.</p>
          </div>
          <div v-for="m in messages" :key="m.id" class="flex" :class="m.from === 'user' ? 'justify-end' : 'justify-start'">
            <div
              class="max-w-[80%] rounded-2xl px-4 py-2 text-body1"
              :class="m.from === 'user' ? 'bg-brand-tertiary text-white' : 'border border-slate-200 bg-white text-slate-800'"
              :data-from="m.from"
            >
              <p class="whitespace-pre-wrap break-words">{{ m.text }}</p>
              <p class="mt-1 text-right text-overline opacity-70">{{ time(m.at) }}</p>
            </div>
          </div>
        </div>

        <form class="flex items-end gap-2 border-t border-slate-200 p-4" @submit.prevent="submit">
          <label class="sr-only" for="halo-input">Pesan</label>
          <textarea
            id="halo-input"
            v-model="draft"
            rows="2"
            maxlength="1000"
            placeholder="Tulis pesan…"
            class="min-h-[2.75rem] flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-body1 focus-visible:border-brand-tertiary"
            data-testid="halo-input"
            @keydown.enter.exact.prevent="submit"
          />
          <UiButton type="submit" :disabled="!draft.trim()" data-testid="halo-send" aria-label="Kirim pesan">
            <template #icon-left><Send class="h-4 w-4" aria-hidden="true" /></template>
            Kirim
          </UiButton>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
