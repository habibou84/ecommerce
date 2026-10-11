// Application livreur : la page reste disponible sans réseau (dernière version en cache) ; les données passent
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
