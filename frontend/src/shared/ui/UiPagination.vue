<script setup lang="ts">
/**
 * Paginasi tabel — "Showing 1 to 10 of 50 entries" + tombol angka, halaman aktif biru penuh dan
 * halaman berikutnya bertanda biru muda (§4.1.4, §4.1.5, §4.2.3).
 *
 * Reusable.
 */
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    page: number
    perPage: number
    total: number
    /** Jumlah tombol angka maksimum yang ditampilkan. */
    window?: number
  }>(),
  { window: 5 },
)

const emit = defineEmits<{ 'update:page': [page: number] }>()

const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1))
const to = computed(() => Math.min(props.total, props.page * props.perPage))

const pages = computed(() => {
  const size = Math.min(props.window, lastPage.value)
  let start = Math.max(1, props.page - Math.floor(size / 2))
  if (start + size - 1 > lastPage.value) start = lastPage.value - size + 1
  return Array.from({ length: size }, (_, i) => start + i)
})

function go(page: number): void {
  if (page >= 1 && page <= lastPage.value && page !== props.page) emit('update:page', page)
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-body2 text-slate-500">Showing {{ from }} to {{ to }} of {{ total }} entries</p>

    <nav class="flex items-center gap-1.5" aria-label="Paginasi">
      <button
        type="button"
        class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition hover:bg-slate-200 disabled:opacity-40"
        :disabled="page <= 1"
        aria-label="Halaman sebelumnya"
        @click="go(page - 1)"
      >
        <ChevronLeft class="h-4 w-4" />
      </button>

      <button
        v-for="p in pages"
        :key="p"
        type="button"
        class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-body2 font-medium transition"
        :class="
          p === page
            ? 'bg-brand-tertiary text-white'
            : p === page + 1
              ? 'bg-brand-tertiary/15 text-brand-tertiary hover:bg-brand-tertiary/25'
              : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
        "
        :aria-current="p === page ? 'page' : undefined"
        @click="go(p)"
      >
        {{ p }}
      </button>

      <button
        type="button"
        class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition hover:bg-slate-200 disabled:opacity-40"
        :disabled="page >= lastPage"
        aria-label="Halaman berikutnya"
        @click="go(page + 1)"
      >
        <ChevronRight class="h-4 w-4" />
      </button>
    </nav>
  </div>
</template>
