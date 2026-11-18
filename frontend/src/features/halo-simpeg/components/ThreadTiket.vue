<script setup lang="ts">
/**
 * Panel percakapan tiket (B2, E-04; pola gelembung Gambar 33/34, tanpa frame Figma H8-9). Sumber = `detailTiket.detail`.
 * - Header: subjek (menerima fokus saat panel dibuka), kategori ("—" bila NULL), pemilik, tanggal dibuat, status, dan
 *   `ditutup_oleh_label` (null pada tiket Closed = tanpa nama penutup).
 * - Gelembung: `milik_saya` di kanan; `pengirim_label` ditampilkan apa adanya (dihitung BE, D7 (a)); warna per
 *   `urutan_pengirim` [K `VD:44`]; isi sebagai teks (`white-space: pre-wrap`, tanpa `v-html`, BP8-4).
 * - Lampiran lama/baru lewat E-04 sebagai blob (AC-E08.9), object URL dicabut sesudah klik unduh (`simpanBerkas`).
 * - Petugas tiket (bukan pemilik): `snapshot` NIP/jabatan/satker pelapor di header lewat `UiTooltip` [K `VD:21-26`], bukan
 *   `title`; centang "Ingatkan Saya Kembali?" (`ingatkan_24_jam`, F8-11) hanya bila `pengingat !== null`, jatuh tempo tampil
 *   bila `pengingat.aktif`.
 * - Form balas hanya bila `bisa_balas`; tiket berkategori NULL → catatan pengganti (H8-16 (a1)); 422 tiket ditutup / tanpa
 *   balasan / tanpa kategori → pesan BE + detail dimuat ulang.
 */
import { ArrowLeft, Info, Paperclip } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, reactive, ref, useId, watch } from 'vue'

import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { useIsDesktop } from '@/shared/composables/useIsDesktop'
import { formatApiDateTime } from '@/shared/utils/dateTime'
import { UiBadge, UiButton, UiCheckbox, UiFileField, UiNotice, UiTooltip } from '@/shared/ui'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { BATAS_TIKET, EKSTENSI_LAMPIRAN, KODE_GALAT_TIKET, TEKS_GALAT_TIKET } from '../constants'
import { balasFormSchema } from '../schemas'
import { kodeGalat } from '../services/master.service'
import { tiketHaloService } from '../services/tiket.service'
import { balasTiket, bukaTiket, detailTiket } from '../stores/tiket'
import type { Lampiran, LampiranRingkas, Pesan } from '../types'

const props = withDefaults(defineProps<{ idTiket: number; tampilKembali?: boolean }>(), { tampilKembali: false })
const emit = defineEmits<{ kembali: [] }>()

const detail = computed(() => (detailTiket.detail?.id === props.idTiket ? detailTiket.detail : null))
const judulEl = ref<HTMLElement | null>(null)

/** Fokus pindah ke judul thread saat tiket dibuka (8_2 §3a). */
watch(
  () => detail.value?.id,
  async (id) => {
    if (id === undefined) return
    await nextTick()
    judulEl.value?.focus()
  },
  { immediate: true },
)

const WARNA_PENGIRIM = ['bg-slate-100', 'bg-[#E8F1FF]', 'bg-[#EAF7EF]', 'bg-[#FFF4E5]', 'bg-[#F3EDFF]'] as const
const warna = (p: Pesan): string => (p.milik_saya ? 'bg-brand-tertiary text-white' : (WARNA_PENGIRIM[p.urutan_pengirim % WARNA_PENGIRIM.length] ?? WARNA_PENGIRIM[0]))
const waktu = (iso: string | null): string => (iso ? (formatApiDateTime(iso) ?? iso) : '—')

/**
 * Review B2 Q1: instance per tiket (`:key`) bisa sudah di-unmount saat respons tiba (pengguna pindah ke tiket lain). Efek
 * sesudah unmount dilewati, dan muat ulang detail hanya bila tiket ini masih yang terbuka (`detailTiket.idDibuka`).
 */
let aktif = true
onBeforeUnmount(() => {
  aktif = false
})

const galatUnduh = ref('')
const mengunduh = ref<number | null>(null)
async function unduh(p: Pesan, lampiran: Lampiran | LampiranRingkas): Promise<void> {
  galatUnduh.value = ''
  mengunduh.value = p.id_inbox_msg
  try {
    const berkas = await tiketHaloService.lampiran(props.idTiket, p.id_inbox_msg)
    simpanBerkas({ blob: berkas.blob, nama: berkas.nama || lampiran.nama })
  } catch (e) {
    if (aktif) galatUnduh.value = isApiError(e) ? e.message : TEKS_GALAT_TIKET.lampiranTidakDitemukan
  } finally {
    if (aktif) mengunduh.value = null
  }
}

// --- form balas ---------------------------------------------------------------------------------------------------------
const balas = reactive({ pesan: '', ingatkan: false })
const arsip = ref<File | null>(null)
const kunciBerkas = ref(0)
const mengirim = ref(false)
const submitted = ref(false)
const galatBalas = ref('')
const galatKolom = ref<Record<string, string>>({})

watch(
  () => props.idTiket,
  () => {
    balas.pesan = ''
    balas.ingatkan = false
    arsip.value = null
    kunciBerkas.value += 1
    submitted.value = false
    galatBalas.value = ''
    galatKolom.value = {}
    galatUnduh.value = ''
  },
)

const galatKlien = computed<Record<string, string>>(() => {
  const r = balasFormSchema.safeParse({ pesan: balas.pesan, arsip: arsip.value, ingatkan_24_jam: false })
  if (r.success) return {}
  const e: Record<string, string> = {}
  for (const i of r.error.issues) e[String(i.path[0] ?? '')] ??= i.message
  return e
})
/** Centang pengingat hanya untuk petugas tiket bukan pemilik (`pengingat !== null`). */
const bolehIngatkan = computed(() => detail.value?.pengingat != null)
// Review S5: `sekarang` reaktif per menit agar label berganti saat jatuh tempo lewat selagi thread terbuka.
const sekarang = ref(Date.now())
const detak = setInterval(() => {
  sekarang.value = Date.now()
}, 60_000)
onBeforeUnmount(() => clearInterval(detak))
/** Jatuh tempo pengingat sudah lewat (perbandingan instan UTC, aman lintas zona). */
const pengingatLewat = computed(() => {
  const jt = detail.value?.pengingat?.jatuh_tempo
  return jt ? new Date(jt).getTime() <= sekarang.value : false
})
const isDesktop = useIsDesktop()
const idSnapshot = `${useId()}-snapshot`
/**
 * Review S4 (a): klik "Data pelapor" membuka/menutup tooltip di semua lebar (termasuk layar sentuh ≥ 1024 px). Radix menutup
 * tooltip saat klik pemicu, jadi keadaan dibaca SEBELUM penutupan itu — saat pointerdown (mouse/sentuh) atau keydown Enter/Spasi
 * (keyboard, review B4 K1: fokus sudah membuka tooltip, Enter/Spasi menutupnya) — lalu dibalik saat click. Pointerdown yang tidak
 * berakhir click (`pointercancel`) membuang keadaan tersimpan.
 */
const snapshotBuka = ref(false)
let bukaSaatTekan: boolean | null = null
function tekanSnapshot(): void {
  bukaSaatTekan = snapshotBuka.value
}
function tekanTombolSnapshot(e: KeyboardEvent): void {
  if (e.key === 'Enter' || e.key === ' ') tekanSnapshot()
}
function batalTekanSnapshot(): void {
  bukaSaatTekan = null
}
function klikSnapshot(): void {
  snapshotBuka.value = !(bukaSaatTekan ?? snapshotBuka.value)
  bukaSaatTekan = null
}
const teksSnapshot = computed(() => {
  const sn = detail.value?.snapshot
  return sn ? `NIP: ${sn.nip} · Jabatan: ${sn.jabatan ?? '—'} · Satker: ${sn.satker ?? '—'}` : ''
})

const errBalas = (f: string): string | undefined => galatKolom.value[f] ?? (submitted.value ? galatKlien.value[f] : undefined)

/** Kode yang berarti keadaan tiket berubah di server → detail dimuat ulang agar flag `bisa_*` terbaru. */
const KODE_MUAT_ULANG: string[] = [KODE_GALAT_TIKET.tiketDitutup, KODE_GALAT_TIKET.tiketTanpaBalasan, KODE_GALAT_TIKET.tiketTanpaKategori]

async function kirim(): Promise<void> {
  submitted.value = true
  galatBalas.value = ''
  galatKolom.value = {}
  if (Object.keys(galatKlien.value).length > 0 || mengirim.value) return
  mengirim.value = true
  try {
    // Centang hanya dikirim bila tiket punya pengingat (petugas tiket bukan pemilik); selain itu selalu 0 (F8-11).
    await balasTiket(props.idTiket, { pesan: balas.pesan, arsip: arsip.value, ingatkan_24_jam: bolehIngatkan.value && balas.ingatkan })
    balas.pesan = ''
    balas.ingatkan = false
    arsip.value = null
    kunciBerkas.value += 1
    submitted.value = false
  } catch (e) {
    if (!aktif) return
    galatKolom.value = galatPerKolom(e, (k) => (k === 'msg' ? 'pesan' : k))
    const pesan = isApiError(e) ? e.message : 'Pesan tidak terkirim. Silakan coba lagi.'
    galatBalas.value = Object.values(galatKolom.value).includes(pesan) ? '' : pesan
    const kode = kodeGalat(e)
    if (kode !== null && KODE_MUAT_ULANG.includes(kode) && detailTiket.idDibuka === props.idTiket) void bukaTiket(props.idTiket)
  } finally {
    if (aktif) mengirim.value = false
  }
}
</script>

<template>
  <section class="flex min-h-0 flex-1 flex-col" aria-labelledby="halo-thread-judul" data-testid="halo-thread">
    <div v-if="tampilKembali" class="mb-3">
      <UiButton variant="secondary" appearance="soft" size="sm" data-testid="halo-thread-kembali" @click="emit('kembali')">
        <template #icon-left><ArrowLeft class="h-4 w-4" aria-hidden="true" /></template>
        Kembali ke daftar
      </UiButton>
    </div>

    <p v-if="detailTiket.statusDetail === 'memuat' && !detail" class="py-8 text-center text-sm text-slate-500">Memuat...</p>
    <div v-else-if="detailTiket.statusDetail === 'galat'" class="space-y-3 py-6" data-testid="halo-thread-galat">
      <p class="text-sm text-danger" role="alert">{{ detailTiket.galatDetail?.pesan }}</p>
      <UiButton
        v-if="(detailTiket.galatDetail?.status ?? 500) >= 500 || detailTiket.galatDetail?.status === null"
        size="sm"
        variant="secondary"
        appearance="outline"
        @click="bukaTiket(idTiket)"
      >
        Muat ulang
      </UiButton>
    </div>

    <template v-else-if="detail">
      <header class="border-b border-slate-100 pb-3">
        <h2 id="halo-thread-judul" ref="judulEl" tabindex="-1" class="text-h6 font-semibold text-slate-900 focus:outline-none" data-testid="halo-thread-judul">
          {{ detail.subjek }}
        </h2>
        <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-caption text-slate-500">
          <div><dt class="sr-only">Kategori</dt><dd data-testid="halo-thread-kategori">{{ detail.kategori?.nama ?? '—' }}</dd></div>
          <div>
            <dt class="sr-only">Pemilik</dt>
            <dd class="inline-flex items-center gap-1">
              {{ detail.pemilik_nama }}
              <!-- Review B3 R6 / S4: desktop = tombol ikon + tooltip (hover, fokus, atau klik). Pembaca layar: nama tombol
                   "Data pelapor", isi HANYA lewat aria-describedby ke elemen `hidden` (tidak ikut terbaca di alur baca).
                   Layar sempit: snapshot tampil sebagai teks kecil. -->
              <template v-if="detail.snapshot && isDesktop">
                <UiTooltip v-model:open="snapshotBuka" :content="teksSnapshot" side="bottom">
                  <button
                    type="button"
                    class="rounded p-0.5 text-brand-primary hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
                    aria-label="Data pelapor"
                    :aria-describedby="idSnapshot"
                    data-testid="halo-thread-snapshot"
                    @pointerdown="tekanSnapshot"
                    @pointercancel="batalTekanSnapshot"
                    @keydown="tekanTombolSnapshot"
                    @click="klikSnapshot"
                  >
                    <Info class="h-3.5 w-3.5" aria-hidden="true" />
                  </button>
                </UiTooltip>
                <span :id="idSnapshot" hidden>{{ teksSnapshot }}</span>
              </template>
            </dd>
          </div>
          <div><dt class="sr-only">Dibuat</dt><dd>{{ waktu(detail.dibuat_pada) }}</dd></div>
          <div>
            <dt class="sr-only">Status</dt>
            <dd>
              <UiBadge :tone="detail.status === 'open' ? 'success' : 'neutral'" size="sm" :data-status="detail.status">
                {{ detail.status === 'open' ? 'Open' : 'Closed' }}
              </UiBadge>
            </dd>
          </div>
          <div v-if="detail.status === 'closed' && detail.ditutup_oleh_label" data-testid="halo-thread-ditutup">
            <dt class="inline">Ditutup oleh</dt> <dd class="inline">{{ detail.ditutup_oleh_label }}</dd>
          </div>
        </dl>
        <p v-if="detail.snapshot && !isDesktop" class="mt-1 text-caption text-slate-500" data-testid="halo-thread-snapshot-teks">{{ teksSnapshot }}</p>
      </header>

      <p v-if="galatUnduh" class="mt-3 text-sm text-danger" role="alert" data-testid="halo-thread-galat-unduh">{{ galatUnduh }}</p>

      <ol class="scrollbar-slim mt-3 flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto pr-1" aria-label="Pesan" data-testid="halo-thread-pesan">
        <li v-if="detail.pesan.length === 0" class="py-6 text-center text-sm text-slate-500">Belum ada pesan.</li>
        <li
          v-for="p in detail.pesan"
          :key="p.id_inbox_msg"
          class="flex max-w-[85%] flex-col"
          :class="p.milik_saya ? 'items-end self-end' : 'items-start self-start'"
          :data-pesan="p.milik_saya ? 'saya' : 'lawan'"
          :data-urutan="p.urutan_pengirim"
          :data-testid="`halo-pesan-${p.id_inbox_msg}`"
        >
          <span class="mb-0.5 text-caption font-medium text-slate-600" data-testid="halo-pesan-pengirim">{{ p.pengirim_label }}</span>
          <p class="whitespace-pre-wrap break-words rounded-2xl px-3 py-2 text-sm" :class="warna(p)" data-testid="halo-pesan-isi">{{ p.isi }}</p>
          <UiButton
            v-if="p.lampiran"
            size="sm"
            variant="secondary"
            appearance="soft"
            class="mt-1"
            :disabled="mengunduh === p.id_inbox_msg"
            :data-testid="`halo-pesan-lampiran-${p.id_inbox_msg}`"
            @click="unduh(p, p.lampiran)"
          >
            <template #icon-left><Paperclip class="h-4 w-4" aria-hidden="true" /></template>
            {{ p.lampiran.nama }}
          </UiButton>
          <span class="mt-0.5 text-[0.65rem] text-slate-400">{{ waktu(p.waktu) }}</span>
        </li>
      </ol>

      <div class="mt-3 border-t border-slate-100 pt-3">
        <form v-if="detail.bisa_balas" novalidate class="space-y-3" data-testid="halo-balas-form" @submit.prevent="kirim">
          <p v-if="galatBalas" class="text-sm text-danger" role="alert" data-testid="halo-balas-galat">{{ galatBalas }}</p>
          <FormField
            v-model="balas.pesan"
            name="pesan"
            label="Balasan"
            type="textarea"
            required
            :hint="`Maksimal ${BATAS_TIKET.pesan.toLocaleString('id-ID')} karakter.`"
            :error="errBalas('pesan')"
          />
          <UiFileField
            :key="kunciBerkas"
            label="Lampiran (opsional)"
            :allowed="[...EKSTENSI_LAMPIRAN]"
            :max-mb="BATAS_TIKET.lampiranMb"
            :disabled="mengirim"
            @change="arsip = $event"
          />
          <p v-if="errBalas('arsip')" class="text-caption text-danger" role="alert">{{ errBalas('arsip') }}</p>
          <div v-if="bolehIngatkan" data-testid="halo-balas-ingatkan">
            <UiCheckbox v-model="balas.ingatkan" label="Ingatkan Saya Kembali?" :disabled="mengirim" />
            <template v-if="detail.pengingat?.aktif">
              <p class="mt-1 text-caption text-slate-500" data-testid="halo-pengingat-jatuh-tempo">
                {{ pengingatLewat ? 'Pengingat jatuh tempo' : 'Pengingat aktif' }}: {{ waktu(detail.pengingat.jatuh_tempo) }}
              </p>
              <!-- Review B3 R7: bawaan centang kosong [K]; mengirim tanpa centang membatalkan pengingat (notify_pic = 2). -->
              <p class="mt-0.5 text-caption text-slate-500" data-testid="halo-pengingat-hint">Kirim tanpa centang akan membatalkan pengingat.</p>
            </template>
            <p v-if="errBalas('ingatkan_24_jam')" class="mt-1 text-caption text-danger" role="alert">{{ errBalas('ingatkan_24_jam') }}</p>
          </div>
          <div class="flex justify-end">
            <UiButton type="submit" :disabled="mengirim" data-testid="halo-balas-kirim">{{ mengirim ? 'Mengirim...' : 'Kirim' }}</UiButton>
          </div>
        </form>
        <UiNotice v-else-if="detail.kategori === null" tone="info" data-testid="halo-balas-tanpa-kategori">
          Pertanyaan ini tidak memiliki kategori. Silakan tambah pertanyaan baru.
        </UiNotice>
        <UiNotice v-else tone="info" data-testid="halo-balas-tertutup">
          {{ detail.status === 'closed' ? 'Percakapan ini telah ditutup.' : 'Percakapan ini tidak menerima balasan.' }}
          <template v-if="galatBalas"><br />{{ galatBalas }}</template>
        </UiNotice>
      </div>
    </template>
  </section>
</template>
