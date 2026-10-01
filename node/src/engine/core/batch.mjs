import { Abi } from './abi.mjs'
import { Address, Hex, InvalidArgument, Keccak, Num, has } from './hex.mjs'
import { Secp256k1, TypedData } from './secp256k1.mjs'

const BATCH_SETTLEMENT_ADDRESS = '0x4020074e9dF2ce1deE5A9C1b5c3f541D02a10003'
const ERC3009_DEPOSIT_COLLECTOR_ADDRESS = '0x4020806089470a89826cB9fB1f4059150b550004'
const BATCH_SETTLEMENT_DOMAIN = { name: 'x402 Batch Settlement', version: '1' }
const fields = (...f) => f.map(([name, type]) => ({ name, type }))
const AUTHORIZATION = fields(['from', 'address'], ['to', 'address'], ['value', 'uint256'], ['validAfter', 'uint256'], ['validBefore', 'uint256'], ['nonce', 'bytes32'])
const channelConfigTypes = { ChannelConfig: fields(['payer', 'address'], ['payerAuthorizer', 'address'], ['receiver', 'address'], ['receiverAuthorizer', 'address'], ['token', 'address'], ['withdrawDelay', 'uint40'], ['salt', 'bytes32']) }
const voucherTypes = { Voucher: fields(['channelId', 'bytes32'], ['maxClaimableAmount', 'uint128']) }
const refundTypes = { Refund: fields(['channelId', 'bytes32'], ['nonce', 'uint256'], ['amount', 'uint128']) }
const claimBatchTypes = { ClaimBatch: fields(['claims', 'ClaimEntry[]']), ClaimEntry: fields(['channelId', 'bytes32'], ['maxClaimableAmount', 'uint128'], ['totalClaimed', 'uint128']) }
const authorizationTypes = { TransferWithAuthorization: AUTHORIZATION }

const CONFIG = '(address,address,address,address,address,uint40,bytes32)'
const VOUCHER_CLAIMS = '(((address,address,address,address,address,uint40,bytes32),uint128),bytes,uint128)[]'
const ERC6492_MAGIC = '6492649264926492649264926492649264926492649264926492649264926492'

const RECEIVE_AUTHORIZATION_TYPES = {
  ReceiveWithAuthorization: authorizationTypes.TransferWithAuthorization,
}

export const Erc20 = {
  USDC_BASE: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
  USDC_BASE_NAME: 'USD Coin',
  USDC_BASE_VERSION: '2',
  BASE_CHAIN_ID: 8453,
  encodeBalanceOf(owner) {
    return Abi.encodeCall('balanceOf(address)', [Address.checksum(owner)])
  },
  decodeUint256(data) {
    return Abi.decode(['uint256'], data)[0]
  },
}

export const BatchSettlement = {
  ESCROW: BATCH_SETTLEMENT_ADDRESS,
  ERC3009_DEPOSIT_COLLECTOR: ERC3009_DEPOSIT_COLLECTOR_ADDRESS,
  MULTICALL3: '0xcA11bde05977b3631167028862bE2a173976CA11',
  PERMIT2_DEPOSIT_COLLECTOR: '0x4020425FAf3B746C082C2f942b4E5159887B0005',
  DOMAIN_NAME: BATCH_SETTLEMENT_DOMAIN.name,
  DOMAIN_VERSION: BATCH_SETTLEMENT_DOMAIN.version,
  MIN_WITHDRAW_DELAY: 900,
  MAX_WITHDRAW_DELAY: 2592000,
  CONFIG,
  VOUCHER_CLAIMS,
  CHANNEL_CONFIG_TYPES: channelConfigTypes,
  VOUCHER_TYPES: voucherTypes,
  REFUND_TYPES: refundTypes,
  CLAIM_BATCH_TYPES: claimBatchTypes,
  RECEIVE_AUTHORIZATION_TYPES,
  TRANSFER_AUTHORIZATION_TYPES: authorizationTypes,

  domain(chainId) {
    return { name: BATCH_SETTLEMENT_DOMAIN.name, version: BATCH_SETTLEMENT_DOMAIN.version, chainId: Number(chainId), verifyingContract: Address.checksum(BATCH_SETTLEMENT_ADDRESS) }
  },
  config(config) {
    for (const k of ['payer', 'payerAuthorizer', 'receiver', 'receiverAuthorizer', 'token', 'withdrawDelay', 'salt']) {
      if (!has(config, k)) throw new InvalidArgument(`channel config is missing ${k}`)
    }
    return {
      payer: Address.checksum(config.payer),
      payerAuthorizer: Address.checksum(config.payerAuthorizer),
      receiver: Address.checksum(config.receiver),
      receiverAuthorizer: Address.checksum(config.receiverAuthorizer),
      token: Address.checksum(config.token),
      withdrawDelay: Number(Num.dec(config.withdrawDelay)),
      salt: Hex.lower(config.salt),
    }
  },
  configTuple(config) {
    const c = BatchSettlement.config(config)
    return [c.payer, c.payerAuthorizer, c.receiver, c.receiverAuthorizer, c.token, c.withdrawDelay, c.salt]
  },
  channelId(config, chainId) {
    const c = BatchSettlement.config(config)
    if (!Hex.isHex(c.salt, 32)) throw new InvalidArgument('channel config salt is not 32 bytes')
    try {
      return TypedData.hash(BatchSettlement.domain(chainId), channelConfigTypes, 'ChannelConfig', c)
    } catch (e) {
      throw new InvalidArgument(e.shortMessage ?? e.message)
    }
  },
  channelIdMatches(config, channelId, chainId) {
    return Hex.isHex(channelId, 32) && BatchSettlement.channelId(config, chainId).toLowerCase() === channelId.toLowerCase()
  },
  validateChannelConfig(config, channelId, chainId, payTo, receiverAuthorizer, asset, withdrawDelay = null) {
    const c = BatchSettlement.config(config)
    if (!BatchSettlement.channelIdMatches(c, channelId, chainId)) return 'invalid_batch_settlement_evm_channel_id_mismatch'
    if (!Address.equals(c.receiver, payTo)) return 'invalid_batch_settlement_evm_receiver_mismatch'
    if (!receiverAuthorizer || Address.equals(receiverAuthorizer, Address.ZERO) || !Address.equals(c.receiverAuthorizer, receiverAuthorizer)) return 'invalid_batch_settlement_evm_receiver_authorizer_mismatch'
    if (!Address.equals(c.token, asset)) return 'invalid_batch_settlement_evm_token_mismatch'
    if (withdrawDelay !== null && c.withdrawDelay !== Math.trunc(Number(withdrawDelay))) return 'invalid_batch_settlement_evm_withdraw_delay_mismatch'
    if (c.withdrawDelay < BatchSettlement.MIN_WITHDRAW_DELAY || c.withdrawDelay > BatchSettlement.MAX_WITHDRAW_DELAY) return 'invalid_batch_settlement_evm_withdraw_delay_out_of_range'
    return null
  },
  voucherDigest(channelId, maxClaimableAmount, chainId) {
    return TypedData.hash(BatchSettlement.domain(chainId), voucherTypes, 'Voucher', { channelId, maxClaimableAmount })
  },
  signVoucher(key, channelId, maxClaimableAmount, chainId) {
    return Secp256k1.signHash(key, BatchSettlement.voucherDigest(channelId, maxClaimableAmount, chainId))
  },
  recoverVoucher(channelId, maxClaimableAmount, signature, chainId) {
    return Secp256k1.recoverHash(BatchSettlement.voucherDigest(channelId, maxClaimableAmount, chainId), signature)
  },
  async verifyVoucher(config, channelId, maxClaimableAmount, signature, chainId) {
    const c = BatchSettlement.config(config)
    const expected = Address.equals(c.payerAuthorizer, Address.ZERO) ? c.payer : c.payerAuthorizer
    try {
      return Address.equals(await BatchSettlement.recoverVoucher(channelId, maxClaimableAmount, signature, chainId), expected)
    } catch (e) {
      if (e instanceof InvalidArgument) return false
      throw e
    }
  },
  claimEntries(claims, chainId) {
    return claims.map((c) => ({
      channelId: BatchSettlement.channelId(c.voucher.channel, chainId),
      maxClaimableAmount: Num.dec(c.voucher.maxClaimableAmount),
      totalClaimed: Num.dec(c.totalClaimed),
    }))
  },
  claimBatchDigest(claims, chainId) {
    return TypedData.hash(BatchSettlement.domain(chainId), claimBatchTypes, 'ClaimBatch', { claims: BatchSettlement.claimEntries(claims, chainId) })
  },
  signClaimBatch(key, claims, chainId) {
    return Secp256k1.signHash(key, BatchSettlement.claimBatchDigest(claims, chainId))
  },
  refundDigest(channelId, nonce, amount, chainId) {
    return TypedData.hash(BatchSettlement.domain(chainId), refundTypes, 'Refund', { channelId, nonce, amount })
  },
  signRefund(key, channelId, amount, nonce, chainId) {
    return Secp256k1.signHash(key, BatchSettlement.refundDigest(channelId, nonce, amount, chainId))
  },
  erc3009DepositNonce(channelId, salt) {
    return Keccak.hashHex(Abi.encode(['bytes32', 'uint256'], [channelId, salt]))
  },
  erc3009CollectorData(validAfter, validBefore, salt, signature) {
    return Abi.encode(['uint256', 'uint256', 'uint256', 'bytes'], [validAfter, validBefore, salt, signature])
  },
  permit2CollectorData(nonce, deadline, permit2Signature, eip2612PermitData = '0x') {
    return Abi.encode(['uint256', 'uint256', 'bytes', 'bytes'], [nonce, deadline, permit2Signature, eip2612PermitData])
  },
  tokenDomain(asset, name, version, chainId) {
    return { name: String(name), version: String(version), chainId: Math.trunc(Number(chainId)), verifyingContract: Address.checksum(asset) }
  },
  receiveAuthorization(payer, amount, validAfter, validBefore, nonce) {
    return {
      from: Address.checksum(payer),
      to: Address.checksum(ERC3009_DEPOSIT_COLLECTOR_ADDRESS),
      value: Num.dec(amount),
      validAfter: Num.dec(validAfter),
      validBefore: Num.dec(validBefore),
      nonce,
    }
  },
  receiveAuthorizationDigest(tokenDomain, authorization) {
    return TypedData.hash(tokenDomain, RECEIVE_AUTHORIZATION_TYPES, 'ReceiveWithAuthorization', authorization)
  },
  signReceiveAuthorization(key, tokenDomain, authorization) {
    return Secp256k1.signHash(key, BatchSettlement.receiveAuthorizationDigest(tokenDomain, authorization))
  },
  transferAuthorizationTypedData(tokenDomain, authorization) {
    const d = tokenDomain
    const a = authorization
    return {
      domain: { name: String(d.name), version: String(d.version), chainId: Math.trunc(Number(d.chainId)), verifyingContract: Address.checksum(d.verifyingContract) },
      types: { TransferWithAuthorization: authorizationTypes.TransferWithAuthorization.map((f) => ({ name: f.name, type: f.type })) },
      primaryType: 'TransferWithAuthorization',
      message: { from: Address.checksum(a.from), to: Address.checksum(a.to), value: Num.dec(a.value), validAfter: Num.dec(a.validAfter), validBefore: Num.dec(a.validBefore), nonce: Hex.lower(a.nonce) },
    }
  },
  transferAuthorizationDigest(tokenDomain, authorization) {
    return TypedData.hash(tokenDomain, authorizationTypes, 'TransferWithAuthorization', authorization)
  },
  signTransferAuthorization(key, tokenDomain, authorization) {
    return Secp256k1.signHash(key, BatchSettlement.transferAuthorizationDigest(tokenDomain, authorization))
  },
  parseErc6492Signature(signature) {
    const hex = Hex.lower(signature)
    if (hex.length < 66 || hex.slice(-64) !== ERC6492_MAGIC) return { address: null, data: null, signature: hex }
    const [address, data, inner] = Abi.decode(['address', 'bytes', 'bytes'], '0x' + hex.slice(2, -64))
    return { address, data, signature: inner }
  },
  async verifyErc3009Deposit(config, amount, authorization, asset, name, version, chainId) {
    const c = BatchSettlement.config(config)
    const parsed = BatchSettlement.parseErc6492Signature(authorization.signature)
    const nonce = BatchSettlement.erc3009DepositNonce(BatchSettlement.channelId(c, chainId), authorization.salt)
    const digest = BatchSettlement.receiveAuthorizationDigest(
      BatchSettlement.tokenDomain(asset, name, version, chainId),
      BatchSettlement.receiveAuthorization(c.payer, amount, authorization.validAfter, authorization.validBefore, nonce),
    )
    try {
      return Address.equals(await Secp256k1.recoverHash(digest, parsed.signature), c.payer)
    } catch (e) {
      if (e instanceof InvalidArgument) return false
      throw e
    }
  },
  erc3009DepositCollectorData(authorization) {
    const parsed = BatchSettlement.parseErc6492Signature(authorization.signature)
    return BatchSettlement.erc3009CollectorData(authorization.validAfter, authorization.validBefore, authorization.salt, parsed.signature)
  },
  voucherClaimTuples(claims) {
    return claims.map((c) => [[BatchSettlement.configTuple(c.voucher.channel), Num.dec(c.voucher.maxClaimableAmount)], Hex.lower(c.signature), Num.dec(c.totalClaimed)])
  },
  encodeDeposit(config, amount, collector, collectorData) {
    return Abi.encodeCall(`deposit(${CONFIG},uint128,address,bytes)`, [BatchSettlement.configTuple(config), amount, Address.checksum(collector), collectorData])
  },
  encodeErc3009Deposit(config, amount, authorization) {
    return BatchSettlement.encodeDeposit(config, amount, ERC3009_DEPOSIT_COLLECTOR_ADDRESS, BatchSettlement.erc3009DepositCollectorData(authorization))
  },
  encodeClaim(claims) {
    return Abi.encodeCall(`claim(${VOUCHER_CLAIMS})`, [BatchSettlement.voucherClaimTuples(claims)])
  },
  encodeClaimWithSignature(claims, authorizerSignature) {
    return Abi.encodeCall(`claimWithSignature(${VOUCHER_CLAIMS},bytes)`, [BatchSettlement.voucherClaimTuples(claims), authorizerSignature])
  },
  encodeRefundWithSignature(config, amount, nonce, receiverAuthorizerSignature) {
    return Abi.encodeCall(`refundWithSignature(${CONFIG},uint128,uint256,bytes)`, [BatchSettlement.configTuple(config), amount, nonce, receiverAuthorizerSignature])
  },
  encodeSettle(receiver, token) {
    return Abi.encodeCall('settle(address,address)', [Address.checksum(receiver), Address.checksum(token)])
  },
  encodeMulticall(calls) {
    return Abi.encodeCall('multicall(bytes[])', [[...calls]])
  },
  encodeTransferWithAuthorization(authorization, signature) {
    const a = authorization
    return Abi.encodeCall('transferWithAuthorization(address,address,uint256,uint256,uint256,bytes32,bytes)', [Address.checksum(a.from), Address.checksum(a.to), Num.dec(a.value), Num.dec(a.validAfter), Num.dec(a.validBefore), Hex.lower(a.nonce), Hex.lower(signature)])
  },
  encodeAggregate3(calls) {
    return Abi.encodeCall('aggregate3((address,bool,bytes)[])', [calls.map((c) => [Address.checksum(c.to), false, Hex.lower(c.data)])])
  },
  encodeChannels(channelId) {
    return Abi.encodeCall('channels(bytes32)', [channelId])
  },
  decodeChannels(data) {
    const [balance, totalClaimed] = Abi.decode(['uint128', 'uint128'], data)
    return { balance, totalClaimed }
  },
  encodeReceivers(receiver, token) {
    return Abi.encodeCall('receivers(address,address)', [Address.checksum(receiver), Address.checksum(token)])
  },
  decodeReceivers(data) {
    const [totalClaimed, totalSettled] = Abi.decode(['uint128', 'uint128'], data)
    return { totalClaimed, totalSettled }
  },
  encodeRefundNonce(channelId) {
    return Abi.encodeCall('refundNonce(bytes32)', [channelId])
  },
  decodeRefundNonce(data) {
    return Abi.decode(['uint256'], data)[0]
  },
  encodePendingWithdrawals(channelId) {
    return Abi.encodeCall('pendingWithdrawals(bytes32)', [channelId])
  },
  decodePendingWithdrawals(data) {
    const [amount, initiatedAt] = Abi.decode(['uint128', 'uint40'], data)
    return { amount, initiatedAt }
  },
  encodeGetChannelId(config) {
    return Abi.encodeCall(`getChannelId(${CONFIG})`, [BatchSettlement.configTuple(config)])
  },
  encodeGetVoucherDigest(channelId, maxClaimableAmount) {
    return Abi.encodeCall('getVoucherDigest(bytes32,uint128)', [channelId, maxClaimableAmount])
  },
  encodeGetRefundDigest(channelId, nonce, amount) {
    return Abi.encodeCall('getRefundDigest(bytes32,uint256,uint128)', [channelId, nonce, amount])
  },
  encodeGetClaimBatchDigest(claims) {
    return Abi.encodeCall(`getClaimBatchDigest(${VOUCHER_CLAIMS})`, [BatchSettlement.voucherClaimTuples(claims)])
  },
  decodeBytes32(data) {
    return Abi.decode(['bytes32'], data)[0]
  },
}
