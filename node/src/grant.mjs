import { Address } from './engine/core/hex.mjs'
import { Pass } from './engine/core/pass.mjs'
import { Secp256k1 } from './engine/core/secp256k1.mjs'
import { GateJson } from './engine/gate/index.mjs'

const untilText = (until) => {
  if (until instanceof Date) {
    if (Number.isNaN(until.getTime())) throw new TypeError('signGrant: until is not a valid date')
    return until.toISOString().replace(/\.\d{3}Z$/, 'Z')
  }
  if (typeof until === 'string' && until.trim() !== '' && !/[\r\n]/.test(until)) return until.trim()
  throw new TypeError('signGrant: until is a Date or an ISO time, like "2026-10-01T00:00:00Z"')
}

export async function signGrant({ key, delegate, scope = '*', until, realm = Pass.REALM, now = Date.now() } = {}) {
  if (typeof key !== 'string' || !/^0x[0-9a-fA-F]{64}$/.test(key)) throw new TypeError('signGrant: key is the private key of a key the seller admits, 0x and 64 hex digits')
  if (typeof delegate !== 'string' || !Address.isAddress(delegate)) throw new TypeError('signGrant: delegate is the address of the key you let in')
  if (typeof scope !== 'string' || scope.trim() === '' || /[\r\n]/.test(scope)) throw new TypeError('signGrant: scope is one tool name, or "*" for every tool')
  const text = untilText(until)
  const t = Pass.strtotime(text)
  if (t === false) throw new TypeError(`signGrant: until is not a time: ${text}`)
  if (t * 1000 <= now) throw new TypeError(`signGrant: until is in the past: ${text}`)
  const grant = { delegate: delegate.toLowerCase(), scope: scope.trim(), until: text }
  const signature = await Pass.signGrant(Secp256k1.normalizePrivateKey(key), realm, grant)
  return GateJson.base64UrlEncode(JSON.stringify({ ...grant, signature }))
}

