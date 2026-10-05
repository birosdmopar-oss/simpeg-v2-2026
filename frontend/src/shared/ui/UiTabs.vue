<script setup lang="ts">
/**
 * Tab — dua bentuk yang muncul di redesign:
 *  - `underline`: "Kehadiran Saya / Kehadiran Tim" (§4.2.1) dan "Pertanyaan Umum / Panduan Pengguna" (§4.2.4).
 *  - `pill`     : menu riwayat Detail Pegawai, tab aktif kuning #FFC043 (§4.1.6).
 *
 * Reusable. Pola WAI-ARIA tablist: panah kiri/kanan berpindah tab, tab aktif saja yang fokusable.
 */
import { computed, ref, watch } from 'vue'

export type TabItem = { value: string; label: string; badge?: string | number }

const props = withDefaults(
  defineProps<{
    modelValue: string
    items: TabItem[]
    variant?: 'underline' | 'pill'
    ariaLabel?: string
  }>(),
  { variant: 'underline', ariaLabel: 'Tab' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const tabRefs = ref<HTMLButtonElement[]>([])
watch(
  () => props.items.length,
  () => {
    tabRefs.value = []
  },
)

const activeIndex = computed(() => props.items.findIndex((item) => item.value === props.modelValue))

function move(delta: number): void {
  const next = (activeIndex.value + delta + props.items.length) % props.items.length
  emit('update:modelValue', props.items[next].value)
  tabRefs.value[next]?.focus()
}
</script>

<template>
  <div
    role="tablist"
    :aria-label="ariaLabel"
    class="flex items-center gap-1 overflow-x-auto scrollbar-slim"
    :class="variant === 'underline' ? 'border-b border-slate-200' : ''"
    @keydown.left.prevent="move(-1)"
    @keydown.right.prevent="move(1)"
  >
    <button
      v-for="(item, index) in items"
      :key="item.value"
      :ref="(el) => { if (el) tabRefs[index] = el as HTMLButtonElement }"
      type="button"
      role="tab"
      :aria-selected="item.value === modelValue"
      :tabindex="item.value === modelValue ? 0 : -1"
      class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap font-medium transition"
      :class="[
        variant === 'underline'
          ? [
              'border-b-2 px-4 py-2.5 text-body1',
              item.value === modelValue
                ? 'border-brand-tertiary text-brand-tertiary'
                : 'border-transparent text-slate-500 hover:text-slate-700',
            ]
          : [
              'rounded-lg px-4 py-2 text-body2',
              item.value === modelValue
                ? 'bg-brand-secondary text-[#5b4306]'
                : 'text-slate-600 hover:bg-slate-100',
            ],
      ]"
      @click="emit('update:modelValue', item.value)"
    >
      {{ item.label }}
      <span v-if="item.badge !== undefined" class="rounded-full bg-slate-100 px-1.5 text-caption text-slate-600">
        {{ item.badge }}
      </span>
    </button>
  </div>
</template>
