/**
 * Keadaan filter bersama halaman laporan Presensi 5C (PRD 5C §7.1): Bulan (`YYYY-MM`), Unit/Satker, Jenis Pegawai
 * (multi), Cari, paginasi. Disimpan di query string URL (`?bulan=&unit=&satker=&jp=&q=&page=&per_page=`) supaya halaman
 * bisa dibagikan dan di-refresh; nilai bawaan tidak ditulis. `param` mengikuti kontrak README API (`unit_satker`
 * `0` / `<id_unit>` / `sat_<id_satker>`, `jenis_pegawai[]`, `per_page` maks 100).
 *
 * Opsi Unit/Satker/Jenis Pegawai dimuat dari master (`GET master/{entitas}/options`); gagal dimuat → opsi kosong, halaman
 * tetap bisa dipakai. Role 3/4 tidak menampilkan Unit/Satker (`bisaPilihUnit = false`): server memaksa lingkupnya.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQuery, type LocationQueryRaw } from 'vue-router'

import { masterService } from '@/features/master-data/services/master.service'
import type { SelectOption } from '@/shared/ui'

import { PER_PAGE_LAPORAN, type FilterLaporan, type ParamLaporan } from '../types.laporan'

const POLA_BULAN = /^\d{4}-(0[1-9]|1[0-2])$/

/** Tahun, bulan (1–12), dan tanggal menurut WIB (UTC+7) lewat Intl, tanpa `toISOString()`. */
function tanggalWib(sekarang: Date): { tahun: number; bulan: number; tanggal: number } {
  const bagian = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(sekarang)
  const ambil = (jenis: string): number => Number(bagian.find((p) => p.type === jenis)?.value ?? 1970)
  return { tahun: ambil('year'), bulan: ambil('month'), tanggal: ambil('day') }
}

/** `YYYY-MM` untuk bulan yang berjarak `selisih` bulan dari `tahun`/`bulan` (bulan 1–12), tanpa `Date`. */
function bulanGeser(tahun: number, bulan: number, selisih: number): string {
  const indeks = tahun * 12 + (bulan - 1) + selisih
  return `${Math.floor(indeks / 12)}-${String((indeks % 12) + 1).padStart(2, '0')}`
}

/** Bulan kalender berjalan `YYYY-MM` menurut WIB. Dipakai laporan kalender (Rekap Kehadiran, Rekap Uang Makan). */
export function bulanBerjalanWib(sekarang: Date = new Date()): string {
  const { tahun, bulan } = tanggalWib(sekarang)
  return bulanGeser(tahun, bulan, 0)
}

/**
 * Bulan periode Tukin `YYYY-MM` menurut WIB: tanggal > 15 → bulan berikutnya (periode 16 s.d. 15). Sama dengan legacy
 * `list_lt` (Presensi.php:110-119) dan batas `bulan` maks di RekapTukinService.
 */
export function bulanPeriodeTukinWib(sekarang: Date = new Date()): string {
  const { tahun, bulan, tanggal } = tanggalWib(sekarang)
  return bulanGeser(tahun, bulan, tanggal > 15 ? 1 : 0)
}

/** `unit_satker` API dari pilihan Unit/Satker: Satker menang atas Unit, tanpa keduanya = `0` (Kementerian). */
export function lingkupDariFilter(idUnit: string, idSatker: string): string {
  if (idSatker) return `sat_${idSatker}`
  return idUnit || '0'
}

function teks(nilai: unknown): string {
  return typeof nilai === 'string' ? nilai : Array.isArray(nilai) && typeof nilai[0] === 'string' ? nilai[0] : ''
}

function angka(nilai: unknown, bawaan: number): number {
  const n = Number.parseInt(teks(nilai), 10)
  return Number.isFinite(n) && n > 0 ? n : bawaan
}

export interface OpsiFilterLaporan {
  /** true = tampilkan pemilih Unit/Satker (role 1). */
  bisaPilihUnit: boolean
  perPageBawaan?: number
  /** Bulan awal bila URL tidak memuatnya (uji menyuntikkan; bawaan = bulan berjalan WIB). */
  bulanBawaan?: string
  /** true = bulan bawaan memakai periode Tukin (tanggal > 15 → bulan berikutnya). Hanya laporan Tukin. */
  bulanTukin?: boolean
}

export function useFilterLaporan(opsi: OpsiFilterLaporan) {
  const route = useRoute()
  const router = useRouter()
  const perPageBawaan = opsi.perPageBawaan ?? 25
  const bulanBawaan = opsi.bulanBawaan ?? (opsi.bulanTukin ? bulanPeriodeTukinWib() : bulanBerjalanWib())

  /** Keadaan filter dari query URL; yang tidak ada di URL kembali ke bawaan. */
  function dariQuery(q: LocationQuery): FilterLaporan {
    return {
      bulan: POLA_BULAN.test(teks(q.bulan)) ? teks(q.bulan) : bulanBawaan,
      idUnit: opsi.bisaPilihUnit && /^\d+$/.test(teks(q.unit)) ? teks(q.unit) : '',
      idSatker: opsi.bisaPilihUnit && /^\d+$/.test(teks(q.satker)) ? teks(q.satker) : '',
      jenisPegawai: teks(q.jp)
        .split(',')
        .filter((v) => /^\d+$/.test(v)),
      search: teks(q.q),
      page: angka(q.page, 1),
      perPage: (PER_PAGE_LAPORAN as readonly number[]).includes(angka(q.per_page, perPageBawaan)) ? angka(q.per_page, perPageBawaan) : perPageBawaan,
    }
  }

  const filter = reactive<FilterLaporan>(dariQuery(route.query))

  const unitOpsi = ref<SelectOption[]>([])
  const satkerOpsi = ref<SelectOption[]>([])
  const jenisPegawaiOpsi = ref<SelectOption[]>([])

  const keOpsi = (daftar: { id: string; nama: string }[]): SelectOption[] => daftar.map((o) => ({ value: o.id, label: o.nama }))

  async function opsiMaster(entitas: string, parent?: string): Promise<SelectOption[]> {
    try {
      return keOpsi(await masterService.options(entitas, parent))
    } catch {
      return []
    }
  }

  async function muatOpsi(): Promise<void> {
    const [jenis, unit] = await Promise.all([opsiMaster('jenis-pegawai'), opsi.bisaPilihUnit ? opsiMaster('unit') : Promise.resolve([])])
    jenisPegawaiOpsi.value = jenis
    unitOpsi.value = unit
    if (opsi.bisaPilihUnit && filter.idUnit) satkerOpsi.value = await opsiMaster('satker', filter.idUnit)
  }

  const param = computed<ParamLaporan>(() => {
    const p: ParamLaporan = { bulan: filter.bulan, page: filter.page, per_page: filter.perPage }
    if (opsi.bisaPilihUnit) p.unit_satker = lingkupDariFilter(filter.idUnit, filter.idSatker)
    if (filter.jenisPegawai.length > 0) p.jenis_pegawai = [...filter.jenisPegawai]
    const cari = filter.search.trim()
    if (cari) p.search = cari
    return p
  })

  /** Query string URL: hanya nilai yang berbeda dari bawaan. */
  const queryUrl = computed<LocationQueryRaw>(() => {
    const u: LocationQueryRaw = {}
    if (filter.bulan !== bulanBawaan) u.bulan = filter.bulan
    if (filter.idUnit) u.unit = filter.idUnit
    if (filter.idSatker) u.satker = filter.idSatker
    if (filter.jenisPegawai.length > 0) u.jp = filter.jenisPegawai.join(',')
    if (filter.search.trim()) u.q = filter.search.trim()
    if (filter.page > 1) u.page = String(filter.page)
    if (filter.perPage !== perPageBawaan) u.per_page = String(filter.perPage)
    return u
  })

  // Ganti Unit → Satker lama tidak sah lagi; ganti filter apa pun (selain halaman) kembali ke halaman 1.
  watch(
    () => filter.idUnit,
    async (unit) => {
      satkerOpsi.value = unit ? await opsiMaster('satker', unit) : []
      if (filter.idSatker && !satkerOpsi.value.some((o) => String(o.value) === filter.idSatker)) filter.idSatker = ''
    },
  )
  // Sinkron agar tidak berjalan setelah penerapan dari URL (lihat `dariUrl`) dan mengatur halaman URL di belakangnya.
  let dariUrl = false
  watch(
    () => [filter.bulan, filter.idUnit, filter.idSatker, filter.jenisPegawai.join(','), filter.search, filter.perPage],
    () => {
      if (!dariUrl) filter.page = 1
    },
    { flush: 'sync' },
  )
  watch(queryUrl, (u) => void router.replace({ query: u }), { flush: 'post' })

  // Back/forward atau klik menu ke halaman yang sama mengganti query tanpa membuat ulang komponen: terapkan ke filter.
  // Hanya nilai yang benar-benar berbeda yang ditulis: penulisan ulang array yang sama akan memicu `queryUrl` dan
  // menulis query baru ke URL (membuang `bulan`) saat halaman sedang ditinggalkan.
  watch(
    () => route.query,
    (q) => {
      const baru = dariQuery(q)
      const berbeda = Object.fromEntries(
        Object.entries(baru).filter(([kunci, nilai]) => JSON.stringify((filter as unknown as Record<string, unknown>)[kunci]) !== JSON.stringify(nilai)),
      )
      dariUrl = true
      try {
        Object.assign(filter, berbeda)
      } finally {
        dariUrl = false
      }
    },
  )

  function setBulan(nilai: string): void {
    if (POLA_BULAN.test(nilai)) filter.bulan = nilai
  }

  function setJenisPegawai(id: string, aktif: boolean): void {
    const tanpa = filter.jenisPegawai.filter((v) => v !== id)
    filter.jenisPegawai = aktif ? [...tanpa, id] : tanpa
  }

  return { filter, param, queryUrl, unitOpsi, satkerOpsi, jenisPegawaiOpsi, muatOpsi, setBulan, setJenisPegawai }
}
