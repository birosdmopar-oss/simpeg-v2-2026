<script setup lang="ts">
/**
 * Toggle switch status aktif/non-aktif master (G-TC #7) — Radix Vue Switch (ADR-020), aksesibel (role=switch).
 */
import { SwitchRoot, SwitchThumb } from 'radix-vue'

defineProps<{ checked: boolean; disabled?: boolean; label: string }>()
const emit = defineEmits<{ toggle: [value: boolean] }>()
</script>

<template>
  <SwitchRoot
    :checked="checked"
    :disabled="disabled"
    :aria-label="label"
    class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary/60 disabled:cursor-not-allowed disabled:opacity-50"
    :class="checked ? 'bg-green-600' : 'bg-slate-300'"
    @update:checked="emit('toggle', $event)"
  >
    <SwitchThumb
      class="block h-4 w-4 rounded-full bg-white shadow transition-transform"
      :class="checked ? 'translate-x-[18px]' : 'translate-x-0.5'"
    />
  </SwitchRoot>
</template>
