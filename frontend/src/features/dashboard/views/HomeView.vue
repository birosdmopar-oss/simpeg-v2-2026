<script setup lang="ts">
/**
 * Beranda per role (Fase 7, Modul F): role 1/3/4 → Dashboard Admin; lainnya → Dashboard Pengguna/Pimpinan.
 * Baris identitas role dipertahankan (`home-role`) supaya MTC-001 ("redirect ke dashboard sesuai role") tetap bisa diverifikasi.
 */
import { computed } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import AdminDashboard from '../components/AdminDashboard.vue'
import AnnouncementPopup from '../components/AnnouncementPopup.vue'
import UserDashboard from '../components/UserDashboard.vue'
import { ADMIN_DASHBOARD_ROLES } from '../types'

const auth = useAuthStore()
const isAdmin = computed(() => auth.role !== null && ADMIN_DASHBOARD_ROLES.includes(auth.role))
</script>

<template>
  <RedesignShell>
    <p class="mb-4 text-body2 text-slate-500">
      Anda masuk sebagai
      <span class="font-medium text-brand-primary" data-testid="home-role">
        {{ auth.role ? `${auth.role} — ${ROLE_LABELS[auth.role]}` : '' }}
      </span>
      <template v-if="auth.user?.id_satker"> · Satker {{ auth.user.id_satker }}</template>
    </p>
    <AdminDashboard v-if="isAdmin" />
    <UserDashboard v-else />
    <AnnouncementPopup />
  </RedesignShell>
</template>
