<script setup lang="ts">
/**
 * Tooltip custom (AGENTS.md bagian 3, CR-060) — pengganti atribut `title` bawaan browser. Kartu gelap kecil berradius
 * (radix-vue Tooltip, `role="tooltip"`): muncul saat hover dengan jeda singkat ATAU saat elemen difokus keyboard, hilang
 * dengan Esc/blur/pointer keluar, dan menghubungkan pemicu lewat `aria-describedby`. Konten teks polos.
 *
 * Pemakaian: bungkus SATU elemen pemicu (button, tautan, badge, sel teks):
 *   <UiTooltip content="Nama berkas lengkap.pdf"><span class="truncate">…</span></UiTooltip>
 * Pemicu bukan elemen fokus (badge/teks) hanya bisa memunculkannya lewat hover; isinya tetap harus tertulis di tempat
 * lain (mis. teks terpotong yang lengkapnya ada di detail) — jangan menyembunyikan informasi penting hanya di tooltip.
 * `data-tooltip` pada pemicu mencerminkan isi (untuk test/e2e).
 */
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'radix-vue'

withDefaults(
  defineProps<{
    content: string
    side?: 'top' | 'right' | 'bottom' | 'left'
    /** Jeda (ms) sebelum muncul karena hover; fokus keyboard memunculkannya segera. */
    delay?: number
    disabled?: boolean
  }>(),
  { side: 'top', delay: 300, disabled: false },
)
</script>

<template>
  <TooltipProvider :delay-duration="delay" :skip-delay-duration="150">
    <TooltipRoot :disabled="disabled || content === ''">
      <TooltipTrigger as-child :data-tooltip="content || undefined">
        <slot />
      </TooltipTrigger>
      <TooltipPortal>
        <TooltipContent
          :side="side"
          :side-offset="6"
          class="z-[70] max-w-xs break-words rounded-lg bg-slate-900 px-2.5 py-1.5 text-caption text-white shadow-panel"
          data-testid="ui-tooltip"
        >
          {{ content }}
        </TooltipContent>
      </TooltipPortal>
    </TooltipRoot>
  </TooltipProvider>
</template>
