<script setup lang="ts">
/**
 * Beranda keluarga pegawai (role 2/6/7; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10). M2b: kartu prediksi (`PrediksiCards`; role 2
 * Pensiun/Naik Pangkat/Tanda Jasa/KGB, role 7 Pensiun + KGB, role 6 tanpa kartu) [K]. Kartu Butuh Approval (Jalur C) untuk
 * role 2, tautan Presensi Saya (Q7-42, `PresensiSayaCard`) role 2/6/7, lalu presensi & AK bawahan bila akun atasan
 * (`BawahanCards`) [K]. Slot berikutnya: banner (Jalur A), kelengkapan data, ulang tahun, berita terbaru (Jalur A). Tautan
 * "update FK" PTT TIDAK dipasang: di legacy blok itu dikomentari (`dashboard_ptt.php:39-48`), jadi Q7-44 menunggu koreksi.
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { PRESENSI_PEGAWAI_ROLES } from '@/features/presensi/roles'

import { BUTUH_APPROVAL_ROLES } from '../composables/ringkasanNotifikasi'
import type { RingkasanPegawai } from '../types'

import BawahanCards from './BawahanCards.vue'
import ButuhApprovalCard from './ButuhApprovalCard.vue'
import PrediksiCards from './PrediksiCards.vue'
import PresensiSayaCard from './PresensiSayaCard.vue'
import UlangTahunCard from './UlangTahunCard.vue'

const props = defineProps<{ ringkasan: RingkasanPegawai | null }>()

const auth = useAuthStore()
const punyaApproval = computed(() => auth.role !== null && BUTUH_APPROVAL_ROLES.includes(auth.role))
const prediksi = computed(() => props.ringkasan?.prediksi ?? [])
const punyaPresensi = computed(() => auth.role !== null && PRESENSI_PEGAWAI_ROLES.includes(auth.role))
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-pegawai">
    <PrediksiCards v-if="prediksi.length > 0" :kartu="prediksi" />
    <div v-if="punyaApproval || punyaPresensi" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <PresensiSayaCard v-if="punyaPresensi" />
      <ButuhApprovalCard v-if="punyaApproval" />
    </div>
    <BawahanCards v-if="ringkasan?.bawahan?.atasan" :bawahan="ringkasan.bawahan" />
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <UlangTahunCard hari="ini" />
      <UlangTahunCard hari="besok" />
    </div>
  </div>
</template>
