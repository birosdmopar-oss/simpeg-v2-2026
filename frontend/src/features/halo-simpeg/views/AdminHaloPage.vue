<script setup lang="ts">
/**
 * Admin Halo Simpeg (Gambar 34): inbox percakapan (kiri) + jendela balasan (kanan). Halaman penuh tanpa sidebar.
 * Akses: role 1 & 3 (ASUMSI). DATA CONTOH: percakapan di memori (halo.store.ts).
 */
import { ArrowLeft, Send } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { UiAvatar, UiBadge, UiButton, UiCard, UiSearchInput } from '@/shared/ui'
import { placeholderAvatar } from '@/shared/ui/placeholderAssets'

import { haloStore } from '../halo.store'

const search = ref('')
const activeId = ref<number | null>(null)
const draft = ref('')

const threads = computed(() => {
  const q = search.value.trim().toLowerCase()
  return haloStore.state.threads.filter((t) => t.nama.toLowerCase().includes(q))
})
const active = computed(() => haloStore.state.threads.find((t) => t.id === activeId.value) ?? null)
const time = (iso: string): string => new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })

function open(id: number): void {
  activeId.value = id
  haloStore.markRead(id)
}
function submit(): void {
  if (!active.value || !draft.value.trim()) return
  haloStore.reply(active.value.id, draft.value)
  draft.value = ''
}
</script>

<template>
  <main class="min-h-screen bg-slate-100 p-4 sm:p-6" data-testid="halo-admin">
    <div class="mx-auto max-w-6xl space-y-4">
      <div class="flex items-center gap-3">
        <RouterLink :to="{ name: 'home' }" class="inline-flex items-center gap-1 text-body2 font-medium text-brand-tertiary hover:underline">
          <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Kembali
        </RouterLink>
        <h1 class="text-h2 font-semibold text-slate-900">Admin Halo Simpeg</h1>
      </div>

      <div class="grid gap-4 md:grid-cols-[20rem_1fr]">
        <UiCard title="Percakapan" flush>
          <div class="space-y-3 pb-3">
            <div class="px-4"><UiSearchInput v-model="search" placeholder="Cari nama" label="Cari percakapan" /></div>
            <ul class="divide-y divide-slate-100" data-testid="halo-threads">
              <li v-for="t in threads" :key="t.id">
                <button
                  type="button"
                  class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50"
                  :class="t.id === activeId ? 'bg-brand-tertiary/5' : ''"
                  :data-testid="`halo-thread-${t.id}`"
                  @click="open(t.id)"
                >
                  <UiAvatar :name="t.nama" :src="placeholderAvatar(t.seed)" size="md" alt="" />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-slate-900">{{ t.nama }}</span>
                    <span class="block truncate text-body2 text-slate-500">{{ t.messages[t.messages.length - 1]?.text }}</span>
                  </span>
                  <UiBadge v-if="t.unread > 0" tone="danger" :data-testid="`halo-unread-${t.id}`">{{ t.unread }}</UiBadge>
                </button>
              </li>
              <li v-if="threads.length === 0" class="px-4 py-8 text-center text-body2 text-slate-500" data-testid="halo-empty">Tidak ada percakapan</li>
            </ul>
          </div>
        </UiCard>

        <UiCard :title="active ? active.nama : 'Pilih percakapan'" :subtitle="active?.unit" flush>
          <p v-if="!active" class="px-5 py-16 text-center text-body1 text-slate-500">Pilih percakapan di sebelah kiri untuk membalas.</p>
          <template v-else>
            <div class="max-h-[26rem] min-h-[16rem] space-y-3 overflow-y-auto bg-slate-50 p-5" data-testid="halo-admin-messages">
              <div v-for="m in active.messages" :key="m.id" class="flex" :class="m.from === 'admin' ? 'justify-end' : 'justify-start'">
                <div
                  class="max-w-[80%] rounded-2xl px-4 py-2 text-body1"
                  :class="m.from === 'admin' ? 'bg-brand-tertiary text-white' : 'border border-slate-200 bg-white text-slate-800'"
                >
                  <p class="whitespace-pre-wrap break-words">{{ m.text }}</p>
                  <p class="mt-1 text-right text-overline opacity-70">{{ time(m.at) }}</p>
                </div>
              </div>
            </div>
            <form class="flex items-end gap-2 border-t border-slate-200 p-4" @submit.prevent="submit">
              <label class="sr-only" for="halo-reply">Balasan</label>
              <textarea
                id="halo-reply"
                v-model="draft"
                rows="2"
                maxlength="1000"
                placeholder="Tulis balasan…"
                class="min-h-[2.75rem] flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-body1 focus-visible:border-brand-tertiary"
                data-testid="halo-reply-input"
              />
              <UiButton type="submit" :disabled="!draft.trim()" data-testid="halo-reply-send">
                <template #icon-left><Send class="h-4 w-4" aria-hidden="true" /></template>
                Balas
              </UiButton>
            </form>
          </template>
        </UiCard>
      </div>
    </div>
  </main>
</template>
