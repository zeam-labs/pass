import { createHash, randomBytes } from 'node:crypto'
import { ChannelFileStore } from './stores.mjs'
import { Secp256k1 } from './core/secp256k1.mjs'
import { microOf, usd } from './pricing.mjs'

const NONCE_SECS = 300
const KEEP_CREDITED = 64
const KEEP_NONCES = 8
const KEEP_LINES = 8

export const Clock = {
  fresh(channelId) {
    return { channelId: String(channelId).toLowerCase(), balanceMs: 0, spentMs: 0, returnedMs: 0, on: false, since: null, lastActive: null, calls: {}, credited: [], nonces: {}, lines: [] }
  },

  covered(intervals) {
    const sorted = intervals.filter(([s, e]) => e > s).sort((a, b) => a[0] - b[0] || a[1] - b[1])
    let total = 0
    let end = -Infinity
    for (const [s, e] of sorted) {
      if (s >= end) {
        total += e - s
        end = e
      } else if (e > end) {
        total += e - end
        end = e
      }
    }
    return total
  },

  settle(rec, now, idleMs = 0) {
    const r = rec
    if (r.since === null) {
      r.since = now
      for (const [id, c] of Object.entries(r.calls)) if (c.deadline <= now) delete r.calls[id]
      return r
    }
    const since = r.since
    const spans = Object.values(r.calls).map((c) => [Math.max(c.start, since), Math.min(now, c.deadline)])
    if (r.on && idleMs > 0 && r.lastActive !== null) spans.push([Math.max(r.lastActive, since), Math.min(now, r.lastActive + idleMs)])
    const burned = Math.min(r.balanceMs, Clock.covered(spans))
    r.balanceMs -= burned
    r.spentMs += burned
    r.since = now
    for (const [id, c] of Object.entries(r.calls)) if (c.deadline <= now) delete r.calls[id]
    return r
  },

  remaining(rec, now, idleMs = 0) {
    return Clock.settle(structuredClone(rec), now, idleMs).balanceMs
  },

  switch(rec, on, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    rec.on = on
    rec.since = now
    if (on) rec.lastActive = now
    return rec
  },

  begin(rec, id, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    if (!rec.on) return { ok: false, code: 'meter_off' }
    if (rec.balanceMs <= 0) return { ok: false, code: 'out_of_time' }
    const deadline = now + rec.balanceMs
    rec.calls[id] = { start: now, deadline }
    rec.lastActive = now
    return { ok: true, deadline }
  },

  end(rec, id, now, idleMs = 0, start = null, stop = null) {
    const c = rec.calls[id] ?? null
    const ran = Math.min(stop ?? now, now)
    const at = Math.max(ran, rec.since ?? -Infinity)
    if (c && start !== null && start > c.start && ran <= c.deadline) c.start = Math.min(start, ran)
    Clock.settle(rec, at, idleMs)
    delete rec.calls[id]
    rec.lastActive = Math.max(rec.lastActive ?? ran, ran)
    return { elapsedMs: c ? ran - c.start : 0, over: c === null || ran > c.deadline }
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
    const taken = Math.min(ms, rec.balanceMs)
    rec.balanceMs -= taken
    rec.credited = rec.credited.filter((k) => k !== key)
    return taken
  },

  unburnedMicro(rec, now, idleMs, blockMicro, blockMs) {
    const left = Clock.remaining(rec, now, idleMs)
    return Math.floor((left * blockMicro + blockMs - 1) / blockMs)
  },

  forget(rec, now, idleMs = 0) {
    Clock.settle(rec, now, idleMs)
    const returned = rec.balanceMs
    rec.returnedMs += returned
    rec.balanceMs = 0
    rec.on = false
    rec.calls = {}
    return returned
  },

  lineMessage(channelId, nonce) {
    return `ZEAM Pass line\nchannel: ${String(channelId).toLowerCase()}\nnonce: ${nonce}`
  },
}

export function timeOptions(o) {
  if (o === undefined || o === null || o === false) return null
  if (typeof o !== 'object') throw new TypeError('pass: time is { block: "0.00025", blockMs: 250 }')
  const blockMicro = microOf(o.block, 'time.block')
  const blockMs = o.blockMs === undefined ? 250 : Math.trunc(Number(o.blockMs))
  const idleMs = o.idleMs === undefined ? 0 : Math.trunc(Number(o.idleMs))
  const maxBlocks = o.maxBlocks === undefined ? Math.min(14400, Math.floor(1e9 / blockMicro)) : Math.trunc(Number(o.maxBlocks))
  if (!(blockMs >= 1 && blockMs <= 3600000)) throw new TypeError('pass: time.blockMs is 1 to 3600000')
  if (!(idleMs >= 0 && idleMs <= 3600000)) throw new TypeError('pass: time.idleMs is 0 to 3600000')
  if (!(maxBlocks >= 1 && maxBlocks * blockMicro <= 1e9)) throw new TypeError('pass: time.maxBlocks is 1 or more, and at most $1,000 of blocks')
  return { blockMicro, blockMs, idleMs, maxBlocks }
}

export function timeText(t, base = '') {
  const idle = t.idleMs > 0 ? ` and ${t.idleMs} ms after each` : ''
  return `Line time: $${usd(t.blockMicro)} per ${t.blockMs} ms block. buy_time {blocks} (1 to ${t.maxBlocks}); the time is credited to the paying channel once the payment settles. Open a line: POST ${base}/line {"op":"open","channelId"}, sign the message it returns with the payer key, POST {"op":"prove","channelId","nonce","signature"}. Send the credential as x-line (MCP: _meta["zeam-pass/line"]). Time burns while a call runs on the line${idle}; a call stops when the time runs out. {"op":"off"}: no new calls on the line; a running call burns to its end. Unburned time comes back with a refund.`
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

  msOf(blocks) {
    return blocks * this.t.blockMs
  }

  callMs(priceMicro) {
    return Math.floor((priceMicro * this.t.blockMs) / this.t.blockMicro)
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
    return { channelId: rec.channelId, msRemaining: Clock.remaining(rec, now, this.t.idleMs), msSpent: Clock.settle(structuredClone(rec), now, this.t.idleMs).spentMs, msReturned: rec.returnedMs, metering: rec.on, blockMs: this.t.blockMs, blockUSD: usd(this.t.blockMicro) }
  }

  credit(channelId, ms, key) {
    return this.change(channelId, (rec) => Clock.buy(rec, ms, String(key), this.now(), this.t.idleMs))
  }

  uncredit(channelId, ms, key) {
    return this.change(channelId, (rec) => Clock.unbuy(rec, ms, String(key), this.now(), this.t.idleMs))
  }

  switch(channelId, on) {
    return this.change(channelId, (rec) => { Clock.switch(rec, on, this.now(), this.t.idleMs); return rec.balanceMs })
  }

  async begin(channelId, id) {
    const b = await this.change(channelId, (rec) => Clock.begin(rec, id, this.now(), this.t.idleMs))
    return b.ok ? { ...b, started: this.now() } : b
  }

  end(channelId, id, started = null, stopped = null) {
    return this.change(channelId, (rec) => ({ ...Clock.end(rec, id, this.now(), this.t.idleMs, started, stopped), msRemaining: rec.balanceMs }))
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
    return rec === null ? 0 : Clock.unburnedMicro(rec, this.now(), this.t.idleMs, this.t.blockMicro, this.t.blockMs)
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
      return rec.balanceMs
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
