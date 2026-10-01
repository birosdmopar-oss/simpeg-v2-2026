<script setup lang="ts">
/**
 * Dashboard Pengguna/Pimpinan (§4.2.1/§4.2.2, Gambar 25/26): sambutan + jam + Rekam Masuk/Keluar, kartu KGB/Pangkat/Cuti/AK,
 * tab "Kehadiran Tim" khusus pimpinan. Rekam kehadiran BELUM tersambung (tidak ada endpoint) — tombol memberi pesan jelas.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { UiAvatar, UiBadge, UiButton, UiCard, UiNotice, UiProgressBar, UiTabs } from '@/shared/ui'
import { ILLUSTRATIONS, placeholderAvatar } from '@/shared/ui/placeholderAssets'

import { KEHADIRAN_TIM, USER_STATS } from '../dashboard.mock'
import { TEAM_ATTENDANCE_ROLES } from '../types'

const auth = useAuthStore()
const tab = ref('saya')
const notice = ref<string | null>(null)
const now = ref(new Date())
let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 30_000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

const isPimpinan = computed(() => auth.role !== null && TEAM_ATTENDANCE_ROLES.includes(auth.role))
const tabs = computed(() => [{ value: 'saya', label: 'Dashboard Saya' }, ...(isPimpinan.value ? [{ value: 'tim', label: 'Kehadiran Tim' }] : [])])
const jam = computed(() => now.value.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }))
const tanggal = computed(() => now.value.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
const TONE = { Hadir: 'success', Cuti: 'warning', 'Dinas Luar': 'info', 'Belum Hadir': 'neutral' } as const
const rekam = (jenis: string): void => {
  notice.value = `Rekam ${jenis} belum tersambung ke backend — data contoh.`
}
</script>

<template>
  <div class="space-y-5" data-testid="dashboard-user">
    <UiTabs v-if="tabs.length > 1" v-model="tab" :items="tabs" aria-label="Tampilan dashboard" />
    <UiNotice v-if="notice" tone="info" dismissible @dismiss="notice = null">{{ notice }}</UiNotice>

    <template v-if="tab === 'saya'">
      <UiCard>
        <div class="flex flex-wrap items-center justify-between gap-5">
          <div class="min-w-0">
            <h1 class="text-h2 font-semibold text-slate-900">Selamat datang, {{ auth.user?.username }}</h1>
            <p class="mt-1 text-body2 text-slate-500">{{ tanggal }}</p>
            <p class="mt-3 text-h1 font-semibold tabular-nums text-brand-primary" data-testid="clock">{{ jam }}</p>
            <div class="mt-4 flex flex-wrap gap-3">
              <UiButton data-testid="rekam-masuk" @click="rekam('masuk')">Rekam Masuk</UiButton>
              <UiButton variant="secondary" appearance="soft" data-testid="rekam-keluar" @click="rekam('keluar')">Rekam Keluar</UiButton>
            </div>
          </div>
          <img :src="ILLUSTRATIONS.greeting.url" alt="" class="h-36" />
        </div>
      </UiCard>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <UiCard v-for="s in USER_STATS" :key="s.key" :data-testid="`ustat-${s.key}`">
          <p class="text-body2 text-slate-500">{{ s.label }}</p>
          <p class="mt-1 text-h3 font-semibold text-slate-900">{{ s.value }}</p>
          <UiProgressBar class="mt-3" :value="s.progress" :tone="s.tone" :label="s.label" />
        </UiCard>
      </div>
    </template>

    <UiCard v-else title="Kehadiran Tim" subtitle="Status kehadiran anggota tim hari ini" flush>
      <ul class="divide-y divide-slate-100" data-testid="team-list">
        <li v-for="m in KEHADIRAN_TIM" :key="m.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
          <UiAvatar :name="m.nama" :src="placeholderAvatar(`tim-${m.id}`)" size="md" alt="" />
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-slate-900">{{ m.nama }}</p>
            <p class="truncate text-body2 text-slate-500">{{ m.jabatan }}</p>
          </div>
          <span class="text-body2 tabular-nums text-slate-600">{{ m.masuk }} – {{ m.keluar }}</span>
          <UiBadge :tone="TONE[m.status]">{{ m.status }}</UiBadge>
        </li>
      </ul>
    </UiCard>
  </div>
</template>
