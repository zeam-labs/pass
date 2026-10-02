import { GRANTED_BY, createEngine } from './engine/index.mjs'
import { randomBytes } from 'node:crypto'
import { FreeLimit, describe as describePrice, priceOf, usd } from './engine/pricing.mjs'
import { timeText } from './engine/meter.mjs'
import { StateFile } from './engine/stores.mjs'

const META_PAYMENT = 'x402/payment'
const META_RESPONSE = 'x402/payment-response'
const META_GRANTED_BY = 'zeam-pass/granted-by'
const META_PRICE = 'zeam-pass/price'
const META_LINE = 'zeam-pass/line'
const META_METER = 'zeam-pass/meter'
const LINE_OPS = ['open', 'prove', 'on', 'off', 'status', 'close']
const CHANNEL = /^0x[0-9a-fA-F]{64}$/
const MCP_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']
const CORS = { 'access-control-allow-origin': '*', 'access-control-expose-headers': 'PAYMENT-REQUIRED, PAYMENT-RESPONSE, X-Pass-Granted-By, X-Pass-Ms-Remaining, X-Pass-Ms-Elapsed' }
const HOW = { paywall: 'Paid per call with x402', gate: 'Admitted keys only, proven by a zero-value x402 signature. Nothing is paid.', both: 'Admitted keys only, paid per call with x402' }
const PREFLIGHT = { ...CORS, 'access-control-allow-methods': 'POST, OPTIONS', 'access-control-allow-headers': 'content-type, payment-signature, x-payment, x-line, x-grant, mcp-protocol-version, mcp-session-id', 'access-control-max-age': '86400' }

const b64 = (s) => (typeof Buffer !== 'undefined' ? Buffer.from(s, 'utf8').toString('base64') : btoa(unescape(encodeURIComponent(s))))
const unb64 = (s) => (typeof Buffer !== 'undefined' ? Buffer.from(s, 'base64').toString('utf8') : decodeURIComponent(escape(atob(s))))
const decodeHeader = (v) => { try { return JSON.parse(unb64(String(v))) } catch { return null } }
const json = (status, body, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } })
const kind = (v) => (v === null ? 'null' : Array.isArray(v) ? 'array' : typeof v)
const fits = (v, t) => (t === 'integer' ? Number.isInteger(v) : t === 'number' ? typeof v === 'number' && Number.isFinite(v) : kind(v) === t)
const text = (o) => [{ type: 'text', text: typeof o === 'string' ? o : JSON.stringify(o) }]
const message = (e) => String(e?.message ?? e)
const RESULT_NOT_JSON = 'the result is not valid JSON (a non-finite number or a non-JSON value); nothing was charged'
const said = (out) => (Array.isArray(out?.content) ? out.content.map((c) => c?.text).filter((t) => typeof t === 'string').join('\n') : '') || 'the tool reported an error'

function faithful(v) {
  if (v === null || typeof v === 'string' || typeof v === 'boolean') return true
  if (typeof v === 'number') return Number.isFinite(v)
  if (Array.isArray(v)) {
    for (let i = 0; i < v.length; i++) if (!(i in v) || !faithful(v[i])) return false
    return true
  }
  if (typeof v !== 'object') return false
  const proto = Object.getPrototypeOf(v)
  if ((proto !== Object.prototype && proto !== null) || typeof v.toJSON === 'function') return false
  return Object.values(v).every((x) => x === undefined || faithful(x))
}

function serialize(value) {
  let body
  try { body = JSON.stringify(value) } catch { return null }
  return body === undefined || !faithful(value) ? null : body
}

function checkValue(k, p, v) {
  const types = p.type === undefined ? null : [].concat(p.type)
  if (types && !types.some((t) => fits(v, t))) return `argument ${k} must be ${types.join(' or ')}`
  if (Array.isArray(p.enum) && !p.enum.some((e) => JSON.stringify(e) === JSON.stringify(v))) return `argument ${k} must be one of ${JSON.stringify(p.enum)}`
  if (typeof v === 'number') {
    if (typeof p.minimum === 'number' && v < p.minimum) return `argument ${k} must be at least ${p.minimum}`
    if (typeof p.maximum === 'number' && v > p.maximum) return `argument ${k} must be at most ${p.maximum}`
  }
  if (typeof v === 'string') {
    const n = [...v].length
    if (typeof p.minLength === 'number' && n < p.minLength) return `argument ${k} must be at least ${p.minLength} characters`
    if (typeof p.maxLength === 'number' && n > p.maxLength) return `argument ${k} must be at most ${p.maxLength} characters`
  }
  return null
}

function checkObject(s, args, prefix, nested) {
  for (const k of Array.isArray(s.required) ? s.required : []) if (!Object.hasOwn(args, k)) return `missing required argument: ${prefix}${k}`
  for (const [k, p] of Object.entries(kind(s.properties) === 'object' ? s.properties : {})) {
    if (!Object.hasOwn(args, k) || kind(p) !== 'object') continue
    const bad = checkValue(prefix + k, p, args[k])
    if (bad) return bad
    if (nested && kind(args[k]) === 'object') {
      const inner = checkObject(p, args[k], `${prefix}${k}.`, false)
      if (inner) return inner
    }
  }
  return null
}

function validate(schema, args) {
  if (kind(args) !== 'object') return 'the arguments must be an object'
  return checkObject(kind(schema) === 'object' ? schema : {}, args, '', true)
}


function required(r) {
  const t = decodeHeader(r.headers['PAYMENT-REQUIRED']) ?? (Array.isArray(r.body?.accepts) ? r.body : null)
  return kind(t) === 'object' ? { x402Version: 2, ...t } : null
}

function refusal(r) {
  const terms = required(r)
  if (terms) {
    const doc = { ...(kind(r.body) === 'object' ? r.body : {}), ...terms }
    return { isError: true, structuredContent: doc, content: text(doc) }
  }
  return kind(r.body) === 'object' ? { isError: true, structuredContent: r.body, content: text(r.body) } : { isError: true, content: text(r.body) }
}

function failure(body) {
  return { isError: true, structuredContent: body, content: text(body) }
}

function failed(out, tool) {
  if (kind(out?.structuredContent) === 'object') return out
  return { ...out, isError: true, structuredContent: { error: 'tool_failed', tool, message: said(out) } }
}

function settledMeta(headers) {
  const meta = {}
  const settled = decodeHeader(headers?.['PAYMENT-RESPONSE'])
  if (settled) meta[META_RESPONSE] = settled
  if (typeof headers?.[GRANTED_BY] === 'string') meta[META_GRANTED_BY] = headers[GRANTED_BY]
  return meta
}

const payHeader = (payment, grant = null) => (h) => (h === 'payment-signature' && payment ? b64(JSON.stringify(payment)) : h === 'x-grant' ? grant : null)

function readBody(req) {
  if (req.body !== undefined) return Promise.resolve(typeof req.body === 'string' ? req.body : Buffer.isBuffer(req.body) ? req.body.toString('utf8') : JSON.stringify(req.body))
  return new Promise((ok, fail) => { let b = ''; req.on('data', (d) => { b += d }); req.on('end', () => ok(b)); req.on('error', fail) })
}

async function toRequest(req) {
  const url = `${req.protocol ?? 'http'}://${req.headers.host ?? 'localhost'}${req.originalUrl ?? req.url}`
  const headers = new Headers()
  for (const [k, v] of Object.entries(req.headers ?? {})) if (!k.startsWith(':') && !['content-length', 'transfer-encoding'].includes(k) && v !== undefined) headers.set(k, Array.isArray(v) ? v.join(', ') : String(v))
  const hasBody = !['GET', 'HEAD'].includes(req.method)
  return new Request(url, { method: req.method, headers, body: hasBody ? await readBody(req) : undefined })
}

async function send(res, response) {
  res.statusCode = response.status
  response.headers.forEach((v, k) => res.setHeader(k, v))
  res.end(Buffer.from(await response.arrayBuffer()))
}

function fallback(res, e) {
  if (res.headersSent) return res.destroy?.(e)
  res.statusCode = 500
  res.setHeader('content-type', 'application/json')
  res.end(JSON.stringify({ error: 'internal_error', message: message(e) }))
}

function pass(options = {}) {
  const engine = createEngine(options)
  const { serverName = engine.options.name, version = '1.0.0' } = options
  const site = engine.options.site
  const tools = new Map()
  const o = engine.options
  const freeLimit = new FreeLimit(o.freeLimit, () => (o.clock ? Number(o.clock()) : Date.now()))
  const freeFile = o.freeLimit >= 1 ? new StateFile(o.stateDir, 'free.json') : null
  const isFree = (t) => t.free === true || o.free.includes(t.name)
  const ownPrice = (t) => t.price !== undefined || t.unit !== undefined
  const time = o.time
  const meter = engine.meter
  const priceFor = (t, args = {}) => {
    if (t.builtin === 'buy_time') return { micro: (Number.isSafeInteger(args.blocks) && args.blocks >= 1 ? args.blocks : 1) * time.blockMicro, unitMicro: null }
    if (ownPrice(t)) return priceOf({ price: t.price, unit: t.unit }, o.priceMicroUSD)
    if (typeof o.prices === 'function') return priceOf(o.prices(t.name, args), o.priceMicroUSD)
    return priceOf(o.prices?.[t.name], o.priceMicroUSD)
  }
  const priceTag = (t) => {
    if (t.builtin === 'line') return { usd: '0', per: 'call', free: true }
    if (t.builtin === 'buy_time') return { usd: usd(time.blockMicro), per: 'block', blockMs: time.blockMs, maxBlocks: time.maxBlocks }
    if (t.meter === 'time' && !(typeof o.prices === 'function' && !ownPrice(t))) {
      const p = priceFor(t)
      return { per: 'time', blockUSD: usd(time.blockMicro), blockMs: time.blockMs, callUSD: usd(p.micro), callMs: meter.callMs(p.micro) }
    }
    if (isFree(t)) return describePrice(null, { free: true, perHour: o.freeLimit })
    if (engine.mode === 'gate') return { usd: '0', per: 'call' }
    if (!ownPrice(t) && typeof o.prices === 'function') return describePrice(null, { varies: true })
    return describePrice(priceFor(t))
  }
  const listing = (base = '') => (engine.mode === 'gate' ? {} : { prices: Object.fromEntries([...tools.values()].map((t) => [t.name, priceTag(t)])), ...(time ? { time: timeText(time, base) } : {}) })
  const publicUrl = (url) => { try { const u = new URL(url); return site ? new URL(u.pathname + u.search, site).toString() : u.toString() } catch { return undefined } }
  const first = (...values) => values.map((v) => (typeof v === 'string' ? v.trim() : '')).find((v) => v !== '') ?? ''
  engine.start()

  async function check({ path = '/', header, resource, tool: described, refundUrl = null, price = null }) {
    const payment = first(header('payment-signature'), header('x-payment'))
    const grant = first(header('x-grant'))
    const tool = described ?? { name: path }
    const r = await engine.check({ tool: { name: tool.name, description: tool.description }, resource, payment, grant, refundUrl, price, listing: listing(String(refundUrl ?? '').replace(/\/refund$/, '')) })
    return r.ok ? { ok: true, ticket: r.ticket, status: 200, headers: {}, body: {} } : { ok: false, status: r.status, headers: r.headers ?? {}, body: r.body }
  }

  function done(ticket, ok, used = {}) {
    return engine.done(ticket, ok, used)
  }

  function usage() {
    const m = { units: null }
    m.ctx = {
      units(n) {
        if (!Number.isSafeInteger(n) || n < 0) throw new TypeError('pass: units is a whole number, 0 or more')
        m.units = n
      },
    }
    return m
  }

  async function gate(req, exec, isFailure = () => false) {
    const c = await check(req)
    if (!c.ok) return { refused: c }
    const m = usage()
    let value
    let threw = false
    let error
    try { value = await exec({ ...m.ctx, channelId: c.ticket?.hold?.channelId ?? null }) } catch (e) { threw = true; error = e }
    const failed = threw || isFailure(value)
    const buying = !failed && time && c.ticket?.hold && req.tool?.name === 'buy_time' && tools.get('buy_time')?.builtin === 'buy_time'
    const bought = buying ? { channelId: c.ticket.hold.channelId, ms: (Number.isSafeInteger(req.args?.blocks) ? req.args.blocks : 1) * time.blockMs, key: c.ticket.hold.pendingId } : null
    if (bought) {
      try {
        await meter.credit(bought.channelId, bought.ms, bought.key)
      } catch (e) {
        await done(c.ticket, false)
        return { value, threw: true, error: new Error(`the time could not be recorded, so nothing was charged: ${message(e)}`), failed: true, settlement: null }
      }
    }
    const d = await done(c.ticket, !failed, { units: m.units })
    if (bought && !d.ok) await meter.uncredit(bought.channelId, bought.ms, bought.key).catch(() => {})
    return { value, threw, error, failed, settlement: d }
  }

  const within = (ms, run) => {
    const aborter = new AbortController()
    let timer
    const late = new Promise((_, fail) => {
      timer = setTimeout(() => {
        const e = new Error(`the call ran past its ${ms} ms`)
        e.code = 'out_of_time'
        aborter.abort(e)
        fail(e)
      }, Math.max(0, ms))
      timer.unref?.()
    })
    return Promise.race([run(aborter.signal), late]).finally(() => clearTimeout(timer))
  }

  const outOfTime = (onLine, ms) => ({ error: 'out_of_time', message: onLine ? 'the line ran out of time during the call. Its time is spent. Buy time: buy_time.' : `the call ran past the ${ms} ms its price buys. Nothing was charged. Buy time and call on a line.` })

  async function onLine(t, args, credential) {
    const channelId = await meter.line(credential)
    if (!channelId) return { status: 403, body: { error: 'line_unknown', message: 'no open line with that credential. Open one: POST <base>/line {"op":"open","channelId"}.' } }
    const ch = await engine.store.get(channelId)
    if (!ch) return { status: 403, body: { error: 'line_unknown', message: 'no channel for this line.' } }
    if (Number(ch.withdrawRequestedAt ?? 0) > 0) return { status: 402, body: { error: 'channel_leaving', message: 'this channel is withdrawing. Open a new channel.' } }
    const id = randomBytes(8).toString('hex')
    const b = await meter.begin(channelId, id)
    if (!b.ok) return { status: 402, body: { error: b.code, message: b.code === 'meter_off' ? 'the meter is off. Send {"op":"on"} to the line.' : 'this line has no time left. Buy time: buy_time.', msRemaining: (await meter.status(channelId)).msRemaining } }
    let value
    let error = null
    try {
      value = await within(b.deadline - meter.now(), (signal) => perform(t, args, { ...usage().ctx, signal, deadline: b.deadline, channelId }))
    } catch (e) { error = e }
    const cut = error?.code === 'out_of_time'
    const e = cut ? await meter.end(channelId, id) : await meter.end(channelId, id, b.started, meter.now())
    const meta = { channelId, msRemaining: e.msRemaining, elapsedMs: e.elapsedMs }
    if (error?.code === 'out_of_time' || (error === null && e.over)) return { status: 402, body: { ...outOfTime(true), msRemaining: e.msRemaining } }
    if (error !== null) return { status: 500, body: { error: 'tool_failed', tool: t.name, message: message(error) } }
    if (value.isError) return { status: 500, failedValue: value.value, body: { error: 'tool_failed', tool: t.name, message: said(value.value) } }
    return { status: 200, value, meta }
  }

  async function lineOp(args, headerCredential = '') {
    const fail = (status, code, why) => ({ status, body: { op: 'line_failed', code, why } })
    if (!time) return fail(404, 'no_time', 'this seller sells no line time')
    if (kind(args) !== 'object' || !LINE_OPS.includes(args.op)) return fail(400, 'bad_request', 'op is open, prove, on, off, status or close')
    if (args.op === 'open' || args.op === 'prove') {
      const id = typeof args.channelId === 'string' && CHANNEL.test(args.channelId) ? args.channelId.toLowerCase() : null
      const ch = id ? await engine.store.get(id) : null
      if (!ch?.channelConfig) return fail(404, 'unknown_channel', 'no channel with that id here. Pay a call on it first, e.g. buy_time.')
      if (Number(ch.withdrawRequestedAt ?? 0) > 0) return fail(409, 'channel_leaving', 'this channel is withdrawing. Open a new channel.')
      if (args.op === 'open') {
        const c = await meter.challenge(id)
        return { status: 200, body: { op: 'challenge', channelId: id, nonce: c.nonce, sign: c.message, expiresInSeconds: c.expiresInSeconds } }
      }
      const r = await meter.prove(ch, args.nonce, args.signature)
      if (!r.ok) return fail(r.status, r.code, r.why)
      return { status: 200, body: { op: 'opened', credential: r.credential, channelId: id, msRemaining: r.msRemaining, metering: true } }
    }
    const credential = typeof args.credential === 'string' && args.credential !== '' ? args.credential : headerCredential
    const id = await meter.line(credential)
    if (!id) return fail(403, 'line_unknown', 'no open line with that credential')
    if (args.op === 'close') {
      await meter.close(credential)
      return { status: 200, body: { op: 'closed', channelId: id } }
    }
    if (args.op !== 'status') await meter.switch(id, args.op === 'on')
    return { status: 200, body: { op: args.op, ...(await meter.status(id)) } }
  }

  async function served(t, args, info = {}) {
    const take = t.builtin ? { ok: true } : freeFile ? await freeLimit.takeSaved(freeFile, t.name, info.ip ?? '') : freeLimit.take(t.name, info.ip ?? '')
    if (!take.ok) return { limited: FreeLimit.refusal(o.freeLimit, take.retryAfter), retryAfter: take.retryAfter }
    try { return { value: await perform(t, args, { ...usage().ctx, line: info.line ?? '' }) } } catch (e) { return { threw: true, error: e } }
  }

  function tool(def) {
    if (!def?.name || typeof def.run !== 'function') throw new Error('pass: a tool is { name, description, inputSchema, run(args) }')
    const t = { description: '', inputSchema: { type: 'object' }, ...def }
    if (tools.get(t.name)?.builtin) throw new Error(`pass: ${t.name} is a built-in tool`)
    delete t.builtin
    if (t.meter !== undefined && t.meter !== 'time') throw new Error('pass: meter is "time"')
    if (t.meter === 'time' && (!time || isFree(t))) throw new Error(`pass: ${t.name} meters time; set the seller's time option, and do not make it free`)
    if (!isFree(t) && engine.mode !== 'gate' && (ownPrice(t) || typeof o.prices !== 'function')) priceFor(t)
    tools.set(def.name, t)
    return api
  }

  const describe = (t) => ({ name: t.name, description: t.description, inputSchema: t.inputSchema, _meta: { [META_PRICE]: priceTag(t) } })
  const listTools = () => [...tools.values()].map(describe)
  const names = () => [...tools.keys()]

  async function perform(t, args, ctx) {
    const value = await t.run(args, ctx)
    if (kind(value) === 'object' && value.isError === true) return { value, isError: true }
    const body = serialize(value)
    if (body === null) throw new Error(RESULT_NOT_JSON)
    return { value, body }
  }

  const probe = (t, args, payment, credential) => payment === '' && kind(args) === 'object' && Object.keys(args).length === 0 && !t.builtin && !isFree(t) && !(t.meter === 'time' && credential)

  async function terms(t, path, header, resource, refundUrl) {
    let price
    try { price = engine.mode === 'gate' ? null : priceFor(t, {}) } catch { return null }
    const q = await check({ path, header, resource, tool: describe(t), refundUrl, price })
    return q.ok ? null : q
  }

  async function http(request, toolName, refundUrl = null, info = {}) {
    const t = tools.get(toolName)
    if (!t) return json(404, { error: 'unknown_tool', tool: toolName, tools: names() })
    const raw = await request.text()
    let args
    try { args = raw.trim() ? JSON.parse(raw) : {} } catch { return json(400, { error: 'invalid_arguments', tool: toolName, message: 'the body is not JSON' }) }
    const bad = validate(t.inputSchema, args)
    if (bad && probe(t, args, first(request.headers.get('payment-signature'), request.headers.get('x-payment')), first(request.headers.get('x-line')))) {
      const q = await terms(t, `/v1/${toolName}`, (h) => request.headers.get(h), publicUrl(request.url), refundUrl)
      return q ? json(q.status, q.body, q.headers) : json(400, { error: 'invalid_arguments', tool: toolName, message: bad })
    }
    if (bad) return json(400, { error: 'invalid_arguments', tool: toolName, message: bad })
    if (t.builtin === 'line') {
      const l = await lineOp(args, request.headers.get('x-line') ?? '')
      return json(l.status, l.body)
    }
    if (isFree(t)) {
      const f = await served(t, args, { ip: info.ip, line: request.headers.get('x-line') ?? '' })
      if (f.limited) return json(429, f.limited, { 'retry-after': String(f.retryAfter) })
      if (f.threw) return json(500, { error: 'tool_failed', message: message(f.error), tool: toolName })
      if (f.value.isError) return json(500, { error: 'tool_failed', message: said(f.value.value), tool: toolName })
      return new Response(f.value.body, { status: 200, headers: { 'content-type': 'application/json' } })
    }
    const credential = first(request.headers.get('x-line'))
    if (t.meter === 'time' && credential) {
      const l = await onLine(t, args, credential)
      if (l.status !== 200) return json(l.status, l.body)
      return new Response(l.value.body, { status: 200, headers: { 'content-type': 'application/json', 'x-pass-ms-remaining': String(l.meta.msRemaining), 'x-pass-ms-elapsed': String(l.meta.elapsedMs) } })
    }
    let price
    try { price = engine.mode === 'gate' ? null : priceFor(t, args) } catch (e) { return json(500, { error: 'price_invalid', tool: toolName, message: message(e) }) }
    const bound = t.meter === 'time' ? meter.callMs(price.micro) : null
    const g = await gate({ path: `/v1/${toolName}`, header: (h) => request.headers.get(h), resource: publicUrl(request.url), tool: describe(t), refundUrl, price, args }, (ctx) => (bound === null ? perform(t, args, ctx) : within(bound, (signal) => perform(t, args, { ...ctx, signal }))), (r) => r.isError)
    if (g.refused) return json(g.refused.status, g.refused.body, g.refused.headers)
    if (g.threw && g.error?.code === 'out_of_time') return json(402, outOfTime(false, bound))
    if (g.failed) return json(500, { error: 'tool_failed', message: g.threw ? message(g.error) : said(g.value.value), tool: toolName })
    if (!g.settlement.ok) return json(g.settlement.status, g.settlement.body?.error === 'tool_failed' ? { ...g.settlement.body, tool: toolName } : g.settlement.body, g.settlement.headers)
    return new Response(g.value.body, { status: 200, headers: { 'content-type': 'application/json', ...g.settlement.headers } })
  }

  const delivered = (value, body, meta = {}) => {
    const structured = kind(value) === 'object' ? { structuredContent: JSON.parse(body) } : {}
    return { content: [{ type: 'text', text: typeof value === 'string' ? value : body }], ...structured, ...(Object.keys(meta).length ? { _meta: meta } : {}) }
  }

  async function mcpCall(params, resource, grant, refundUrl = null, info = {}) {
    const t = tools.get(params.name)
    if (!t) return failure({ error: 'unknown_tool', tool: params.name, tools: names() })
    const args = params.arguments ?? {}
    const bad = validate(t.inputSchema, args)
    if (bad && probe(t, args, params._meta?.[META_PAYMENT] ? 'paid' : '', first(typeof params._meta?.[META_LINE] === 'string' ? params._meta[META_LINE] : '', info.line))) {
      const q = await terms(t, `mcp:${t.name}`, payHeader(null, grant), resource, refundUrl)
      return q ? refusal(q) : failure({ error: 'invalid_arguments', tool: t.name, message: bad })
    }
    if (bad) return failure({ error: 'invalid_arguments', tool: t.name, message: bad })
    if (isFree(t)) {
      const f = await served(t, args, { ip: info.ip, line: first(typeof params._meta?.[META_LINE] === 'string' ? params._meta[META_LINE] : '', info.line) })
      if (f.limited) return failure(f.limited)
      if (f.threw) return failure({ error: 'tool_failed', tool: t.name, message: message(f.error) })
      if (f.value.isError) return failed(f.value.value, t.name)
      return delivered(f.value.value, f.value.body)
    }
    const credential = first(typeof params._meta?.[META_LINE] === 'string' ? params._meta[META_LINE] : '', info.line)
    if (t.meter === 'time' && credential) {
      const l = await onLine(t, args, credential)
      if (l.failedValue) return failed(l.failedValue, t.name)
      if (l.status !== 200) return failure(l.body)
      return delivered(l.value.value, l.value.body, { [META_METER]: l.meta })
    }
    let price
    try { price = engine.mode === 'gate' ? null : priceFor(t, args) } catch (e) { return failure({ error: 'price_invalid', tool: t.name, message: message(e) }) }
    const bound = t.meter === 'time' ? meter.callMs(price.micro) : null
    const g = await gate({ path: `mcp:${t.name}`, header: payHeader(params._meta?.[META_PAYMENT], grant), resource, tool: describe(t), refundUrl, price, args }, (ctx) => (bound === null ? perform(t, args, ctx) : within(bound, (signal) => perform(t, args, { ...ctx, signal }))), (r) => r.isError)
    if (g.refused) return refusal(g.refused)
    if (g.threw && g.error?.code === 'out_of_time') return failure(outOfTime(false, bound))
    if (g.threw) return failure({ error: 'tool_failed', tool: t.name, message: message(g.error) })
    if (g.failed) return failed(g.value.value, t.name)
    if (!g.settlement.ok) return refusal(g.settlement)
    return delivered(g.value.value, g.value.body, settledMeta(g.settlement.headers))
  }

  async function mcpOne(msg, resource, grant, refundUrl = null, info = {}) {
    const id = kind(msg) === 'object' && ['string', 'number'].includes(typeof msg.id) ? msg.id : null
    const reply = (result) => ({ jsonrpc: '2.0', id, result })
    const fail = (code, why) => ({ jsonrpc: '2.0', id, error: { code, message: why } })
    try {
      if (kind(msg) !== 'object' || msg.jsonrpc !== '2.0' || typeof msg.method !== 'string') return fail(-32600, 'invalid request')
      if (msg.id === undefined) return null
      switch (msg.method) {
        case 'initialize': {
          const asked = msg.params?.protocolVersion
          return reply({ protocolVersion: MCP_VERSIONS.includes(asked) ? asked : MCP_VERSIONS[0], capabilities: { tools: {} }, serverInfo: { name: serverName, version } })
        }
        case 'ping': return reply({})
        case 'tools/list': return reply({ tools: listTools() })
        case 'tools/call': {
          const p = msg.params
          if (kind(p) !== 'object' || typeof p.name !== 'string' || (p._meta !== undefined && kind(p._meta) !== 'object')) return fail(-32602, 'invalid params: expected { name, arguments, _meta } with _meta an object')
          return reply(await mcpCall(p, resource, grant, refundUrl, info))
        }
        default: return fail(-32601, `method not found: ${msg.method}`)
      }
    } catch (e) {
      return fail(-32603, `internal error: ${message(e)}`)
    }
  }

  async function mcp(request, refundUrl = null, info = {}) {
    if (request.method === 'GET') return new Response(null, { status: 405, headers: { allow: 'POST' } })
    let body
    try { body = await request.json() } catch { return json(400, { jsonrpc: '2.0', id: null, error: { code: -32700, message: 'parse error' } }) }
    if (Array.isArray(body) && !body.length) return json(400, { jsonrpc: '2.0', id: null, error: { code: -32600, message: 'invalid request: empty batch' } })
    const resource = publicUrl(request.url)
    const grant = request.headers.get('x-grant')
    info = { ...info, line: request.headers.get('x-line') ?? '' }
    const answers = (await Promise.all((Array.isArray(body) ? body : [body]).map((m) => mcpOne(m, resource, grant, refundUrl, info)))).filter(Boolean)
    if (!answers.length) return new Response(null, { status: 202 })
    return json(200, Array.isArray(body) ? answers : answers[0])
  }

  async function refund(request) {
    let b
    try { b = JSON.parse(await request.text()) } catch { return json(400, { op: 'refund_failed', code: 'invalid_json', why: 'the body is not JSON' }) }
    if (kind(b) !== 'object') return json(400, { op: 'refund_failed', code: 'invalid_request', why: 'expected { channelId, issued, signature }, optional selfSend or gasPayment' })
    try {
      const r = await engine.refund(b)
      return json(r.status, r.body)
    } catch (e) { return json(503, { op: 'refund_failed', code: 'refund_unavailable', why: message(e) }) }
  }

  function refundExpress() {
    return (req, res, next) => {
      if (req.method !== 'POST') return next()
      ;(async () => send(res, await refund(await toRequest(req))))().catch((e) => fallback(res, e))
    }
  }

  async function verify(url) {
    try {
      return { ok: !!site && new URL(site).origin === new URL(url).origin, site }
    } catch (e) {
      return { ok: false, error: message(e) }
    }
  }

  function openapi(base) {
    const paths = {}
    for (const t of tools.values()) {
      const responses = isFree(t)
        ? { 200: { description: 'the result' }, 400: { description: 'invalid arguments' }, ...(o.freeLimit ? { 429: { description: `over ${o.freeLimit} free calls an hour per address` } } : {}) }
        : { 200: { description: 'the result' }, 400: { description: 'invalid arguments; nothing is charged' }, 402: { description: 'x402 payment required' }, 403: { description: 'key not admitted' } }
      paths[`/v1/${t.name}`] = { post: { operationId: t.name, summary: t.description, 'x-price': priceTag(t), requestBody: { required: true, content: { 'application/json': { schema: t.inputSchema } } }, responses } }
    }
    const contact = engine.options.contact
    const reach = contact ? { contact: /^mailto:/i.test(contact) ? { email: contact.slice(7) } : { url: contact } } : {}
    return { openapi: '3.0.3', info: { title: serverName, version, description: `${HOW[engine.mode]}. MCP: ${base}/mcp`, ...reach }, servers: [{ url: base }], paths }
  }

  const withCors = (res) => {
    const h = new Headers(res.headers)
    for (const [k, v] of Object.entries(CORS)) h.set(k, v)
    return new Response(res.body, { status: res.status, statusText: res.statusText, headers: h })
  }

  function handle(base = '') {
    const root = base.replace(/\/+$/, '')
    const route = async (request, info) => {
      const u = new URL(request.url)
      if (!u.pathname.startsWith(root)) return null
      const rest = u.pathname.slice(root.length) || '/'
      const m = /^\/v1\/([^/]+)$/.exec(rest)
      const refundAt = publicUrl(`${u.origin}${root}/refund`) ?? null
      if (request.method === 'OPTIONS' && (rest === '/mcp' || rest === '/refund' || rest === '/line' || m)) return new Response(null, { status: 204, headers: PREFLIGHT })
      if (rest === '/line' && request.method === 'POST' && time) {
        let body
        try { body = JSON.parse(await request.text()) } catch { return json(400, { op: 'line_failed', code: 'bad_request', why: 'the body is not JSON' }) }
        const l = await lineOp(body, request.headers.get('x-line') ?? '')
        return json(l.status, l.body)
      }
      if (rest === '/mcp') return mcp(request, refundAt, info)
      if (rest === '/refund' && request.method === 'POST') return refund(request)
      if (rest === '/openapi.json' && request.method === 'GET') return json(200, openapi(`${u.origin}${root}`))
      if (m && request.method === 'POST') {
        let toolName
        try { toolName = decodeURIComponent(m[1]) } catch { return json(404, { error: 'unknown_tool', tool: m[1], tools: names() }) }
        return http(request, toolName, refundAt, info)
      }
      return null
    }
    return async (request, info = {}) => {
      let res
      try {
        res = await route(request, info && typeof info === 'object' ? info : {})
      } catch (e) {
        const onMcp = (() => { try { return new URL(request.url).pathname.slice(root.length) === '/mcp' } catch { return false } })()
        res = onMcp ? json(500, { jsonrpc: '2.0', id: null, error: { code: -32603, message: `internal error: ${message(e)}` } }) : json(500, { error: 'internal_error', message: message(e) })
      }
      return res ? withCors(res) : null
    }
  }

  function express(base = '') {
    const h = handle(base)
    return (req, res, next) => {
      ;(async () => {
        const response = await h(await toRequest(req), { ip: req.ip ?? req.socket?.remoteAddress })
        if (!response) return next()
        await send(res, response)
      })().catch((e) => fallback(res, e))
    }
  }

  function buffer(res, settle) {
    const orig = { writeHead: res.writeHead, write: res.write, end: res.end }
    const chunks = []
    let head = null
    let ended = false
    const push = (chunk, enc) => { if (chunk != null) chunks.push(typeof chunk === 'string' ? Buffer.from(chunk, typeof enc === 'string' ? enc : 'utf8') : Buffer.from(chunk)) }
    res.writeHead = function (status, ...more) { head = [status, ...more]; res.statusCode = status; return res }
    res.write = function (chunk, enc, cb) { push(chunk, enc); const f = typeof enc === 'function' ? enc : cb; if (typeof f === 'function') queueMicrotask(f); return true }
    res.end = function (chunk, enc, cb) {
      if (typeof chunk === 'function') { cb = chunk; chunk = null } else if (typeof enc === 'function') { cb = enc; enc = null }
      if (ended) return res
      ended = true
      push(chunk, enc)
      Object.assign(res, orig)
      settle({ status: res.statusCode, head, body: Buffer.concat(chunks), cb }).catch((e) => fallback(res, e))
      return res
    }
  }

  const routePrice = (spec, req) => (engine.mode === 'gate' ? null : priceOf(typeof spec === 'function' ? spec(req) : spec ?? null, o.priceMicroUSD))

  const paywall = {
    express: ({ when = () => true, price } = {}) => (req, res, next) => {
      ;(async () => {
        if (!when(req)) return next()
        const header = (h) => req.get?.(h) ?? req.headers?.[h]
        const resource = publicUrl(`${req.protocol ?? 'http'}://${header('host') ?? 'localhost'}${req.originalUrl ?? req.url}`)
        const c = await check({ path: req.originalUrl ?? req.url, header, resource, price: routePrice(price, req) })
        if (!c.ok) return send(res, json(c.status, c.body, c.headers))
        buffer(res, async ({ status, head, body, cb }) => {
          const failed = status >= 400
          const d = await done(c.ticket, !failed)
          if (failed || d.ok) {
            if (!failed) for (const [k, v] of Object.entries(d.headers)) res.setHeader(k, v)
            if (head) res.writeHead(...head)
            res.end(body, cb)
            return
          }
          for (const k of res.getHeaderNames?.() ?? []) res.removeHeader(k)
          await send(res, json(d.status, d.body, d.headers))
        })
        next()
      })().catch((e) => fallback(res, e))
    },
    wrap: (handler, { when = () => true, price } = {}) => async (request, ...rest) => {
      if (!when(request)) return handler(request, ...rest)
      const u = new URL(request.url)
      const g = await gate({ path: u.pathname + u.search, header: (h) => request.headers.get(h), resource: publicUrl(request.url), price: routePrice(price, request) }, () => handler(request, ...rest), (r) => typeof r?.status !== 'number' || r.status >= 400)
      if (g.refused) return json(g.refused.status, g.refused.body, g.refused.headers)
      if (g.threw) throw g.error
      if (g.failed) return g.value
      if (!g.settlement.ok) return json(g.settlement.status, g.settlement.body, g.settlement.headers)
      const h = new Headers(g.value.headers)
      for (const [k, v] of Object.entries(g.settlement.headers)) h.set(k, v)
      return new Response(g.value.body, { status: g.value.status, statusText: g.value.statusText, headers: h })
    },
  }

  function paid(toolName, handler, { description, inputSchema, resource, price } = {}) {
    const described = description !== undefined || inputSchema !== undefined ? { name: toolName, ...(description !== undefined ? { description } : {}), ...(inputSchema !== undefined ? { inputSchema } : {}) } : undefined
    const priced = { name: toolName, ...(price !== undefined ? { price } : {}) }
    if (engine.mode !== 'gate' && (ownPrice(priced) || typeof o.prices !== 'function')) priceFor(priced)
    return async (...args) => {
      const extra = args.length > 1 ? args[args.length - 1] : args[0]
      const meta = kind(extra?._meta) === 'object' ? extra._meta : {}
      const grant = extra?.requestInfo?.headers?.['x-grant'] ?? null
      let p
      try { p = engine.mode === 'gate' ? null : priceFor(priced, args.length > 1 && kind(args[0]) === 'object' ? args[0] : {}) } catch (e) { return failure({ error: 'price_invalid', tool: toolName, message: message(e) }) }
      const g = await gate({ path: `mcp:${toolName}`, header: payHeader(meta[META_PAYMENT], typeof grant === 'string' ? grant : null), resource, tool: described ?? { name: toolName }, price: p }, () => handler(...args), (out) => out?.isError === true || serialize(out) === null)
      if (g.refused) return refusal(g.refused)
      if (g.threw) throw g.error
      if (g.failed) return serialize(g.value) === null ? failure({ error: 'tool_failed', tool: toolName, message: RESULT_NOT_JSON }) : g.value
      if (!g.settlement.ok) return refusal(g.settlement)
      const out = g.value
      const added = settledMeta(g.settlement.headers)
      return Object.keys(added).length ? { ...out, _meta: { ...(out?._meta ?? {}), ...added } } : out
    }
  }

  function instrument(server, { free: freeTools = [], resource } = {}) {
    const free = [...freeTools, ...o.free]
    const wrap = (toolName, handler, description) => (free.includes(toolName) ? handler : paid(toolName, handler, { description, resource }))
    const seal = (toolName, registered) => {
      if (kind(registered) !== 'object' || typeof registered.update !== 'function' || free.includes(toolName)) return registered
      const update = registered.update.bind(registered)
      registered.update = (u) => update(typeof u?.callback === 'function' ? { ...u, callback: paid(u.name ?? toolName, u.callback, { description: u.description ?? registered.description, resource }) } : u)
      return registered
    }
    const existing = kind(server._registeredTools) === 'object' ? server._registeredTools : null
    for (const [toolName, t] of Object.entries(existing ?? {})) {
      if (typeof t?.handler !== 'function' || free.includes(toolName)) continue
      t.handler = paid(toolName, t.handler, { description: t.description, resource })
      seal(toolName, t)
    }
    const register = server.registerTool.bind(server)
    server.registerTool = (toolName, config, handler) => seal(toolName, register(toolName, config, wrap(toolName, handler, config?.description)))
    if (typeof server.tool === 'function') {
      const legacy = server.tool.bind(server)
      server.tool = (toolName, ...rest) => {
        const handler = rest.pop()
        return seal(toolName, legacy(toolName, ...rest, wrap(toolName, handler, typeof rest[0] === 'string' ? rest[0] : undefined)))
      }
    }
    return server
  }

  if (time) {
    tools.set('buy_time', { name: 'buy_time', builtin: 'buy_time', description: `Buys line time: $${usd(time.blockMicro)} per ${time.blockMs} ms block, for the channel that pays. Then open a line.`, inputSchema: { type: 'object', properties: { blocks: { type: 'integer', minimum: 1, maximum: time.maxBlocks, description: `blocks of ${time.blockMs} ms; default 1` } } }, run: async (args, ctx) => {
      const blocks = Number.isSafeInteger(args.blocks) ? args.blocks : 1
      const st = await meter.status(ctx.channelId)
      return { channelId: ctx.channelId, boughtMs: blocks * time.blockMs, msRemaining: st.msRemaining + blocks * time.blockMs, blockMs: time.blockMs, paidUSD: usd(blocks * time.blockMicro) }
    } })
    tools.set('line', { name: 'line', builtin: 'line', free: true, description: 'A line spends bought time without a payment per call. op: open {channelId} returns a message to sign with the payer key; prove {channelId, nonce, signature} returns the credential; on, off, status, close {credential}.', inputSchema: { type: 'object', properties: { op: { enum: LINE_OPS }, channelId: { type: 'string' }, nonce: { type: 'string' }, signature: { type: 'string' }, credential: { type: 'string' } }, required: ['op'] }, run: async (args, ctx) => {
      const l = await lineOp(args, ctx.line ?? '')
      return l.status === 200 ? l.body : { isError: true, structuredContent: l.body, content: text(l.body) }
    } })
  }

  const api = { tool, handle, express, check, done, validate, paywall, paid, instrument, refund, refundExpress, verify, tools: listTools, tick: engine.tick, payout: engine.payout, status: engine.status, stop: engine.stop, engine }
  return api
}

function tick(options = {}) {
  const p = pass({ ...options, tick: false })
  return p.tick()
}

export { pass, tick, validate }
export { signGrant } from './grant.mjs'
