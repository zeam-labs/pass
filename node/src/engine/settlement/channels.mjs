import { empty } from '../core/hex.mjs'

export class Channels {
  constructor(cfg, store, chain) {
    this.cfg = cfg
    this.store = store
    this.chain = chain
  }

  static n(value) {
    if (typeof value === 'bigint') return value
    if (value === null || value === undefined || value === '') return 0n
    if (typeof value === 'number') return BigInt(Math.trunc(value))
    const s = String(value)
    if (!/^-?\d+$/.test(s)) throw new TypeError(`not a decimal integer: ${s.slice(0, 40)}`)
    return BigInt(s)
  }

  static max(a, b) {
    return Channels.n(a) >= Channels.n(b) ? Channels.n(a) : Channels.n(b)
  }

  get(channelId) {
    return this.store.get(String(channelId).toLowerCase())
  }

  update(channelId, fn) {
    return this.store.update(String(channelId).toLowerCase(), fn)
  }

  async repaired(channelId) {
    const channel = await this.get(channelId)
    if (channel === null) return null
    const now = this.cfg.nowMs()
    const last = channel.repairedAt !== undefined ? Math.trunc(Number(channel.repairedAt)) : 0
    if (empty(channel.handedOver) && now - last < this.cfg.repairEveryMs) return channel
    let st
    try {
      st = await this.chain.channel(channelId)
    } catch {
      return channel
    }
    let nonce = null
    if (!empty(channel.handedOver)) {
      try {
        nonce = await this.chain.refundNonce(channelId)
      } catch {
        nonce = null
      }
    }
    return this.update(channelId, (c) => {
      if (c === null) return null
      c.repairedAt = now
      if (!empty(c.handedOver)) {
        if (nonce !== null && String(nonce) !== String(c.handedOver.nonce)) {
          delete c.handedOver
          c.balance = st.balance
        }
      } else if (c.balance !== undefined && c.balance !== null && Channels.n(st.balance) < Channels.n(c.balance)) {
        c.balance = st.balance
      }
      const charged = Channels.n(c.chargedCumulativeAmount ?? '0')
      if (Channels.n(st.totalClaimed) > charged) {
        c.chargedCumulativeAmount = st.totalClaimed
        c.totalClaimed = st.totalClaimed
      }
      return c
    })
  }

  async leaving(channel) {
    const id = channel.channelId
    const stamped = channel.withdrawRequestedAt !== undefined && channel.withdrawRequestedAt !== null && Math.trunc(Number(channel.withdrawRequestedAt)) > 0
    let w
    try {
      w = await this.chain.pendingWithdrawal(id)
    } catch {
      return stamped
    }
    const leaving = Channels.n(w.amount) > 0n
    if (leaving !== stamped) {
      const now = this.cfg.nowMs()
      await this.update(id, (c) => {
        if (c === null) return null
        c.withdrawRequestedAt = leaving ? now : 0
        return c
      })
    }
    return leaving
  }

  async release(channelId, pendingId = null) {
    let had = false
    try {
      await this.update(channelId, (c) => {
        if (c === null || c.pendingRequest === undefined || c.pendingRequest === null) return c
        if (pendingId !== null && (c.pendingRequest.pendingId === undefined || c.pendingRequest.pendingId !== pendingId)) return c
        had = true
        delete c.pendingRequest
        return c
      })
    } catch {
      return false
    }
    return had
  }

  static pendingLive(channel, now = 0) {
    return channel !== null && channel !== undefined && channel.pendingRequest?.expiresAt !== undefined && channel.pendingRequest.expiresAt !== null && Math.trunc(Number(channel.pendingRequest.expiresAt)) > now
  }

  static claimable(c) {
    if (empty(c.signature) || empty(c.signedMaxClaimable)) return false
    let charged, signed, claimed, balance
    try {
      charged = Channels.n(c.chargedCumulativeAmount ?? '0')
      signed = Channels.n(c.signedMaxClaimable)
      claimed = Channels.n(c.totalClaimed ?? '0')
      balance = Channels.n(c.balance ?? '0')
    } catch {
      return false
    }
    if (charged <= 0n || charged <= claimed || charged > signed || charged > balance) return false
    return charged - claimed <= balance - claimed
  }

  static async earned(cfg, c) {
    if (!cfg?.meter || !c?.channelId || !c.channelConfig?.token) return c
    const micro = await cfg.meter.unburnedMicro(c.channelId)
    const asset = micro > 0 ? cfg.assetOf(c.channelConfig.token) : null
    if (asset === null) return c
    const charged = Channels.n(c.chargedCumulativeAmount ?? '0')
    const claimed = Channels.n(c.totalClaimed ?? '0')
    let net = charged - BigInt(cfg.unitsOfMicroUSD(asset, micro))
    if (net < claimed) net = claimed
    return net >= charged ? c : { ...c, chargedCumulativeAmount: net.toString() }
  }

  static refundable(c, live) {
    const charged = Channels.n(c.chargedCumulativeAmount ?? '0')
    const left = Channels.n(live.balance) - Channels.max(charged, live.claimed)
    return left > 0n ? left.toString() : '0'
  }

  static claimEntry(c) {
    return { voucher: { channel: c.channelConfig, maxClaimableAmount: String(c.signedMaxClaimable) }, signature: c.signature, totalClaimed: String(c.chargedCumulativeAmount) }
  }
}
