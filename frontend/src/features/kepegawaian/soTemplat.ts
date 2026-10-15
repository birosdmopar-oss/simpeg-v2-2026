/**
 * Susunan bagan Struktur Organisasi per format legacy so_012025 (B-19, MAKE-012) — salinan hierarki view
 * `views/hr/employee/rwy/jabatan/so_012025/so_*.php`:
 *  - kementerian: Menteri (+ Wakil) → staf ahli, inspektorat, sekretaris (→ Es.II), deputi (→ Es.II);
 *  - sesmen / deputi / inspektorat_unit: Es.I → sekretariat (→ Es.III, kotak JF) lalu Es.II (→ Es.III → Es.IV, atau kotak
 *    JF bila Es.III tanpa Es.IV);
 *  - satker (upt / bpo / pusat / inspektorat): Es.II → sekretariat, SPI (BPO), Es.III (→ Es.IV, atau kotak JF), Es.IV
 *    tanpa induk Es.III.
 * Kotak "Jabatan Fungsional dan Jabatan Pelaksana" ditampilkan di tempat yang sama dengan legacy.
 */
import type { OrgNode, SoKotak, StrukturOrganisasi } from './types'

let seq = 0
const nextId = (): string => `so-${++seq}`

const JF_LABEL = 'Jabatan Fungsional dan Jabatan Pelaksana'

const jf = (): OrgNode => ({ id: nextId(), jabatan: JF_LABEL, pejabat: '', jenis: null, jf: true, children: [] })

function kotak(k: SoKotak, children: OrgNode[] = [], sub?: string | null): OrgNode {
  return { id: nextId(), jabatan: k.jabatan, pejabat: k.pejabat, jenis: k.jenis, sub: sub ?? undefined, children }
}

const daftar = (v: unknown): SoKotak[] => (Array.isArray(v) ? (v as SoKotak[]) : [])
const tunggal = (v: unknown): SoKotak | null => (v && !Array.isArray(v) ? (v as SoKotak) : null)

function es3Node(es3: SoKotak, jfBilaKosong: boolean): OrgNode {
  const es4 = daftar(es3.es4).map((e) => kotak(e))
  return kotak(es3, es4.length ? es4 : jfBilaKosong ? [jf()] : [])
}

function kementerian(d: StrukturOrganisasi['data']): OrgNode[] {
  const menteri = tunggal(d.menteri)
  const wakil = tunggal(d.wakil_menteri)
  const children: OrgNode[] = [
    ...daftar(d.staf_ahli).map((k) => kotak(k)),
    ...(tunggal(d.inspektorat) ? [kotak(tunggal(d.inspektorat) as SoKotak, [], 'Inspektorat')] : []),
  ]
  const sek = tunggal(d.sekretaris)
  if (sek) children.push(kotak(sek, daftar(sek.list_es2).map((e) => kotak(e, [], e.satker))))
  for (const dep of daftar(d.deputi)) children.push(kotak(dep, daftar(dep.list_es2).map((e) => kotak(e, [], e.satker))))
  if (!menteri) return children
  const wakilTeks = wakil && (wakil.jabatan || wakil.pejabat) ? [wakil.jabatan, wakil.pejabat].filter(Boolean).join(' — ') : undefined
  return [kotak({ ...menteri, jabatan: menteri.jabatan || 'Menteri' }, children, wakilTeks)]
}

function unit(d: StrukturOrganisasi['data']): OrgNode[] {
  const es1 = tunggal(d.es1)
  const children: OrgNode[] = []
  const sek = daftar(d.es2_sek)[0]
  if (sek) children.push(kotak(sek, [...daftar(sek.es3).map((e) => kotak(e, [jf()])), jf()]))
  for (const es2 of daftar(d.es2)) children.push(kotak(es2, daftar(es2.es3).map((e) => es3Node(e, true))))
  return es1 ? [kotak(es1, children)] : children
}

function satker(d: StrukturOrganisasi['data'], format: StrukturOrganisasi['format']): OrgNode[] {
  const es2 = tunggal(d.es2)
  const children: OrgNode[] = []
  const sek = tunggal(d.es2_sek)
  if (sek) children.push(kotak(sek))
  const spi = tunggal(d.es3_sek)
  if (spi) children.push(kotak(spi))
  for (const es3 of daftar(d.es3)) children.push(es3Node(es3, format !== 'inspektorat'))
  for (const es4 of daftar(d.es4)) children.push(kotak(es4))
  if (format === 'inspektorat') children.push(jf())
  return es2 ? [kotak(es2, children)] : children
}

/** Pohon tampilan dari respons `GET struktur-organisasi`. */
export function susunBagan(so: StrukturOrganisasi): OrgNode[] {
  seq = 0
  switch (so.format) {
    case 'kementerian':
      return kementerian(so.data)
    case 'sesmen':
    case 'deputi':
    case 'inspektorat_unit':
      return unit(so.data)
    default:
      return satker(so.data, so.format)
  }
}
