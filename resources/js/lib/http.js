import axios from 'axios'

// Session par cookie, limitée au nom d'hôte (Sanctum) : jeton CSRF lu dans le cookie XSRF-TOKEN
const http = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

let csrfReady = null

export function ensureCsrf() {
  csrfReady ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true }).catch((e) => {
    csrfReady = null
    throw e
  })
  return csrfReady
}

// Avant toute écriture : cookie CSRF. Jeton expiré (419) : on le renouvelle et on rejoue une fois.
http.interceptors.request.use(async (config) => {
  if (config.method !== 'get') await ensureCsrf()
  return config
})

http.interceptors.response.use(
  (response) => response,
  async (error) => {
    const { config, response } = error
    if (response?.status === 419 && config && !config._retried) {
      csrfReady = null
      await ensureCsrf()
      return http({ ...config, _retried: true })
    }
    return Promise.reject(error)
  },
)

/**
 * Message lisible d'une erreur d'API ({ message, errors? }).
 */
export function errorMessage(error, fallback = 'Une erreur est survenue.') {
  if (error?.isAxiosError && !error.response) return 'Pas de connexion. Réessayez quand le réseau revient.'
  const data = error?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return data?.message || fallback
}

/**
 * Erreurs par champ : { phone: 'Numéro invalide.' }
 */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors ?? {}
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

export default http
