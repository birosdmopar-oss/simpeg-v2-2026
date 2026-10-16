<script setup lang="ts">
/**
 * Arsip Kepegawaian (tombol di header Detail Pegawai; B-20 penutup × B-18, MAKE-014; keputusan user 13-10-2026 Q-M6-6)
 * = arsip Data Umum legacy lewat endpoint lampiran dengan `id_entri` = NIP, memakai ArsipTable:
 *  - Arsip Data Umum (kode 38): pilih jenis (KTP, Paspor, BPJS, Taspen, Kartu Istri/Suami/Pegawai, NPWP, Lain-lain + nama)
 *    lalu unggah; unggah/hapus role 1, 3 (lingkup), 2/6/7 milik sendiri; jpg/jpeg/png/pdf 5 MB;
 *  - Arsip Status (kode 37): nama arsip opsional; unggah/hapus role 1.
 * Lihat & unduh: siapa pun yang boleh melihat pegawai. Server tetap menegakkan hak & lingkup.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { UiSelect, UiTextField } from '@/shared/ui'

import type { AturanLampiran } from '../types'

import ArsipTable from './ArsipTable.vue'

const props = defineProps<{ open: boolean; nip: string; nama: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const KODE_ARSIP = 38
const KODE_STATUS = 37
const ATURAN_ARSIP: AturanLampiran = { id_riwayat: KODE_ARSIP, wajib: false, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'pdf'] }
const ATURAN_STATUS: AturanLampiran = {
  id_riwayat: KODE_STATUS,
  wajib: false,
  batas_mb: 5,
  ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
}
const JENIS = [
  { value: 'KTP', label: 'KTP' },
  { value: 'Paspor', label: 'Paspor' },
  { value: 'BPJS', label: 'BPJS' },
  { value: 'Taspen', label: 'Taspen' },
  { value: 'KARIS', label: 'Kartu Istri' },
  { value: 'KARSU', label: 'Kartu Suami' },
  { value: 'KARPEG', label: 'Kartu Pegawai' },
  { value: 'NPWP', label: 'NPWP' },
  { value: 'Lain-lain', label: 'Lain-lain' },
]

const auth = useAuthStore()
const superAdmin = computed(() => auth.role === Role.SUPER_ADMIN)
/** Legacy add_adu/delete_adu: 1, 3 (lingkup di server), 2/6/7 hanya miliknya. */
const bolehArsip = computed(() => {
  const role = auth.role
  if (role === Role.SUPER_ADMIN || role === Role.ADMIN_SATKER) return true
  return ([Role.PEGAWAI, Role.PTT, Role.PPPK] as number[]).includes(role ?? 0) && auth.user?.nip === props.nip
})

const jenis = ref('')
const namaLain = ref('')
const namaStatus = ref('')

const isianArsip = computed<Record<string, string>>(() => ({
  jenis_arsip: jenis.value,
  ...(jenis.value === 'Lain-lain' ? { nama_arsip_lain: namaLain.value.trim() } : {}),
}))
const tahanArsip = computed(() => {
  if (!jenis.value) return 'Pilih jenis arsip dulu.'
  if (jenis.value === 'Lain-lain' && !namaLain.value.trim()) return 'Isi nama arsip lain dulu.'
  return ''
})
const isianStatus = computed<Record<string, string>>(() => {
  const hasil: Record<string, string> = {}
  if (namaStatus.value.trim()) hasil.nama_arsip = namaStatus.value.trim()
  return hasil
})
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-4xl -translate-x-1/2 -translate-y-1/2 space-y-6 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="arsip-kepegawaian"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Arsip Kepegawaian</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ nama }} ({{ nip }})</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <div class="space-y-3">
          <div v-if="bolehArsip" class="grid gap-3 sm:grid-cols-2">
            <UiSelect v-model="jenis" label="Jenis Arsip" placeholder="Pilih jenis arsip" :options="JENIS" data-testid="arsip-du-jenis" />
            <UiTextField v-if="jenis === 'Lain-lain'" v-model="namaLain" label="Nama Arsip Lain" data-testid="arsip-du-nama-lain" />
          </div>
          <ArsipTable
            :nip="nip"
            :target="{ id_riwayat: KODE_ARSIP, id_entri: nip }"
            :aturan="ATURAN_ARSIP"
            :can-upload="bolehArsip"
            :can-delete="bolehArsip"
            :isian-unggah="isianArsip"
            :unggah-ditahan="tahanArsip"
            tampil-tag
            title="Arsip Data Umum"
          />
        </div>

        <div class="space-y-3 border-t border-slate-200 pt-5">
          <div v-if="superAdmin" class="max-w-md">
            <UiTextField v-model="namaStatus" label="Nama Arsip Status (opsional)" placeholder="Arsip Status" data-testid="arsip-status-nama" />
          </div>
          <ArsipTable
            :nip="nip"
            :target="{ id_riwayat: KODE_STATUS, id_entri: nip }"
            :aturan="ATURAN_STATUS"
            :can-upload="superAdmin"
            :can-delete="superAdmin"
            :isian-unggah="isianStatus"
            tampil-tag
            title="Arsip Status"
          />
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
