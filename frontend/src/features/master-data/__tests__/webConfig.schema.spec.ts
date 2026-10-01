/**
 * G-09 Web Config (DBV-006/CR-030) — validasi nilai per tipe key di form, setara WebConfigTypes backend.
 */
import { describe, expect, it } from 'vitest'

import { webConfigSchema, webConfigValueError } from '../schemas/webConfig.schema'
import type { WebConfigItem } from '../webConfig.types'

type Def = Pick<WebConfigItem, 'type' | 'constraints'>

const integer: Def = { type: 'integer', constraints: { min: 0, max: 10_000_000 } }
const decimal: Def = { type: 'decimal', constraints: { min: 0, max: 100, scale: 2 } }

describe('webConfigValueError', () => {
  it.each<[Def, string]>([
    [integer, '37000'],
    [integer, '0'],
    [decimal, '1.5'],
    [decimal, '1.50'],
    [decimal, '100'],
    [{ type: 'text', constraints: { max_length: 5 } }, 'abcde'],
    [{ type: 'textarea', constraints: {} }, 'Baris 1\nBaris 2'],
    [{ type: 'html', constraints: {} }, 'A<br/>B'],
    [{ type: 'id_ref', constraints: { ref: 'unit' } }, '8'],
    [{ type: 'url', constraints: {} }, 'https://contoh.go.id/logo.png'],
    [{ type: 'asset_path', constraints: {} }, 'kop/logo-kiri.png'],
    [{ type: 'time', constraints: {} }, '07:30'],
    [{ type: 'time', constraints: {} }, '23:59:59'],
    [{ type: 'email_list', constraints: {} }, 'a@contoh.id, b@contoh.id'],
  ])('sah: %o %s', (def, value) => {
    expect(webConfigValueError(def, value)).toBeNull()
  })

  it.each<[Def, string, string]>([
    [integer, '', 'wajib diisi'],
    [integer, 'tiga puluh ribu', 'bilangan bulat'],
    [integer, '37.000', 'bilangan bulat'],
    [integer, '-1', 'antara'],
    [integer, '10000001', 'antara'],
    [decimal, '1,5', 'titik'],
    [decimal, '1.555', '2 digit'],
    [decimal, '100.5', 'antara'],
    [{ type: 'text', constraints: { max_length: 3 } }, 'abcd', 'maksimal 3'],
    [{ type: 'text', constraints: { max_length: 50 } }, 'a\nb', 'satu baris'],
    [{ type: 'id_ref', constraints: { ref: 'unit' } }, '08', 'ID angka'],
    [{ type: 'url', constraints: {} }, 'contoh.go.id', 'URL'],
    [{ type: 'asset_path', constraints: {} }, '/var/www/logo.png', 'path relatif'],
    [{ type: 'asset_path', constraints: {} }, 'kop/../x.png', 'path relatif'],
    [{ type: 'time', constraints: {} }, '7:00', 'HH:MM'],
    [{ type: 'email_list', constraints: {} }, 'a@contoh.id,,b@contoh.id', 'kosong'],
    [{ type: 'email_list', constraints: {} }, 'a@contoh.id,A@contoh.id', 'ganda'],
    [{ type: 'email_list', constraints: {} }, 'bukan-email', 'tidak valid'],
    [{ type: null, constraints: {} }, 'x', 'katalog'],
  ])('ditolak: %o %s', (def, value, message) => {
    expect(webConfigValueError(def, value)).toContain(message)
  })

  it('skema form: config_value per tipe + remark ≤ 255', () => {
    const schema = webConfigSchema(integer)
    expect(schema.safeParse({ config_value: '37000', remark: '' }).success).toBe(true)
    expect(schema.safeParse({ config_value: 'x', remark: '' }).success).toBe(false)
    expect(schema.safeParse({ config_value: '1', remark: 'x'.repeat(256) }).success).toBe(false)
  })
})
