/**
 * Apakah `class` dari pemanggil sudah menentukan lebar/ukuran flex (`w-52`, `basis-…`, `flex-1`/`flex-auto`, termasuk
 * varian responsif `md:w-1/2`)? `max-w-…`/`min-w-…`/`col-span-…` tidak dihitung (hanya membatasi, `w-full` tetap berlaku). Komponen form yang akar defaultnya `w-full` memakai ini agar `w-full`
 * TIDAK ikut terpasang bersama kelas lebar pemanggil (urutan CSS Tailwind membuat `w-full` menang atas `w-52`) — CR-060.
 */
function flatten(value: unknown): string {
  if (typeof value === 'string') return value
  if (Array.isArray(value)) return value.map(flatten).join(' ')
  if (value && typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .filter(([, on]) => Boolean(on))
      .map(([name]) => name)
      .join(' ')
  }
  return ''
}

const WIDTH = /(?:^|\s)(?:[\w-]+:)*(?:(?:w|basis)-\S+|flex-(?:1|auto|initial|none)(?=\s|$))/

export function hasWidthClass(value: unknown): boolean {
  return WIDTH.test(flatten(value))
}
