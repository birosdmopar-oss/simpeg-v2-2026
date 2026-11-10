<script setup lang="ts">
/**
 * Tabel "Masa Kerja Direktur Politeknik" (role 1/4; `views/user/dashboard_ad.php:200-232`) [K]: Nama, Politeknik, Masa Kerja.
 * Memuat sendiri `GET /dashboard/direktur`; galat → pesan di kartu, tidak merusak beranda.
 */
import { onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiCard } from '@/shared/ui'

import { berandaService } from '../services/beranda.service'
import type { DirekturPoliteknik } from '../types'

const items = ref<DirekturPoliteknik[]>([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    items.value = await berandaService.direktur()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat data direktur.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <UiCard title="Masa Kerja Direktur Politeknik" flush data-testid="beranda-direktur">
    <p v-if="error" class="px-5 pb-4 text-sm text-red-700" role="alert">{{ error }}</p>
    <div v-else class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-5 py-3">Nama</th>
            <th class="px-5 py-3">Politeknik</th>
            <th class="px-5 py-3">Masa Kerja</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="3" class="px-5 py-6 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="3" class="px-5 py-6 text-center text-slate-500">Belum ada data direktur politeknik.</td>
          </tr>
          <tr v-for="(d, i) in items" v-else :key="i" :data-testid="`beranda-direktur-${i}`">
            <td class="px-5 py-3 font-medium text-slate-800">{{ d.nama }}</td>
            <td class="px-5 py-3 text-slate-600">{{ d.politeknik }}</td>
            <td class="px-5 py-3 text-slate-600">{{ d.masa_kerja || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiCard>
</template>
