// Applications gestion et livreur : notifications (plus bas) ; la page livreur reste disponible sans réseau (dernière version en cache) ; les données passent
// toujours par le réseau.
const CACHE = 'livreur-v1'

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  const isShell = request.mode === 'navigate' && url.pathname.startsWith('/livreur')
  const isAsset = url.pathname.startsWith('/build/')
  if (!isShell && !isAsset) return

  event.respondWith(
    fetch(request)
      .then((response) => {
        const copy = response.clone()
        caches.open(CACHE).then((cache) => cache.put(isShell ? '/livreur' : request, copy))
        return response
      })
      .catch(() => caches.match(isShell ? '/livreur' : request)),
  )
})

// Notifications (nouvelle commande, alerte, mission…) : affichées même application fermée
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'Jibiat Marchand', body: event.data?.text() || '' }
  }
  event.waitUntil(self.registration.showNotification(data.title || 'Jibiat Marchand', {
    body: data.body || '',
    icon: '/brand/icon-192.png',
    badge: '/brand/icon-192.png',
    tag: data.tag || undefined,
    renotify: Boolean(data.tag),
    vibrate: [200, 100, 200],
    data: { url: data.url || '/gestion' },
  }))
})

// Toucher la notification : ouvre (ou ramène au premier plan) l'application sur la bonne page
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/gestion', self.location.origin).href
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const existing = windows.find((w) => new URL(w.url).origin === new URL(target).origin)
    if (existing) {
      await existing.focus()
      return existing.navigate(target).catch(() => existing.postMessage({ type: 'navigate', url: target }))
    }
    return self.clients.openWindow(target)
  })())
})
