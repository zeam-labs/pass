import { encodeAbiParameters, decodeAbiParameters, parseAbiParameters } from 'viem'
import { Address, Hex, InvalidArgument, Keccak, Num } from './hex.mjs'

const cache = new Map()

function splitTop(s) {
  const out = []
  let depth = 0
  let cur = ''
  for (const ch of s) {
    if (ch === '(') depth++
    if (ch === ')') depth--
    if (ch === ',' && depth === 0) {
      out.push(cur)
      cur = ''
    } else cur += ch
  }
  if (cur !== '' || out.length) out.push(cur)
  return out
}

function params(types) {
  const key = types.join(',')
  if (!cache.has(key)) {
    try {
      cache.set(key, types.length ? parseAbiParameters(key) : [])
    } catch (e) {
      throw new InvalidArgument('unsupported ABI types: ' + key)
    }
  }
  return cache.get(key)
}

function arrayOf(type) {
  const m = /^(.*)\[(\d*)\]$/.exec(type)
  return m ? { inner: m[1], length: m[2] === '' ? null : Number(m[2]) } : null
}

function toAbi(param, value) {
  const arr = arrayOf(param.type)
  if (arr) {
    if (!Array.isArray(value)) throw new InvalidArgument(`expected an array for ${param.type}`)
    const inner = { ...param, type: arr.inner }
    return value.map((v) => toAbi(inner, v))
  }
  if (param.type === 'tuple') {
    const items = Array.isArray(value) ? value : Object.values(value ?? {})
    return param.components.map((c, i) => toAbi(c, items[i]))
  }
  if (/^u?int\d*$/.test(param.type)) return Num.of(value)
  if (param.type === 'address') return Address.checksum(value)
  if (param.type === 'bool') return !!value
  if (/^bytes\d*$/.test(param.type)) return Hex.lower(value)
  if (param.type === 'string') return String(value)
  return value
}

function fromAbi(param, value) {
  const arr = arrayOf(param.type)
  if (arr) {
    const inner = { ...param, type: arr.inner }
    return value.map((v) => fromAbi(inner, v))
  }
  if (param.type === 'tuple') return param.components.map((c, i) => fromAbi(c, Array.isArray(value) ? value[i] : Object.values(value)[i]))
  if (typeof value === 'bigint' || typeof value === 'number') return value.toString(10)
  if (param.type === 'address') return Address.checksum(value)
  if (/^bytes\d*$/.test(param.type)) return value.toLowerCase()
  return value
}

export const Abi = {
  splitTop,
  encode(types, values) {
    const p = params(types)
    try {
      return encodeAbiParameters(p, p.map((q, i) => toAbi(q, values[i])))
    } catch (e) {
      if (e instanceof InvalidArgument) throw e
      throw new InvalidArgument(e.shortMessage ?? e.message)
    }
  },
  decode(types, data) {
    const p = params(types)
    try {
      const out = decodeAbiParameters(p, Hex.lower(data))
      return p.map((q, i) => fromAbi(q, out[i]))
    } catch (e) {
      if (e instanceof InvalidArgument) throw e
      throw new InvalidArgument(e.shortMessage ?? e.message)
    }
  },
  parseSignature(signature) {
    const m = /^([A-Za-z_$][A-Za-z0-9_$]*)\((.*)\)$/.exec(String(signature).replace(/\s+/g, ''))
    if (!m) throw new InvalidArgument('malformed function signature: ' + signature)
    const types = m[2] === '' ? [] : splitTop(m[2])
    return { name: m[1], types, canonical: `${m[1]}(${types.join(',')})` }
  },
  selector(signature) {
    return Keccak.selector(Abi.parseSignature(signature).canonical)
  },
  encodeCall(signature, args = []) {
    const sig = Abi.parseSignature(signature)
    return Keccak.selector(sig.canonical) + (sig.types.length ? Abi.encode(sig.types, args).slice(2) : '')
  },
}
