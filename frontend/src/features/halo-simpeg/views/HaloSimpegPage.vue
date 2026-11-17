<script setup lang="ts">
// Halaman Halo Simpeg pemilik + petugas (role 1–7). KERANGKA B1: isi master-detail (daftar tiket, thread, antrean, panel
// "Perlu ditindak") dibangun di B2/B3 sesudah README MAKE-071 beku. `idTiket` = keadaan panel kanan (rute `halo-tiket`).
import { computed } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { type Crumb, UiCard } from '@/shared/ui'

const props = defineProps<{ idTiket: number | null; idTidakSah: boolean }>()

const crumbs = computed<Crumb[]>(() => {
  const dasar: Crumb[] = [{ label: 'Home', to: { name: 'home' } }, { label: 'Halo Simpeg', to: { name: 'halo-simpeg' } }]
  if (props.idTidakSah) return [...dasar, { label: 'Tiket' }]
  return props.idTiket === null ? dasar : [...dasar, { label: `Tiket #${props.idTiket}` }]
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard>
      <h1 class="text-h5 font-semibold text-slate-900" data-testid="halo-judul">Halo Simpeg</h1>
      <!-- Teks 404 tiket = teks BE (PRD §6.1b). -->
      <p v-if="idTidakSah" class="mt-2 text-body2 text-danger" role="alert" data-testid="halo-tiket-tidak-ditemukan">
        Data percakapan tidak ditemukan.
      </p>
      <p v-else class="mt-2 text-body2 text-slate-500" data-testid="halo-kerangka">Layar tiket sedang disiapkan.</p>
    </UiCard>
  </RedesignShell>
</template>
