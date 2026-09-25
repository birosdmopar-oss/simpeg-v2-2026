<script setup lang="ts">
/**
 * Checklist aturan password real-time (ISSUE-006): dihitung (computed) dari nilai field setiap kali diketik, tidak
 * menunggu submit. Aturan kebijakan diambil dari PASSWORD_RULES (satu sumber dengan schema Zod); `extra` untuk aturan
 * milik form (mis. beda dari password lama, konfirmasi cocok).
 */
import { Circle, CircleCheck } from 'lucide-vue-next'
import { computed } from 'vue'

import { PASSWORD_RULES, type PasswordChecklistItem } from '../schemas/password.schema'

const props = withDefaults(defineProps<{ password: string; extra?: PasswordChecklistItem[] }>(), {
  extra: () => [],
})

const items = computed<PasswordChecklistItem[]>(() => [
  ...PASSWORD_RULES.map((rule) => ({ id: rule.id, label: rule.label, ok: rule.test(props.password) })),
  ...props.extra,
])
</script>

<template>
  <ul class="space-y-1 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs" aria-label="Syarat password" data-testid="password-checklist">
    <li
      v-for="item in items"
      :key="item.id"
      class="flex items-center gap-2"
      :class="item.ok ? 'text-emerald-700' : 'text-slate-500'"
      :data-testid="`password-rule-${item.id}`"
      :data-ok="item.ok ? 'true' : 'false'"
    >
      <CircleCheck v-if="item.ok" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <Circle v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span>{{ item.label }}</span>
      <span class="sr-only">{{ item.ok ? '(terpenuhi)' : '(belum terpenuhi)' }}</span>
    </li>
  </ul>
</template>
