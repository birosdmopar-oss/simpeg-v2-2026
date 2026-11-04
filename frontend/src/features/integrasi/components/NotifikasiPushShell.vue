<script setup lang="ts">
/**
 * Banner izin notifikasi push web (PRD Fase 6 §7.4 F6-13, MAKE-066) — dipasang di layout bersama setelah login.
 *
 * - Izin sudah diberikan → token didaftarkan diam-diam setiap aplikasi dimuat (padanan legacy `header.php:59`); banner tidak
 *   tampil.
 * - Izin belum ditanya → banner `UiNotice` dengan tombol "Aktifkan" (permintaan izin browser hanya dari klik) dan "Nanti"
 *   (tidak tampil lagi di browser ini).
 * - Izin ditolak, browser tanpa dukungan, atau konfigurasi Firebase kosong → tidak tampil apa pun.
 */
import { onMounted, ref } from 'vue'

import { UiButton, UiNotice } from '@/shared/ui'

import {
  aktifkanNotifikasiPush,
  daftarkanTokenPush,
  izinNotifikasi,
  sudahDitunda,
  tandaiDitunda,
} from '../composables/notifikasiPush'

const tampil = ref(false)
const memproses = ref(false)

onMounted(() => {
  const izin = izinNotifikasi()

  if (izin === 'granted') {
    void daftarkanTokenPush()
  } else if (izin === 'default' && !sudahDitunda()) {
    tampil.value = true
  }
})

async function aktifkan(): Promise<void> {
  memproses.value = true
  try {
    await aktifkanNotifikasiPush()
  } finally {
    memproses.value = false
    tampil.value = false
  }
}

function nanti(): void {
  tandaiDitunda()
  tampil.value = false
}
</script>

<template>
  <UiNotice v-if="tampil" tone="info" class="mt-4" data-testid="notifikasi-push-banner">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <span>Aktifkan notifikasi browser agar pemberitahuan pengajuan dan persetujuan SIMPEG muncul di perangkat ini.</span>
      <span class="flex gap-2">
        <UiButton size="sm" :loading="memproses" data-testid="notifikasi-push-aktifkan" @click="aktifkan">Aktifkan</UiButton>
        <UiButton size="sm" appearance="ghost" variant="secondary" :disabled="memproses" data-testid="notifikasi-push-nanti" @click="nanti">
          Nanti
        </UiButton>
      </span>
    </div>
  </UiNotice>
</template>
