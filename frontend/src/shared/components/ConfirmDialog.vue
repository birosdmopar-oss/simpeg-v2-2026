<script setup lang="ts">
/**
 * Modal konfirmasi aksi destructive (Tech Spec 9.1) — Radix Vue AlertDialog (ADR-020), styling Tailwind.
 */
import {
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogOverlay,
  AlertDialogPortal,
  AlertDialogRoot,
  AlertDialogTitle,
} from 'radix-vue'

withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    confirmLabel?: string
    cancelLabel?: string
    danger?: boolean
    loading?: boolean
  }>(),
  { description: '', confirmLabel: 'Ya, lanjutkan', cancelLabel: 'Batal', danger: false, loading: false },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [] }>()
</script>

<template>
  <AlertDialogRoot :open="open" @update:open="emit('update:open', $event)">
    <AlertDialogPortal>
      <AlertDialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <AlertDialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg bg-white p-6 shadow-xl focus:outline-none"
      >
        <AlertDialogTitle class="text-lg font-semibold text-slate-900">{{ title }}</AlertDialogTitle>
        <AlertDialogDescription v-if="description" class="mt-2 text-sm text-slate-600">
          {{ description }}
        </AlertDialogDescription>
        <div class="mt-6 flex justify-end gap-2">
          <AlertDialogCancel
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            :disabled="loading"
          >
            {{ cancelLabel }}
          </AlertDialogCancel>
          <AlertDialogAction
            class="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
            :class="danger ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-primary hover:bg-brand-primary/90'"
            :disabled="loading"
            @click.prevent="emit('confirm')"
          >
            {{ loading ? 'Memproses...' : confirmLabel }}
          </AlertDialogAction>
        </div>
      </AlertDialogContent>
    </AlertDialogPortal>
  </AlertDialogRoot>
</template>
