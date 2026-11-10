<script setup lang="ts">
/**
 * Beranda keluarga admin (role 1/3/4/5/8; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10):
 * 1. 4 kartu pengingat Pensiun / Naik Pangkat / Tanda Jasa / KGB (`PengingatCards`) [K];
 * 2. kartu Butuh Approval (Jalur C) untuk role yang angkanya berlaku (`BUTUH_APPROVAL_ROLES`: 1, 3 di keluarga ini);
 * 3. komposisi pegawai + status hari ini (`KomposisiPegawaiCard`, saklar Q7-36);
 * 4. masa kerja direktur politeknik (role 1/4, `DirekturCard`) [K];
 * 5. ulang tahun hari ini / besok (`UlangTahunCard`, Q7-45) [K].
 * Slot M3: banner (`BannerCarousel`, Jalur A) di atas, berita terbaru (Jalur A), ulang tahun, ringkasan SKL (Jalur B).
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { BUTUH_APPROVAL_ROLES } from '@/features/notifikasi'

import { DIREKTUR_ROLES, type RingkasanAdmin } from '../types'

import ButuhApprovalCard from './ButuhApprovalCard.vue'
import DirekturCard from './DirekturCard.vue'
import KomposisiPegawaiCard from './KomposisiPegawaiCard.vue'
import PengingatCards from './PengingatCards.vue'
import UlangTahunCard from './UlangTahunCard.vue'

defineProps<{ ringkasan: RingkasanAdmin }>()

const auth = useAuthStore()

const LABEL_LINGKUP = { kementerian: 'Kementerian', satker: 'Satker', unit: 'Unit' } as const

const punyaApproval = computed(() => auth.role !== null && BUTUH_APPROVAL_ROLES.includes(auth.role))
const punyaDirektur = computed(() => auth.role !== null && DIREKTUR_ROLES.includes(auth.role))
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-admin">
    <p class="text-sm text-slate-500" data-testid="beranda-lingkup">Lingkup data: {{ LABEL_LINGKUP[ringkasan.lingkup.jenis] }}</p>
    <PengingatCards :kartu="ringkasan.pengingat" />
    <div v-if="punyaApproval" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <ButuhApprovalCard />
    </div>
    <KomposisiPegawaiCard :ringkasan="ringkasan" />
    <DirekturCard v-if="punyaDirektur" />
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <UlangTahunCard hari="ini" />
      <UlangTahunCard hari="besok" />
    </div>
  </div>
</template>
