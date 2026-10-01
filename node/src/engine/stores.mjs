import { promises as fs } from 'node:fs'
import { createHash } from 'node:crypto'
import { join } from 'node:path'
import { ensureDir, readJson, remove, withLock, writeAtomic } from './files.mjs'
import { Json } from './settlement/json.mjs'

const isRecord = (v) => v !== null && typeof v === 'object'

export class ChannelFileStore {
  constructor(dir) {
    const d = String(dir ?? '').replace(/\/+$/, '')
    if (d === '') throw new TypeError('a file store needs a directory')
    this.dir = ensureDir(d)
  }

  static key(channelId) {
    const id = String(channelId).toLowerCase()
    if (!/^0x[0-9a-f]{64}$/.test(id)) throw new TypeError('a channel id is 32 bytes of hex')
    return id
  }

  path(channelId) {
    return join(this.dir, `${ChannelFileStore.key(channelId)}.json`)
  }

  async get(channelId) {
    const v = await readJson(this.path(channelId))
    return isRecord(v) ? v : null
  }

  async update(channelId, fn) {
    const path = this.path(channelId)
    const out = await withLock(path, async () => {
      const raw = await readJson(path)
      const current = isRecord(raw) ? raw : null
      const next = (await fn(current)) ?? null
      if (next === null) await remove(path)
      else await writeAtomic(path, Json.encode(next))
      return next
    })
    return out.value
  }

  async list() {
    let names = []
    try {
      names = await fs.readdir(this.dir)
    } catch {
      return []
    }
    const out = []
    for (const n of names.filter((f) => /^0x[0-9a-f]{64}\.json$/.test(f)).sort()) {
      const r = await this.get(n.slice(0, -5))
      if (r !== null) out.push(r)
    }
    return out
  }
}

export class GateFileStore {
  constructor(dir) {
    const d = String(dir ?? '').replace(/\/+$/, '')
    if (d === '') throw new TypeError('a file store needs a directory')
    this.dir = ensureDir(d)
  }

  path(key) {
    const k = String(key)
    return join(this.dir, `${k.toLowerCase().replace(/[^a-z0-9_.-]/g, '_')}-${createHash('sha1').update(k).digest('hex').slice(0, 12)}.json`)
  }

  async get(key) {
    const v = await readJson(this.path(key))
    return isRecord(v) ? v : null
  }

  async update(key, change) {
    const path = this.path(key)
    const out = await withLock(path, async () => {
      const raw = await readJson(path)
      const current = isRecord(raw) ? raw : null
      const [next, result] = await change(current)
      if (Json.encode(next ?? null) !== Json.encode(current)) await writeAtomic(path, Json.encode(next ?? null))
      return result
    })
    return out.value
  }
}

export class StateFile {
  constructor(dir, name) {
    this.path = join(ensureDir(dir), name)
  }

  async get() {
    return readJson(this.path)
  }

  async set(value) {
    await writeAtomic(this.path, Json.encode(value))
  }

  async update(fn) {
    return (await withLock(this.path, async () => {
      const next = await fn(await readJson(this.path))
      await writeAtomic(this.path, Json.encode(next))
      return next
    })).value
  }
}
