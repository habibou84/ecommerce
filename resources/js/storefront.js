/*
 * Boutique en ligne : quelques Ko de JavaScript sans dépendance (téléphones modestes, réseau 3G).
 * Choix de la variante, photos, panier, commande en une page.
 */

const $ = (selector, root = document) => root.querySelector(selector)
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)]

const money = (amount) => `${Math.round(amount).toLocaleString('fr-FR').replace(/ | /g, ' ')} F`

class RequestError extends Error {
  constructor(message, errors = {}) {
    super(message)
    this.errors = errors
  }
}

// Boutique hébergée à l'adresse d'une autre : ses pages sont sous « /{nom} » (vide sinon)
const BASE = document.documentElement.dataset.base || ''

// Appels JSON de nos pages : l'en-tête X-Requested-With remplace le jeton CSRF (voir RequireAjax)
async function api(method, url, body) {
  const response = await fetch(url.startsWith('/') ? BASE + url : url, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    const message = response.status === 429 ? 'Trop de tentatives, patientez un instant.' : data.message || 'Une erreur est survenue. Réessayez.'
    throw new RequestError(message, data.errors || {})
  }
  return data
}

let toastTimer
function toast(html) {
  const el = $('#toast')
  el.innerHTML = html
  el.hidden = false
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (el.hidden = true), 4000)
}

function escapeHtml(text) {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

function setCartCount(count) {
  $$('[data-cart-count]').forEach((el) => {
    el.textContent = count
    el.hidden = !count
  })
}

const firstError = (error) => Object.values(error.errors || {})[0]?.[0] || error.message

/* ---------- Fiche produit ---------- */

function productPage() {
  const dataEl = $('#product-data')
  if (!dataEl) return
  const data = JSON.parse(dataEl.textContent)
  const variants = data.variants
  let variant = variants.find((v) => v.id === data.selected) || variants[0]
  const chosen = [...variant.options]

  const mainImage = $('[data-main-image]')
  const qty = $('[data-qty]')
  const inStock = (v) => v.available === null || v.available > 0

  function showImage(urls) {
    if (!mainImage || !urls) return
    mainImage.src = urls.medium
    mainImage.srcset = `${urls.medium} 800w, ${urls.large} 1600w`
    $$('[data-thumb]').forEach((t) => t.classList.toggle('border-brand', t.dataset.medium === urls.medium))
    $$('[data-thumb]').forEach((t) => t.classList.toggle('border-transparent', t.dataset.medium !== urls.medium))
  }

  function render() {
    $('[data-price]').textContent = money(variant?.price ?? 0)
    const compare = $('[data-compare]')
    compare.hidden = !variant?.compare_at
    compare.textContent = variant?.compare_at ? money(variant.compare_at) : ''

    const stock = $('[data-stock]')
    const available = variant && inStock(variant)
    stock.textContent = !variant ? 'Cette combinaison n’existe pas' : !available ? 'Épuisé'
      : variant.available !== null && variant.available <= 5 ? `Plus que ${variant.available} en stock` : 'En stock'
    stock.className = `mt-5 text-sm ${available ? 'text-emerald-700' : 'text-red-700'}`
    $$('[data-add-to-cart], [data-buy-now]').forEach((b) => (b.disabled = !available))

    // Valeurs choisies, et valeurs sans variante disponible avec les autres choix barrées
    $$('[data-option]').forEach((chip) => {
      const index = Number(chip.dataset.option)
      chip.setAttribute('aria-pressed', chosen[index] === chip.dataset.value ? 'true' : 'false')
      const candidate = [...chosen]
      candidate[index] = chip.dataset.value
      const match = variants.find((v) => v.options.every((o, i) => o === candidate[i]))
      chip.classList.toggle('is-unavailable', !match || !inStock(match))
    })
    $$('[data-option-label]').forEach((el) => (el.textContent = chosen[Number(el.dataset.optionLabel)] ?? ''))

    if (variant?.image) showImage(variant.image)

    const link = $('[data-whatsapp]')
    if (link && data.whatsapp) {
      const label = variant && variant.options.length ? `${data.title} (${variant.title})` : data.title
      const text = `Bonjour, je souhaite commander : ${label} × ${qty.value || 1}${variant ? ` — ${money(variant.price)}` : ''}\n${data.url}`
      link.href = `${data.whatsapp}?text=${encodeURIComponent(text)}`
    }
  }

  $$('[data-option]').forEach((chip) =>
    chip.addEventListener('click', () => {
      chosen[Number(chip.dataset.option)] = chip.dataset.value
      variant = variants.find((v) => v.options.every((o, i) => o === chosen[i]))
      // Combinaison inexistante : on garde la valeur choisie et on prend la première variante qui l'a
      if (!variant) {
        const index = Number(chip.dataset.option)
        variant = variants.find((v) => v.options[index] === chip.dataset.value && inStock(v)) || variants.find((v) => v.options[index] === chip.dataset.value)
        if (variant) variant.options.forEach((o, i) => (chosen[i] = o))
      }
      render()
    }),
  )

  $$('[data-thumb]').forEach((thumb) => thumb.addEventListener('click', () => showImage({ medium: thumb.dataset.medium, large: thumb.dataset.large })))

  $$('[data-qty-step]').forEach((button) =>
    button.addEventListener('click', () => {
      qty.value = Math.min(999, Math.max(1, (Number(qty.value) || 1) + Number(button.dataset.qtyStep)))
      render()
    }),
  )
  qty.addEventListener('change', render)

  async function add(button, thenCheckout) {
    if (!variant) return
    button.disabled = true
    try {
      const quantity = Number(qty.value) || 1
      const { data: summary } = await api('POST', '/panier/articles', { variant_id: variant.id, quantity })
      setCartCount(summary.count)
      window.jmTrack?.('add_to_cart', { value: variant.price * quantity, currency: data.currency, items: [{ id: data.id, name: data.title, price: variant.price, quantity }] })
      if (thenCheckout) {
        window.location.href = `${BASE}/commande`
        return
      }
      toast(`Ajouté au panier · <a href="${BASE}/panier" class="underline font-medium">Voir le panier (${summary.count})</a>`)
    } catch (error) {
      toast(escapeHtml(firstError(error)))
    }
    button.disabled = false
  }
  $('[data-add-to-cart]')?.addEventListener('click', (e) => add(e.currentTarget, false))
  $('[data-buy-now]')?.addEventListener('click', (e) => add(e.currentTarget, true))

  render()
}

/* ---------- Panier ---------- */

function cartPage() {
  const list = $('[data-cart-lines]')
  if (!list || !$('[data-cart-full]')) return

  async function update(line, quantity) {
    line.style.opacity = '0.5'
    try {
      const { data: summary } = await api('POST', '/panier/articles', { variant_id: Number(line.dataset.line), quantity, replace: true })
      setCartCount(summary.count)
      $('[data-subtotal]').textContent = money(summary.subtotal)
      $('[data-cart-total]').textContent = money(summary.total)
      const discount = summary.discount?.error ? 0 : summary.discount?.amount || 0
      $('[data-discount-row]').hidden = !discount
      $('[data-discount]').textContent = `− ${money(discount)}`
      const free = $('[data-free-shipping]')
      if (free && summary.free_shipping) {
        free.innerHTML = summary.free_shipping.remaining > 0
          ? `Plus que <strong>${money(summary.free_shipping.remaining)}</strong> pour la livraison offerte 🚚` : '🎉 Livraison offerte !'
      }
      $$('[data-line]', list).forEach((el) => {
        const current = summary.lines.find((l) => String(l.variant_id) === el.dataset.line)
        if (!current) return el.remove()
        $('[data-line-qty]', el).textContent = current.quantity
        $('[data-line-total]', el).textContent = money(current.total)
        $('[data-line-unit]', el).textContent = money(current.unit_price)
        $('[data-line-compare]', el).hidden = !current.compare_at
        $('[data-line-compare]', el).textContent = current.compare_at ? money(current.compare_at) : ''
        $('[data-line-volume]', el).hidden = !current.volume
        $('[data-line-short]', el).hidden = !current.short
      })
      if (!summary.lines.length) {
        $('[data-cart-full]').hidden = true
        $('[data-cart-empty]').hidden = false
      }
    } catch (error) {
      toast(escapeHtml(firstError(error)))
    }
    line.style.opacity = ''
  }

  list.addEventListener('click', (event) => {
    const line = event.target.closest('[data-line]')
    if (!line) return
    const current = Number($('[data-line-qty]', line).textContent)
    const step = event.target.closest('[data-line-step]')
    if (step) update(line, Math.max(0, current + Number(step.dataset.lineStep)))
    if (event.target.closest('[data-line-remove]')) update(line, 0)
  })
}

/* ---------- Commande ---------- */

function checkoutPage() {
  const form = $('[data-checkout]')
  if (!form) return

  const placeInput = $('[data-place-search]')
  const placeId = form.elements.place_id
  const options = $('[data-place-options]')
  let searchTimer
  let lastSearch = ''

  const method = () => form.querySelector('[name=delivery_method]:checked')?.value || form.elements.delivery_method.value

  function showError(field, message) {
    const el = $(`[data-error="${field}"]`, form) || $('[data-error="form"]', form)
    el.textContent = message
    el.hidden = !message
  }
  const clearErrors = () => $$('[data-error]', form).forEach((el) => (el.hidden = true))

  async function quote() {
    const fee = $('[data-fee]')
    const eta = $('[data-eta]')
    if (method() === 'delivery' && !placeId.value) {
      fee.textContent = 'Choisissez votre quartier'
      $('[data-total]').textContent = money(JSON.parse($('#checkout-data').textContent).total)
      eta.hidden = true
      return
    }
    try {
      const { data } = await api('POST', '/commande/devis', { delivery_method: method(), place_id: Number(placeId.value) || null })
      fee.textContent = method() === 'pickup' ? 'Retrait gratuit' : data.fee === null ? 'À confirmer par la boutique' : data.fee === 0 ? 'Offerte' : money(data.fee)
      $('[data-total]').textContent = money(data.total ?? data.subtotal - data.discount)
      eta.textContent = data.eta ? `Délai : ${data.eta}` : data.free_above && data.fee ? `Livraison offerte dès ${money(data.free_above)}` : ''
      eta.hidden = !eta.textContent
    } catch (error) {
      fee.textContent = '—'
    }
  }

  function choosePlace(id, label) {
    placeId.value = id
    placeInput.value = label
    options.hidden = true
    placeInput.setAttribute('aria-expanded', 'false')
    showError('place_id', '')
    quote()
  }

  placeInput?.addEventListener('input', () => {
    placeId.value = ''
    clearTimeout(searchTimer)
    const q = placeInput.value.trim()
    if (q.length < 2) {
      options.hidden = true
      return
    }
    searchTimer = setTimeout(async () => {
      lastSearch = q
      try {
        const { data } = await api('GET', `/lieux?q=${encodeURIComponent(q)}`)
        if (lastSearch !== q) return
        options.innerHTML = data.length
          ? data.map((p) => `<li role="option" tabindex="-1" data-id="${p.id}" class="cursor-pointer px-3 py-2.5 hover:bg-slate-100">${escapeHtml(p.label)}</li>`).join('')
          : '<li class="px-3 py-2.5 text-sm text-slate-500">Aucun lieu trouvé : essayez la commune.</li>'
        options.hidden = false
        placeInput.setAttribute('aria-expanded', 'true')
      } catch (error) {
        options.hidden = true
      }
    }, 250)
    quote()
  })
  placeInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !options.hidden) {
      event.preventDefault()
      const first = $('[data-id]', options)
      if (first) choosePlace(first.dataset.id, first.textContent)
    }
  })
  options?.addEventListener('click', (event) => {
    const item = event.target.closest('[data-id]')
    if (item) choosePlace(item.dataset.id, item.textContent)
  })
  document.addEventListener('click', (event) => {
    if (options && !event.target.closest('[data-place-options], [data-place-search]')) options.hidden = true
  })

  $$('[name=delivery_method]', form).forEach((radio) =>
    radio.addEventListener('change', () => {
      $$('[data-when]', form).forEach((el) => (el.hidden = el.dataset.when !== method()))
      quote()
    }),
  )

  // Coordonnées gardées dès la saisie : la boutique peut rappeler si la commande n'aboutit pas
  const saveContact = () => api('POST', '/commande/contact', { name: form.elements.name.value, phone: form.elements.phone.value }).catch(() => {})
  form.elements.phone.addEventListener('change', saveContact)
  form.elements.name.addEventListener('change', saveContact)

  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    clearErrors()
    const button = $('[type=submit]', form)
    const values = Object.fromEntries(new FormData(form))
    if (method() === 'delivery' && !values.place_id) {
      showError('place_id', 'Choisissez votre commune ou quartier dans la liste.')
      placeInput.focus()
      return
    }
    button.disabled = true
    button.textContent = 'Envoi…'
    try {
      const { redirect } = await api('POST', '/commande', {
        ...values,
        place_id: Number(values.place_id) || null,
        pickup_location_id: Number(values.pickup_location_id) || null,
      })
      window.location.href = redirect
    } catch (error) {
      const fields = Object.keys(error.errors)
      fields.forEach((field) => showError(field, error.errors[field][0]))
      if (!fields.length) showError('form', error.message)
      button.disabled = false
      button.textContent = 'Confirmer la commande'
    }
  })

  quote()
}

/* ---------- Code promo, vente flash ---------- */

function promoCode() {
  const box = $('[data-promo]')
  if (!box) return
  const input = $('[data-promo-input]', box)
  const error = $('[data-promo-error]', box)

  async function send(code) {
    try {
      await api('POST', '/panier/code', { code })
      window.location.reload()
    } catch (e) {
      if (error) {
        error.textContent = firstError(e)
        error.hidden = false
      }
    }
  }
  $('[data-promo-apply]', box)?.addEventListener('click', () => input.value.trim() && send(input.value.trim()))
  input?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return
    event.preventDefault() // la page de commande est un formulaire : Entrée ne doit pas l'envoyer
    if (input.value.trim()) send(input.value.trim())
  })
  $('[data-promo-remove]', box)?.addEventListener('click', () => send(''))
}

function countdowns() {
  const els = $$('[data-countdown]')
  if (!els.length) return
  const pad = (n) => String(n).padStart(2, '0')
  const tick = () => els.forEach((el) => {
    const left = Math.max(0, new Date(el.dataset.countdown).getTime() - Date.now())
    const days = Math.floor(left / 86400000)
    const h = Math.floor((left % 86400000) / 3600000)
    const m = Math.floor((left % 3600000) / 60000)
    const sec = Math.floor((left % 60000) / 1000)
    el.textContent = left <= 0 ? 'terminée' : `${days ? `${days} j ` : ''}${pad(h)}:${pad(m)}:${pad(sec)}`
  })
  tick()
  setInterval(tick, 1000)
}

/* ---------- Paiement en ligne ---------- */

function paymentForms() {
  // Commande : la liste des moyens n'apparaît que si le client choisit de payer en ligne
  const methods = $('[data-payment-methods]')
  $$('[name=payment]').forEach((radio) =>
    radio.addEventListener('change', () => (methods.hidden = $('[name=payment]:checked')?.value !== 'online')),
  )

  // Lien de paiement, paiement depuis la page de remerciement
  $$('[data-pay-form]').forEach((form) =>
    form.addEventListener('submit', async (event) => {
      event.preventDefault()
      const button = $('button', form)
      const error = $('[data-pay-error]', form)
      button.disabled = true
      error.hidden = true
      try {
        const { redirect } = await api('POST', form.getAttribute('action'), { method: new FormData(form).get('method') })
        window.location.href = redirect
      } catch (e) {
        error.textContent = firstError(e)
        error.hidden = false
        button.disabled = false
      }
    }),
  )
}

/* ---------- Avis client ---------- */

function reviewForm() {
  const form = $('[data-review-form]')
  if (!form) return
  // Étoiles : celles jusqu'à la note choisie s'allument
  $$('[data-review-product]', form).forEach((group) => {
    const paint = () => {
      const value = Number($('input:checked', group)?.value || 0)
      $$('[data-star]', group).forEach((star) => (star.style.color = Number(star.dataset.star) <= value ? '#f59e0b' : '#cbd5e1'))
    }
    $$('input', group).forEach((input) => input.addEventListener('change', paint))
    paint()
  })
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = new FormData(form)
    const button = $('button', form)
    const error = $('[data-review-error]', form)
    const ratings = {}
    $$('[data-review-product]', form).forEach((group) => {
      const id = group.dataset.reviewProduct
      ratings[id] = { rating: Number(data.get(`rating-${id}`)) || null, comment: data.get(`comment-${id}`) || null }
    })
    button.disabled = true
    error.hidden = true
    try {
      const { message } = await api('POST', form.getAttribute('action'), { name: data.get('name'), ratings })
      form.hidden = true
      $('[data-review-message]').textContent = message
      $('[data-review-thanks]').hidden = false
    } catch (e) {
      error.textContent = firstError(e)
      error.hidden = false
      button.disabled = false
    }
  })
}

/* ---------- Page du live : pièces restantes en direct, réservation en deux touches ---------- */

function livePage() {
  const root = $('[data-live]')
  const dialog = $('[data-live-dialog]')
  if (!root || !dialog) return
  const form = $('[data-live-form]')
  const placeInput = $('[data-place-search]', form)
  const options = $('[data-place-options]', form)
  let searchTimer

  // Nom, numéro et quartier gardés sur le téléphone : la prochaine réservation se fait en deux touches
  const saved = (() => { try { return JSON.parse(localStorage.getItem('jm_live_customer') || '{}') } catch { return {} } })()

  function showError(field, message) {
    const el = $(`[data-error="${field}"]`, form) || $('[data-error="form"]', form)
    el.textContent = message
    el.hidden = !message
  }

  function render(items) {
    items.forEach((item) => {
      const row = $(`[data-live-item="${item.id}"]`, root)
      if (!row) return
      $('[data-remaining]', row).textContent = item.remaining > 0 ? `Il en reste ${item.remaining}` : "Épuisé · liste d'attente"
      $('[data-take]', row).textContent = item.remaining > 0 ? 'Je prends' : "M'inscrire"
    })
  }

  $$('[data-take]', root).forEach((button) => button.addEventListener('click', () => {
    form.elements.item_id.value = button.dataset.take
    $('[data-live-title]', form).textContent = `Réserver la ${button.dataset.code}`
    form.elements.name.value = saved.name || ''
    form.elements.phone.value = saved.phone || ''
    form.elements.place_id.value = saved.place_id || ''
    placeInput.value = saved.place_label || ''
    form.elements.address.value = saved.address || ''
    $$('[data-error]', form).forEach((el) => (el.hidden = true))
    dialog.showModal()
  }))
  $('[data-live-close]', form).addEventListener('click', () => dialog.close())

  placeInput.addEventListener('input', () => {
    form.elements.place_id.value = ''
    clearTimeout(searchTimer)
    const q = placeInput.value.trim()
    if (q.length < 2) { options.hidden = true; return }
    searchTimer = setTimeout(async () => {
      try {
        const { data } = await api('GET', `/live/lieux?q=${encodeURIComponent(q)}`)
        options.innerHTML = data.length
          ? data.map((p) => `<li role="option" tabindex="-1" data-id="${p.id}" class="cursor-pointer px-3 py-2.5 hover:bg-slate-100">${escapeHtml(p.label)}</li>`).join('')
          : '<li class="px-3 py-2.5 text-sm text-slate-500">Aucun lieu trouvé : essayez la commune.</li>'
        options.hidden = false
      } catch {
        options.hidden = true
      }
    }, 250)
  })
  options.addEventListener('click', (event) => {
    const li = event.target.closest('[data-id]')
    if (!li) return
    form.elements.place_id.value = li.dataset.id
    placeInput.value = li.textContent
    options.hidden = true
    showError('place_id', '')
  })

  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    if (!form.elements.place_id.value) { showError('place_id', 'Choisissez votre quartier dans la liste.'); return }
    const submit = $('[data-live-submit]', form)
    submit.disabled = true
    const body = {
      item_id: Number(form.elements.item_id.value), name: form.elements.name.value, phone: form.elements.phone.value,
      place_id: Number(form.elements.place_id.value), address: form.elements.address.value || null,
    }
    try {
      const result = await api('POST', '/live/reserver', body)
      try {
        localStorage.setItem('jm_live_customer', JSON.stringify({ name: body.name, phone: body.phone, place_id: body.place_id, place_label: placeInput.value, address: body.address }))
        Object.assign(saved, { name: body.name, phone: body.phone, place_id: body.place_id, place_label: placeInput.value, address: body.address })
      } catch {
        // stockage indisponible : le client retapera ses informations
      }
      dialog.close()
      toast(escapeHtml(result.message))
      refresh()
    } catch (error) {
      const fields = Object.entries(error.errors || {})
      if (fields.length) fields.forEach(([field, messages]) => showError(field, messages[0]))
      else showError('form', error.message || 'Réservation impossible, réessayez.')
    } finally {
      submit.disabled = false
    }
  })

  async function refresh() {
    try {
      const { data } = await api('GET', '/live/etat')
      if (!data) { location.reload(); return }
      render(data.items)
    } catch {
      // réseau momentané : prochain tour
    }
  }
  setInterval(refresh, 5000)
}

/* ---------- Commun ---------- */

$$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()))

productPage()
cartPage()
checkoutPage()
livePage()

// Lien de parrainage (?parrain=CODE) : code gardé sur le téléphone et proposé à la commande
try {
  const code = new URLSearchParams(location.search).get('parrain')
  if (code) localStorage.setItem('jm_referral', code.slice(0, 12).toUpperCase())
  const field = document.querySelector('[data-referral-code]')
  if (field && !field.value) field.value = localStorage.getItem('jm_referral') ?? ''
} catch {
  // stockage indisponible (navigation privée) : le client tape le code
}
paymentForms()
reviewForm()
promoCode()
countdowns()
