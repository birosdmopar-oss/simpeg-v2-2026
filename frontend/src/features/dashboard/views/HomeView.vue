<script setup lang="ts">
/**
 * Beranda per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10, kepemilikan Jalur D Q7-46). Keluarga role ikut legacy
 * (`User.php:526-567`): admin 1/3/4/5/8 → `AdminDashboard`, pegawai 2/6/7 → `PegawaiDashboard`. Data dari
 * `GET api/v1/dashboard/ringkasan`; galat memuat tidak merusak halaman (sapaan tetap tampil).
 * `data-testid="home-role"` dipertahankan untuk MTC-001 ("redirect ke dashboard sesuai role").
 * M3: popup aktif (`PopupAktifDialog`, Jalur A) di akar halaman (sekali per sesi login, lewat antrean dialog) dan banner
 * (`BannerCarousel`) di bawah sapaan untuk SEMUA role (Q7-39; BE yang menyaring sasaran). Keduanya memuat data sendiri dan
 * tidak merender apa pun bila kosong/403/galat, jadi tidak bergantung pada ringkasan beranda.
 */
import { computed, onMounted, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'
import { BannerCarousel, PopupAktifDialog } from '@/features/konten'
import { isApiError } from '@/lib/axios'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import AdminDashboard from '../components/AdminDashboard.vue'
import PegawaiDashboard from '../components/PegawaiDashboard.vue'
import { berandaService } from '../services/beranda.service'
import { ADMIN_DASHBOARD_ROLES, type RingkasanAdmin, type RingkasanBeranda, type RingkasanPegawai } from '../types'

const auth = useAuthStore()

const ringkasan = ref<RingkasanBeranda | null>(null)
const loading = ref(false)
const error = ref('')

const keluargaAdmin = computed(() => auth.role !== null && ADMIN_DASHBOARD_ROLES.includes(auth.role))
const dataAdmin = computed<RingkasanAdmin | null>(() => (ringkasan.value?.keluarga === 'admin' ? ringkasan.value : null))
const dataPegawai = computed<RingkasanPegawai | null>(() => (ringkasan.value?.keluarga === 'pegawai' ? ringkasan.value : null))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    ringkasan.value = await berandaService.ringkasan()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat ringkasan beranda.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <RedesignShell>
    <div class="flex flex-col gap-4" data-testid="beranda">
      <section class="rounded-lg border border-slate-200 bg-white p-6">
        <h1 class="text-xl font-semibold text-slate-900">Selamat datang, {{ auth.user?.username }}</h1>
        <p class="mt-1 text-sm text-slate-500">
          Anda masuk sebagai
          <span class="font-medium text-brand-primary" data-testid="home-role">
            {{ auth.role ? `${auth.role} — ${ROLE_LABELS[auth.role]}` : '' }}
          </span>
          <template v-if="auth.user?.id_satker"> · Satker {{ auth.user.id_satker }}</template>
        </p>
      </section>

      <BannerCarousel />

      <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
      <p v-else-if="loading && !ringkasan" class="text-sm text-slate-500" data-testid="beranda-memuat">Memuat ringkasan…</p>

      <template v-if="keluargaAdmin">
        <AdminDashboard v-if="dataAdmin" :ringkasan="dataAdmin" />
      </template>
      <PegawaiDashboard v-else :ringkasan="dataPegawai" />
    </div>
    <PopupAktifDialog />
  </RedesignShell>
</template>
