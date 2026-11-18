<script setup lang="ts">
/**
 * Halaman Halo Simpeg (role 1–7) — B2 layar pemilik (MAKE-073). Master-detail ala Gambar 33/34 (tanpa frame Figma, H8-9):
 * - ≥ 1024 px: daftar "Tiket saya" di kiri, thread di kanan; `idTiket` (rute `halo-tiket`) = keadaan panel kanan
 *   (deep-link lonceng/push `/halo-simpeg/tiket/{id}`, tombol kembali browser berfungsi; fokus ke judul thread).
 * - < 1024 px: satu panel — daftar, atau thread + "Kembali ke daftar" (perilaku responsif, bukan deviasi).
 * - "Tambah Pertanyaan" hanya role 2/6/7 (`POST tiket`, F8-04). Tab antrean petugas + panel "Perlu ditindak" = B3.
 * - `idTidakSah` (id 0 / di luar batas) → "Data percakapan tidak ditemukan." (review B1 V3).
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { useIsDesktop } from '@/shared/composables/useIsDesktop'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { type Crumb, UiCard } from '@/shared/ui'

import DaftarTiketSaya from '../components/DaftarTiketSaya.vue'
import KontakHelpdeskCard from '../components/KontakHelpdeskCard.vue'
import ThreadTiket from '../components/ThreadTiket.vue'
import TiketBaruDialog from '../components/TiketBaruDialog.vue'
import { HALO_PEMBUAT_TIKET_ROLES, TEKS_GALAT_TIKET } from '../constants'
import { bukaTiket, tutupPanel } from '../stores/tiketSaya'
import type { TiketDetail } from '../types'

const props = defineProps<{ idTiket: number | null; idTidakSah: boolean }>()

const auth = useAuthStore()
const router = useRouter()
const isDesktop = useIsDesktop()

const bolehBuat = computed(() => auth.role !== null && HALO_PEMBUAT_TIKET_ROLES.includes(auth.role))
const dialogBaru = ref(false)

const crumbs = computed<Crumb[]>(() => {
  const dasar: Crumb[] = [{ label: 'Home', to: { name: 'home' } }, { label: 'Halo Simpeg', to: { name: 'halo-simpeg' } }]
  if (props.idTidakSah) return [...dasar, { label: 'Tiket' }]
  return props.idTiket === null ? dasar : [...dasar, { label: `Tiket #${props.idTiket}` }]
})

watch(
  () => props.idTiket,
  (id) => {
    if (id === null) tutupPanel()
    else void bukaTiket(id)
  },
  { immediate: true },
)

const panelThread = computed(() => props.idTiket !== null || props.idTidakSah)
const tampilDaftar = computed(() => isDesktop.value || !panelThread.value)
const tampilThread = computed(() => isDesktop.value || panelThread.value)

function kembali(): void {
  void router.push({ name: 'halo-simpeg' })
}

function onDibuat(t: TiketDetail): void {
  void router.push({ name: 'halo-tiket', params: { id: t.id } })
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]" data-testid="halo-halaman">
      <div v-if="tampilDaftar" class="flex min-w-0 flex-col gap-4" data-testid="halo-panel-daftar">
        <UiCard title="Tiket Saya">
          <DaftarTiketSaya :boleh-buat="bolehBuat" :id-aktif="idTiket" @tiket-baru="dialogBaru = true" />
        </UiCard>
        <KontakHelpdeskCard />
      </div>

      <UiCard v-if="tampilThread" class="min-w-0" data-testid="halo-panel-thread">
        <p v-if="idTidakSah" class="py-6 text-sm text-danger" role="alert" data-testid="halo-tiket-tidak-ditemukan">
          {{ TEKS_GALAT_TIKET.tidakDitemukan }}
        </p>
        <!-- :key per tiket (review B2 P2): respons balas/unduh tiket A yang terlambat tidak menyentuh form/galat tiket B. -->
        <ThreadTiket v-else-if="idTiket !== null" :key="idTiket" :id-tiket="idTiket" :tampil-kembali="!isDesktop" @kembali="kembali" />
        <p v-else class="py-10 text-center text-sm text-slate-500" data-testid="halo-thread-kosong">
          Pilih "Lihat detail" pada daftar untuk membuka percakapan.
        </p>
        <button v-if="idTidakSah && !isDesktop" type="button" class="mt-2 text-sm text-brand-primary underline" @click="kembali">
          Kembali ke daftar
        </button>
      </UiCard>
    </div>

    <TiketBaruDialog v-if="bolehBuat" v-model:open="dialogBaru" @dibuat="onDibuat" />
  </RedesignShell>
</template>
