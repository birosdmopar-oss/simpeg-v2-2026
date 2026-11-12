<script setup lang="ts">
/**
 * Daftar folder arsip (F-10, MAKE-091, PRD §5.1/§9.2): grid kartu, satu kolom di 390 px. Folder = tampilan per `jenis_rwy`
 * (bukan tabel). "Semua berkas" paling atas; "Belum Terhubung" dan "Tanpa Jenis" paling bawah. Setiap kartu menampilkan
 * label, jumlah berkas, serta badge berkas hilang / tidak dapat dibuka. Semua teks diinterpolasi (tanpa `v-html`).
 */
import { Folder, FolderOpen } from 'lucide-vue-next'
import { computed } from 'vue'

import { UiBadge } from '@/shared/ui'

import { FOLDER_SEMUA, type ArsipFolder } from '../../arsip.types'
import { paramFolder, urutkanFolder } from '../../arsip.utils'

const props = defineProps<{
  nip: string
  folder: ArsipFolder[]
  total: number
  totalHilang: number
  totalTakDapatDibuka: number
}>()

const nf = new Intl.NumberFormat('id-ID')

interface Kartu {
  param: string
  label: string
  jumlah: number
  hilang: number
  takDapatDibuka: number
  semua: boolean
}

const kartu = computed<Kartu[]>(() => [
  { param: FOLDER_SEMUA, label: 'Semua berkas', jumlah: props.total, hilang: props.totalHilang, takDapatDibuka: props.totalTakDapatDibuka, semua: true },
  ...urutkanFolder(props.folder).map((f) => ({
    param: paramFolder(f),
    label: f.label,
    jumlah: f.jumlah,
    hilang: f.jumlah_hilang,
    takDapatDibuka: f.jumlah_tak_dapat_dibuka,
    semua: false,
  })),
])
</script>

<template>
  <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="arsip-folder-grid">
    <li v-for="k in kartu" :key="k.param">
      <RouterLink
        :to="{ name: 'arsip-folder', params: { nip, folder: k.param } }"
        class="flex h-full flex-col gap-2 rounded-card border border-slate-200 bg-white p-4 shadow-card transition hover:border-brand-tertiary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary/60"
        :data-testid="`arsip-folder-${k.param}`"
      >
        <span class="flex items-center gap-2 text-body1 font-semibold text-slate-900">
          <component :is="k.semua ? FolderOpen : Folder" class="h-5 w-5 shrink-0 text-brand-tertiary" aria-hidden="true" />
          <span class="min-w-0 break-words">{{ k.label }}</span>
        </span>
        <span class="text-body2 text-slate-600" :data-testid="`arsip-folder-jumlah-${k.param}`">{{ nf.format(k.jumlah) }} berkas</span>
        <span v-if="k.hilang > 0 || k.takDapatDibuka > 0" class="flex flex-wrap gap-1.5">
          <UiBadge v-if="k.hilang > 0" tone="danger" :data-testid="`arsip-folder-hilang-${k.param}`">{{ nf.format(k.hilang) }} berkas hilang</UiBadge>
          <UiBadge v-if="k.takDapatDibuka > 0" tone="warning" :data-testid="`arsip-folder-tak-dapat-dibuka-${k.param}`">
            {{ nf.format(k.takDapatDibuka) }} tidak dapat dibuka
          </UiBadge>
        </span>
      </RouterLink>
    </li>
  </ul>
</template>
