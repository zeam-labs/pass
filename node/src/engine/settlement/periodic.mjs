import { randomBytes } from 'node:crypto'
import { Address, Hex, empty } from '../core/hex.mjs'
import { BatchSettlement } from '../core/batch.mjs'
import { Pass } from '../core/pass.mjs'
import { Secp256k1 } from '../core/secp256k1.mjs'
import { Chain } from './chain.mjs'
import { Channels } from './channels.mjs'
import { GasQuote, Payout, Relay } from './relay.mjs'
import { Verify } from './verify.mjs'

const n = Channels.n
const message = (e) => String(e?.message ?? e)

export class Claims {
  constructor(cfg, store, chain = null, relay = null) {
    this.cfg = cfg
    this.store = store
    this.chain = chain ?? new Chain(cfg.rpcUrl)
    this.relay = relay ?? new Relay(cfg.relayUrl)
    this.gas = new GasQuote(cfg, this.chain, this.relay)
    this.channels = new Channels(cfg, store, this.chain)
    this.payout = new Payout(cfg, this.chain)
  }

  async worthClaiming(asset, all) {
    const now = this.cfg.nowMs()
    const found = []
    for (const raw of all) {
      const c = await Channels.earned(this.cfg, raw)
      if (c.channelConfig?.token === undefined || !Address.equals(c.channelConfig.token, asset.address) || !Channels.claimable(c)) continue
      let live
      try {
        live = await this.chain.live(c.channelId)
      } catch {
        continue
      }
      if (live.withdrawing && empty(c.withdrawRequestedAt)) {
        await this.channels.update(c.channelId, (r) => {
          if (r !== null) r.withdrawRequestedAt = now
          return r
        })
      }
      const charged = n(c.chargedCumulativeAmount)
      if (charged > n(live.balance) || charged <= n(live.claimed)) continue
      const delta = charged - n(live.claimed)
      if (delta > n(live.payable)) continue
      const microUSD = Math.trunc(Number(this.cfg.microUSDOf(asset, delta.toString())))
      let urgent = false
      if (live.withdrawing) {
        const gas = await this.gas.microUSD(this.cfg.gas.claimAlone)
        urgent = gas !== null && microUSD * this.cfg.feeShare >= gas
      }
      const idle = now - (c.lastRequestTimestamp !== undefined ? Math.trunc(Number(c.lastRequestTimestamp)) : 0) >= 1000 * this.cfg.idleClaimSecs
      if (!live.withdrawing && !idle) continue
      found.push({ c, live, urgent, microUSD, units: delta.toString() })
    }
    const urgent = found.filter((x) => x.urgent)
    const rest = found.filter((x) => !x.urgent)
    if (!rest.length) return urgent
    const worth = rest.reduce((s, x) => s + x.microUSD, 0)
    const gas = await this.gas.microUSD(this.cfg.gas.claimBase + this.cfg.gas.claimEntry * rest.length + this.cfg.gas.payout)
    const ok = gas !== null && worth * this.cfg.feeShare >= gas
    return ok ? [...urgent, ...rest] : urgent
  }

  async claimCall(picked) {
    const claims = picked.map((x) => Channels.claimEntry(x.c))
    const signature = await BatchSettlement.signClaimBatch(this.cfg.signingKey(), claims, this.cfg.chainId)
    return { what: `claim ${claims.length} voucher(s)`, to: BatchSettlement.ESCROW, data: BatchSettlement.encodeClaimWithSignature(claims, signature) }
  }

  async run() {
    const all = await this.store.list()
    const report = {}
    for (const asset of this.cfg.assets) report[asset.symbol] = await this.runAsset(asset, all)
    return report
  }

  async runAsset(asset, all) {
    const selected = (await this.worthClaiming(asset, all)).slice(0, Math.max(1, Math.trunc(Number(this.cfg.maxClaimsPerBatch))))
    let riding = 0n
    for (const x of selected) riding += BigInt(x.units)
    let plan
    try {
      plan = await this.payout.calls(asset.address, selected.length > 0)
    } catch (e) {
      plan = { calls: [], owed: '0', held: '0', error: message(e) }
    }
    const payoutCalls = plan.calls
    let usd = 0
    let payoutOk = false
    if (payoutCalls.length) {
      const units = (riding + BigInt(plan.owed) + BigInt(plan.held)).toString()
      usd = this.cfg.microUSDOf(asset, units) / 1e6
      let gasUnits = this.cfg.gas.payout + (selected.length ? this.cfg.gas.claimBase + selected.length * this.cfg.gas.claimEntry : 0)
      for (const c of payoutCalls) if (c.what === 'create split') gasUnits += this.cfg.gas.createSplit
      payoutOk = await this.gas.atMargin(usd, gasUnits)
    }
    const claiming = payoutOk ? selected : selected.filter((x) => x.live.withdrawing)
    let calls = []
    if (claiming.length) calls.push(await this.claimCall(claiming))
    if (payoutOk) calls = [...calls, ...payoutCalls]
    const out = { selected: selected.length, claimed: [], valueUSD: Math.round(usd * 1e6) / 1e6, calls: [] }
    if (plan.error !== undefined) out.error = plan.error
    if (payoutCalls.length && !payoutOk) out.waiting = 'below break-even: waits until the fee share covers the gas'
    if (!calls.length) return out
    const results = await this.relay.send(calls, this.cfg.relaySplit())
    calls.forEach((call, i) => out.calls.push({ what: call.what, ...results[i] }))
    if (claiming.length && results[0]?.hash !== undefined) {
      for (const x of claiming) {
        const id = x.c.channelId
        let st
        try {
          st = await this.chain.channel(id)
        } catch {
          continue
        }
        await this.channels.update(id, (r) => {
          if (r === null) return null
          if (n(st.totalClaimed) > n(r.totalClaimed ?? '0')) r.totalClaimed = st.totalClaimed
          if (n(st.balance) < n(r.balance ?? '0')) r.balance = st.balance
          return r
        })
        out.claimed.push(id)
      }
    }
    return out
  }
}

export class Withdrawals {
  constructor(cfg, store, chain = null) {
    this.cfg = cfg
    this.store = store
    this.chain = chain ?? new Chain(cfg.rpcUrl)
  }

  async run() {
    const out = { checked: 0, withdrawing: [], cleared: [], errors: 0 }
    const now = this.cfg.nowMs()
    for (const c of await this.store.list()) {
      if (c.channelId === undefined || c.channelId === null || n(c.balance ?? '0') <= 0n) continue
      const id = String(c.channelId).toLowerCase()
      let w
      try {
        w = await this.chain.pendingWithdrawal(id)
      } catch {
        out.errors++
        continue
      }
      out.checked++
      const leaving = n(w.amount) > 0n
      const stamped = !empty(c.withdrawRequestedAt)
      if (leaving) out.withdrawing.push(id)
      if (leaving === stamped) continue
      await this.store.update(id, (r) => {
        if (r === null) return null
        r.withdrawRequestedAt = leaving ? now : 0
        return r
      })
      if (!leaving) out.cleared.push(id)
    }
    return out
  }
}

export class Refunds {
  static FUTURE_SKEW_MS = 30000

  constructor(cfg, store, chain = null, relay = null) {
    this.cfg = cfg
    this.store = store
    this.chain = chain ?? new Chain(cfg.rpcUrl)
    this.relay = relay ?? new Relay(cfg.relayUrl)
    this.gas = new GasQuote(cfg, this.chain, this.relay)
    this.channels = new Channels(cfg, store, this.chain)
  }

  static message(channelId, issued) {
    return `${Pass.REALM} refund\nchannel: ${String(channelId ?? '').toLowerCase()}\nissued: ${issued}`
  }

  static answer(status, body) {
    return { status, body }
  }

  static failed(status, code, why, more = {}) {
    return Refunds.answer(status, { op: 'refund_failed', code, why, ...more })
  }

  static parseIssued(issued) {
    if (typeof issued !== 'string' || issued.trim() === '') return null
    const t = Date.parse(issued)
    if (!Number.isNaN(t)) return t
    const s = Pass.strtotime(issued)
    return s === false ? null : s * 1000
  }

  async proofOwner(channelId, issued, signature) {
    const t = Refunds.parseIssued(issued)
    if (t === null) return { ok: false, why: 'issued is not a timestamp' }
    const now = this.cfg.nowMs()
    const window = 1000 * Math.trunc(Number(this.cfg.refundWindowSecs))
    if (now - t > window || t - now > Refunds.FUTURE_SKEW_MS) return { ok: false, why: `issued ${issued} is outside the ${Math.trunc(Number(this.cfg.refundWindowSecs))}s window` }
    let who
    try {
      who = await Secp256k1.recoverPersonal(Refunds.message(channelId, issued), String(signature ?? ''))
    } catch (e) {
      return { ok: false, why: `signature does not recover: ${message(e)}` }
    }
    return { ok: true, address: who.toLowerCase() }
  }

  static owns(channel, address) {
    const who = String(address ?? '').toLowerCase()
    if (who === '') return false
    const payer = String(channel.channelConfig?.payer ?? '').toLowerCase()
    const auth = String(channel.channelConfig?.payerAuthorizer ?? '').toLowerCase()
    return who === payer || (auth !== '' && who === auth)
  }

  async handle(channelId, issued, signature, ask = {}) {
    const cid = String(channelId ?? '').toLowerCase()
    let ch = Verify.isCanonicalChannelId(cid) ? await this.channels.repaired(cid) : null
    const sent = ask?.channelConfig ?? null
    const own = sent === null ? null : this.ownConfig(cid, sent)
    if (own?.mismatch) return Refunds.failed(400, 'channel_config_mismatch', own.why, { mismatch: own.mismatch })
    const config = own?.config ?? null
    if (ch === null && config === null) return Refunds.failed(404, 'unknown_channel', 'no channel with that id here. For a channel with no calls here, send its channelConfig with the proof.')
    const proof = await this.proofOwner(cid, issued, signature)
    if (!proof.ok) return Refunds.failed(400, 'proof_invalid', proof.why, { sign: Refunds.message(cid, '<ISO8601 within five minutes>') })
    if (!Refunds.owns(ch ?? { channelConfig: config }, proof.address)) return Refunds.failed(403, 'not_the_payer', `${proof.address} is not this channel's payer`)
    if (ch === null) {
      const adopted = await this.adopt(cid, config)
      if (adopted.failed) return adopted.failed
      ch = adopted.channel
    }
    return this.refundNow(ch, ask ?? {})
  }

  ownConfig(cid, config) {
    const fields = { mismatch: 'fields', why: 'channelConfig needs payer, payerAuthorizer, receiver, receiverAuthorizer, token, withdrawDelay and salt, as deposited' }
    if (config === null || typeof config !== 'object' || Array.isArray(config)) return fields
    let id
    try {
      id = Verify.computeChannelId(config, this.cfg.network).toLowerCase()
    } catch {
      return fields
    }
    if (id !== cid) return { mismatch: 'channelId', why: `channelConfig hashes to ${id}, not to channelId ${cid}` }
    const lower = (a) => String(a ?? '').toLowerCase()
    if (!Address.equals(config.receiver, this.cfg.receiver)) return { mismatch: 'receiver', why: `channelConfig receiver is ${lower(config.receiver)}; this seller's is ${lower(this.cfg.receiver)}` }
    if (!Address.equals(config.receiverAuthorizer, this.cfg.receiverAuthorizer)) return { mismatch: 'receiverAuthorizer', why: `channelConfig receiverAuthorizer is ${lower(config.receiverAuthorizer)}; this seller's is ${lower(this.cfg.receiverAuthorizer)}` }
    if (this.cfg.assetOf(config.token) === null) return { mismatch: 'token', why: `channelConfig token ${lower(config.token)} is not accepted here` }
    return { config }
  }

  async adopt(cid, config) {
    let st
    try {
      st = await this.chain.channel(cid)
    } catch {
      return { failed: Refunds.failed(409, 'chain_unreadable', 'could not read the channel on chain', { retry_after_seconds: Refunds.RETRY_SECS }) }
    }
    if (n(st.balance) <= 0n) return { failed: Refunds.failed(400, 'channel_config_mismatch', "channelConfig names this seller, but that channel holds no balance on chain: its escrow balance is 0", { mismatch: 'balance' }) }
    const now = this.cfg.nowMs()
    const channel = await this.channels.update(cid, (r) => r ?? {
      channelId: cid,
      channelConfig: config,
      chargedCumulativeAmount: String(st.totalClaimed),
      balance: String(st.balance),
      totalClaimed: String(st.totalClaimed),
      withdrawRequestedAt: 0,
      refundNonce: 0,
      onchainSyncedAt: now,
      lastRequestTimestamp: 0,
      adoptedAt: now,
    })
    return { channel }
  }

  static ask(body) {
    const b = body !== null && typeof body === 'object' ? body : {}
    const obj = (x) => (x !== null && typeof x === 'object' && !Array.isArray(x) ? x : null)
    const scalar = (x) => (['string', 'number'].includes(typeof x) ? String(x) : null)
    const p = obj(b.gasPayment)
    const a = p === null ? null : obj(p.authorization)
    const config = obj(b.channelConfig)
    return {
      selfSend: b.selfSend === true || b.selfSend === 'true',
      channelConfig: config !== null ? { ...config } : b.channelConfig === undefined ? null : b.channelConfig,
      gasPayment: p === null ? null : {
        authorization: a === null ? null : Object.fromEntries(Refunds.AUTHORIZATION_FIELDS.map((k) => [k, scalar(a[k])])),
        signature: scalar(p.signature),
      },
    }
  }

  static AUTHORIZATION_FIELDS = ['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce']
  static GAS_PAYMENT_MARGIN_SECS = 30
  static RETRY_SECS = 60
  static REFUND_EVERY_SECS = 3600

  microUSD(ch, units) {
    return this.cfg.microUSDOf(ch.channelConfig.token, units)
  }

  async refundNow(raw, ask = {}) {
    const id = String(raw.channelId).toLowerCase()
    let ch = await Channels.earned(this.cfg, raw)
    let live
    try {
      live = await this.chain.live(id)
    } catch {
      return Refunds.failed(409, 'chain_unreadable', 'could not read the channel on chain', { retry_after_seconds: Refunds.RETRY_SECS })
    }
    let left = Channels.refundable(ch, live)
    if (n(left) <= 0n) return Refunds.failed(409, 'nothing_to_return', 'nothing to return: the channel is fully spent', { leftMicroUSD: 0, returnedMicroUSD: 0, gasMicroUSD: 0 })
    const now = this.cfg.nowMs()
    if (Channels.pendingLive(ch, now)) {
      const wait = Math.ceil((Math.trunc(Number(ch.pendingRequest.expiresAt)) - now) / 1000)
      return Refunds.failed(409, 'request_open', `a paid call is open on this channel. Retry in ${wait}s.`, { retry_after_seconds: wait })
    }
    let nonce
    try {
      nonce = await this.chain.refundNonce(id)
    } catch {
      return Refunds.failed(409, 'chain_unreadable', 'could not read the refund nonce on chain', { retry_after_seconds: Refunds.RETRY_SECS })
    }
    if (ask.selfSend === true) {
      const f = await this.frozen(raw, live, ch, left)
      return f.failed ?? this.selfSend(f.ch, live, f.left, nonce)
    }
    const since = now - Math.trunc(Number(ch.lastRefundAt ?? 0))
    if (since < 1000 * Refunds.REFUND_EVERY_SECS) {
      const wait = Math.ceil((1000 * Refunds.REFUND_EVERY_SECS - since) / 1000)
      return Refunds.failed(409, 'refund_too_soon', `1 refund per channel per hour. Retry in ${wait}s, or send ${Refunds.SELF_SEND} for a signed refund to send yourself.`, { retry_after_seconds: wait, leftMicroUSD: this.microUSD(ch, left) })
    }
    const f = await this.frozen(raw, live, ch, left)
    if (f.failed) return f.failed
    ch = f.ch
    left = f.left
    const leftMicroUSD = this.microUSD(ch, left)
    let call
    try {
      call = await this.refundCall(ch, live, left, nonce)
    } catch (e) {
      return Refunds.failed(409, 'refund_error', message(e).slice(0, 160), { retry_after_seconds: Refunds.RETRY_SECS, leftMicroUSD })
    }
    const gas = await this.refundGas(ch, live, call)
    if (gas === null) return Refunds.failed(503, 'gas_unpriced', `refund gas cannot be priced. Retry in 60s, or send ${Refunds.SELF_SEND} for a signed refund to send yourself.`, { retry_after_seconds: Refunds.RETRY_SECS, leftMicroUSD })
    const amounts = { leftMicroUSD, returnedMicroUSD: leftMicroUSD, gasMicroUSD: gas.gasMicroUSD, feeMicroUSD: gas.feeMicroUSD, coverMicroUSD: gas.coverMicroUSD }
    let payment = null
    if (!gas.covered) {
      const asset = this.cfg.assetOf(ch.channelConfig.token)
      const rule = `channel fees $${Refunds.usd(gas.feeMicroUSD)} < deposit and refund gas $${Refunds.usd(gas.coverMicroUSD)}`
      if (asset === null || !asset.eip3009) return Refunds.failed(409, 'nothing_to_return', `${rule}; this token has no gasless payment for the $${Refunds.usd(gas.gasMicroUSD)} refund gas. Send ${Refunds.SELF_SEND} for a signed refund of the full balance, sent at your own gas.`, { ...amounts, returnedMicroUSD: 0, ...gas.detail })
      if (gas.gasMicroUSD >= leftMicroUSD) return Refunds.failed(409, 'nothing_to_return', `${rule}; refund gas $${Refunds.usd(gas.gasMicroUSD)} >= balance $${Refunds.usd(leftMicroUSD)}. Send ${Refunds.SELF_SEND} for a signed refund of the full balance, sent at your own gas.`, { ...amounts, returnedMicroUSD: 0, ...gas.detail })
      const payTo = await this.gas.gasWallet()
      if (payTo === null) return Refunds.failed(503, 'gas_unpriced', `the relay is unreachable. Retry in 60s, or send ${Refunds.SELF_SEND} for a signed refund to send yourself.`, { retry_after_seconds: Refunds.RETRY_SECS, leftMicroUSD })
      const units = this.cfg.unitsOfMicroUSD(asset, gas.gasMicroUSD)
      const offered = ask.gasPayment ?? null
      if (offered === null) return this.gasQuote(ch, asset, payTo, units, amounts, gas, null)
      const bad = await this.gasPaymentProblem(ch, asset, payTo, this.cfg.unitsOfMicroUSD(asset, gas.costMicroUSD), left, offered)
      if (bad !== null) return this.gasQuote(ch, asset, payTo, units, amounts, gas, bad)
      payment = { asset, payTo, authorization: offered.authorization, signature: offered.signature }
    }
    if (payment === null) return this.sendRefund(ch, call, left, 0, amounts)
    const pay = { to: payment.asset.address, data: BatchSettlement.encodeTransferWithAuthorization(payment.authorization, payment.signature) }
    const bundle = { to: BatchSettlement.MULTICALL3, data: BatchSettlement.encodeAggregate3([call, pay]) }
    const out = await this.sendRefund(ch, bundle, left, this.microUSD(ch, payment.authorization.value), amounts)
    return out.underpaid === undefined ? out : this.requote(ch, live, payment, amounts, gas, out.underpaid, call)
  }

  async frozen(raw, live, ch, left) {
    if (!this.cfg.meter) return { ch, left }
    if (!(await this.cfg.meter.stop(ch.channelId))) return { failed: Refunds.failed(409, 'request_open', 'a call is running on this channel\'s line. Retry in 5s.', { retry_after_seconds: 5 }) }
    const now = await Channels.earned(this.cfg, raw)
    const rest = Channels.refundable(now, live)
    if (n(rest) <= 0n) return { failed: Refunds.failed(409, 'nothing_to_return', 'nothing to return: the channel is fully spent', { leftMicroUSD: 0, returnedMicroUSD: 0, gasMicroUSD: 0 }) }
    return { ch: now, left: rest }
  }

  async requote(ch, live, payment, amounts, gas, sendCostMicroUSD, call) {
    const fresh = (await this.refundGas(ch, live, call)) ?? gas
    const quoted = Math.max(fresh.covered ? 0 : fresh.gasMicroUSD, Math.ceil(sendCostMicroUSD * this.cfg.gasBuffer))
    const numbers = { ...amounts, gasMicroUSD: quoted, feeMicroUSD: fresh.feeMicroUSD, coverMicroUSD: fresh.coverMicroUSD, sendCostMicroUSD }
    const moved = `refund gas rose past the quote margin: sending costs $${Refunds.usd(sendCostMicroUSD)}, your payment is $${Refunds.usd(this.microUSD(ch, payment.authorization.value))}. Nothing was sent`
    if (quoted >= amounts.leftMicroUSD) return Refunds.failed(409, 'nothing_to_return', `${moved}; refund gas $${Refunds.usd(quoted)} (margin included) >= balance $${Refunds.usd(amounts.leftMicroUSD)}. Send ${Refunds.SELF_SEND} for a signed refund of the full balance, sent at your own gas.`, { ...numbers, returnedMicroUSD: 0, ...fresh.detail })
    const units = this.cfg.unitsOfMicroUSD(payment.asset, quoted)
    return this.gasQuote(ch, payment.asset, payment.payTo, units, numbers, fresh, `${moved}. New quote: that cost + ${fresh.detail.marginPercent}% margin`, { requoted: true, sendCostMicroUSD })
  }

  static SELF_SEND = '{"selfSend": true}'

  static usd(microUSD) {
    const s = (Number(microUSD) / 1e6).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')
    return s === '' ? '0' : s
  }

  async selfSend(ch, live, left, nonce) {
    if (!empty(ch.handedOver?.transaction) && String(ch.handedOver.nonce) === String(nonce)) {
      return Refunds.answer(200, { op: 'refund_signed', microUSD: this.microUSD(ch, ch.handedOver.units ?? '0'), transaction: ch.handedOver.transaction, why: 'your signed refund is unsent; here it is again' })
    }
    let call
    try {
      call = await this.refundCall(ch, live, left, nonce)
    } catch (e) {
      return Refunds.failed(409, 'refund_error', message(e).slice(0, 160), { retry_after_seconds: Refunds.RETRY_SECS })
    }
    return this.handOver(ch, live, call, left, nonce)
  }

  async refundGas(ch, live, call = null) {
    const bundled = Refunds.bundledClaim(ch, live)
    const q = await this.gas.refund(bundled > 0n, true)
    if (q === null) return null
    const { cover } = q
    const earned = this.microUSD(ch, (n(live.claimed) + bundled).toString())
    const feeMicroUSD = GasQuote.feeMicroUSD(earned, this.cfg.feeShare)
    const detailOf = (p) => ({ gasUnits: p.gasUnits, gasUnitsWithMargin: p.gasUnitsWithMargin, gasPriceWei: p.gasPriceWei, ethUSD: p.ethUSD, l1FeeWei: p.l1FeeWei, marginPercent: p.marginPercent, quotedBy: p.quotedBy })
    if (feeMicroUSD >= cover.microUSD) return { covered: true, gasMicroUSD: 0, costMicroUSD: 0, feeMicroUSD, coverMicroUSD: cover.microUSD, detail: detailOf(q.pay) }
    const pay = (await this.gas.relayQuote(call)) ?? q.pay
    return { covered: false, gasMicroUSD: pay.microUSD, costMicroUSD: pay.costMicroUSD, feeMicroUSD, coverMicroUSD: cover.microUSD, detail: detailOf(pay) }
  }

  async gasPaymentProblem(ch, asset, payTo, units, left, p) {
    const a = p.authorization
    if (a === null || Object.values(a).some((v) => v === null) || p.signature === null) return 'gasPayment is {authorization: {from, to, value, validAfter, validBefore, nonce}, signature}'
    if (!Address.equals(a.from, ch.channelConfig.payer)) return "gasPayment must come from the channel's payer"
    if (!Address.equals(a.to, payTo)) return `gasPayment must go to the relay gas wallet ${payTo}`
    if (!/^\d+$/.test(a.value) || n(a.value) < n(units)) return `gasPayment is ${a.value}; sending costs ${units}`
    if (n(a.value) > n(left)) return `gasPayment is ${a.value}, over the ${left} refund`
    const nowSecs = BigInt(Math.floor(this.cfg.nowMs() / 1000))
    if (!/^\d+$/.test(a.validAfter) || n(a.validAfter) > nowSecs) return 'gasPayment is not valid yet'
    if (!/^\d+$/.test(a.validBefore) || n(a.validBefore) < nowSecs + BigInt(Refunds.GAS_PAYMENT_MARGIN_SECS)) return 'gasPayment expires too soon'
    if (!Hex.isHex(a.nonce, 32)) return 'gasPayment nonce is not 32 bytes'
    let signer = null
    try {
      signer = await Secp256k1.recoverHash(BatchSettlement.transferAuthorizationDigest(Refunds.tokenDomain(this.cfg, asset), a), p.signature)
    } catch {
      signer = null
    }
    if (signer === null || !Address.equals(signer, a.from)) return "gasPayment signature is not the payer's"
    return null
  }

  static tokenDomain(cfg, asset) {
    return BatchSettlement.tokenDomain(asset.address, asset.name, asset.version ?? '1', cfg.chainId)
  }

  gasQuote(ch, asset, payTo, units, amounts, gasInfo, why, extra = {}) {
    const nowSecs = Math.floor(this.cfg.nowMs() / 1000)
    const authorization = {
      from: Address.checksum(ch.channelConfig.payer),
      to: Address.checksum(payTo),
      value: String(units),
      validAfter: '0',
      validBefore: String(nowSecs + Math.trunc(Number(this.cfg.gasPaymentSecs))),
      nonce: '0x' + randomBytes(32).toString('hex'),
    }
    const gas = this.microUSD(ch, units)
    const d = gasInfo.detail
    const lead = why === null ? '' : `${why}. `
    return Refunds.answer(409, {
      op: 'refund_quote',
      code: 'gas_payment_needed',
      why: `${lead}channel fees $${Refunds.usd(gasInfo.feeMicroUSD)} < deposit and refund gas $${Refunds.usd(gasInfo.coverMicroUSD)}. Refund gas: $${Refunds.usd(gas)} USDC, no ETH (${d.gasUnits} gas, L1 fee ${d.l1FeeWei} wei, ${d.gasPriceWei} wei per gas, $${d.ethUSD} per ETH, ${d.marginPercent}% margin; ${d.quotedBy === 'relay' ? 'priced by the relay' : 'priced from measured refunds'}). Sign authorization (EIP-3009 TransferWithAuthorization; typed data in sign) and POST again with gasPayment: {authorization, signature} within ${Math.trunc(Number(this.cfg.gasPaymentSecs))}s. The refund of $${Refunds.usd(amounts.leftMicroUSD)} and the gas payment go in one transaction: $${Refunds.usd(amounts.leftMicroUSD - gas)} net to you. Or send ${Refunds.SELF_SEND} for a signed refund of the full balance, sent at your own gas.`,
      ...amounts,
      gasMicroUSD: gas,
      ...d,
      ...extra,
      payTo: authorization.to,
      authorization,
      sign: BatchSettlement.transferAuthorizationTypedData(Refunds.tokenDomain(this.cfg, asset), authorization),
    })
  }

  static bundledClaim(ch, live) {
    const charged = n(ch.chargedCumulativeAmount ?? '0')
    if (charged <= n(live.claimed) || empty(ch.signature) || empty(ch.signedMaxClaimable)) return 0n
    return charged - n(live.claimed)
  }

  async refundCall(ch, live, left, nonce) {
    const id = String(ch.channelId).toLowerCase()
    const refundSig = await BatchSettlement.signRefund(this.cfg.signingKey(), id, left, nonce, this.cfg.chainId)
    const refund = BatchSettlement.encodeRefundWithSignature(ch.channelConfig, left, nonce, refundSig)
    if (Refunds.bundledClaim(ch, live) <= 0n) return { to: BatchSettlement.ESCROW, data: refund }
    const claims = [Channels.claimEntry(ch)]
    const claimSig = await BatchSettlement.signClaimBatch(this.cfg.signingKey(), claims, this.cfg.chainId)
    return { to: BatchSettlement.ESCROW, data: BatchSettlement.encodeMulticall([BatchSettlement.encodeClaimWithSignature(claims, claimSig), refund]) }
  }

  async sendRefund(ch, call, left, gasMicroUSD, amounts = {}) {
    const id = String(ch.channelId).toLowerCase()
    const sent = await this.relay.send([call], this.cfg.relaySplit())
    if (sent[0]?.hash === undefined) {
      const error = String(sent[0]?.error ?? 'the relay did not send the refund')
      const numbers = { leftMicroUSD: amounts.leftMicroUSD, gasMicroUSD, feeMicroUSD: amounts.feeMicroUSD, coverMicroUSD: amounts.coverMicroUSD }
      if (gasMicroUSD > 0 && sent[0]?.code === 'underpaid') return { underpaid: Math.max(1, Math.trunc(Number(sent[0].gasMicroUSD ?? 0))) }
      if (/one refund an hour/.test(error)) {
        const wait = Number(/retry in (\d+)s/.exec(error)?.[1] ?? Refunds.REFUND_EVERY_SECS)
        return Refunds.failed(409, 'refund_too_soon', `1 refund per channel per hour. Retry in ${wait}s, or send ${Refunds.SELF_SEND} for a signed refund to send yourself.`, { retry_after_seconds: wait, ...numbers })
      }
      return Refunds.failed(409, 'refund_error', error.slice(0, 160), { retry_after_seconds: Refunds.RETRY_SECS, ...numbers })
    }
    let after = null
    const tries = Math.max(1, Math.trunc(Number(this.cfg.drainTries)))
    for (let i = 0; i < tries; i++) {
      try {
        after = await this.chain.live(id)
      } catch {
        after = null
      }
      if (after !== null && n(after.balance) <= n(after.claimed)) break
      if (i + 1 < Math.trunc(Number(this.cfg.drainTries))) await this.cfg.sleepMs(this.cfg.drainWaitMs)
    }
    const drained = after !== null && n(after.balance) <= n(after.claimed)
    const at = this.cfg.nowMs()
    let state = null
    await this.channels.update(id, (r) => {
      if (r === null) return null
      r.lastRefundAt = at
      if (after !== null) {
        r.balance = after.balance
        r.totalClaimed = after.claimed
        r.chargedCumulativeAmount = after.claimed
        r.refundNonce = Math.trunc(Number(r.refundNonce ?? 0)) + 1
        delete r.signedMaxClaimable
        delete r.signature
        delete r.pendingRequest
        delete r.handedOver
      } else if (n(ch.chargedCumulativeAmount ?? '0') < n(r.chargedCumulativeAmount ?? '0')) {
        r.chargedCumulativeAmount = ch.chargedCumulativeAmount
      }
      return r
    })
    if (after !== null) state = { channelId: id, balance: after.balance, totalClaimed: after.claimed, chargedCumulativeAmount: after.claimed }
    const timeMs = this.cfg.meter ? await this.cfg.meter.forget(id) : 0
    const returned = this.microUSD(ch, left)
    const body = { op: 'refunded', microUSD: returned, returnedMicroUSD: returned, gasMicroUSD, transaction: sent[0].hash, drained, ...(timeMs > 0 ? { timeReturnedMs: timeMs } : {}) }
    body.why = gasMicroUSD > 0
      ? `refund $${Refunds.usd(returned)} and gas payment $${Refunds.usd(gasMicroUSD)} (${Math.round((this.cfg.gasBuffer - 1) * 100)}% margin included) sent in one transaction: $${Refunds.usd(returned - gasMicroUSD)} net to you`
      : `refund $${Refunds.usd(returned)} sent; ZEAM paid the gas`
    if (state !== null) body.channelState = state
    return Refunds.answer(200, body)
  }

  async handOver(ch, live, call, left, nonce) {
    const id = String(ch.channelId).toLowerCase()
    const now = this.cfg.nowMs()
    const transaction = { chainId: this.cfg.chainId, to: Address.checksum(call.to), data: call.data, value: '0' }
    try {
      await this.channels.update(id, (r) => {
        if (r === null) return null
        const before = r.balance ?? '0'
        const rest = n(before) - n(left)
        r.balance = rest > 0n ? rest.toString() : '0'
        if (n(ch.chargedCumulativeAmount ?? '0') < n(r.chargedCumulativeAmount ?? '0')) r.chargedCumulativeAmount = ch.chargedCumulativeAmount
        r.handedOver = { units: String(left), chainBalance: String(live.balance), nonce: String(nonce), at: now, transaction }
        return r
      })
    } catch (e) {
      return Refunds.failed(409, 'refund_error', message(e).slice(0, 160), { retry_after_seconds: Refunds.RETRY_SECS })
    }
    const timeMs = this.cfg.meter ? await this.cfg.meter.forget(id) : 0
    return Refunds.answer(200, { op: 'refund_signed', microUSD: this.microUSD(ch, left), transaction, why: 'signed refund of the full balance; send it at your own gas', ...(timeMs > 0 ? { timeReturnedMs: timeMs } : {}) })
  }
}
