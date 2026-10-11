// Обёртка над fetch к бэкенду.
// Базовый адрес API берётся из src/config.json (поле apiBase) на этапе сборки.

import config from './config.json'
import { getInitData } from './telegram'

const API_BASE = (config.apiBase ?? '/api').replace(/\/+$/, '')

function baseHeaders() {
  const headers = { Accept: 'application/json' }
  const initData = getInitData()
  if (initData) headers['X-Telegram-Init-Data'] = initData
  // Для локальной отладки вне Telegram (если задан в config.json).
  if (config.debugTelegramId) headers['X-Debug-Telegram-Id'] = String(config.debugTelegramId)
  return headers
}

async function handle(res) {
  if (!res.ok) {
    let message = 'Ошибка запроса (' + res.status + ')'
    try {
      const body = await res.json()
      if (body && body.message) message = body.message
    } catch (e) { /* ignore */ }
    const err = new Error(message)
    err.status = res.status
    throw err
  }
  return res.json()
}

export async function apiGet(path, params = {}) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '' || v === 'all') continue
    if (Array.isArray(v)) {
      for (const item of v) {
        if (item !== null && item !== undefined && item !== '') qs.append(k + '[]', item)
      }
    } else {
      qs.append(k, v)
    }
  }
  const query = qs.toString()
  const url = API_BASE + path + (query ? '?' + query : '')
  const res = await fetch(url, { headers: baseHeaders() })
  return handle(res)
}

export async function apiSend(method, path, body) {
  const res = await fetch(API_BASE + path, {
    method,
    headers: { ...baseHeaders(), 'Content-Type': 'application/json' },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  return handle(res)
}
