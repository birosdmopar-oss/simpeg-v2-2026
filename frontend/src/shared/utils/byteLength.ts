/**
 * Panjang teks dalam byte UTF-8 — sama dengan `strlen()` PHP yang dipakai rule backend `max_byte_length`
 * (TINYTEXT = 255 byte, bukan 255 karakter; huruf beraksen/emoji memakan 2-4 byte).
 */
const encoder = new TextEncoder()

export function utf8ByteLength(value: string): number {
  return encoder.encode(value).length
}
