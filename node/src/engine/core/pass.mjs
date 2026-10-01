import { Abi } from './abi.mjs'
import { Address, Hex, InvalidArgument, Keccak, Num, isNumeric } from './hex.mjs'
import { Secp256k1 } from './secp256k1.mjs'

const SPLIT = '(address[],uint256[],uint256,uint16)'

export const Splits = {
  PULL_SPLIT_FACTORY: '0x6B9118074aB15142d7524E8c4ea8f62A3Bdb98f1',
  WAREHOUSE: '0x8fb66F38cF86A3d5e8768f8F1754A24A6c661Fb8',
  SPLIT_WALLET_IMPLEMENTATION: '0x98254AeDb6B2c30b70483064367f0BA24ca86244',
  TOTAL: 1000000,
  FEE_PPM: 99900,
  REALM: 'ZEAM Pass',
  SPLIT,

  feeSplit(payout, fee, feePpm = Splits.FEE_PPM) {
    const p = Address.checksum(payout)
    const f = Address.checksum(fee)
    if (p === f) throw new InvalidArgument('the payout and the fee recipient must differ')
    return { recipients: [p, f], allocations: [String(Splits.TOTAL - Math.trunc(feePpm)), String(Math.trunc(feePpm))], totalAllocation: String(Splits.TOTAL), distributionIncentive: 0 }
  },
  wholeSplit(fee) {
    return { recipients: [Address.checksum(fee)], allocations: [String(Splits.TOTAL)], totalAllocation: String(Splits.TOTAL), distributionIncentive: 0 }
  },
  saltFor(realm, name) {
    return Keccak.utf8(`${realm} seller ${name}`)
  },
  splitTuple(split) {
    return [split.recipients.map((a) => Address.checksum(a)), split.allocations.map((a) => Num.dec(a)), Num.dec(split.totalAllocation), Number(Num.dec(split.distributionIncentive))]
  },
  create2Salt(split, owner, salt) {
    const encoded = Abi.encode([SPLIT, 'address'], [Splits.splitTuple(split), Address.checksum(owner)])
    return Keccak.hashHex(Hex.concat([encoded, salt]))
  },
  cloneInitCode(implementation = Splits.SPLIT_WALLET_IMPLEMENTATION) {
    return '0x60593d8160093d39f336602c57343d527f' + '9e4ac34f21c619cefc926c8bd93b54bf5a39c7ab2127a895af1cc0691d7e3dff' + '593da1005b3d3d3d3d363d3d37363d73' + Address.checksum(implementation).slice(2).toLowerCase() + '5af43d3d93803e605757fd5bf3' + '00'.repeat(15)
  },
  create2Address(deployer, salt, initCode) {
    const hash = Keccak.hashHex(Hex.concat(['0xff', Address.checksum(deployer), salt, Keccak.hashHex(initCode)]))
    return Address.checksum('0x' + hash.slice(-40))
  },
  predictAddress(split, owner, salt) {
    return Splits.create2Address(Splits.PULL_SPLIT_FACTORY, Splits.create2Salt(split, owner, salt), Splits.cloneInitCode())
  },
  encodeCreateSplitDeterministic(split, owner, creator, salt) {
    return Abi.encodeCall(`createSplitDeterministic(${SPLIT},address,address,bytes32)`, [Splits.splitTuple(split), Address.checksum(owner), Address.checksum(creator), salt])
  },
  encodeIsDeployed(split, owner, salt) {
    return Abi.encodeCall(`isDeployed(${SPLIT},address,bytes32)`, [Splits.splitTuple(split), Address.checksum(owner), salt])
  },
  decodeIsDeployed(data) {
    const [address, deployed] = Abi.decode(['address', 'bool'], data)
    return { address, deployed }
  },
  encodePredictDeterministicAddress(split, owner, salt) {
    return Abi.encodeCall(`predictDeterministicAddress(${SPLIT},address,bytes32)`, [Splits.splitTuple(split), Address.checksum(owner), salt])
  },
  decodeAddress(data) {
    return Abi.decode(['address'], data)[0]
  },
  encodeDistribute(split, token, distributor) {
    return Abi.encodeCall(`distribute(${SPLIT},address,address)`, [Splits.splitTuple(split), Address.checksum(token), Address.checksum(distributor)])
  },
  encodeGetSplitBalance(token) {
    return Abi.encodeCall('getSplitBalance(address)', [Address.checksum(token)])
  },
  decodeGetSplitBalance(data) {
    const [splitBalance, warehouseBalance] = Abi.decode(['uint256', 'uint256'], data)
    return { splitBalance, warehouseBalance }
  },
  encodeWarehouseWithdraw(owner, token) {
    return Abi.encodeCall('withdraw(address,address)', [Address.checksum(owner), Address.checksum(token)])
  },
  tokenId(token) {
    return BigInt(Address.checksum(token)).toString(10)
  },
  encodeWarehouseBalanceOf(owner, tokenId) {
    const id = Address.isAddress(tokenId) ? Splits.tokenId(tokenId) : tokenId
    return Abi.encodeCall('balanceOf(address,uint256)', [Address.checksum(owner), id])
  },
}

const phpStrtotime = (text) => {
  const s = String(text).trim()
  if (s === '') return false
  if (/^@-?\d+$/.test(s)) return Number(s.slice(1))
  let t = Date.parse(s)
  if (Number.isNaN(t) && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(s)) t = Date.parse(s.replace(' ', 'T') + 'Z')
  return Number.isNaN(t) ? false : Math.floor(t / 1000)
}

export const Pass = {
  REALM: 'ZEAM Pass',
  strtotime: phpStrtotime,

  grantMessage(realm, grant) {
    const scope = Object.hasOwn(grant, 'scope') && grant.scope !== null && grant.scope !== undefined ? grant.scope : 'self'
    let message = `${realm} grant\ndelegate: ${String(grant.delegate ?? '').toLowerCase()}\nscope: ${String(scope).toLowerCase()}\nuntil: ${grant.until ?? ''}`
    if (grant.budget !== undefined && grant.budget !== null && grant.budget !== '') message += `\nbudget: ${grant.budget}`
    return message
  },
  grantId(message) {
    return Keccak.utf8(message)
  },
  budgetMs(budget) {
    if (budget === null || budget === undefined || budget === '') return null
    const m = /^(\d+(?:\.\d+)?)\s*(ms|s|m|h|d)?$/.exec(String(budget).trim())
    if (!m) return false
    const units = { ms: 1, s: 1000, m: 60000, h: 3600000, d: 86400000 }
    return Math.floor(Number(m[1]) * (m[2] ? units[m[2]] : 1))
  },
  signGrant(key, realm, grant) {
    return Secp256k1.personalSign(key, Pass.grantMessage(realm, grant))
  },
  async verifyGrant(realm, grant, nowMs = null) {
    if (grant === null || typeof grant !== 'object') return { ok: false, why: 'no grant' }
    const e = (v) => v === undefined || v === null || v === false || v === '' || v === 0 || v === '0'
    if (e(grant.delegate) || e(grant.until) || e(grant.signature)) return { ok: false, why: 'a grant needs delegate, until and signature' }
    const budget = grant.budget !== undefined && grant.budget !== null ? grant.budget : null
    const ms = Pass.budgetMs(budget)
    if (ms === false) return { ok: false, why: 'budget is not a duration (250ms, 30s, 2h, 1d)' }
    const t = phpStrtotime(grant.until)
    if (t === false) return { ok: false, why: 'until is not a timestamp' }
    const now = nowMs === null ? Date.now() : Math.trunc(nowMs)
    if (t * 1000 <= now) return { ok: false, why: `grant expired at ${grant.until}` }
    const scope = grant.scope !== undefined && grant.scope !== null ? grant.scope : 'self'
    const message = Pass.grantMessage(realm, { delegate: grant.delegate, scope, until: grant.until, budget })
    let root
    try {
      root = (await Secp256k1.recoverPersonal(message, grant.signature)).toLowerCase()
    } catch (err) {
      if (!(err instanceof InvalidArgument)) throw err
      return { ok: false, why: 'bad signature: ' + String(err.message).slice(0, 80) }
    }
    return { ok: true, root, delegate: String(grant.delegate).toLowerCase(), scope: String(scope).toLowerCase(), until: grant.until, budget, budgetMs: ms, message, id: Pass.grantId(message) }
  },
  noteMessage(note) {
    return `ZEAM Pass gate credit\nid: ${note.id}\nseller: ${String(note.seller).toLowerCase()}\nname: ${note.name}\nchecks: ${note.checks}\nissued: ${note.issued}`
  },
  signNote(key, note) {
    return Secp256k1.personalSign(key, Pass.noteMessage(note))
  },
  async verifyNote(note, issuer) {
    const e = (v) => v === undefined || v === null || v === false || v === '' || v === 0 || v === '0'
    if (note === null || typeof note !== 'object' || e(note.id) || e(note.signature) || !(isNumeric(note.checks) && Number(note.checks) > 0)) return { ok: false, why: 'not a credit note' }
    return (await Secp256k1.verifyPersonal(issuer, Pass.noteMessage(note), note.signature)) ? { ok: true, checks: Math.trunc(Number(note.checks)) } : { ok: false, why: 'the note is not signed by ZEAM' }
  },
  sellerSplit(payout, fee, name, whole = false) {
    if (whole && Address.checksum(payout) !== Address.checksum(fee)) throw new InvalidArgument('payoutIsFeeRecipient needs the payout to be the fee recipient')
    const split = whole ? Splits.wholeSplit(fee) : Splits.feeSplit(payout, fee)
    const salt = Splits.saltFor(Pass.REALM, name)
    return { split, salt, owner: Address.ZERO, address: Splits.predictAddress(split, Address.ZERO, salt) }
  },
}
