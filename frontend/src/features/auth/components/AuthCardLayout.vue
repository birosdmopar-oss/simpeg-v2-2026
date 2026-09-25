<script setup lang="ts">
/**
 * Kerangka halaman tamu Modul A (login, lupa password, reset password): latar gradasi brand + identitas aplikasi,
 * lalu kartu form dari slot. Referensi visual: palet & font Laporan Redesign (layar ini belum ada di Figma).
 */
import { LockKeyhole } from 'lucide-vue-next'
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-primary to-[#0f2447] px-4 py-10">
    <div class="w-full max-w-md">
      <div class="mb-6 text-center text-white">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-white/10">
          <LockKeyhole class="h-6 w-6" />
        </div>
        <h1 class="text-2xl font-semibold">SIMPEG v2</h1>
        <p class="mt-1 text-sm text-white/80">Sistem Informasi Kepegawaian Kementerian Pariwisata</p>
      </div>

      <slot />
    </div>
  </main>
</template>
