// Инициализация Telegram Web App (Mini App).
// Скрипт telegram-web-app.js подключён в index.html.

function wa() {
  return (typeof window !== 'undefined' && window.Telegram && window.Telegram.WebApp) || null
}

export function initTelegram() {
  const tg = wa()
  if (!tg) return null

  try {
    tg.ready()
    tg.expand()

    // Отключаем вертикальные свайпы Telegram, из-за которых свайп вниз
    // в любом месте сворачивал мини-апп (Bot API 7.7+).
    if (tg.disableVerticalSwipes) tg.disableVerticalSwipes()

    // Подгоняем шапку и фон под светлую тему приложения.
    const bg = getComputedStyle(document.documentElement)
      .getPropertyValue('--color-bg').trim() || '#f3f2f2'
    if (tg.setHeaderColor) tg.setHeaderColor(bg)
    if (tg.setBackgroundColor) tg.setBackgroundColor(bg)
  } catch (e) {
    // В обычном браузере (вне Telegram) просто игнорируем.
  }
  return tg
}

// initData — подписанная строка от Telegram. Уходит на бэкенд заголовком
// X-Telegram-Init-Data, где её можно валидировать (utils::isSafe).
export function getInitData() {
  const tg = wa()
  return (tg && tg.initData) || ''
}

// Признак запуска внутри Telegram.
export function isTelegram() {
  return !!getInitData()
}

// Закрыть Mini App (кнопка «Закрыть» в шапке).
export function closeApp() {
  const tg = wa()
  if (tg && tg.close) tg.close()
}
