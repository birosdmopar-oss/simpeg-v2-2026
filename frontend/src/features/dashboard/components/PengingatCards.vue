<script setup lang="ts">
/**
 * 4 kartu pengingat beranda admin (`views/user/dashboard_ad.php:8-37`) [K]: Pensiun, Naik Pangkat, Tanda Jasa, KGB. Tautan
 * hanya Pensiun → halaman Proyeksi Pensiun F-09 (`/dokumen/pensiun`); KP/TJ/KGB tanpa tautan dulu (Q7-37).
 */
import { Award, CalendarClock, TrendingUp, Wallet } from 'lucide-vue-next'
import type { Component } from 'vue'

import { UiStatTile } from '@/shared/ui'
import type { StatTone } from '@/shared/ui/UiStatTile.vue'

import type { KartuPengingat } from '../types'

defineProps<{ kartu: KartuPengingat[] }>()

const angka = new Intl.NumberFormat('id-ID')

const GAYA: Record<KartuPengingat['kode'], { icon: Component; tone: StatTone }> = {
  pensiun: { icon: CalendarClock, tone: 'primary' },
  kp: { icon: TrendingUp, tone: 'warning' },
  tj: { icon: Award, tone: 'danger' },
  kgb: { icon: Wallet, tone: 'violet' },
}
</script>

<template>
  <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="beranda-pengingat">
    <li v-for="k in kartu" :key="k.kode" :data-testid="`beranda-pengingat-${k.kode}`">
      <RouterLink
        v-if="k.kode === 'pensiun'"
        :to="{ name: 'dokumen', params: { jenis: 'pensiun' } }"
        class="block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
        :aria-label="`${k.label}: ${angka.format(k.jumlah)} — buka Proyeksi Pensiun`"
      >
        <UiStatTile :label="k.label" :value="angka.format(k.jumlah)" :icon="GAYA[k.kode].icon" :tone="GAYA[k.kode].tone" />
      </RouterLink>
      <UiStatTile v-else :label="k.label" :value="angka.format(k.jumlah)" :icon="GAYA[k.kode].icon" :tone="GAYA[k.kode].tone" />
    </li>
  </ul>
</template>
