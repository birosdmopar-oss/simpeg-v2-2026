<script setup lang="ts">
/**
 * Beranda sementara setelah login (dashboard sesungguhnya di Fase 7, Modul F).
 * Menampilkan identitas & role sesi agar MTC-001 ("redirect ke dashboard sesuai role") bisa diverifikasi.
 */
import { useAuthStore } from '@/features/auth/stores/auth.store'
import { ROLE_LABELS } from '@/features/auth/types'
import AppShell from '@/shared/components/AppShell.vue'

const auth = useAuthStore()
</script>

<template>
  <AppShell>
    <section class="rounded-lg border border-slate-200 bg-white p-6">
      <h1 class="text-xl font-semibold text-slate-900">Selamat datang, {{ auth.user?.username }}</h1>
      <p class="mt-1 text-sm text-slate-500">
        Anda masuk sebagai
        <span class="font-medium text-brand-primary" data-testid="home-role">
          {{ auth.role ? `${auth.role} — ${ROLE_LABELS[auth.role]}` : '' }}
        </span>
        <template v-if="auth.user?.id_satker"> · Satker {{ auth.user.id_satker }}</template>
      </p>
      <p class="mt-4 text-sm text-slate-600">Dashboard per role dikerjakan di Fase 7 (Modul F).</p>
    </section>
  </AppShell>
</template>
