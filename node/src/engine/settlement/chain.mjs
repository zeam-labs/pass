import { http } from 'viem'
import { Abi } from '../core/abi.mjs'
import { Address, Hex, Num, big } from '../core/hex.mjs'
import { BatchSettlement, Erc20 } from '../core/batch.mjs'
import { Splits } from '../core/pass.mjs'

export class RpcError extends Error {
  constructor(message, code = null, data = null) {
    super(message)
    this.code = code
    this.data = data
  }
}

export class Rpc {
  constructor(url, { timeout = 10, fetchFn } = {}) {
    if (!/^https?:\/\//i.test(String(url))) throw new TypeError('an RPC URL starts with http:// or https://')
    this.url = url
    this.transport = http(url, { timeout: timeout * 1000, retryCount: 0, ...(fetchFn ? { fetchFn } : {}) })({ retryCount: 0 })
  }

  async call(method, params = []) {
    try {
      return await this.transport.request({ method, params })
    } catch (e) {
      throw new RpcError(`${method}: ${e.details ?? e.shortMessage ?? e.message}`, e.code ?? null, e.data ?? null)
    }
  }
}

export class Chain {
  static PERMIT2 = '0x000000000022D473030F116dDEE9F6B43aC78BA3'
  static ERC1271_MAGIC = '0x1626ba7e'

  constructor(rpc, options = {}) {
    if (typeof rpc === 'string') rpc = new Rpc(rpc, options)
    if (!rpc || typeof rpc.call !== 'function') throw new TypeError('a chain reader needs an RPC URL or an object with call(method, params)')
    this.rpcClient = rpc
  }

  rpc(method, params = []) {
    return this.rpcClient.call(method, params)
  }

  async call(to, data, from = null) {
    const tx = { to: Address.checksum(to), data: Hex.lower(data) }
    if (from !== null) tx.from = Address.checksum(from)
    return String(await this.rpc('eth_call', [tx, 'latest']))
  }

  async gasPrice() {
    return Num.dec(await this.rpc('eth_gasPrice'))
  }

  async code(address) {
    const code = await this.rpc('eth_getCode', [Address.checksum(address), 'latest'])
    return typeof code === 'string' ? code.toLowerCase() : '0x'
  }

  async hasCode(address) {
    const code = await this.code(address)
    return code !== '0x' && code !== ''
  }

  async channel(channelId) {
    const c = BatchSettlement.decodeChannels(await this.call(BatchSettlement.ESCROW, BatchSettlement.encodeChannels(channelId)))
    return { balance: Num.dec(c.balance), totalClaimed: Num.dec(c.totalClaimed) }
  }

  async pendingWithdrawal(channelId) {
    const w = BatchSettlement.decodePendingWithdrawals(await this.call(BatchSettlement.ESCROW, BatchSettlement.encodePendingWithdrawals(channelId)))
    return { amount: Num.dec(w.amount), initiatedAt: Number(Num.dec(w.initiatedAt)) }
  }

  async refundNonce(channelId) {
    return Num.dec(BatchSettlement.decodeRefundNonce(await this.call(BatchSettlement.ESCROW, BatchSettlement.encodeRefundNonce(channelId))))
  }

  async state(channelId) {
    const c = await this.channel(channelId)
    const w = await this.pendingWithdrawal(channelId)
    return { balance: c.balance, totalClaimed: c.totalClaimed, withdrawRequestedAt: w.initiatedAt, withdrawing: big(w.amount) > 0n, refundNonce: await this.refundNonce(channelId) }
  }

  async live(channelId) {
    const c = await this.channel(channelId)
    const w = await this.pendingWithdrawal(channelId)
    const payable = big(c.balance) - big(c.totalClaimed)
    return { balance: c.balance, claimed: c.totalClaimed, payable: payable < 0n ? '0' : payable.toString(), withdrawing: big(w.amount) > 0n }
  }

  async balanceOf(token, owner) {
    return Num.dec(Erc20.decodeUint256(await this.call(token, Erc20.encodeBalanceOf(owner))))
  }

  async allowance(token, owner, spender) {
    const data = Abi.encodeCall('allowance(address,address)', [Address.checksum(owner), Address.checksum(spender)])
    return Num.dec(Erc20.decodeUint256(await this.call(token, data)))
  }

  async receivers(receiver, token) {
    const r = BatchSettlement.decodeReceivers(await this.call(BatchSettlement.ESCROW, BatchSettlement.encodeReceivers(receiver, token)))
    return { totalClaimed: Num.dec(r.totalClaimed), totalSettled: Num.dec(r.totalSettled) }
  }

  async splitBalance(split, token) {
    const b = Splits.decodeGetSplitBalance(await this.call(split, Splits.encodeGetSplitBalance(token)))
    return (big(Num.dec(b.splitBalance)) + big(Num.dec(b.warehouseBalance))).toString()
  }

  async isValidSignature(address, digest, signature) {
    let out
    try {
      out = await this.call(address, Abi.encodeCall('isValidSignature(bytes32,bytes)', [digest, Hex.lower(signature)]))
    } catch {
      return false
    }
    return out.slice(0, 10).toLowerCase() === Chain.ERC1271_MAGIC
  }

  async receipt(hash) {
    const r = await this.rpc('eth_getTransactionReceipt', [String(hash).toLowerCase()])
    return r !== null && typeof r === 'object' ? r : null
  }
}
