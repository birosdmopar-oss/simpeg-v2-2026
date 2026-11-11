<script setup lang="ts">
/**
 * Kartu kelengkapan data beranda pegawai (role 2, PNS) — view legacy `dashboard_pns.php:104-119` [K]: tiap kartu < 100%
 * menampilkan nama data, persen, dan bar (hijau bila ≥ 50, merah bila < 50), bertautan ke tab Detail Pegawai milik sendiri
 * (`/pegawai/{nip}?tab=<kode>`, padanan `hr/employee/edit` / `hr/rwy/<jenis>` legacy). Tooltip legacy "Klik Untuk Melengkapi
 * … Anda" menjadi `aria-label` (AGENTS.md §3: tanpa atribut `title`). Persen bisa > 100 di backend (tanpa batas, Q-D14), tetapi
 * kartu seperti itu tidak dikirim.
 */
import { UiCard, UiProgressBar } from '@/shared/ui'

import type { KartuKelengkapan } from '../types'

defineProps<{ kartu: KartuKelengkapan[]; nip: string }>()
</script>

<template>
  <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" data-testid="beranda-kelengkapan">
    <li v-for="k in kartu" :key="k.kode" :data-testid="`beranda-kelengkapan-${k.kode}`">
      <RouterLink
        :to="{ name: 'pegawai-detail', params: { nip }, query: { tab: k.kode } }"
        class="block h-full rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
        :aria-label="`Klik Untuk Melengkapi ${k.label} Anda: ${k.persen}%`"
      >
        <UiCard class="h-full">
          <div class="flex flex-col gap-2 px-4 pb-4 pt-4">
            <p class="text-body2 font-medium text-slate-600">{{ k.label }}</p>
            <p class="text-h4 text-slate-900">{{ k.persen }}%</p>
            <UiProgressBar :value="k.persen" :tone="k.persen >= 50 ? 'success' : 'danger'" size="xs" :label="k.label" />
          </div>
        </UiCard>
      </RouterLink>
    </li>
  </ul>
</template>
