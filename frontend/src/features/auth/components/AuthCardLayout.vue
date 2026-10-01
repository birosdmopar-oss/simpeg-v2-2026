<script setup lang="ts">
/**
 * Kerangka halaman tamu Modul A (login, lupa password, reset password): latar gradasi brand + identitas aplikasi,
 * lalu kartu form dari slot. Referensi visual: palet & font Laporan Redesign (layar ini belum ada di Figma).
 */
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-primary via-brand-primary to-[#0f2447] px-4 py-10">
    <div class="w-full max-w-md">
      <div class="mb-6 text-center text-white">
        <!-- Lencana sementara (sama dengan sidebar); lambang Kemenpar final belum tersedia. -->
        <div class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-full bg-white text-h5 font-bold text-brand-primary ring-4 ring-brand-secondary" aria-hidden="true">S</div>
        <h1 class="text-h3 font-semibold">SIMPEG v2</h1>
        <p class="mt-1 text-body1 text-white/80">Sistem Informasi Kepegawaian Kementerian Pariwisata</p>
      </div>

      <slot />
    </div>
  </main>
</template>
