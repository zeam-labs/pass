import { promises as fs, mkdirSync } from 'node:fs'
import { randomBytes } from 'node:crypto'
import { dirname } from 'node:path'

const queues = new Map()
const STALE_MS = 30000

export function ensureDir(dir) {
  mkdirSync(dir, { recursive: true, mode: 0o700 })
  return dir
}

async function grab(lockPath, waitMs) {
  const deadline = Date.now() + waitMs
  for (;;) {
    try {
      const h = await fs.open(lockPath, 'wx', 0o600)
      await h.writeFile(String(process.pid))
      await h.close()
      return true
    } catch (e) {
      if (e.code !== 'EEXIST') throw e
      try {
        const st = await fs.stat(lockPath)
        if (Date.now() - st.mtimeMs > STALE_MS) {
          await fs.unlink(lockPath).catch(() => {})
          continue
        }
      } catch {
        continue
      }
      if (Date.now() >= deadline) return false
      await new Promise((r) => setTimeout(r, 5 + Math.floor(Math.random() * 20)))
    }
  }
}

export async function withLock(path, fn, { waitMs = 10000, attempt = false } = {}) {
  const prev = queues.get(path) ?? Promise.resolve()
  if (attempt && queues.has(path)) return { ran: false }
  let release
  const mine = new Promise((r) => { release = r })
  const chained = prev.then(() => mine)
  queues.set(path, chained)
  await prev
  const lockPath = `${path}.lock`
  try {
    if (!(await grab(lockPath, attempt ? 0 : waitMs))) {
      if (attempt) return { ran: false }
      throw new Error(`could not lock ${path}`)
    }
    try {
      return { ran: true, value: await fn() }
    } finally {
      await fs.unlink(lockPath).catch(() => {})
    }
  } finally {
    release()
    if (queues.get(path) === chained) queues.delete(path)
  }
}

export async function readText(path) {
  try {
    return await fs.readFile(path, 'utf8')
  } catch (e) {
    if (e.code === 'ENOENT') return null
    throw e
  }
}

export async function writeAtomic(path, text, mode = 0o600) {
  await fs.mkdir(dirname(path), { recursive: true, mode: 0o700 })
  const tmp = `${path}.${process.pid}.${randomBytes(6).toString('hex')}.tmp`
  const h = await fs.open(tmp, 'w', mode)
  try {
    await h.writeFile(text)
    await h.sync()
  } finally {
    await h.close()
  }
  await fs.rename(tmp, path)
}

export async function remove(path) {
  await fs.unlink(path).catch((e) => {
    if (e.code !== 'ENOENT') throw e
  })
}

export async function readJson(path) {
  const raw = await readText(path)
  if (raw === null || raw === '') return null
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}
