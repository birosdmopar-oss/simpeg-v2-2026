<script setup lang="ts">
/**
 * Daftar Tugas Belajar / Izin Belajar (berparameter `jenis`; PRD Fase 4 §6.7–6.8). Tab Saya (pegawai/PTT/PPPK), Perlu
 * Persetujuan (hanya bila server memberi `adalah_pic` = true), dan Monitoring (role 1/3). Tombol Ajukan hanya bila
 * `can_create` dari respons daftar. Item ⋮ dari `aksi {}` server; Hapus lewat ConfirmDialog. Halaman daftar ber-tabel
 * memakai pola gulir CR-069/CR-070.
 */
import { Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiTabs } from '@/shared/ui'
import type { TabItem } from '@/shared/ui'

import StudiFormDialog from '../components/StudiFormDialog.vue'
import StudiProsesDialog, { type AksiProsesStudi } from '../components/StudiProsesDialog.vue'
import StudiTable from '../components/StudiTable.vue'
import { STUDI_CONFIG } from '../studiConfig'
import { ibService, tbService, type ModeStudi, type StudiItem, type StudiJenis, type StudiListResponse, type StudiOpsi, type StudiService } from '../services/studi.service'

const props = defineProps<{ jenis: StudiJenis }>()

const cfg = STUDI_CONFIG[props.jenis]
const service: StudiService = props.jenis === 'tb' ? tbService : ibService
const router = useRouter()
const auth = useAuthStore()
const PER_PAGE = 20

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: cfg.judul }]
const admin = computed(() => auth.role === Role.SUPER_ADMIN || auth.role === Role.ADMIN_SATKER)
const pegawai = computed(() => auth.role === Role.PEGAWAI || auth.role === Role.PTT || auth.role === Role.PPPK)

const opsi = ref<StudiOpsi | null>(null)
const tab = ref<ModeStudi>(admin.value ? 'admin' : 'saya')
const search = ref('')
const page = ref(1)
const hasil = ref<StudiListResponse | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')

const tabItems = computed<TabItem[]>(() => {
  const out: TabItem[] = []
  if (pegawai.value) out.push({ value: 'saya', label: 'Saya' })
  if (opsi.value?.adalah_pic) out.push({ value: 'pic', label: 'Perlu Persetujuan' })
  if (admin.value) out.push({ value: 'admin', label: 'Monitoring' })
  return out
})

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    hasil.value = await service.list({ mode: tab.value, search: search.value, page: page.value, per_page: PER_PAGE })
  } catch (err) {
    hasil.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 300)
})
watch(tab, () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

// --- Dialog & aksi baris ----------------------------------------------------------------------------------------------
const formOpen = ref(false)
const formRow = ref<StudiItem | null>(null)
const proses = ref<{ open: boolean; row: StudiItem | null; aksi: AksiProsesStudi }>({ open: false, row: null, aksi: 'setujui' })
const hapus = ref<{ open: boolean; row: StudiItem | null; loading: boolean }>({ open: false, row: null, loading: false })

function tambah(): void {
  notice.value = ''
  formRow.value = null
  formOpen.value = true
}

async function onAksi(row: StudiItem, key: string): Promise<void> {
  notice.value = ''
  if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else if (key === 'detail' || key === 'perpanjangan') {
    await router.push({ name: cfg.routeDetail, params: { id: row.id } })
  } else if (key === 'setujui' || key === 'tolak') {
    proses.value = { open: true, row, aksi: key === 'setujui' ? 'setujui' : 'tolak' }
  } else if (key === 'surat') {
    try {
      simpanBerkas(await service.surat(row.id, `${cfg.jenis}-surat-${row.nip}`))
    } catch (err) {
      failure.value = describeApiError(err).message
    }
  } else if (key === 'hapus') {
    hapus.value = { open: true, row, loading: false }
  }
}

function onSelesai(pesan: string): void {
  notice.value = pesan
  void muat()
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  try {
    await service.hapus(row.id)
    notice.value = `${cfg.judul} ${row.nama ?? row.nip} dihapus.`
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    hapus.value = { open: false, row: null, loading: false }
    void muat()
  }
}

onMounted(async () => {
  try {
    opsi.value = await service.opsi()
  } catch {
    opsi.value = null
  }
  if (tabItems.value.length > 0 && !tabItems.value.some((t) => t.value === tab.value)) tab.value = tabItems.value[0].value as ModeStudi
  void muat()
})

const kosongPesan = computed(() => (tab.value === 'pic' ? `Tidak ada ${cfg.judul} yang menunggu persetujuan Anda.` : `Belum ada ${cfg.judul}.`))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" :data-testid="`${cfg.testid}-page`">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ cfg.judul }}</h1>
          <p class="text-body2 text-slate-500">Pengajuan, persetujuan, dan riwayat {{ cfg.judul }}.</p>
        </div>
        <UiButton v-if="hasil?.can_create" :data-testid="`${cfg.testid}-ajukan`" @click="tambah">
          <Plus class="h-4 w-4" aria-hidden="true" /> Ajukan {{ cfg.singkatan }}
        </UiButton>
      </div>

      <UiNotice v-if="notice" tone="success" :data-testid="`${cfg.testid}-notice`">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" :data-testid="`${cfg.testid}-failure`">{{ failure }}</UiNotice>

      <UiTabs v-if="tabItems.length > 0" v-model="tab" :items="tabItems" :aria-label="cfg.judul" :data-testid="`${cfg.testid}-tabs`" />

      <UiCard :title="`Daftar ${cfg.judul}`" flush fill class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
          <UiSearchInput v-model="search" placeholder="Cari NIP / nama" :label="`Cari ${cfg.judul}`" class="min-w-[200px] flex-1" :data-testid="`${cfg.testid}-search`" />
        </div>
        <StudiTable :rows="hasil?.items ?? []" :cfg="cfg" :memuat="memuat" :kosong-pesan="kosongPesan" @aksi="onAksi" />
        <div v-if="hasil && hasil.total > PER_PAGE" class="p-4">
          <UiPagination v-model:page="page" :per-page="PER_PAGE" :total="hasil.total" />
        </div>
      </UiCard>

      <StudiFormDialog v-model:open="formOpen" :row="formRow" :cfg="cfg" :service="service" @saved="onSelesai(formRow ? 'Data diperbarui.' : 'Pengajuan dikirim.')" />
      <StudiProsesDialog
        v-if="proses.row"
        v-model:open="proses.open"
        :row="proses.row"
        :aksi="proses.aksi"
        :cfg="cfg"
        :service="service"
        @saved="onSelesai(proses.aksi === 'setujui' ? 'Pengajuan disetujui.' : 'Pengajuan ditolak.')"
      />
      <ConfirmDialog
        :open="hapus.open"
        :title="`Hapus ${cfg.judul}`"
        :description="`Hapus ${cfg.judul} ${hapus.row?.nama ?? hapus.row?.nip ?? ''}? Data dan berkasnya dihapus permanen dan tercatat di audit.`"
        confirm-label="Ya, hapus"
        danger
        :loading="hapus.loading"
        @update:open="(v) => !hapus.loading && (hapus.open = v)"
        @confirm="konfirmasiHapus"
      />
    </div>
  </RedesignShell>
</template>
