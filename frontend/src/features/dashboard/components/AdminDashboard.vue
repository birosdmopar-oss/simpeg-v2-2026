<script setup lang="ts">
/**
 * Beranda keluarga admin (role 1/3/4/5/8; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10):
 * 1. 4 kartu pengingat Pensiun / Naik Pangkat / Tanda Jasa / KGB (`PengingatCards`) [K];
 * 2. kartu Butuh Approval (Jalur C) untuk role yang angkanya berlaku (`BUTUH_APPROVAL_ROLES`: 1, 3 di keluarga ini);
 * 3. komposisi pegawai + status hari ini (`KomposisiPegawaiCard`, saklar Q7-36);
 * 4. masa kerja direktur politeknik (role 1/4, `DirekturCard`) [K];
 * 5. berita terbaru (`BeritaTerbaruWidget`, Jalur A; tidak tampil bila kosong, tidak dipasang selama `ACTIVE_PHASE < FASE_KONTEN`);
 * 6. ulang tahun hari ini / besok (`UlangTahunCard`, Q7-45) [K];
 * 7. ringkasan SKL (`SklRingkasanCard`, role 1, Q7-26; endpoint F-11 Jalur B), juga digerbang `FASE_KONTEN`.
 * Banner (Jalur A) dipasang di `HomeView` di atas kartu ini (Q7-39).
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { BeritaTerbaruWidget } from '@/features/konten'
import { BUTUH_APPROVAL_ROLES } from '@/features/notifikasi'

import { KONTEN_BERANDA_AKTIF } from '../fase'
import { DIREKTUR_ROLES, SKL_RINGKASAN_ROLES, type RingkasanAdmin } from '../types'

import ButuhApprovalCard from './ButuhApprovalCard.vue'
import DirekturCard from './DirekturCard.vue'
import KomposisiPegawaiCard from './KomposisiPegawaiCard.vue'
import PengingatCards from './PengingatCards.vue'
import SklRingkasanCard from './SklRingkasanCard.vue'
import UlangTahunCard from './UlangTahunCard.vue'

defineProps<{ ringkasan: RingkasanAdmin }>()

const auth = useAuthStore()

const LABEL_LINGKUP = { kementerian: 'Kementerian', satker: 'Satker', unit: 'Unit' } as const

const punyaApproval = computed(() => auth.role !== null && BUTUH_APPROVAL_ROLES.includes(auth.role))
const punyaDirektur = computed(() => auth.role !== null && DIREKTUR_ROLES.includes(auth.role))
const punyaSkl = computed(() => KONTEN_BERANDA_AKTIF && auth.role !== null && SKL_RINGKASAN_ROLES.includes(auth.role))
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
    <BeritaTerbaruWidget v-if="KONTEN_BERANDA_AKTIF" />
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <UlangTahunCard hari="ini" />
      <UlangTahunCard hari="besok" />
    </div>
    <SklRingkasanCard v-if="punyaSkl" />
  </div>
</template>
