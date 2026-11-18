#!/usr/bin/env node
/**
 * Fabrique les vidéos « Les étapes essentielles pour commencer » (public/videos/etapes) à partir des vrais écrans de
 * l'application : ouvre la boutique de démonstration, photographie chaque écran, l'habille (titre, téléphone, cadre
 * vert sur ce qu'il faut toucher) et assemble les images en vidéo. Le contenu de chaque vidéo est dans etapes.cjs.
 *
 *   node tools/videos/fabriquer.cjs            toutes les vidéos
 *   node tools/videos/fabriquer.cjs 4 6        seulement les étapes 4 et 6
 *   node tools/videos/fabriquer.cjs --images   les images seulement (dossier de travail), sans vidéo
 *
 * Voir tools/videos/README.md pour la préparation (application lancée, boutique de démonstration, ffmpeg).
 */
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')
const { chromium } = require('playwright')
const { ETAPES, preparer } = require('./etapes.cjs')

const ROOT = path.resolve(__dirname, '../..')
const OUT = path.join(ROOT, 'public/videos/etapes')
const args = process.argv.slice(2)
const option = (name, fallback) => (args.find((a) => a.startsWith(`--${name}=`)) ?? `--${name}=${fallback}`).split('=').slice(1).join('=')
const only = args.filter((a) => /^\d+$/.test(a)).map(Number)
const imagesOnly = args.includes('--images')
const CONFIG = {
  boutique: option('boutique', 'http://demo.localhost:8000'),
  site: option('site', 'http://localhost:8000'),
  telephone: option('telephone', '0700000001'),
  livreur: option('livreur', '0700000003'),
  motDePasse: option('mot-de-passe', 'password'),
  travail: option('travail', path.join(os.tmpdir(), 'jibiat-videos')),
}

// Téléphone photographié : 390 × 844, comme dans l'application ; image finale 1080 × 1920 (vidéo verticale)
const PHONE = { width: 390, height: 844 }
const FRAME = { width: 1080, height: 1920 }
const SCREEN_W = 492
const SCALE = SCREEN_W / PHONE.width
const FADE = 0.45

const dataUri = (file, type) => `data:${type};base64,${fs.readFileSync(file).toString('base64')}`
const LOGO = dataUri(path.join(ROOT, 'public/brand/logo-256.png'), 'image/png')
const APP_ICON = dataUri(path.join(ROOT, 'public/brand/icon-512.png'), 'image/png')
const BAR = 46 // barre d'état du téléphone, au-dessus de l'écran photographié
const FONT = dataUri(path.join(ROOT, 'resources/fonts/inter-latin-var.woff2'), 'font/woff2')
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

const CSS = `
@font-face { font-family: Inter; src: url(${FONT}) format('woff2'); font-weight: 100 900; }
* { margin: 0; box-sizing: border-box; }
body { width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; font-family: Inter, sans-serif; color: #fff; text-align: center;
  background: radial-gradient(ellipse 75% 46% at 50% 44%, #0d5a2c 0%, #083a1d 38%, #04190d 72%, #020d07 100%); }
.green { color: #6ee7a0; }
.top { position: absolute; left: 60px; right: 60px; top: 132px; }
h1 { font-size: 80px; line-height: 1.08; font-weight: 800; letter-spacing: -1.5px; }
.sub { margin: 26px auto 0; max-width: 940px; font-size: 39px; line-height: 1.28; color: #cfe3d6; }
.brand { margin-top: 34px; display: flex; align-items: center; justify-content: center; gap: 16px; font-size: 31px; font-weight: 700; color: #e6f2ea; }
.brand img { width: 52px; height: 52px; padding: 6px; border-radius: 12px; background: #fff; }
.brand span { font-weight: 500; color: #9db8a8; }
.phone { position: absolute; left: 50%; top: 676px; transform: translateX(-50%); width: ${SCREEN_W + 36}px; padding: 18px;
  background: #0b0f14; border-radius: 78px; box-shadow: 0 0 0 3px #232a33, 0 40px 120px rgba(0,0,0,.55); }
.screen { position: relative; width: ${SCREEN_W}px; height: ${BAR + Math.round(PHONE.height * SCALE)}px; overflow: hidden; border-radius: 62px; background: #fff; text-align: left; }
.screen > img { display: block; width: ${SCREEN_W}px; margin-top: ${BAR}px; }
.screen > .clock { position: absolute; left: 44px; top: 13px; font-size: 21px; font-weight: 700; color: #0b0f14; z-index: 5; }
.island { position: absolute; left: 50%; top: 9px; transform: translateX(-50%); width: 150px; height: 32px; border-radius: 22px; background: #0b0f14; z-index: 5; }
.mark { position: absolute; border: 5px solid #34d27b; border-radius: 22px; box-shadow: 0 0 0 6px rgba(52,210,123,.28), 0 0 44px 10px rgba(52,210,123,.55); z-index: 4; }
.center { position: absolute; left: 70px; right: 70px; top: 50%; transform: translateY(-58%); }
.pill { display: inline-block; padding: 12px 30px; border-radius: 99px; background: #17c258; color: #04190d; font-size: 34px; font-weight: 800; }
.icon { display: block; margin: 34px auto 54px; width: 300px; height: 300px; padding: 34px; border-radius: 68px; background: #fff; box-shadow: 0 30px 90px rgba(0,0,0,.45); }
.center h1 { font-size: 86px; }
.center .sub { font-size: 42px; }
.site { display: inline-block; margin-top: 96px; padding: 26px 50px; border-radius: 28px; background: #17c258; color: #04190d; font-size: 40px; font-weight: 800; }
/* Écrans dessinés : discussion WhatsApp, menu du navigateur, écran d'accueil du téléphone */
.wa { height: 100%; background: #efe7dd; font-size: 25px; line-height: 1.32; color: #111b21; }
.wa header { display: flex; align-items: center; gap: 16px; padding: 62px 26px 20px; background: #0b5d4c; color: #fff; }
.wa header img { width: 60px; height: 60px; border-radius: 50%; background: #fff; }
.wa header b { display: block; font-size: 28px; } .wa header small { font-size: 20px; opacity: .8; }
.wa .msgs { padding: 26px 22px; display: flex; flex-direction: column; gap: 16px; }
.wa .m { max-width: 82%; padding: 14px 18px; border-radius: 18px; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.12); white-space: pre-line; }
.wa .m.me { align-self: flex-end; background: #d9fdd3; }
.wa .btn { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8e4; text-align: center; color: #0a7cff; font-weight: 600; }
.veil { position: absolute; inset: 0; background: rgba(15,23,42,.42); z-index: 2; }
.menu { position: absolute; z-index: 3; background: #fff; border-radius: 22px; box-shadow: 0 18px 60px rgba(0,0,0,.35); font-size: 26px; color: #1f2937; overflow: hidden; }
.menu.haut { right: 14px; top: 60px; width: 330px; } .menu.bas { left: 14px; right: 14px; bottom: 230px; }
.menu p { padding: 19px 26px; border-bottom: 1px solid #eef1f0; } .menu p.on { font-weight: 800; background: #eafaf0; }
.home { height: 100%; padding: 150px 34px 0; background: linear-gradient(160deg, #1e3a8a, #6d28d9 55%, #db2777); display: grid; grid-template-columns: repeat(4, 1fr); gap: 34px 22px; align-content: start; }
.home div { text-align: center; color: #fff; font-size: 17px; } .home i { display: block; margin: 0 auto 8px; width: 84px; height: 84px; border-radius: 22px; background: rgba(255,255,255,.28); }
.home img { display: block; margin: 0 auto 8px; width: 84px; height: 84px; border-radius: 22px; }
`

function drawnScreen(scene) {
  if (scene.discussion) {
    const d = scene.discussion
    return `<div class="wa"><header><img src="${LOGO}"><div><b>${esc(d.nom)}</b><small>${esc(d.sous ?? 'Compte professionnel')}</small></div></header><div class="msgs">${d.messages.map((m) =>
      `<div class="m ${m.moi ? 'me' : ''}">${esc(m.texte)}${(m.boutons ?? []).map((b) => `<div class="btn">${esc(b)}</div>`).join('')}</div>`).join('')}</div></div>`
  }
  if (scene.accueilTelephone) {
    const icons = Array.from({ length: 11 }, (_, i) => (i === 5 ? `<div><img src="${APP_ICON}">${esc(scene.accueilTelephone)}</div>` : '<div><i></i>&nbsp;</div>')).join('')
    return `<div class="home">${icons}</div>`
  }
  return null
}

function frameHtml(etape, scene, shot, withMark) {
  if (scene.type === 'debut') {
    return `<style>${CSS}</style><div class="center"><span class="pill">Étape ${etape.n} sur ${ETAPES.length}</span><img class="icon" src="${LOGO}">
      <h1>${esc(etape.titre[0])}<br><span class="green">${esc(etape.titre[1])}</span></h1><p class="sub">${esc(etape.accroche)}</p></div>`
  }
  if (scene.type === 'fin') {
    const next = ETAPES.find((e) => e.n === etape.n + 1)
    return `<style>${CSS}</style><div class="center"><img class="icon" src="${LOGO}"><h1>${next ? 'C\'est fait ✅' : 'Vous êtes prêt 🎉'}</h1>
      <p class="sub">${next ? `Étape suivante : ${esc(next.suite)}` : 'Votre boutique tourne. Bonnes ventes !'}</p><span class="site">jibiat-marchand.com</span></div>`
  }
  const drawn = drawnScreen(scene)
  const menu = scene.menu ? `<div class="veil"></div><div class="menu ${scene.menu.bas ? 'bas' : 'haut'}">${scene.menu.lignes.map((l, i) => `<p class="${i === scene.menu.choix ? 'on' : ''}">${esc(l)}</p>`).join('')}</div>` : ''
  const mark = withMark && shot?.mark
    ? `<div class="mark" style="left:${shot.mark.x * SCALE - 8}px;top:${shot.mark.y * SCALE - 8 + BAR}px;width:${shot.mark.width * SCALE + 16}px;height:${shot.mark.height * SCALE + 16}px"></div>` : ''
  return `<style>${CSS}</style><div class="top"><h1>${esc(scene.titre[0])}<br><span class="green">${esc(scene.titre[1])}</span></h1><p class="sub">${esc(scene.texte)}</p>
    <div class="brand"><img src="${LOGO}">Jibiat Marchand <span>· ${esc(scene.note ?? `étape ${etape.n}`)}</span></div></div>
    <div class="phone"><div class="screen"><div class="island"></div>${drawn ? '' : '<span class="clock">9:41</span>'}${drawn ?? `<img src="${dataUri(shot.file, 'image/png')}">`}${menu}${mark}</div></div>`
}

async function login(context, url, phone) {
  const page = await context.newPage()
  await page.goto(url, { waitUntil: 'networkidle' })
  await page.locator('input').first().fill(phone)
  await page.locator('input[type=password]').fill(CONFIG.motDePasse)
  await page.locator('input[type=password]').press('Enter')
  await page.waitForURL((u) => !u.pathname.endsWith('/connexion'), { timeout: 20000 })
  // Aide de la première fois sur l'accueil : déjà vue
  await page.evaluate(() => localStorage.setItem('jm.swipe-hint-done', '1'))
  return page
}

/** Photographie l'écran d'une scène ; renvoie le fichier et le cadre à montrer (en points du téléphone). */
async function capture(pages, scene, file) {
  const e = scene.ecran
  const page = pages[e.zone ?? 'boutique']
  const base = e.zone === 'site' ? CONFIG.site : CONFIG.boutique
  await page.goto(base + e.url, { waitUntil: 'networkidle', timeout: 45000 })
  await page.waitForTimeout(700)
  if (e.avant) await e.avant(page)
  let mark = null
  if (e.cadre) {
    const target = page.locator(e.cadre).first()
    await target.waitFor({ state: 'visible', timeout: 15000 })
    await target.evaluate((el, block) => el.scrollIntoView({ block, behavior: 'instant' }), e.place ?? 'center')
    await page.waitForTimeout(350)
    const box = await target.boundingBox()
    const top = Math.max(box.y, 4)
    const bottom = Math.min(box.y + box.height, PHONE.height - 4)
    mark = { x: Math.max(box.x, 6), y: top, width: Math.min(box.width, PHONE.width - 12), height: bottom - top }
  }
  await page.screenshot({ path: file })
  return { file, mark }
}

function assemble(clips, output) {
  const inputs = clips.flatMap((c) => ['-loop', '1', '-t', c.seconds.toFixed(2), '-i', c.file])
  let filter = ''
  let last = '0:v'
  let length = clips[0].seconds
  clips.slice(1).forEach((c, i) => {
    const fade = c.fade ?? FADE
    filter += `[${last}][${i + 1}:v]xfade=transition=fade:duration=${fade}:offset=${(length - fade).toFixed(2)}[v${i + 1}];`
    last = `v${i + 1}`
    length += c.seconds - fade
  })
  filter += `[${last}]format=yuv420p[out]`
  execFileSync('ffmpeg', ['-v', 'error', '-y', ...inputs, '-filter_complex', filter, '-map', '[out]', '-r', '30', '-c:v', 'libx264', '-preset', 'slow', '-crf', '24',
    '-movflags', '+faststart', '-an', output], { stdio: 'inherit' })
  return length
}

async function main() {
  fs.mkdirSync(CONFIG.travail, { recursive: true })
  const browser = await chromium.launch()
  const phone = { viewport: PHONE, deviceScaleFactor: 3, locale: 'fr-FR' }
  // Sans écran, le navigateur refuse les notifications : l'application doit montrer son bouton « Activer », comme chez le marchand
  const store = await browser.newContext(phone)
  await store.addInitScript(() => Object.defineProperty(Notification, 'permission', { get: () => 'default' }))
  const pages = {
    boutique: await login(store, `${CONFIG.boutique}/gestion/connexion`, CONFIG.telephone),
    livreur: await login(await browser.newContext(phone), `${CONFIG.boutique}/livreur/connexion`, CONFIG.livreur),
    site: await (await browser.newContext(phone)).newPage(),
  }
  await preparer(pages, CONFIG)
  const painter = await (await browser.newContext({ viewport: FRAME, deviceScaleFactor: 1 })).newPage()
  const paint = async (html, file) => {
    await painter.setContent(`<!doctype html><meta charset="utf-8">${html}`, { waitUntil: 'load' })
    await painter.evaluate(() => document.fonts.ready)
    await painter.screenshot({ path: file })
  }

  const seconds = {}
  for (const etape of ETAPES.filter((e) => !only.length || only.includes(e.n))) {
    const id = String(etape.n).padStart(2, '0')
    const dir = path.join(CONFIG.travail, id)
    fs.mkdirSync(dir, { recursive: true })
    const clips = []
    const scenes = [{ type: 'debut' }, ...etape.scenes, { type: 'fin' }]
    for (const [i, scene] of scenes.entries()) {
      const name = path.join(dir, `scene-${String(i).padStart(2, '0')}`)
      if (scene.type) {
        await paint(frameHtml(etape, scene), `${name}.png`)
        clips.push({ file: `${name}.png`, seconds: scene.type === 'debut' ? 2.8 : 3.4 })
        continue
      }
      const shot = scene.ecran ? await capture(pages, scene, `${name}-ecran.png`) : null
      // Le temps de lire : titre et texte
      const reading = Math.max(3.4, `${scene.titre.join(' ')} ${scene.texte}`.split(/\s+/).length * 0.3)
      if (shot?.mark) {
        await paint(frameHtml(etape, scene, shot, false), `${name}-a.png`)
        await paint(frameHtml(etape, scene, shot, true), `${name}-b.png`)
        clips.push({ file: `${name}-a.png`, seconds: 1.3 }, { file: `${name}-b.png`, seconds: reading, fade: 0.3 })
      } else {
        await paint(frameHtml(etape, scene, shot, false), `${name}-a.png`)
        clips.push({ file: `${name}-a.png`, seconds: reading + 0.8 })
      }
    }
    if (imagesOnly) {
      console.log(`Étape ${etape.n} : ${clips.length} images dans ${dir}`)
      continue
    }
    const length = assemble(clips, path.join(OUT, `etape-${id}.mp4`))
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', clips[0].file, '-vf', 'scale=540:-1', '-q:v', '4', path.join(OUT, `etape-${id}.jpg`)])
    seconds[etape.n] = Math.round(length)
    console.log(`Étape ${etape.n} : ${Math.round(length)} s, ${clips.length} images → public/videos/etapes/etape-${id}.mp4`)
  }
  await browser.close()

  if (!imagesOnly) {
    // Durées et numéro de version (les navigateurs rechargent les vidéos) lus par App\Support\StartSteps
    const file = path.join(OUT, 'etapes.json')
    const known = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : { seconds: {} }
    const now = new Date()
    const version = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}${String(now.getHours()).padStart(2, '0')}`
    fs.writeFileSync(file, `${JSON.stringify({ version, seconds: { ...known.seconds, ...seconds } }, null, 2)}\n`)
  }
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
