import { keccak256, checksumAddress } from 'viem'

export class InvalidArgument extends Error {}

const HEX = /^[0-9a-fA-F]*$/

export const Hex = {
  isHex(value, bytes = null) {
    if (typeof value !== 'string' || !/^0x([0-9a-fA-F]{2})*$/.test(value)) return false
    return bytes === null || value.length === 2 + 2 * bytes
  },
  body(hex) {
    if (typeof hex !== 'string') throw new InvalidArgument('hex value must be a string')
    const body = /^0x/i.test(hex) ? hex.slice(2) : hex
    if (body !== '' && (body.length % 2 !== 0 || !HEX.test(body))) throw new InvalidArgument('not an even-length hex string: ' + hex.slice(0, 80))
    return body.toLowerCase()
  },
  toBytes(hex) {
    return Buffer.from(Hex.body(hex), 'hex')
  },
  fromBytes(bytes) {
    return '0x' + Buffer.from(bytes).toString('hex')
  },
  lower(hex) {
    return '0x' + Hex.body(hex)
  },
  concat(hexes) {
    return '0x' + hexes.map((h) => Hex.body(h)).join('')
  },
  fromUtf8(text) {
    return '0x' + Buffer.from(String(text), 'utf8').toString('hex')
  },
}

export const Keccak = {
  hashHex(hex) {
    return keccak256(Hex.lower(hex))
  },
  utf8(text) {
    return keccak256(Hex.fromUtf8(text))
  },
  selector(signature) {
    return Keccak.utf8(signature).slice(0, 10)
  },
}

export const Address = {
  ZERO: '0x0000000000000000000000000000000000000000',
  isAddress(value) {
    return typeof value === 'string' && /^0x[0-9a-fA-F]{40}$/.test(value)
  },
  checksum(address) {
    if (!Address.isAddress(address)) throw new InvalidArgument('not an address: ' + (typeof address === 'string' || typeof address === 'number' ? String(address) : typeof address))
    return checksumAddress(address.toLowerCase())
  },
  isChecksummed(address) {
    return Address.isAddress(address) && Address.checksum(address) === address
  },
  equals(a, b) {
    return Address.isAddress(a) && Address.isAddress(b) && a.toLowerCase() === b.toLowerCase()
  },
}

export const Num = {
  of(value) {
    if (typeof value === 'bigint') return value
    if (typeof value === 'boolean') return value ? 1n : 0n
    if (typeof value === 'number' && Number.isInteger(value) && Math.abs(value) < 9007199254740992) return BigInt(value)
    if (typeof value === 'string') {
      const v = value.trim()
      if (/^0x[0-9a-fA-F]+$/.test(v)) return BigInt(v)
      if (/^-?[0-9]+$/.test(v)) return BigInt(v)
    }
    throw new InvalidArgument('not an integer: ' + (['string', 'number', 'boolean'].includes(typeof value) ? String(value) : typeof value))
  },
  dec(value) {
    return Num.of(value).toString(10)
  },
}

export const big = (value) => {
  if (typeof value === 'bigint') return value
  if (value === null || value === undefined || value === '') return 0n
  if (typeof value === 'number') return BigInt(Math.trunc(value))
  return BigInt(String(value))
}


export const int = (value) => {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0
  if (typeof value === 'bigint') return Number(value)
  if (typeof value === 'boolean') return value ? 1 : 0
  if (typeof value === 'string') {
    const m = /^\s*[+-]?\d+/.exec(value)
    return m ? Number.parseInt(m[0], 10) : 0
  }
  return 0
}

export const isNumeric = (v) => (typeof v === 'number' && Number.isFinite(v)) || (typeof v === 'string' && v.trim() !== '' && /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test(v))

export const isList = (v) => Array.isArray(v)

export const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)

export const has = (o, k) => o !== null && typeof o === 'object' && Object.hasOwn(o, k)

export const set = (o, k) => has(o, k) && o[k] !== null && o[k] !== undefined

export const empty = (v) => v === undefined || v === null || v === false || v === 0 || v === "" || v === "0" || (Array.isArray(v) && v.length === 0) || (isObject(v) && Object.keys(v).length === 0)
