<script setup lang="ts">
/**
 * Beranda keluarga pegawai (role 2/6/7; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10). M2b: kartu prediksi (`PrediksiCards`; role 2
 * Pensiun/Naik Pangkat/Tanda Jasa/KGB, role 7 Pensiun + KGB, role 6 tanpa kartu) [K]. Kartu Butuh Approval (Jalur C) untuk
 * role 2, tautan Presensi Saya (Q7-42, `PresensiSayaCard`) role 2/6/7, lalu presensi & AK bawahan bila akun atasan
 * (`BawahanCards`) [K]. Kelengkapan data role 2 PNS (`KelengkapanCards`, kartu < 100%), ulang tahun, lalu berita terbaru
 * (`BeritaTerbaruWidget`, Jalur A; tidak tampil bila kosong, tidak dipasang selama `ACTIVE_PHASE < FASE_KONTEN`). Banner
 * dipasang di `HomeView` (semua role). Tautan
 * "update FK" PTT TIDAK dipasang: di legacy blok itu dikomentari (`dashboard_ptt.php:39-48`), jadi Q7-44 menunggu koreksi.
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { BeritaTerbaruWidget } from '@/features/konten'
import { BUTUH_APPROVAL_ROLES } from '@/features/notifikasi'
import { PRESENSI_PEGAWAI_ROLES } from '@/features/presensi/roles'

import { KONTEN_BERANDA_AKTIF } from '../fase'
import type { RingkasanPegawai } from '../types'

import BawahanCards from './BawahanCards.vue'
import ButuhApprovalCard from './ButuhApprovalCard.vue'
import KelengkapanCards from './KelengkapanCards.vue'
import PrediksiCards from './PrediksiCards.vue'
import PresensiSayaCard from './PresensiSayaCard.vue'
import UlangTahunCard from './UlangTahunCard.vue'

const props = defineProps<{ ringkasan: RingkasanPegawai | null }>()

const auth = useAuthStore()
const punyaApproval = computed(() => auth.role !== null && BUTUH_APPROVAL_ROLES.includes(auth.role))
const prediksi = computed(() => props.ringkasan?.prediksi ?? [])
const kelengkapan = computed(() => props.ringkasan?.kelengkapan ?? [])
const punyaPresensi = computed(() => auth.role !== null && PRESENSI_PEGAWAI_ROLES.includes(auth.role))
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-pegawai">
    <PrediksiCards v-if="prediksi.length > 0" :kartu="prediksi" />
    <KelengkapanCards v-if="kelengkapan.length > 0 && auth.user?.nip" :kartu="kelengkapan" :nip="auth.user.nip" />
    <div v-if="punyaApproval || punyaPresensi" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <PresensiSayaCard v-if="punyaPresensi" />
      <ButuhApprovalCard v-if="punyaApproval" />
    </div>
    <BawahanCards v-if="ringkasan?.bawahan?.atasan" :bawahan="ringkasan.bawahan" />
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <UlangTahunCard hari="ini" />
      <UlangTahunCard hari="besok" />
    </div>
    <BeritaTerbaruWidget v-if="KONTEN_BERANDA_AKTIF" />
  </div>
</template>
