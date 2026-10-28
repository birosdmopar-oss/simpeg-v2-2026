<script setup lang="ts">
/**
 * Presensi manual akun khusus (D-03, PRD 5A §7.6) [K `views/hr/employee/presensi/form.php`]: Tambah (NIP, tanggal, jam
 * masuk, jam pulang) atau Edit (NIP & tanggal tetap, hanya jam). Validasi yang mengikat (wajib, format, minimal satu jam,
 * urutan jam, tanggal ≤ hari ini, duplikat) di server; galat 422 ditampilkan per kolom. Jendela waktu & geofence tidak
 * berlaku untuk manual [K].
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import { formatJam, formatTanggal, presensiService } from '../services/presensi.service'
import type { BarisPresensiAdmin } from '../types'

const props = defineProps<{
  open: boolean
  /** null = tambah; baris = edit. */
  baris: BarisPresensiAdmin | null
  /** NIP bawaan saat tambah dari detail pegawai (masih bisa diubah). */
  nipAwal?: string
  namaPegawai?: string
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [pesan: string] }>()

const isEdit = computed(() => props.baris !== null)
const nip = ref('')
const tgl = ref('')
const jamMasuk = ref('')
const jamPulang = ref('')
const errors = ref<Record<string, string>>({})
const formError = ref('')
const menyimpan = ref(false)

watch(
  () => [props.open, props.baris] as const,
  ([open, b]) => {
    if (!open) return
    nip.value = b?.nip ?? props.nipAwal ?? ''
    tgl.value = b?.tgl ?? ''
    jamMasuk.value = b?.jam_masuk ? formatJam(b.jam_masuk) : ''
    jamPulang.value = b?.jam_pulang ? formatJam(b.jam_pulang) : ''
    errors.value = {}
    formError.value = ''
  },
  { immediate: true },
)

async function simpan(event: Event): Promise<void> {
  event.preventDefault()
  if (menyimpan.value) return
  menyimpan.value = true
  errors.value = {}
  formError.value = ''
  try {
    if (props.baris) {
      // Pemilih jam bertingkat menit: jam yang tidak diubah dikirim apa adanya (termasuk detik), supaya server tidak
      // menganggapnya berubah dan tidak mengosongkan bukti lokasi jam itu (◻ Q5A-16).
      const asli = (baru: string, lama: string | null) => (lama && baru === formatJam(lama) ? lama : baru)
      await presensiService.ubahManual(props.baris.nip, props.baris.tgl, {
        jam_masuk: asli(jamMasuk.value, props.baris.jam_masuk),
        jam_pulang: asli(jamPulang.value, props.baris.jam_pulang),
      })
      emit('saved', `Presensi NIP ${props.baris.nip} tanggal ${formatTanggal(props.baris.tgl).tanggal} diperbarui.`)
    } else {
      await presensiService.tambahManual({ nip: nip.value, tgl: tgl.value, jam_masuk: jamMasuk.value, jam_pulang: jamPulang.value })
      emit('saved', `Presensi NIP ${nip.value.trim()} tanggal ${formatTanggal(tgl.value).tanggal} ditambahkan.`)
    }
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors) {
      errors.value = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, String(v[0] ?? '')]))
      formError.value = 'Periksa kembali isian yang ditandai.'
    } else {
      formError.value = isApiError(err) && (err.status === 403 || err.status === 404) && err.message ? err.message : describeApiError(err).message
    }
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !menyimpan && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="scrollbar-slim fixed left-1/2 top-1/2 z-50 max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="presensi-manual-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ isEdit ? 'Edit Presensi' : 'Tambah Presensi' }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              {{ isEdit ? 'NIP dan tanggal tidak dapat diubah.' : 'Presensi manual tidak dibatasi jendela waktu maupun lokasi.' }}
            </DialogDescription>
          </div>
          <DialogClose :disabled="menyimpan" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form class="space-y-3" novalidate data-testid="presensi-manual-form" @submit="simpan">
          <UiNotice v-if="formError" tone="danger" data-testid="presensi-manual-error">{{ formError }}</UiNotice>
          <p v-if="namaPegawai" class="text-body2 text-slate-700">Pegawai: <strong>{{ namaPegawai }}</strong></p>
          <UiTextField
            v-model="nip"
            name="nip"
            label="NIP"
            inputmode="numeric"
            required
            :disabled="isEdit || menyimpan"
            :state="errors.nip ? 'error' : 'default'"
            :help-text="errors.nip ?? ''"
            data-testid="presensi-manual-nip"
          />
          <UiTextField
            v-model="tgl"
            name="tgl"
            type="date"
            label="Tanggal"
            required
            :disabled="isEdit || menyimpan"
            :state="errors.tgl ? 'error' : 'default'"
            :help-text="errors.tgl ?? ''"
            data-testid="presensi-manual-tgl"
          />
          <div class="grid grid-cols-2 gap-3">
            <UiTextField
              v-model="jamMasuk"
              name="jam_masuk"
              type="time"
              label="Jam Masuk"
              :disabled="menyimpan"
              :state="errors.jam_masuk ? 'error' : 'default'"
              :help-text="errors.jam_masuk ?? ''"
              data-testid="presensi-manual-jam-masuk"
            />
            <UiTextField
              v-model="jamPulang"
              name="jam_pulang"
              type="time"
              label="Jam Pulang"
              :disabled="menyimpan"
              :state="errors.jam_pulang ? 'error' : 'default'"
              :help-text="errors.jam_pulang ?? ''"
              data-testid="presensi-manual-jam-pulang"
            />
          </div>
          <p class="text-caption text-slate-500">Isi minimal salah satu jam. Kosongkan untuk menghapus jam tersebut.</p>

          <div class="flex flex-wrap justify-end gap-2 pt-2">
            <UiButton variant="secondary" appearance="outline" :disabled="menyimpan" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="menyimpan" data-testid="presensi-manual-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
