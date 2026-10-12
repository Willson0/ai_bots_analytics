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

// Жёстко запрещаем любое приближение/отдаление на мобильных.
// viewport-мета убирает pinch-zoom, но iOS (Safari/WebView) всё равно
// умеет зумить жестами и двойным тапом — глушим это руками.
export function lockZoom() {
  if (typeof document === 'undefined') return

  // iOS Safari: жесты масштабирования двумя пальцами.
  const killGesture = (e) => e.preventDefault()
  document.addEventListener('gesturestart', killGesture, { passive: false })
  document.addEventListener('gesturechange', killGesture, { passive: false })
  document.addEventListener('gestureend', killGesture, { passive: false })

  // Любой мультитач (два+ пальца) — это попытка зума, не скролл.
  document.addEventListener(
    'touchmove',
    (e) => { if (e.touches && e.touches.length > 1) e.preventDefault() },
    { passive: false }
  )

  // Двойной тап по экрану (zoom-to-tap на iOS).
  let lastTouch = 0
  document.addEventListener(
    'touchend',
    (e) => {
      const now = Date.now()
      if (now - lastTouch <= 300) e.preventDefault()
      lastTouch = now
    },
    { passive: false }
  )

  // Ctrl+колесо на десктопе (для полноты).
  document.addEventListener(
    'wheel',
    (e) => { if (e.ctrlKey) e.preventDefault() },
    { passive: false }
  )
}
