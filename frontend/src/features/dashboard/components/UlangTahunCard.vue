<script setup lang="ts">
/**
 * Kartu "Yang Berulangtahun Hari Ini / Besok" (Q7-45 ikut legacy; `dashboard_ad.php:245-380`, sama di beranda pegawai) — semua
 * role. Carousel `UiCarouselPanel` (padanan carousel Bootstrap + tombol Pause legacy). Tiap slide: foto (blob
 * `GET /dashboard/ulang-tahun/{kunci}/foto`, gagal/tidak ada → avatar inisial), nama, tanggal lahir, "jenis pegawai / NIP",
 * jabatan, satker; baris khusus hanya nama, tanggal lahir, jabatan. Memuat datanya sendiri; galat → pesan di kartu.
 */
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiAvatar, UiCard, UiCarouselPanel } from '@/shared/ui'

import { berandaService } from '../services/beranda.service'
import type { HariUlangTahun, UlangTahunItem } from '../types'

const props = defineProps<{ hari: HariUlangTahun }>()

const JUDUL: Record<HariUlangTahun, string> = { ini: 'Yang Berulangtahun Hari Ini', besok: 'Yang Berulangtahun Besok' }

const items = ref<UlangTahunItem[]>([])
const loading = ref(true)
const error = ref('')
const foto = reactive<Record<string, string>>({})

async function muatFoto(item: UlangTahunItem): Promise<void> {
  if (!item.ada_foto) return
  try {
    foto[item.foto_kunci] = URL.createObjectURL(await berandaService.fotoUlangTahun(item.foto_kunci))
  } catch {
    // Foto gagal dimuat → avatar inisial.
  }
}

onMounted(async () => {
  try {
    items.value = await berandaService.ulangTahun(props.hari)
    void Promise.all(items.value.map(muatFoto))
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat data ulang tahun.'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  for (const url of Object.values(foto)) URL.revokeObjectURL(url)
})
</script>

<template>
  <UiCard :title="JUDUL[hari]" :data-testid="`beranda-ulang-tahun-${hari}`">
    <div class="px-5 pb-5">
      <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
      <p v-else-if="loading" class="text-sm text-slate-500">Memuat...</p>
      <UiCarouselPanel v-else :items="items" :label="JUDUL[hari]">
        <template #item="{ item }">
          <div class="flex items-center gap-4" :data-testid="`beranda-ulang-tahun-${hari}-item`">
            <UiAvatar :name="item.nama" :src="foto[item.foto_kunci] ?? null" size="xl" :alt="`Foto ${item.nama}`" />
            <ul class="min-w-0 text-sm">
              <li class="font-semibold text-slate-900" data-testid="nama">{{ item.nama }}</li>
              <li class="text-slate-600" data-testid="tgl">{{ item.tanggal_lahir }}</li>
              <li v-if="!item.khusus" class="text-slate-600" data-testid="nip">{{ item.jenis_pegawai }} / {{ item.nip }}</li>
              <li class="text-slate-600" data-testid="jabatan">{{ item.jabatan }}</li>
              <li v-if="!item.khusus" class="text-slate-500" data-testid="satker">{{ item.satker }}</li>
            </ul>
          </div>
        </template>
        <template #empty>
          <p class="text-sm text-slate-500">Tidak ada pegawai yang berulang tahun.</p>
        </template>
      </UiCarouselPanel>
    </div>
  </UiCard>
</template>
