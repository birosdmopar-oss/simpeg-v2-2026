<script setup lang="ts">
/** Dashboard Admin (§4.1.1, Gambar 12): kartu statistik + sparkline, komposisi pegawai (donat), ulang tahun, berita terbaru. DATA CONTOH. */
import { Cake, GraduationCap, Newspaper, UserCheck, Users } from 'lucide-vue-next'
import { RouterLink } from 'vue-router'

import { UiAvatar, UiCard, UiDonutChart, UiStatTile } from '@/shared/ui'
import { ILLUSTRATIONS, placeholderAvatar } from '@/shared/ui/placeholderAssets'

import { ADMIN_STATS, KOMPOSISI, ULANG_TAHUN } from '../dashboard.mock'

const ICONS = { pns: Users, pppk: UserCheck, ptt: GraduationCap, pensiun: Cake }
</script>

<template>
  <div class="space-y-5" data-testid="dashboard-admin">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <UiStatTile
        v-for="s in ADMIN_STATS"
        :key="s.key"
        :label="s.label"
        :value="s.value.toLocaleString('id-ID')"
        :tone="s.tone"
        :trend="[...s.trend]"
        :icon="ICONS[s.key]"
        :data-testid="`stat-${s.key}`"
      />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
      <UiCard title="Komposisi Pegawai" subtitle="Berdasarkan jenis pegawai" class="lg:col-span-2">
        <UiDonutChart :slices="KOMPOSISI" caption="Komposisi pegawai" />
      </UiCard>

      <UiCard title="Berita Terbaru" accent="primary">
        <div class="flex flex-col items-center gap-3 text-center">
          <img :src="ILLUSTRATIONS.news.url" alt="" class="h-28" />
          <p class="text-body2 text-slate-600">Ikuti informasi dan pengumuman terbaru kepegawaian.</p>
          <RouterLink :to="{ name: 'news' }" class="inline-flex items-center gap-2 text-body2 font-medium text-brand-tertiary hover:underline">
            <Newspaper class="h-4 w-4" aria-hidden="true" /> Buka News Portal
          </RouterLink>
        </div>
      </UiCard>
    </div>

    <UiCard title="Berulang Tahun" subtitle="Pegawai yang berulang tahun dalam waktu dekat" accent="warning">
      <ul class="divide-y divide-slate-100" data-testid="birthday-list">
        <li v-for="p in ULANG_TAHUN" :key="p.seed" class="flex items-center gap-3 py-3">
          <UiAvatar :name="p.nama" :src="placeholderAvatar(p.seed)" size="md" alt="" />
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-slate-900">{{ p.nama }}</p>
            <p class="truncate text-body2 text-slate-500">{{ p.unit }}</p>
          </div>
          <span class="text-body2 text-slate-600">{{ p.tanggal }}</span>
        </li>
      </ul>
    </UiCard>
  </div>
</template>
