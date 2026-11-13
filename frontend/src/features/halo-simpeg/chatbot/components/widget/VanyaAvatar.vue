<script setup lang="ts">
/**
 * Avatar Vanya (F8-45): ilustrasi SVG widget dev (`dev:VP:29-62`) di-port sebagai komponen Vue. Foto opsional (PN8-7) =
 * aset statis FE yang belum diserahkan, jadi selalu SVG. Dekoratif: label ada di tombol/kepala jendela. `useId` menjaga id
 * gradien unik bila beberapa avatar tampil bersamaan (launcher, kepala jendela, avatar mini tiap balon).
 */
import { useId } from 'vue'

withDefaults(defineProps<{ size?: number }>(), { size: 48 })

const gradien = `vanya-bg-${useId()}`
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 64 64" aria-hidden="true" focusable="false" class="block rounded-full" data-testid="vanya-avatar">
    <defs>
      <linearGradient :id="gradien" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#ffe3f0" />
        <stop offset="1" stop-color="#dfe6ff" />
      </linearGradient>
    </defs>
    <rect width="64" height="64" :fill="`url(#${gradien})`" />
    <!-- rambut belakang -->
    <path d="M14 36C11 14 22 6 32 6s21 8 18 30c-.4 8-4 13-4 13H18s-3.6-5-4-13z" fill="#2b1b17" />
    <!-- leher -->
    <rect x="28" y="38" width="8" height="12" rx="3" fill="#efbf98" />
    <!-- blazer & kerah -->
    <path d="M6 64c0-13 12-17 26-17s26 4 26 17z" fill="#16225a" />
    <path d="M25 47l7 10 7-10z" fill="#ffffff" />
    <!-- wajah -->
    <ellipse cx="32" cy="30" rx="12" ry="14" fill="#f6cfae" />
    <!-- poni -->
    <path d="M19.5 29C19 15 28 12 33 12c8 0 13 6 11.5 17-2.5-6.5-8-9-14-9-4.5 0-8.5 3-11 9z" fill="#2b1b17" />
    <!-- mata, pipi, senyum -->
    <circle cx="27" cy="31.5" r="1.7" fill="#2b1b17" />
    <circle cx="37" cy="31.5" r="1.7" fill="#2b1b17" />
    <circle cx="24" cy="36" r="2.3" fill="#f4a0a8" opacity=".55" />
    <circle cx="40" cy="36" r="2.3" fill="#f4a0a8" opacity=".55" />
    <path d="M28 37.5q4 3.8 8 0" stroke="#b4535a" stroke-width="1.6" fill="none" stroke-linecap="round" />
    <!-- headset (asisten virtual) -->
    <path d="M17.5 30C17.5 18 24 11.5 32 11.5S46.5 18 46.5 30" stroke="#e2116e" stroke-width="2.2" fill="none" stroke-linecap="round" />
    <rect x="14.8" y="28" width="4.4" height="9" rx="2.2" fill="#e2116e" />
    <rect x="44.8" y="28" width="4.4" height="9" rx="2.2" fill="#e2116e" />
    <path d="M47 35c0 6-5 8-10 8" stroke="#e2116e" stroke-width="1.6" fill="none" stroke-linecap="round" />
    <circle cx="36.5" cy="43" r="1.9" fill="#e2116e" />
  </svg>
</template>
