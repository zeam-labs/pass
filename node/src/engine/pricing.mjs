const PRICE = /^(\d+)(?:\.(\d{1,6}))?$/
const HOUR_MS = 3600000

export function microOf(price, what = 'price') {
  const s = typeof price === 'number' ? String(price) : String(price ?? '').trim().replace(/^\$/, '')
  const m = PRICE.exec(s)
  if (!m) throw new TypeError(`pass: ${what} is USD with up to six decimals, like "0.02"; got ${JSON.stringify(price)}`)
  const micro = Number(m[1]) * 1e6 + Number((m[2] ?? '').padEnd(6, '0') || 0)
  if (micro < 1 || micro > 1e9) throw new TypeError(`pass: ${what} is from $0.000001 to $1,000`)
  return micro
}

export function usd(micro) {
  const s = (Number(micro) / 1e6).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')
  return s === '' ? '0' : s
}

export function priceOf(spec, fallbackMicro = null) {
  if (spec === undefined || spec === null || spec === '') {
    if (!(fallbackMicro >= 1)) throw new TypeError('pass: this tool has no price; set price, prices[tool] or free')
    return { micro: fallbackMicro, unitMicro: null }
  }
  if (typeof spec === 'object' && !Array.isArray(spec)) {
    const micro = spec.price === undefined || spec.price === null || spec.price === '' ? fallbackMicro : microOf(spec.price)
    if (!(micro >= 1)) throw new TypeError('pass: this tool has no price; set price, prices[tool] or free')
    if (spec.unit === undefined || spec.unit === null || spec.unit === '') return { micro, unitMicro: null }
    const unitMicro = microOf(spec.unit, 'unit')
    if (unitMicro > micro) throw new TypeError('pass: a unit costs at most the price the call reserves')
    return { micro, unitMicro }
  }
  return { micro: microOf(spec), unitMicro: null }
}

export function charge(price, units) {
  if (price.unitMicro === null) return price.micro
  if (!Number.isSafeInteger(units) || units < 0) throw new TypeError('pass: units is a whole number, 0 or more')
  return Math.min(price.micro, units * price.unitMicro)
}

export function describe(price, { free = false, perHour = null, varies = false } = {}) {
  if (free) return perHour ? { usd: '0', per: 'call', free: true, perHour } : { usd: '0', per: 'call', free: true }
  if (varies) return { per: 'call', varies: true }
  if (price.unitMicro !== null) return { usd: usd(price.unitMicro), per: 'unit', upTo: usd(price.micro) }
  return { usd: usd(price.micro), per: 'call' }
}

export function pricing(price) {
  if (price.unitMicro !== null) return `Up to $${usd(price.micro)} per call, reserved; charged $${usd(price.unitMicro)} per unit the call reports, at most the reserve. A failed call is not charged.`
  return `$${usd(price.micro)} per call. A failed call is not charged.`
}

export class FreeLimit {
  constructor(perHour, now = () => Date.now()) {
    this.perHour = perHour
    this.now = now
    this.counts = new Map()
  }

  static window(now) {
    return Math.floor(now / HOUR_MS)
  }

  take(tool, who) {
    if (!(this.perHour >= 1)) return { ok: true }
    const now = this.now()
    const w = FreeLimit.window(now)
    const key = `${w}\n${tool}\n${who ?? ''}`
    const used = this.counts.get(key) ?? 0
    if (used >= this.perHour) {
      const retry = Math.max(1, Math.ceil(((w + 1) * HOUR_MS - now) / 1000))
      return { ok: false, retryAfter: retry }
    }
    this.counts.set(key, used + 1)
    if (this.counts.size > 20000) for (const k of this.counts.keys()) if (!k.startsWith(`${w}\n`)) this.counts.delete(k)
    return { ok: true }
  }

  async takeSaved(file, tool, who) {
    if (!(this.perHour >= 1)) return { ok: true }
    try {
      let out
      await file.update((cur) => {
        const w = FreeLimit.window(this.now())
        const head = `${w}\n`
        const saved = cur?.window === w && cur.counts !== null && typeof cur.counts === 'object' ? cur.counts : {}
        this.counts = new Map(Object.entries(saved).map(([k, v]) => [head + k, Number(v) || 0]))
        out = this.take(tool, who)
        return { window: w, counts: Object.fromEntries([...this.counts].filter(([k]) => k.startsWith(head)).map(([k, v]) => [k.slice(head.length), v])) }
      })
      return out
    } catch {
      return this.take(tool, who)
    }
  }

  static refusal(perHour, retryAfter) {
    return { error: 'free_limit', message: `${perHour} free calls an hour per address. Retry in ${retryAfter}s.`, retry_after_seconds: retryAfter }
  }
}
