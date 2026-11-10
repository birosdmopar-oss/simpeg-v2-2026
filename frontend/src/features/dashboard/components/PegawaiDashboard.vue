<script setup lang="ts">
/**
 * Beranda keluarga pegawai (role 2/6/7; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10). M2b: kartu prediksi (`PrediksiCards`; role 2
 * Pensiun/Naik Pangkat/Tanda Jasa/KGB, role 7 Pensiun + KGB, role 6 tanpa kartu) [K]. Kartu Butuh Approval (Jalur C) untuk
 * role 2, lalu presensi & AK bawahan bila akun atasan (`BawahanCards`) [K]. Slot berikutnya: banner (Jalur A), presensi hari ini
 * (komponen Fase 5, Q7-42), kelengkapan data, ulang tahun, tautan update FK PTT (Q7-44), berita terbaru (Jalur A).
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'

import { BUTUH_APPROVAL_ROLES } from '../composables/ringkasanNotifikasi'
import type { RingkasanPegawai } from '../types'

import BawahanCards from './BawahanCards.vue'
import ButuhApprovalCard from './ButuhApprovalCard.vue'
import PrediksiCards from './PrediksiCards.vue'

const props = defineProps<{ ringkasan: RingkasanPegawai | null }>()

const auth = useAuthStore()
const punyaApproval = computed(() => auth.role !== null && BUTUH_APPROVAL_ROLES.includes(auth.role))
const prediksi = computed(() => props.ringkasan?.prediksi ?? [])
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-pegawai">
    <PrediksiCards v-if="prediksi.length > 0" :kartu="prediksi" />
    <div v-if="punyaApproval" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <ButuhApprovalCard />
    </div>
    <BawahanCards v-if="ringkasan?.bawahan?.atasan" :bawahan="ringkasan.bawahan" />
  </div>
</template>
