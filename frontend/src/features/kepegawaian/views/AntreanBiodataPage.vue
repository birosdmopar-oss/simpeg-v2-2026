<script setup lang="ts">
/**
 * Antrean pengajuan perubahan Data Umum (B-04 × B-20 penutup, pemilik WS-2, MAKE-014; legacy `hr/employee/index` daftar
 * `flag_update` + notifikasi `need_approval`). Data `GET biodata/pengajuan` (role 1, 3; lingkup jalur proses di server).
 * Pencarian, paginasi, menu ⋮ per baris: Setujui → Tolak (PengajuanBiodataDialog dengan diff) → Lihat pegawai.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { ApprovalAksi } from '@/shared/components/approvalActions'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiNotice, UiPagination, UiSearchInput } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import PengajuanBiodataDialog from '../components/PengajuanBiodataDialog.vue'
import { fullName } from '../columns'
import { describeApiError } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { PengajuanBiodataDetail, PengajuanBiodataItem } from '../types'

const router = useRouter()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Pengajuan Data Umum' }]
const PER_PAGE = 20

const rows = ref<PengajuanBiodataItem[]>([])
const total = ref(0)
const page = ref(1)
const search = ref('')
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const prosesOpen = ref(false)
const prosesMode = ref<ApprovalAksi>('setujui')
const dipilih = ref<PengajuanBiodataItem | null>(null)
let token = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  try {
    const hasil = await pegawaiService.antreanPengajuan({ page: page.value, per_page: PER_PAGE, search: search.value.trim() || undefined })
    if (saya !== token) return
    rows.value = hasil.items
    total.value = hasil.total
  } catch (e) {
    if (saya !== token) return
    rows.value = []
    total.value = 0
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(muat)
watch(page, () => void muat())
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 250)
})
onBeforeUnmount(() => clearTimeout(timer))

const actionsFor = (): RowAction[] => [
  { key: 'setujui', label: 'Setujui' },
  { key: 'tolak', label: 'Tolak' },
  { key: 'detail', label: 'Lihat pegawai' },
]

function onAction(key: string, row: PengajuanBiodataItem): void {
  if (key === 'detail') {
    void router.push({ name: 'pegawai-detail', params: { nip: row.nip } })
    return
  }
  dipilih.value = row
  prosesMode.value = key === 'tolak' ? 'tolak' : 'setujui'
  prosesOpen.value = true
}

function onProcessed(hasil: PengajuanBiodataDetail): void {
  notice.value = {
    tone: 'success',
    text: Number(hasil.pengajuan.flag_update) === 3 ? 'Pengajuan perubahan data umum disetujui.' : 'Pengajuan perubahan data umum ditolak.',
  }
  void muat()
}

const namaDipilih = computed(() => (dipilih.value ? fullName(dipilih.value) : ''))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Pengajuan Perubahan Data Umum" subtitle="Pengajuan pegawai yang menunggu verifikasi" class="page-fill" fill data-testid="antrean-biodata-page">
      <div class="mb-4 w-72 max-w-full"><UiSearchInput v-model="search" placeholder="Cari nama / NIP" label="Cari pengajuan" /></div>
      <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="antrean-biodata-notice">{{ notice.text }}</UiNotice>

      <div class="table-fill scrollbar-slim overflow-x-auto">
        <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="antrean-biodata-table">
          <thead>
            <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
              <th scope="col" class="px-4 py-3">Pegawai</th>
              <th scope="col" class="px-4 py-3">Jabatan</th>
              <th scope="col" class="px-4 py-3">Unit / Satker</th>
              <th scope="col" class="px-4 py-3">Diajukan</th>
              <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-if="memuat && rows.length === 0">
              <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
            </tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="antrean-biodata-empty">Tidak ada pengajuan yang menunggu.</td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id_pegawai_hist" :data-testid="`antrean-biodata-row-${row.id_pegawai_hist}`">
              <td class="px-4 py-3">
                <span class="block font-medium text-slate-900">{{ fullName(row) }}</span>
                <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ row.jabatan ?? '—' }}</td>
              <td class="px-4 py-3 text-slate-700">{{ [row.unit, row.satker].filter(Boolean).join(' / ') || '—' }}</td>
              <td class="px-4 py-3 text-slate-700">{{ formatApiDateTime(row.tgl_permohonan ?? '') || '—' }}</td>
              <td class="sticky right-0 bg-white px-4 py-3 text-right">
                <RowActionsMenu
                  :actions="actionsFor()"
                  :label="`Aksi untuk pengajuan ${fullName(row)}`"
                  :testid="`biodata-actions-${row.id_pegawai_hist}`"
                  @select="(key) => onAction(key, row)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <UiPagination v-if="total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="total" />
    </UiCard>

    <PengajuanBiodataDialog
      v-if="dipilih"
      v-model:open="prosesOpen"
      :mode="prosesMode"
      :id-hist="dipilih.id_pegawai_hist"
      :nama="namaDipilih"
      @processed="onProcessed"
    />
  </RedesignShell>
</template>
