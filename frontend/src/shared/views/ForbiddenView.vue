<script setup lang="ts">
// Halaman 403 (ADR-024): sudah login tapi role tidak berhak — terpisah dari redirect /login.
</script>

<template>
  <main class="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center gap-3 p-6 text-center">
    <p class="text-5xl font-bold text-brand-primary">403</p>
    <h1 class="text-lg font-semibold text-slate-800">Akses ditolak</h1>
    <p class="text-sm text-slate-500">Role akun Anda tidak memiliki hak akses ke halaman ini.</p>
    <RouterLink :to="{ name: 'home' }" class="mt-2 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white">
      Kembali ke Beranda
    </RouterLink>
  </main>
</template>
