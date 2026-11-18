<script setup lang="ts">
/**
 * Kartu "Kontak Helpdesk" layar pemilik (B2, E-07): `GET kontak/aktif` (role 1–7, array tanpa status/stempel). Teks polos.
 * Tanpa frame Figma (H8-9): pola kartu redesign.
 */
import { onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiCard } from '@/shared/ui'

import { kontakHaloService } from '../services/master.service'
import type { KontakAktif } from '../types'

const kontak = ref<KontakAktif[]>([])
const memuat = ref(true)
const galat = ref('')

onMounted(async () => {
  try {
    kontak.value = await kontakHaloService.aktif()
  } catch (e) {
    galat.value = isApiError(e) ? e.message : 'Kontak helpdesk tidak dapat dimuat.'
  } finally {
    memuat.value = false
  }
})
</script>

<template>
  <UiCard title="Kontak Helpdesk" data-testid="halo-kontak">
    <p v-if="memuat" class="text-sm text-slate-500">Memuat...</p>
    <p v-else-if="galat" class="text-sm text-danger" role="alert">{{ galat }}</p>
    <p v-else-if="kontak.length === 0" class="text-sm text-slate-500">Belum ada kontak helpdesk.</p>
    <ul v-else class="space-y-2 text-sm">
      <li v-for="k in kontak" :key="k.id" class="rounded-lg bg-slate-50 px-3 py-2" :data-testid="`halo-kontak-${k.id}`">
        <p class="font-medium text-slate-800">{{ k.nama }}</p>
        <p class="text-slate-600">{{ k.kontak }}</p>
        <p v-if="k.email" class="text-slate-500">{{ k.email }}</p>
      </li>
    </ul>
  </UiCard>
</template>
