<script setup lang="ts">
/**
 * Host dialog interaksi di shell (Q7-40): form digital (MAKE-083) dan Survei Kepuasan Layanan (MAKE-084) tampil di halaman
 * mana pun yang pertama dibuka (legacy memuatnya di `views/template/footer.php:196` pada setiap halaman).
 *
 * - Diam (tanpa request) bila `ACTIVE_PHASE` < {@link FASE_INTERAKSI} atau sesi wajib ganti password. Form digital untuk role
 *   2/6/7, SKL untuk role 2/6 (Q7-23); role lain tanpa request.
 * - Memuat `form-digital/saya` dan `survei-layanan/saya` sekali per pemasangan shell; daftar kosong → tidak meminta giliran.
 * - Peserta antrean didaftarkan sinkron di setup (CR-118); ada isi → `minta()`, kosong/galat → `batal()`; antrean menjamin form digital tampil lebih dulu dan SKL terakhir
 *   (urutan legacy). Giliran dilepas setelah butir terakhir.
 */
import { computed, onMounted, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import SurveiLayananDialog from '@/features/survei-layanan/components/SurveiLayananDialog.vue'
import { SKL_PENGISI_ROLES } from '@/features/survei-layanan/roles'
import { surveiLayananService } from '@/features/survei-layanan/services/surveiLayanan.service'
import type { TagihanSkl } from '@/features/survei-layanan/types'
import { useAntreanDialog } from '@/shared/composables/antreanDialog'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

import FormIsianDialog from './FormIsianDialog.vue'
import { FASE_INTERAKSI, FORM_DIGITAL_PENGISI_ROLES } from '../roles'
import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya } from '../types'

const props = withDefaults(defineProps<{ fase?: number }>(), { fase: ACTIVE_PHASE })

const auth = useAuthStore()
const { daftar } = useAntreanDialog()

// CR-118: daftar SINKRON di setup (sebelum fetch) supaya urutan antrean deterministik.
const bolehTampil = props.fase >= FASE_INTERAKSI && !auth.mustChangePassword
const pesertaForm = bolehTampil && auth.role !== null && FORM_DIGITAL_PENGISI_ROLES.includes(auth.role) ? daftar('form-digital') : null
const pesertaSkl = bolehTampil && auth.role !== null && SKL_PENGISI_ROLES.includes(auth.role) ? daftar('skl') : null

const antrean = ref<FormSaya[]>([])
const skl = ref<TagihanSkl[]>([])
const pesan = ref('')
let lepasForm: (() => void) | null = null
let lepasSkl: (() => void) | null = null

const aktif = computed<FormSaya | undefined>(() => antrean.value[0])

async function muatForm(): Promise<void> {
  if (pesertaForm === null) return
  try {
    const rows = await formDigitalService.saya()
    if (rows.length === 0) return pesertaForm.batal()
    lepasForm = await pesertaForm.minta()
    antrean.value = rows
  } catch (e) {
    pesertaForm.batal()
    if (import.meta.env.DEV) console.warn('[form-digital] daftar form gagal dimuat', e)
  }
}

async function muatSkl(): Promise<void> {
  if (pesertaSkl === null) return
  try {
    const rows = await surveiLayananService.saya()
    if (rows.length === 0) return pesertaSkl.batal()
    lepasSkl = await pesertaSkl.minta()
    skl.value = rows
  } catch (e) {
    pesertaSkl.batal()
    if (import.meta.env.DEV) console.warn('[survei-layanan] daftar survei gagal dimuat', e)
  }
}

function selesai(info: string | null): void {
  if (info) pesan.value = info
  antrean.value = antrean.value.slice(1)
  if (antrean.value.length === 0 && lepasForm) {
    lepasForm()
    lepasForm = null
  }
}

function sklSelesai(): void {
  skl.value = []
  if (lepasSkl) {
    lepasSkl()
    lepasSkl = null
  }
}

onMounted(() => {
  if (!bolehTampil) return
  void muatForm()
  void muatSkl()
})
</script>

<template>
  <FormIsianDialog v-if="aktif" :key="aktif.id" :form="aktif" @selesai="selesai" />
  <SurveiLayananDialog v-if="skl.length > 0" :tagihan="skl" @selesai="sklSelesai" />
  <p v-if="pesan" class="sr-only" role="status" data-testid="form-isian-info">{{ pesan }}</p>
</template>
