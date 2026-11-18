<script setup lang="ts">
/**
 * Panel "Perlu ditindak" tab Antrean (B3, AC-E08.13; pengganti popup login legacy F8-12/Q8-07). Daftar sendiri dari server
 * (`GET tiket?peran=petugas&perlu_tindak=1`, 5 butir; `total` = `Ringkasan.perlu_tindak`) lewat `daftarPerluTindak` — bukan
 * menyaring halaman aktif. Kosong tetap tampil ringkas (layout tidak meloncat saat polling). Dimuat ulang saat
 * `Ringkasan.perlu_tindak` berubah, tanpa polling sendiri. "Lihat semua" menyalakan filter `perlu_tindak` di tabel antrean.
 */
import { computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'

import { formatApiDateTime } from '@/shared/utils/dateTime'
import { UiButton } from '@/shared/ui'

import { haloState } from '../stores/haloSimpeg'
import { daftarPerluTindak } from '../stores/tiket'

const emit = defineEmits<{ 'lihat-semua': [] }>()
const router = useRouter()

const st = computed(() => daftarPerluTindak.state)
const items = computed(() => st.value.daftar?.items ?? [])
const total = computed(() => st.value.daftar?.total ?? 0)
const waktu = (iso: string | null | undefined): string => (iso ? (formatApiDateTime(iso) ?? iso) : '—')

onMounted(() => {
  void daftarPerluTindak.muat()
})

watch(
  () => haloState.ringkasan?.perlu_tindak,
  (baru, lama) => {
    if (baru !== undefined && lama !== undefined && baru !== lama) void daftarPerluTindak.muat()
  },
)
</script>

<template>
  <section class="rounded-xl border border-warning/40 bg-warning-soft/40 p-3" aria-labelledby="halo-perlu-tindak-judul" data-testid="halo-perlu-tindak">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 id="halo-perlu-tindak-judul" class="text-sm font-semibold text-slate-800">
        Perlu ditindak <span class="font-normal text-slate-500" data-testid="halo-perlu-tindak-jumlah">({{ total }})</span>
      </h3>
      <UiButton v-if="total > items.length" size="sm" variant="secondary" appearance="ghost" data-testid="halo-perlu-tindak-semua" @click="emit('lihat-semua')">
        Lihat semua
      </UiButton>
    </div>
    <p v-if="st.statusDaftar === 'memuat' && items.length === 0" class="mt-2 text-sm text-slate-500">Memuat...</p>
    <p v-else-if="st.statusDaftar === 'galat'" class="mt-2 text-sm text-danger" role="alert">{{ st.galatDaftar?.pesan }}</p>
    <p v-else-if="items.length === 0" class="mt-2 text-sm text-slate-500" data-testid="halo-perlu-tindak-kosong">Tidak ada tiket yang perlu ditindak.</p>
    <ul v-else class="mt-2 space-y-1">
      <li v-for="t in items" :key="t.id" class="flex items-center justify-between gap-2 text-sm" :data-testid="`halo-perlu-tindak-${t.id}`">
        <span class="min-w-0 truncate">
          <span class="font-medium text-slate-800">{{ t.subjek }}</span>
          <span class="text-slate-500"> · {{ t.pemilik_nama }} · {{ waktu(t.pesan_terakhir?.waktu) }}</span>
        </span>
        <UiButton size="sm" variant="secondary" appearance="soft" @click="router.push({ name: 'halo-tiket', params: { id: t.id }, query: { tab: 'antrean' } })">
          Lihat detail
        </UiButton>
      </li>
    </ul>
  </section>
</template>
