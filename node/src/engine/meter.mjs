import { createHash, randomBytes } from 'node:crypto'
import { ChannelFileStore } from './stores.mjs'
import { Secp256k1 } from './core/secp256k1.mjs'
import { microOf, usd } from './pricing.mjs'

const NONCE_SECS = 300
const KEEP_CREDITED = 64
const KEEP_NONCES = 8
const KEEP_LINES = 8

export const Clock = {
  HOLD_MS: 2000,
  KEEP_DONE: 512,

  fresh(channelId) {
    return { channelId: String(channelId).toLowerCase(), balanceMs: 0, spentMs: 0, returnedMs: 0, on: false, since: null, lastActive: null, calls: {}, done: [], credited: [], nonces: {}, lines: [] }
  },

  merged(intervals) {
    const sorted = intervals.filter(([s, e]) => e > s).sort((a, b) => a[0] - b[0] || a[1] - b[1])
    const out = []
    for (const [s, e] of sorted) {
      const last = out[out.length - 1]
      if (last && s <= last[1]) last[1] = Math.max(last[1], e)
      else out.push([s, e])
    }
    return out
  },

  covered(intervals) {
    return Clock.merged(intervals).reduce((total, [s, e]) => total + (e - s), 0)
  },

  spans(rec, from, to, idleMs = 0) {
    const out = (rec.done ?? []).map(([s, e]) => [Math.max(s, from), Math.min(e, to)])
    for (const c of Object.values(rec.calls)) out.push([Math.max(c.start, from), Math.min(to, c.deadline)])
    if (rec.on && idleMs > 0 && rec.lastActive !== null) out.push([Math.max(rec.lastActive, from), Math.min(to, rec.lastActive + idleMs)])
    return out
  },

  settle(rec, now, idleMs = 0) {
    const r = rec
    r.done = r.done ?? []
    if (r.since === null) {
      r.since = now
      for (const [id, c] of Object.entries(r.calls)) if (c.deadline <= now) delete r.calls[id]
      return r
    }
    for (const [id, c] of Object.entries(r.calls)) {
      if (c.deadline > now) continue
      r.done.push([c.start, c.deadline])
      delete r.calls[id]
    }
    r.done = Clock.merged(r.done)
    let horizon = Math.max(r.since, now - Clock.HOLD_MS)
    if (r.done.length > Clock.KEEP_DONE) horizon = Math.max(horizon, Math.min(now, r.done[r.done.length - Clock.KEEP_DONE - 1][1]))
    const burned = Math.min(r.balanceMs, Clock.covered(Clock.spans(r, r.since, horizon, idleMs)))
    r.balanceMs -= burned
    r.spentMs += burned
    r.since = horizon
    r.done = r.done.filter(([, e]) => e > horizon).map(([s, e]) => [Math.max(s, horizon), e])
    return r
  },

  pending(rec, now, idleMs = 0) {
    return Math.min(rec.balanceMs, Clock.covered(Clock.spans(rec, rec.since ?? now, now, idleMs)))
  },

  left(rec, now, idleMs = 0) {
    return rec.balanceMs - Clock.pending(rec, now, idleMs)
  },

  remaining(rec, now, idleMs = 0) {
    return Clock.left(Clock.settle(structuredClone(rec), now, idleMs), now, idleMs)
  },

  spent(rec, now, idleMs = 0) {
    const r = Clock.settle(structuredClone(rec), now, idleMs)
    return r.spentMs + Clock.pending(r, now, idleMs)
  },

  active(rec, at, idleMs = 0) {
    if (rec.on && idleMs > 0 && rec.lastActive !== null && at > rec.lastActive) rec.done.push([rec.lastActive, Math.min(at, rec.lastActive + idleMs)])
    rec.lastActive = Math.max(rec.lastActive ?? at, at)
  },

  switch(rec, on, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    Clock.active(rec, now, idleMs)
    rec.on = on
    return rec
  },

  begin(rec, id, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    if (!rec.on) return { ok: false, code: 'meter_off' }
    const left = Clock.left(rec, now, idleMs)
    if (left <= 0) return { ok: false, code: 'out_of_time' }
    Clock.active(rec, now, idleMs)
    const deadline = now + left
    rec.calls[id] = { start: now, deadline }
    return { ok: true, deadline }
  },

  end(rec, id, now, idleMs = 0, start = null, stop = null) {
    rec.done = rec.done ?? []
    const c = rec.calls[id] ?? null
    const ran = Math.min(stop ?? now, now)
    let from = c === null ? ran : c.start
    if (c !== null) {
      if (start !== null && start > c.start && ran <= c.deadline) from = Math.min(start, ran)
      rec.done.push([from, Math.min(ran, c.deadline)])
      delete rec.calls[id]
    }
    Clock.active(rec, ran, idleMs)
    Clock.settle(rec, now, idleMs)
    return { elapsedMs: c === null ? 0 : ran - from, over: c === null || ran > c.deadline }
  },

  buy(rec, ms, key, now, idleMs = 0) {
    if (rec.credited.includes(key)) return false
    Clock.settle(rec, now, idleMs)
    rec.balanceMs += ms
    rec.credited = [...rec.credited, key].slice(-KEEP_CREDITED)
    return true
  },

  unbuy(rec, ms, key, now, idleMs = 0) {
    if (!rec.credited.includes(key)) return 0
    Clock.settle(rec, now, idleMs)
    const taken = Math.max(0, Math.min(ms, Clock.left(rec, now, idleMs)))
    rec.balanceMs -= taken
    rec.credited = rec.credited.filter((k) => k !== key)
    return taken
  },

  unburnedMicro(rec, now, idleMs, rateMicro, rateMs) {
    const left = Clock.remaining(rec, now, idleMs)
    return Math.floor((left * rateMicro + rateMs - 1) / rateMs)
  },

  forget(rec, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    const burning = Clock.pending(rec, now, idleMs)
    const returned = rec.balanceMs - burning
    rec.spentMs += burning
    rec.returnedMs += returned
    rec.balanceMs = 0
    rec.on = false
    rec.calls = {}
    rec.done = []
    rec.since = now
    return returned
  },

  lineMessage(channelId, nonce) {
    return `ZEAM Pass line\nchannel: ${String(channelId).toLowerCase()}\nnonce: ${nonce}`
  },
}

const gcd = (a, b) => (b === 0 ? a : gcd(b, a % b))

export function timeOptions(o) {
  if (o === undefined || o === null || o === false) return null
  if (typeof o !== 'object') throw new TypeError('pass: time is { usd: "0.00025", ms: 250 }: that many dollars buys that many milliseconds')
  const micro = microOf(o.usd ?? o.block, 'time.usd')
  const given = o.ms ?? o.blockMs
  const ms = given === undefined ? 250 : Math.trunc(Number(given))
  const idleMs = o.idleMs === undefined ? 0 : Math.trunc(Number(o.idleMs))
  if (!(ms >= 1 && ms <= 3600000)) throw new TypeError('pass: time.ms is 1 to 3600000')
  if (!(idleMs >= 0 && idleMs <= 3600000)) throw new TypeError('pass: time.idleMs is 0 to 3600000')
  const legacyCap = o.maxBlocks === undefined || o.maxBlocks === null ? undefined : Number(o.maxBlocks) * ms
  const cap = o.maxMs ?? legacyCap
  const maxMs = cap === undefined || cap === null ? null : Math.trunc(Number(cap))
  const g = gcd(micro, ms)
  const rateMicro = micro / g
  const rateMs = ms / g
  if (maxMs !== null && !(maxMs >= rateMs && maxMs % rateMs === 0)) throw new TypeError(`pass: time.maxMs is a multiple of ${rateMs} ms, the smallest amount this price sells; leave it out for no maximum`)
  return { rateMicro, rateMs, unitMs: ms, idleMs, maxMs }
}

export const timeRate = (t) => `$${usd(t.rateMicro)} per ${t.rateMs === 1 ? 'ms' : `${t.rateMs} ms`}`

export function boughtMs(t, args) {
  if (Number.isSafeInteger(args?.ms)) return args.ms
  if (Number.isSafeInteger(args?.blocks) && args.blocks >= 1) return args.blocks * t.unitMs
  return t.unitMs
}

export function timeText(t, base = '') {
  const idle = t.idleMs > 0 ? ` and ${t.idleMs} ms after each` : ''
  const step = t.rateMs > 1 ? ` in steps of ${t.rateMs} ms` : ''
  const most = t.maxMs ? ` (up to ${t.maxMs} ms a purchase)` : ''
  return `Line time: ${timeRate(t)}. buy_time {ms}${step}${most}; buying again adds time; the time is credited to the paying channel once the payment settles. Open a line: POST ${base}/line {"op":"open","channelId"}, sign the message it returns with the payer key, POST {"op":"prove","channelId","nonce","signature"}. Send the credential as x-line (MCP: _meta["zeam-pass/line"]). Time burns while a call runs on the line${idle}; a call stops when the time runs out. {"op":"off"}: no new calls on the line; a running call burns to its end. Unburned time comes back with a refund.`
}

export class TimeMeter {
  constructor(dir, t, { now = () => Date.now(), clocks = null, lines = null } = {}) {
    this.t = t
    this.now = now
    this.clocks = clocks ?? new ChannelFileStore(`${dir}/clocks`)
    this.lines = lines ?? new ChannelFileStore(`${dir}/lines`)
  }

  static hash(credential) {
    return '0x' + createHash('sha256').update(String(credential)).digest('hex')
  }

  callMs(priceMicro) {
    return Math.floor((priceMicro * this.t.rateMs) / this.t.rateMicro)
  }

  async change(channelId, fn) {
    let out
    await this.clocks.update(channelId, (current) => {
      const rec = current ?? Clock.fresh(channelId)
      out = fn(rec)
      return rec
    })
    return out
  }

  async status(channelId) {
    const rec = (await this.clocks.get(channelId)) ?? Clock.fresh(channelId)
    const now = this.now()
    return { channelId: rec.channelId, msRemaining: Clock.remaining(rec, now, this.t.idleMs), msSpent: Clock.spent(rec, now, this.t.idleMs), msReturned: rec.returnedMs, metering: rec.on, rateUSD: usd(this.t.rateMicro), rateMs: this.t.rateMs }
  }

  credit(channelId, ms, key) {
    return this.change(channelId, (rec) => Clock.buy(rec, ms, String(key), this.now(), this.t.idleMs))
  }

  uncredit(channelId, ms, key) {
    return this.change(channelId, (rec) => Clock.unbuy(rec, ms, String(key), this.now(), this.t.idleMs))
  }

  switch(channelId, on) {
    return this.change(channelId, (rec) => { const now = this.now(); Clock.switch(rec, on, now, this.t.idleMs); return Clock.left(rec, now, this.t.idleMs) })
  }

  async begin(channelId, id) {
    const b = await this.change(channelId, (rec) => Clock.begin(rec, id, this.now(), this.t.idleMs))
    return b.ok ? { ...b, started: this.now() } : b
  }

  end(channelId, id, started = null, stopped = null) {
    return this.change(channelId, (rec) => { const now = this.now(); return { ...Clock.end(rec, id, now, this.t.idleMs, started, stopped), msRemaining: Clock.left(rec, now, this.t.idleMs) } })
  }

  stop(channelId) {
    return this.change(channelId, (rec) => {
      Clock.settle(rec, this.now(), this.t.idleMs)
      if (Object.keys(rec.calls).length > 0) return false
      Clock.switch(rec, false, this.now(), this.t.idleMs)
      return true
    })
  }

  async busy(channelId) {
    const rec = await this.clocks.get(channelId)
    const now = this.now()
    return rec !== null && Object.values(rec.calls ?? {}).some((c) => c.deadline > now)
  }

  async unburnedMicro(channelId) {
    const rec = await this.clocks.get(channelId)
    return rec === null ? 0 : Clock.unburnedMicro(rec, this.now(), this.t.idleMs, this.t.rateMicro, this.t.rateMs)
  }

  async forget(channelId) {
    let lines = []
    const returned = await this.change(channelId, (rec) => {
      lines = rec.lines
      rec.lines = []
      return Clock.forget(rec, this.now(), this.t.idleMs)
    })
    for (const h of lines) await this.lines.update(h, () => null).catch(() => {})
    return returned
  }

  challenge(channelId) {
    const nonce = randomBytes(16).toString('hex')
    const now = this.now()
    return this.change(channelId, (rec) => {
      const live = Object.entries(rec.nonces).filter(([, until]) => until > now).slice(-(KEEP_NONCES - 1))
      rec.nonces = Object.fromEntries([...live, [nonce, now + NONCE_SECS * 1000]])
      return { nonce, message: Clock.lineMessage(channelId, nonce), expiresInSeconds: NONCE_SECS }
    })
  }

  async prove(channel, nonce, signature) {
    const channelId = String(channel.channelId).toLowerCase()
    const now = this.now()
    const live = await this.change(channelId, (rec) => {
      const until = rec.nonces[String(nonce)]
      delete rec.nonces[String(nonce)]
      return until !== undefined && until > now
    })
    if (!live) return { ok: false, status: 400, code: 'no_challenge', why: 'no live challenge with that nonce. Send {"op":"open"} again.' }
    let who
    try {
      who = (await Secp256k1.recoverPersonal(Clock.lineMessage(channelId, nonce), String(signature ?? ''))).toLowerCase()
    } catch (e) {
      return { ok: false, status: 400, code: 'bad_signature', why: `the signature does not recover: ${String(e?.message ?? e).slice(0, 80)}` }
    }
    const payer = String(channel.channelConfig?.payer ?? '').toLowerCase()
    const auth = String(channel.channelConfig?.payerAuthorizer ?? '').toLowerCase()
    if (who !== payer && who !== auth) return { ok: false, status: 403, code: 'not_the_payer', why: `${who} is not this channel's payer` }
    const credential = randomBytes(32).toString('base64url')
    const h = TimeMeter.hash(credential)
    await this.lines.update(h, () => ({ channelId, at: now }))
    let dropped = []
    const msRemaining = await this.change(channelId, (rec) => {
      const all = [...rec.lines, h]
      dropped = all.slice(0, -KEEP_LINES)
      rec.lines = all.slice(-KEEP_LINES)
      Clock.switch(rec, true, now, this.t.idleMs)
      return Clock.left(rec, now, this.t.idleMs)
    })
    for (const x of dropped) await this.lines.update(x, () => null).catch(() => {})
    return { ok: true, credential, channelId, msRemaining }
  }

  async line(credential) {
    if (typeof credential !== 'string' || credential === '') return null
    const hit = await this.lines.get(TimeMeter.hash(credential))
    return hit?.channelId ?? null
  }

  async close(credential) {
    const h = TimeMeter.hash(credential)
    const hit = await this.lines.get(h)
    if (!hit) return null
    await this.lines.update(h, () => null)
    await this.change(hit.channelId, (rec) => { rec.lines = rec.lines.filter((x) => x !== h) })
    return hit.channelId
  }
}
