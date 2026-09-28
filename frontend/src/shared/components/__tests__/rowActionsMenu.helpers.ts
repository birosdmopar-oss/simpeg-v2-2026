/**
 * Helper test bersama untuk menu aksi baris ⋮ (RowActionsMenu, aturan UI "aksi baris lewat menu titik tiga").
 *
 * Cara membuka Radix Vue DropdownMenu (radix-vue 1.9) di jsdom: trigger membuka lewat `click` tombol kiri (button 0,
 * tanpa Ctrl) atau keydown Enter/Spasi/ArrowDown — BUKAN pointerdown seperti Radix React. Konten menu di-Portal ke
 * `document.body`, jadi dicari lewat `document`, bukan `wrapper`; komponen sebaiknya di-mount dengan
 * `attachTo: document.body` agar fokus/DismissableLayer berjalan normal. Item dipilih lewat `click` (MenuItem memancarkan
 * `select` lalu menutup menu); item `disabled` diabaikan Radix sehingga `select` tidak terpancar.
 *
 * Jebakan: saat menu tertutup, FocusScope Radix mengembalikan fokus ke trigger lewat `setTimeout(0)`. Bila menu baris
 * lain dibuka sebelum timer itu jalan, fokus yang "pulang" ke trigger lama dianggap fokus di luar menu baru sehingga
 * menu baru langsung tertutup. Karena itu setiap penutupan/pemilihan ditunggu sampai timer tersebut selesai (`settle`).
 *
 * File ini bukan spec (tidak cocok pola `*.spec.ts`), hanya diimpor oleh spec.
 */
import { flushPromises, type VueWrapper } from '@vue/test-utils'

/** Ringkasan satu item menu seperti yang dirender (hanya item yang tidak `hidden`). */
export interface RenderedRowAction {
  key: string
  label: string
  disabled: boolean
  danger: boolean
}

type Root = Pick<VueWrapper, 'get'>

function menuElement(testid: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-testid="${testid}-menu"]`)
}

/** Tunggu promise + timer `setTimeout(0)` (pengembalian fokus FocusScope saat menu tertutup). */
async function settle(): Promise<void> {
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
}

/**
 * Buka menu ⋮ bertestid `testid` (atribut `testid` RowActionsMenu) dan kembalikan elemen konten menu. Bila menu sudah
 * terbuka tidak diklik ulang (klik kedua justru menutup menu).
 */
export async function openRowMenu(root: Root, testid: string): Promise<HTMLElement> {
  const trigger = root.get(`[data-testid="${testid}"]`)
  if (trigger.attributes('data-state') !== 'open') {
    await trigger.trigger('click')
    await flushPromises()
  }
  const menu = menuElement(testid)
  if (!menu) throw new Error(`menu aksi "${testid}" tidak terbuka`)
  return menu
}

/** Tutup menu ⋮ yang sedang terbuka lewat Esc (seperti pengguna keyboard). */
export async function closeRowMenu(testid: string): Promise<void> {
  const menu = menuElement(testid)
  if (!menu) return
  menu.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
  await settle()
  if (menuElement(testid)) throw new Error(`menu aksi "${testid}" tidak tertutup oleh Esc`)
}

/** Item yang dirender di menu terbuka, berurutan. */
export function renderedActions(menu: HTMLElement): RenderedRowAction[] {
  return Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitem"]')).map((item) => ({
    key: item.dataset.action ?? '',
    label: (item.textContent ?? '').trim(),
    disabled: item.hasAttribute('data-disabled'),
    danger: item.classList.contains('text-red-600'),
  }))
}

/** Buka menu ⋮, baca daftar itemnya, lalu tutup kembali (state tabel tidak berubah). */
export async function rowMenuActions(root: Root, testid: string): Promise<RenderedRowAction[]> {
  const items = renderedActions(await openRowMenu(root, testid))
  await closeRowMenu(testid)
  return items
}

/**
 * Buka menu ⋮ lalu klik item `data-action="key"`. Gagal bila item tidak dirender (mis. `hidden`). Item `disabled`
 * tetap diklik agar test bisa memastikan aksinya tidak jalan (menu lalu tetap terbuka).
 */
export async function selectRowAction(root: Root, testid: string, key: string): Promise<void> {
  const menu = await openRowMenu(root, testid)
  const item = menu.querySelector<HTMLElement>(`[role="menuitem"][data-action="${key}"]`)
  if (!item) throw new Error(`aksi "${key}" tidak ada di menu "${testid}"`)
  item.click()
  await settle()
}
