<script setup lang="ts">
/**
 * Topbar navy membulat (Laporan Redesign §4, Gambar 12): judul sistem di kiri, lonceng notifikasi di kanan.
 * Titik merah pada lonceng hanya muncul bila `unread > 0` — pusat notifikasi (F-08) baru ada di Fase 7,
 * jadi sampai saat itu lonceng tampil tanpa titik agar tidak menyesatkan.
 * Di layar < lg tersedia tombol hamburger untuk membuka sidebar.
 */
import { Bell, Menu } from 'lucide-vue-next'

withDefaults(defineProps<{ title?: string; unread?: number }>(), {
  title: 'Sistem Informasi Kepegawaian',
  unread: 0,
})

defineEmits<{ 'open-menu': [] }>()
</script>

<template>
  <header
    class="flex h-14 items-center justify-between gap-3 rounded-2xl bg-brand-primary px-4 text-white shadow-float sm:px-6"
    data-testid="app-topbar"
  >
    <div class="flex min-w-0 items-center gap-3">
      <button
        type="button"
        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/10 focus-visible:ring-white/60 focus-visible:ring-offset-brand-primary lg:hidden"
        aria-label="Buka menu"
        data-testid="topbar-open-menu"
        @click="$emit('open-menu')"
      >
        <Menu class="h-5 w-5" aria-hidden="true" />
      </button>
      <p class="truncate text-body1 font-medium">{{ title }}</p>
    </div>

    <button
      type="button"
      class="relative inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/10 focus-visible:ring-white/60 focus-visible:ring-offset-brand-primary"
      :aria-label="unread > 0 ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'"
      data-testid="topbar-bell"
    >
      <Bell class="h-5 w-5" aria-hidden="true" />
      <span
        v-if="unread > 0"
        class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-danger ring-2 ring-brand-primary"
        aria-hidden="true"
      />
    </button>
  </header>
</template>
