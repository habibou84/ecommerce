// Onglets qui débordent (téléphone) : l'onglet ouvert est amené à l'écran, et un fondu signale qu'il y en a d'autres
// à droite ou à gauche
function update(el) {
  el.dataset.moreLeft = el.scrollLeft > 4 ? '1' : ''
  el.dataset.moreRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 4 ? '1' : ''
}

function reveal(el) {
  const active = el.querySelector('.border-emerald-700, [aria-current="page"]')
  if (active && el.scrollWidth > el.clientWidth) {
    // Position mesurée dans la ligne elle-même (offsetLeft dépend du parent positionné, pas toujours la ligne)
    const left = active.getBoundingClientRect().left - el.getBoundingClientRect().left + el.scrollLeft
    el.scrollLeft = left - (el.clientWidth - active.offsetWidth) / 2
  }
  update(el)
}

export const tabStrip = {
  mounted(el) {
    el.classList.add('tab-strip')
    el._tabStrip = () => update(el)
    el.addEventListener('scroll', el._tabStrip, { passive: true })
    window.addEventListener('resize', el._tabStrip)
    requestAnimationFrame(() => reveal(el))
  },
  updated(el) {
    requestAnimationFrame(() => reveal(el))
  },
  unmounted(el) {
    el.removeEventListener('scroll', el._tabStrip)
    window.removeEventListener('resize', el._tabStrip)
  },
}
