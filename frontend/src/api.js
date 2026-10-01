// Обёртка над fetch к бэкенду.
// Базовый адрес API берётся из src/config.json (поле apiBase) на этапе сборки.
// Файл config.json в .gitignore — на каждом окружении свой
// (см. config.example.json).

import config from './config.json'
import { getInitData } from './telegram'

const API_BASE = (config.apiBase ?? '/api').replace(/\/+$/, '')

export async function apiGet(path, params = {}) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '' || v === 'all') continue
    if (Array.isArray(v)) {
      // Массивы (напр. contragent[], link[]) — пустой массив = без фильтра.
      for (const item of v) {
        if (item !== null && item !== undefined && item !== '') qs.append(k + '[]', item)
      }
    } else {
      qs.append(k, v)
    }
  }
  const query = qs.toString()
  const url = API_BASE + path + (query ? '?' + query : '')

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
