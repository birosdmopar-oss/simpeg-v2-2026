<script setup lang="ts">
/**
 * Host dialog interaksi di shell (Q7-40, MAKE-083; SKL menyusul MAKE-084): form digital yang wajib diisi tampil di halaman
 * mana pun yang pertama dibuka (legacy memuatnya di `views/template/footer.php:196` pada setiap halaman).
 *
 * - Diam (tanpa request) bila `ACTIVE_PHASE` < {@link FASE_INTERAKSI}, role bukan 2/6/7, atau sesi wajib ganti password.
 * - Memuat `form-digital/saya` sekali per pemasangan shell; daftar kosong → tidak meminta giliran antrean.
 * - Ada form → `minta('form-digital')` (prioritas pertama antrean); form ditampilkan satu per satu; setelah form terakhir,
 *   giliran antrean dilepas.
 */
import { computed, onMounted, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { useAntreanDialog } from '@/shared/composables/antreanDialog'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

import FormIsianDialog from './FormIsianDialog.vue'
import { FASE_INTERAKSI, FORM_DIGITAL_PENGISI_ROLES } from '../roles'
import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya } from '../types'

const props = withDefaults(defineProps<{ fase?: number }>(), { fase: ACTIVE_PHASE })

const auth = useAuthStore()
const { minta } = useAntreanDialog()

const antrean = ref<FormSaya[]>([])
const pesan = ref('')
let lepas: (() => void) | null = null

const aktif = computed<FormSaya | undefined>(() => antrean.value[0])

async function muat(): Promise<void> {
  if (props.fase < FASE_INTERAKSI) return
  if (auth.role === null || !FORM_DIGITAL_PENGISI_ROLES.includes(auth.role) || auth.mustChangePassword) return
  try {
    const daftar = await formDigitalService.saya()
    if (daftar.length === 0) return
    lepas = await minta('form-digital')
    antrean.value = daftar
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[form-digital] daftar form gagal dimuat', e)
  }
}

function selesai(info: string | null): void {
  if (info) pesan.value = info
  antrean.value = antrean.value.slice(1)
  if (antrean.value.length === 0 && lepas) {
    lepas()
    lepas = null
  }
}

onMounted(() => void muat())
</script>

<template>
  <FormIsianDialog v-if="aktif" :key="aktif.id" :form="aktif" @selesai="selesai" />
  <p v-if="pesan" class="sr-only" role="status" data-testid="form-isian-info">{{ pesan }}</p>
</template>
