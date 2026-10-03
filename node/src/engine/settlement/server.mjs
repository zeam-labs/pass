import { randomBytes } from 'node:crypto'
import { empty, isObject } from '../core/hex.mjs'
import { BatchSettlement } from '../core/batch.mjs'
import { Chain } from './chain.mjs'
import { Channels } from './channels.mjs'
import { Config } from './config.mjs'
import { Json, Reason } from './json.mjs'
import { GasQuote, Relay } from './relay.mjs'
import { Verify } from './verify.mjs'
import { pricing } from '../pricing.mjs'
import { forHeader } from '../bazaar.mjs'

const MISMATCH = Reason.CUMULATIVE_AMOUNT_MISMATCH
const MIN_PENDING_TTL_MS = 5000
const MAX_PENDING_TTL_MS = 600000
const SDK_FIELDS = ['channelId', 'channelConfig', 'chargedCumulativeAmount', 'signedMaxClaimable', 'signature', 'balance', 'totalClaimed', 'withdrawRequestedAt', 'refundNonce', 'onchainSyncedAt', 'lastRequestTimestamp', 'pendingRequest']
const clone = (v) => (v === undefined ? v : JSON.parse(JSON.stringify(v)))
const message = (e) => String(e?.message ?? e)

function utf8Cut(text, bytes) {
  const b = Buffer.from(text, 'utf8')
  if (b.length <= bytes) return text
  let end = bytes
  while (end > 0 && (b[end] & 0xc0) === 0x80) end--
  return b.subarray(0, end).toString('utf8')
}

export class Server {
  static MISMATCH = MISMATCH
  static SDK_FIELDS = SDK_FIELDS

  constructor(cfg, store, chain = null, relay = null) {
    this.cfg = cfg
    this.store = store
    this.chain = chain ?? new Chain(cfg.rpcUrl)
    this.relay = relay ?? new Relay(cfg.relayUrl)
    this.gasQuote = new GasQuote(cfg, this.chain, this.relay)
    this.channels = new Channels(cfg, store, this.chain)
    this.verify = new Verify(cfg, this.chain)
  }

  gas() {
    return this.gasQuote
  }

  micro(price) {
    return price !== null && price !== undefined && Number(price) >= 1 ? Math.trunc(Number(price)) : this.cfg.priceMicroUSD
  }

  accepts(price = null) {
    let rows = []
    for (const asset of this.cfg.assets) {
      for (const method of asset.eip3009 ? ['eip3009', 'permit2'] : ['permit2']) rows.push(this.requirement(asset, method, this.micro(price)))
    }
    const dollar = rows.filter((r) => typeof r.extra.name === 'string' && /^USD Coin$/i.test(r.extra.name))
    if (dollar.length && rows.length > dollar.length) rows = [...rows, ...dollar.map(clone)]
    return rows
  }

  requirement(asset, method, micro = this.cfg.priceMicroUSD) {
    const extra = {}
    if (method === 'eip3009') {
      if (asset.name !== null && asset.name !== '') {
        extra.name = asset.name
        extra.version = asset.version !== null ? asset.version : '1'
      }
    } else extra.assetTransferMethod = 'permit2'
    extra.receiverAuthorizer = this.cfg.receiverAuthorizer
    extra.withdrawDelay = this.cfg.withdrawDelay
    return { scheme: Config.SCHEME, network: this.cfg.network, amount: this.cfg.unitsOfMicroUSD(asset, micro), asset: asset.address, payTo: this.cfg.receiver, maxTimeoutSeconds: this.cfg.maxTimeoutSeconds, extra }
  }

  static resourceInfo(resource) {
    if (resource !== null && typeof resource === 'object') {
      const out = { url: resource.url !== undefined && resource.url !== null ? String(resource.url) : '' }
      if (typeof resource.description === 'string' && resource.description !== '') out.description = utf8Cut(resource.description, 300)
      out.mimeType = resource.mimeType !== undefined && resource.mimeType !== null ? String(resource.mimeType) : 'application/json'
      return out
    }
    return { url: String(resource ?? ''), mimeType: 'application/json' }
  }

  static document(resource, error, accepts) {
    const doc = { x402Version: 2 }
    if (error !== null && error !== undefined) doc.error = error
    doc.resource = resource
    doc.accepts = accepts
    return doc
  }

  static percent(share) {
    return (Number(share) * 100).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
  }

  static usd(microUSD) {
    const s = (microUSD / 1e6).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')
    return s === '' ? '0' : s
  }

  async terms(refundUrl = null, price = null) {
    const p = price !== null && typeof price === 'object' ? price : { micro: this.micro(price), unitMicro: null }
    const floor = await this.gasQuote.depositFloorMicroUSD(p.micro)
    const q = await this.gasQuote.refund(false, false)
    const cover = q === null ? null : q.cover.microUSD
    const relayed = await this.gasQuote.relayRefundQuote()
    const gas = relayed !== null ? relayed.microUSD : q === null ? null : q.pay.microUSD
    const at = this.cfg.refundUrl || refundUrl
    const where = at ? `POST ${at}` : 'POST /refund on this site'
    const about = (microUSD) => (microUSD === null ? '' : ` ($${Server.usd(microUSD)} now)`)
    const margin = relayed !== null ? relayed.marginPercent : Math.round((this.cfg.gasBuffer - 1) * 100)
    return {
      pricing: pricing(p),
      deposit: `First call on a channel deposits at least $${Server.usd(floor)}. Later calls spend it. The unspent balance is refundable.`,
      refund: `Refund: ${where} with {channelId, issued, signature} signed by the payer; add channelConfig if the channel has no calls. ZEAM pays the gas when the channel's fees (${Server.percent(this.cfg.feeShare)}% of its spend) cover its deposit and refund gas${about(cover)}. Otherwise sign a gasless USDC payment of the quoted gas${about(gas)}, ${margin}% margin included. An unsettled call adds its claim gas. 1 refund per channel per hour. {"selfSend": true}: a signed refund you send at your own gas.`,
    }
  }

  async paymentRequired(resource, extensions = null, refundUrl = null, more = {}, price = null) {
    const p = price !== null && typeof price === 'object' ? price : { micro: this.micro(price), unitMicro: null }
    let doc = Server.document(Server.resourceInfo(resource), null, this.accepts(p.micro))
    if (extensions && (Array.isArray(extensions) ? extensions.length : Object.keys(extensions).length)) doc.extensions = extensions
    doc = { ...doc, ...(await this.terms(refundUrl, p)), ...more }
    return { ok: false, status: 402, body: doc, headers: { 'PAYMENT-REQUIRED': Json.base64(forHeader(doc)) } }
  }

  static refuse(status, body, headers = {}) {
    return { ok: false, status, body, headers }
  }

  static channelIdOf(payload) {
    const raw = payload && isObject(payload.payload) ? payload.payload : {}
    const id = raw.voucher?.channelId !== undefined && raw.voucher?.channelId !== null ? raw.voucher.channelId : raw.channelId !== undefined && raw.channelId !== null ? raw.channelId : null
    return typeof id === 'string' && /^0x[0-9a-fA-F]{64}$/.test(id) ? id.toLowerCase() : null
  }

  match(accepts, payload) {
    const version = payload.x402Version ?? null
    const accepted = payload.accepted !== null && typeof payload.accepted === 'object' ? payload.accepted : null
    if (accepted === null) return null
    for (const req of accepts) {
      if (version === 2) {
        const { extra: _r, ...reqCore } = req
        const { extra: _a, ...accCore } = accepted
        if (Json.deepEqual(reqCore, accCore) && Json.containsSubset(req.extra, accepted.extra ?? null)) return req
      } else if (version === 1) {
        if (accepted.scheme !== undefined && accepted.scheme !== null && accepted.network !== undefined && accepted.network !== null && req.scheme === accepted.scheme && req.network === accepted.network) return req
      }
    }
    return null
  }

  static provisional(raw, charged, now) {
    return {
      channelId: raw.voucher.channelId,
      channelConfig: raw.channelConfig,
      chargedCumulativeAmount: String(charged),
      signedMaxClaimable: raw.voucher.maxClaimableAmount,
      signature: raw.voucher.signature,
      balance: '0',
      totalClaimed: '0',
      withdrawRequestedAt: 0,
      refundNonce: 0,
      lastRequestTimestamp: now,
    }
  }

  static inferCharged(signedMax, price) {
    const signed = Verify.uint(signedMax)
    const amount = Verify.uint(price)
    return signed < amount ? '0' : (signed - amount).toString()
  }

  static channelState(c, charged = null) {
    const out = { channelId: c.channelId, balance: c.balance, totalClaimed: c.totalClaimed, withdrawRequestedAt: c.withdrawRequestedAt, refundNonce: String(c.refundNonce) }
    if (charged !== null && charged !== undefined) out.chargedCumulativeAmount = String(charged)
    return out
  }

  async enrich(accepts, error, payload, snapshot) {
    if (error !== MISMATCH) return accepts
    const raw = payload.payload ?? null
    if (!Verify.isVoucherPayload(raw) && !Verify.isDepositPayload(raw) && !Verify.isRefundPayload(raw)) return accepts
    const network = payload.accepted?.network ?? null
    let channel
    try {
      if (Verify.bindingError(raw.channelConfig, raw.voucher.channelId, network)) return accepts
      channel = snapshot !== null && snapshot !== undefined ? snapshot : await this.store.get(String(raw.voucher.channelId).toLowerCase())
    } catch {
      return accepts
    }
    if (channel === null) return accepts
    const out = clone(accepts)
    for (const req of out) {
      if (req.scheme === Config.SCHEME && req.network === network) {
        req.extra.channelState = Server.channelState(channel, channel.chargedCumulativeAmount)
        req.extra.voucherState = { signedMaxClaimable: channel.signedMaxClaimable, signature: channel.signature }
        break
      }
    }
    return out
  }

  static stateIn(accepts) {
    for (const a of accepts) if (a.extra?.channelState !== undefined) return a.extra.channelState
    return null
  }

  fundingRefusal(payload, accepts, floor, price = this.cfg.priceMicroUSD) {
    const quoted = this.quotedMicroUSD(payload)
    if (quoted + 1 < price) {
      return { error: 'price_changed', code: 'price_changed', quotedMicroUSD: quoted, neededMicroUSD: floor, message: `the price is ${price} micro-USD per call. Pay the accepts quote. Nothing was charged.`, accepts }
    }
    const deposit = this.depositMicroUSD(payload)
    return {
      error: 'funding_requires_open_fee',
      code: 'funding_requires_open_fee',
      quotedMicroUSD: quoted,
      depositMicroUSD: deposit,
      neededMicroUSD: floor,
      message: `deposit ${deposit} micro-USD is under the ${floor} micro-USD floor. Deposit at least ${floor} micro-USD; each call costs ${quoted} micro-USD; the unspent balance is refundable. Nothing was charged.`,
      accepts,
    }
  }

  quotedMicroUSD(payload) {
    try {
      const acc = payload.accepted ?? {}
      const v = this.cfg.microUSDOf(acc.asset ?? '', Verify.uint(acc.amount ?? '0').toString())
      return v === null ? 0 : v
    } catch {
      return 0
    }
  }

  depositMicroUSD(payload) {
    try {
      const acc = payload.accepted ?? {}
      const amount = payload.payload?.deposit?.amount ?? '0'
      const v = this.cfg.microUSDOf(acc.asset ?? '', Verify.uint(amount).toString())
      return v === null ? 0 : v
    } catch {
      return 0
    }
  }

  async verifyAndHold(paymentHeaderValue, resource, price = null) {
    const micro = this.micro(price)
    const res = Server.resourceInfo(resource)
    const accepts = this.accepts(micro)
    const payload = Json.decodeHeader(paymentHeaderValue)
    if (payload === null) return Server.refuse(400, { error: 'bad_payment_header', message: 'send PAYMENT-SIGNATURE, or base64 of the payload in x-payment' })
    const raw = isObject(payload.payload) || Array.isArray(payload.payload) ? payload.payload : null
    const kind = raw !== null ? raw.type ?? null : null
    const batch = payload.scheme === Config.SCHEME || payload.accepted?.scheme === Config.SCHEME || (kind !== null && kind !== '' && kind !== false && kind !== 0)
    if (batch && kind !== 'voucher' && kind !== 'deposit') return Server.refuse(400, { error: 'bad_payment_type', message: 'a paid call carries a voucher or a deposit. Refunds: POST /refund. Nothing was charged.' })
    const cid = Server.channelIdOf(payload)
    let ch = null
    if (cid !== null) {
      try {
        ch = await this.channels.repaired(cid)
      } catch {
        ch = null
      }
    }
    if (ch !== null) {
      if (await this.channels.leaving(ch)) return Server.refuse(402, { error: 'channel_leaving', code: 'channel_leaving', message: 'this channel is withdrawing. Open a new channel. Nothing was charged.' })
      if (!empty(ch.handedOver)) {
        return Server.refuse(402, {
          error: 'refund_outstanding',
          code: 'refund_outstanding',
          transaction: ch.handedOver.transaction ?? null,
          message: 'this channel has an unsent signed refund. Send it (transaction here), then deposit again. Nothing was charged.',
        })
      }
      if (kind === 'deposit') {
        let left = 0n
        let price = 0n
        try {
          left = Channels.n(ch.balance) - Channels.max(ch.chargedCumulativeAmount, ch.totalClaimed)
          price = Verify.uint(payload.accepted?.amount ?? '0')
        } catch {
          price = 0n
        }
        if (price > 0n && left >= price) {
          const enriched = await this.enrich(accepts, MISMATCH, payload, null)
          if (Server.stateIn(enriched) !== null) {
            const corrected = Server.document(res, MISMATCH, enriched)
            const body = { ...corrected, message: 'this channel is funded. Pay with a voucher. Nothing was charged.' }
            return Server.refuse(402, body, { 'PAYMENT-REQUIRED': Json.base64(corrected) })
          }
        }
      }
    }
    if (kind === 'deposit') {
      const floor = await this.gasQuote.depositFloorMicroUSD(micro)
      if (this.quotedMicroUSD(payload) + 1 < micro || this.depositMicroUSD(payload) + 1 < floor) return Server.refuse(402, this.fundingRefusal(payload, accepts, floor, micro))
    }
    const match = this.match(accepts, payload)
    if (match === null) return Server.refuse(402, { error: 'no_matching_requirement', message: 'the payment matches no requirement here', accepts })
    const ctx = { snapshot: null }
    const verified = await this.verifyPayment(payload, match, ctx)
    if (!verified.isValid) {
      const reason = verified.invalidReason ?? null
      const error = /exceeds_balance|below_claimed/.test(String(reason ?? '')) ? MISMATCH : reason
      const enriched = await this.enrich(accepts, error, payload, ctx.snapshot)
      const corrected = Server.document(res, error, enriched)
      const body = { error: error ?? 'payment_invalid', code: 'payment_invalid', message: 'the payment did not verify' }
      if (reason !== null) body.reason = reason
      body.accepts = enriched
      const state = Server.stateIn(enriched)
      if (state !== null) body.channelState = state
      return Server.refuse(402, body, { 'PAYMENT-REQUIRED': Json.base64(corrected) })
    }
    return { ok: true, hold: { kind, channelId: ctx.channelId, pendingId: ctx.pendingId, payload, requirement: match, resource: res, payer: verified.payer, micro } }
  }

  async verifyPayment(payload, req, ctx) {
    const raw = payload.payload
    let local = null
    const now = this.cfg.nowMs()
    if (Verify.isVoucherPayload(raw) || Verify.isDepositPayload(raw)) {
      try {
        const bind = Verify.bindingError(raw.channelConfig, raw.voucher.channelId, req.network)
        if (bind) return Verify.invalid(bind)
        const cid = String(raw.voucher.channelId).toLowerCase()
        const snapshot = await this.store.get(cid)
        const charged = snapshot !== null && snapshot.chargedCumulativeAmount !== undefined && snapshot.chargedCumulativeAmount !== null ? String(snapshot.chargedCumulativeAmount) : Server.inferCharged(raw.voucher.maxClaimableAmount, req.amount)
        const expected = BigInt(charged) + Verify.uint(req.amount)
        if (Verify.uint(raw.voucher.maxClaimableAmount) !== expected) {
          ctx.snapshot = snapshot !== null ? snapshot : Server.provisional(raw, charged, now)
          return Verify.invalid(MISMATCH)
        }
        ctx.snapshot = snapshot
        ctx.channelId = cid
        ctx.pendingId = '0x' + randomBytes(32).toString('hex')
        if (Verify.isVoucherPayload(raw)) local = await this.verify.local(raw, req, snapshot, now)
      } catch {
        return Verify.invalid(Reason.VERIFICATION_STATE_UNAVAILABLE)
      }
    }
    const result = local !== null ? local : await this.verify.facilitator(payload, req)
    if (!result.isValid || !result.payer) return result
    if (ctx.pendingId === undefined) return Verify.invalid(Reason.VERIFICATION_STATE_UNAVAILABLE)
    const ex = result.extra ?? {}
    let record = null
    let outcome = null
    const pendingId = ctx.pendingId
    const expiresAt = now + Math.min(MAX_PENDING_TTL_MS, Math.max(MIN_PENDING_TTL_MS, Math.max(0, Math.trunc(Number(req.maxTimeoutSeconds))) * 1000))
    const isLocal = local !== null
    try {
      await this.store.update(ctx.channelId, (current) => {
        if (Channels.pendingLive(current, now)) {
          outcome = 'busy'
          return current
        }
        const base = current !== null && current.chargedCumulativeAmount !== undefined && current.chargedCumulativeAmount !== null ? String(current.chargedCumulativeAmount) : Server.inferCharged(raw.voucher.maxClaimableAmount, req.amount)
        const expected = BigInt(base) + Verify.uint(req.amount)
        if (Verify.uint(raw.voucher.maxClaimableAmount) !== expected) {
          outcome = 'stale'
          record = current !== null ? current : Server.provisional(raw, base, now)
          return current
        }
        const next = {
          channelId: raw.voucher.channelId,
          channelConfig: raw.channelConfig,
          chargedCumulativeAmount: base,
          signedMaxClaimable: raw.voucher.maxClaimableAmount,
          signature: raw.voucher.signature,
          balance: ex.balance !== undefined && ex.balance !== null ? String(ex.balance) : '0',
          totalClaimed: ex.totalClaimed !== undefined && ex.totalClaimed !== null ? String(ex.totalClaimed) : '0',
          withdrawRequestedAt: ex.withdrawRequestedAt !== undefined && ex.withdrawRequestedAt !== null ? Math.trunc(Number(ex.withdrawRequestedAt)) : 0,
          refundNonce: ex.refundNonce !== undefined && ex.refundNonce !== null ? Math.trunc(Number(ex.refundNonce)) : 0,
        }
        const synced = isLocal ? (current !== null && current.onchainSyncedAt !== undefined && current.onchainSyncedAt !== null ? current.onchainSyncedAt : null) : now
        if (synced !== null) next.onchainSyncedAt = synced
        next.lastRequestTimestamp = now
        next.pendingRequest = { pendingId, signedMaxClaimable: raw.voucher.maxClaimableAmount, expiresAt }
        for (const [k, v] of Object.entries(current ?? {})) if (!SDK_FIELDS.includes(k)) next[k] = v
        outcome = 'reserved'
        record = next
        return next
      })
    } catch {
      return Verify.invalid(Reason.VERIFICATION_STATE_UNAVAILABLE)
    }
    if (outcome === 'busy') return Verify.invalid(Reason.CHANNEL_BUSY)
    if (outcome === 'stale') {
      ctx.snapshot = record
      return Verify.invalid(MISMATCH)
    }
    ctx.snapshot = record
    return result
  }

  release(hold) {
    return this.channels.release(hold.channelId, hold.pendingId)
  }

  async settleFailed(hold, reason) {
    await this.release(hold)
    const accepts = this.accepts(hold.micro)
    const doc = Server.document(hold.resource, 'settle_failed', accepts)
    return Server.refuse(402, {
      error: 'settle_failed',
      code: 'settle_failed',
      message: 'the payment did not settle. Not delivered; nothing was charged.',
      reason: String(reason).slice(0, 200),
      accepts,
    }, { 'PAYMENT-REQUIRED': Json.base64(doc) })
  }

  static delivered(response) {
    return { ok: true, status: 200, response, headers: { 'PAYMENT-RESPONSE': Json.base64(response) } }
  }

  static chargeOf(hold, charge) {
    const reserved = Verify.uint(hold.requirement.amount)
    if (charge === null || charge === undefined) return reserved
    const c = BigInt(charge)
    if (c < 0n || c > reserved) throw new RangeError(`a charge of ${c} is outside 0 to the reserved ${reserved}`)
    return c
  }

  static chargedExtra(hold, charged, extra = {}) {
    const reserved = String(hold.requirement.amount)
    return { ...extra, chargedAmount: String(charged), reservedAmount: reserved }
  }

  async settle(hold, charge = null) {
    try {
      const c = Server.chargeOf(hold, charge)
      if (hold.kind === 'voucher') return await this.settleVoucher(hold, c)
      if (hold.kind === 'deposit') return await this.settleDeposit(hold, c)
      return await this.settleFailed(hold, Reason.PAYLOAD_TYPE)
    } catch (e) {
      return this.settleFailed(hold, message(e))
    }
  }

  async settleVoucher(hold, increment = Verify.uint(hold.requirement.amount)) {
    const req = hold.requirement
    const voucher = hold.payload.payload.voucher
    const cap = Verify.uint(voucher.maxClaimableAmount)
    const pendingId = hold.pendingId
    const now = this.cfg.nowMs()
    let outcome = null
    let previous = null
    let charged = null
    await this.store.update(hold.channelId, (current) => {
      if (current === null) {
        outcome = 'missing'
        return current
      }
      if (current.pendingRequest?.pendingId === undefined || current.pendingRequest.pendingId !== pendingId) {
        outcome = 'pending_mismatch'
        return current
      }
      const next = BigInt(String(current.chargedCumulativeAmount)) + increment
      if (next > cap) {
        outcome = 'cap_exceeded'
        delete current.pendingRequest
        return current
      }
      previous = clone(current)
      charged = next.toString()
      current.chargedCumulativeAmount = charged
      current.signedMaxClaimable = voucher.maxClaimableAmount
      current.signature = voucher.signature
      current.lastRequestTimestamp = now
      delete current.pendingRequest
      outcome = 'committed'
      return current
    })
    if (outcome === 'missing') return this.settleFailed(hold, Reason.MISSING_CHANNEL)
    if (outcome === 'cap_exceeded') return this.settleFailed(hold, Reason.CHARGE_EXCEEDS_SIGNED_CUMULATIVE)
    if (outcome !== 'committed') return this.settleFailed(hold, Reason.CHANNEL_BUSY)
    return Server.delivered({
      success: true,
      payer: String(previous.channelConfig.payer).toLowerCase(),
      transaction: '',
      network: req.network,
      amount: '',
      extra: Server.chargedExtra(hold, increment, { channelState: Server.channelState(previous, charged) }),
    })
  }

  async settleDeposit(hold, increment = Verify.uint(hold.requirement.amount)) {
    const req = hold.requirement
    const payload = hold.payload
    const raw = payload.payload
    const channelId = hold.channelId
    await this.channels.repaired(channelId)
    const floor = await this.gasQuote.depositFloorMicroUSD(this.micro(hold.micro))
    const quoted = Math.trunc(Number(this.cfg.microUSDOf(req.asset, req.amount)))
    const arriving = Math.trunc(Number(this.cfg.microUSDOf(req.asset, Verify.uint(raw.deposit.amount).toString())))
    if (quoted + 1 < this.micro(hold.micro) || arriving + 1 < floor) {
      return this.settleFailed(hold, `every deposit is at least ${floor} micro-USD. Pay the accepts funding row with at least that.`)
    }
    const verified = await this.verify.facilitator(payload, req)
    if (!verified.isValid) return this.settleFailed(hold, verified.invalidReason ?? Reason.PAYLOAD_TYPE)
    const execution = verified.execution
    const amount = Verify.uint(raw.deposit.amount).toString()
    const data = BatchSettlement.encodeDeposit(raw.channelConfig, amount, execution.collector, execution.collectorData)
    const sent = await this.relay.send([{ to: BatchSettlement.ESCROW, data }], this.cfg.relaySplit())
    if (sent[0]?.hash === undefined) return this.settleFailed(hold, `${Reason.DEPOSIT_TRANSACTION_FAILED}: ${sent[0]?.error ?? 'no hash'}`)
    const hash = sent[0].hash
    const receipt = await this.waitForReceipt(hash)
    if (receipt === null) return this.settleFailed(hold, `${Reason.DEPOSIT_TRANSACTION_FAILED}: no receipt for ${hash}`)
    if (receipt.status === undefined || receipt.status === null || String(receipt.status).toLowerCase() !== '0x1') return this.settleFailed(hold, `${Reason.DEPOSIT_TRANSACTION_FAILED}: transaction reverted (receipt status reverted)`)
    const ex = verified.extra
    let state = {
      channelId: raw.voucher.channelId,
      balance: (BigInt(String(ex.balance)) + BigInt(amount)).toString(),
      totalClaimed: String(ex.totalClaimed),
      withdrawRequestedAt: Math.trunc(Number(ex.withdrawRequestedAt)),
      refundNonce: String(ex.refundNonce),
    }
    const expected = BigInt(state.balance)
    const deadline = this.cfg.nowMs() + this.cfg.stateCatchUpMs
    let post = null
    for (;;) {
      try {
        post = await this.chain.state(channelId)
      } catch {
        post = null
      }
      if (post !== null && BigInt(post.balance) >= expected) break
      if (this.cfg.nowMs() >= deadline) {
        post = null
        break
      }
      await this.cfg.sleepMs(150)
    }
    if (post !== null) state = { channelId: raw.voucher.channelId, balance: post.balance, totalClaimed: post.totalClaimed, withdrawRequestedAt: post.withdrawRequestedAt, refundNonce: post.refundNonce }
    const pendingId = hold.pendingId
    const now = this.cfg.nowMs()
    let record = null
    await this.store.update(channelId, (current) => {
      if (current === null || current.pendingRequest?.pendingId === undefined || current.pendingRequest.pendingId !== pendingId) return current
      const next = {
        channelId: raw.voucher.channelId,
        channelConfig: raw.channelConfig,
        chargedCumulativeAmount: (BigInt(String(current.chargedCumulativeAmount)) + increment).toString(),
        signedMaxClaimable: raw.voucher.maxClaimableAmount,
        signature: raw.voucher.signature,
        balance: state.balance,
        totalClaimed: state.totalClaimed,
        withdrawRequestedAt: Math.trunc(Number(state.withdrawRequestedAt)),
        refundNonce: Math.trunc(Number(state.refundNonce)),
        onchainSyncedAt: now,
        lastRequestTimestamp: now,
      }
      for (const [k, v] of Object.entries(current)) if (!SDK_FIELDS.includes(k)) next[k] = v
      next.depositsWePaid = (current.depositsWePaid !== undefined ? Math.trunc(Number(current.depositsWePaid)) : 0) + 1
      record = next
      return next
    })
    if (record === null) return this.settleFailed(hold, `${Reason.CHANNEL_BUSY}: deposit ${hash} landed after the hold expired. Not charged, not delivered.`)
    const extra = Server.chargedExtra(hold, increment, { channelState: { ...state, chargedCumulativeAmount: record.chargedCumulativeAmount } })
    const response = { success: true, transaction: hash, network: req.network, payer: raw.channelConfig.payer, amount: raw.deposit.amount, extra }
    if (String(response.amount) !== String(req.amount)) {
      response.extra.depositAmount = response.amount
      response.amount = String(req.amount)
    }
    return Server.delivered(response)
  }

  async waitForReceipt(hash) {
    const deadline = this.cfg.nowMs() + 1000 * this.cfg.receiptTimeoutSecs
    for (;;) {
      let r
      try {
        r = await this.chain.receipt(hash)
      } catch {
        r = null
      }
      if (r !== null) return r
      if (this.cfg.nowMs() >= deadline) return null
      await this.cfg.sleepMs(this.cfg.receiptPollMs)
    }
  }
}

