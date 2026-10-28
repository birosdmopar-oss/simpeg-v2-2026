<script setup lang="ts">
/**
 * Pengingat persetujuan cuti setelah login (PRD §6.4.7, keputusan Horii 28-10): dipasang sekali di shell, jadi tampil di
 * halaman mana pun yang pertama dibuka setelah login — bukan hanya halaman Cuti. Sekali per sesi tab (sessionStorage;
 * dikosongkan saat Keluar), hanya role Cuti dan bukan sesi wajib ganti password. Daftar dari `GET cuti/pengingat`;
 * gagal dimuat → diam (antrean tetap ada di tab Perlu Persetujuan).
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import PengingatCutiDialog from './PengingatCutiDialog.vue'
import { sudahDitampilkan, tandaiDitampilkan } from '../composables/pengingatCuti'
import { CUTI_ROLES } from '../roles'
import { LAYANAN_ROUTE } from '../routes.layanan'
import { cutiService } from '../services/cuti.service'
import type { CutiItem } from '../types'

const auth = useAuthStore()
const router = useRouter()

const open = ref(false)
const items = ref<CutiItem[]>([])
const error = ref('')
const busyId = ref<number | null>(null)

async function muat(): Promise<void> {
  if (auth.role === null || !CUTI_ROLES.includes(auth.role) || auth.mustChangePassword || sudahDitampilkan()) return
  try {
    const hasil = await cutiService.pengingat()
    tandaiDitampilkan()
    if (hasil.items.length > 0) {
      items.value = hasil.items
      open.value = true
    }
  } catch {
    // Abaikan: pengingat hanya kemudahan.
  }
}

function proses(row: CutiItem): void {
  open.value = false
  void router.push({ name: LAYANAN_ROUTE.CUTI_DETAIL, params: { id: row.id } })
}

async function tunda(row: CutiItem): Promise<void> {
  busyId.value = row.id
  error.value = ''
  try {
    await cutiService.tunda(row.id)
    items.value = items.value.filter((p) => p.id !== row.id)
    if (items.value.length === 0) open.value = false
  } catch (e) {
    error.value = describeApiError(e).message
  } finally {
    busyId.value = null
  }
}

onMounted(() => void muat())
</script>

<template>
  <PengingatCutiDialog v-model:open="open" :items="items" :busy-id="busyId" :error="error" @proses="proses" @tunda="tunda" />
</template>
