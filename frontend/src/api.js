// Обёртка над fetch к бэкенду.
// Базовый адрес API берётся из /config.json (поле apiBase).

import { getInitData } from './telegram'

let configPromise = null

function loadConfig() {
  if (!configPromise) {
    const url = import.meta.env.BASE_URL + 'config.json'
    configPromise = fetch(url, { cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : {}))
      .catch(() => ({}))
  }
  return configPromise
}

export async function apiGet(path, params = {}) {
  const cfg = await loadConfig()
  const base = (cfg.apiBase ?? '/api').replace(/\/+$/, '')

  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    // 'all' и пустые значения означают «без фильтра» — не отправляем.
    if (v === null || v === undefined || v === '' || v === 'all') continue
    qs.append(k, v)
  }
  const query = qs.toString()
  const url = base + path + (query ? '?' + query : '')

  const headers = { Accept: 'application/json' }
  const initData = getInitData()
  if (initData) headers['X-Telegram-Init-Data'] = initData

  const res = await fetch(url, { headers })
  if (!res.ok) {
    let message = 'Ошибка запроса (' + res.status + ')'
    try {
      const body = await res.json()
      if (body && body.message) message = body.message
    } catch (e) { /* ignore */ }
    throw new Error(message)
  }
  return res.json()
}
