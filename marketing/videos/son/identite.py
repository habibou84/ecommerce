"""
Identité sonore de Jibiat Marchand : musique de fond, logo sonore et effets, tout synthétisé ici (aucune banque
de sons extérieure, donc aucun droit à payer). Et mixage d'une vidéo : voix off + musique baissée sous la voix
+ effets placés aux instants donnés.

    python3 identite.py generer DOSSIER                 # musique-boucle.wav, logo-sonore.wav, effets/*.wav
    python3 identite.py mixer VOIX.mp3 EVENEMENTS.json SORTIE.wav DUREE [TEMPS_LOGO]

EVENEMENTS.json : [[secondes, "nom_de_l_effet", gain], ...] (gain facultatif, 1 par défaut).
"""
import json
import subprocess
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
BPM = 112
BEAT = 60 / BPM
STEP = BEAT / 4  # double croche
BAR = BEAT * 4
rng = np.random.default_rng(7)  # même musique à chaque génération


def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12)


def t_axis(sec):
    return np.arange(int(sec * SR)) / SR


def env(sec, attack=0.003, tau=0.3):
    t = t_axis(sec)
    a = np.clip(t / attack, 0, 1)
    return a * np.exp(-t / tau)


def lowpass(x, cutoff):
    """Filtre passe-bas d'ordre 1 (assez pour adoucir)."""
    a = np.exp(-2 * np.pi * cutoff / SR)
    y = np.empty_like(x)
    acc = 0.0
    for i, v in enumerate(x):
        acc = (1 - a) * v + a * acc
        y[i] = acc
    return y


def lowpass_fast(x, cutoff):
    # même filtre, vectorisé par blocs grâce à une convolution courte (approximation suffisante)
    a = np.exp(-2 * np.pi * cutoff / SR)
    n = int(np.ceil(np.log(1e-4) / np.log(a))) if a > 0 else 1
    k = (1 - a) * a ** np.arange(max(n, 1))
    return np.convolve(x, k)[: len(x)]


def highpass(x, cutoff):
    return x - lowpass_fast(x, cutoff)


# ——— Instruments ———
def kalimba(midi, sec=1.2, vel=1.0):
    f = hz(midi)
    t = t_axis(sec)
    body = np.sin(2 * np.pi * f * t) * env(sec, 0.002, 0.45)
    tine = 0.35 * np.sin(2 * np.pi * f * 5.43 * t) * env(sec, 0.001, 0.05)
    knock = 0.15 * np.sin(2 * np.pi * f * 2.0 * t) * env(sec, 0.002, 0.12)
    return vel * (body + tine + knock)


def marimba(midi, sec=0.6, vel=1.0):
    f = hz(midi)
    t = t_axis(sec)
    x = np.sin(2 * np.pi * f * t) * env(sec, 0.002, 0.22) + 0.25 * np.sin(2 * np.pi * f * 4 * t) * env(sec, 0.001, 0.04)
    return vel * x


def bell(midi, sec=2.5, vel=1.0):
    f = hz(midi)
    t = t_axis(sec)
    parts = [(1, 1.0, 1.4), (2.0, 0.5, 0.9), (2.76, 0.35, 0.6), (5.4, 0.18, 0.25), (8.93, 0.08, 0.15)]
    return vel * sum(a * np.sin(2 * np.pi * f * r * t) * env(sec, 0.001, tau) for r, a, tau in parts)


def bass(midi, sec, vel=1.0):
    f = hz(midi)
    t = t_axis(sec)
    x = np.sin(2 * np.pi * f * t) + 0.3 * np.sin(2 * np.pi * 2 * f * t) + 0.1 * np.sin(2 * np.pi * 3 * f * t)
    e = np.clip(t / 0.006, 0, 1) * np.exp(-t / 0.55)
    e[-int(0.02 * SR):] *= np.linspace(1, 0, int(0.02 * SR))
    return vel * np.tanh(1.4 * x * e) / 1.4


def kick(vel=1.0):
    sec = 0.35
    t = t_axis(sec)
    f = 45 + 95 * np.exp(-t / 0.035)
    ph = 2 * np.pi * np.cumsum(f) / SR
    return vel * np.sin(ph) * env(sec, 0.001, 0.12)


def clap(vel=1.0):
    sec = 0.25
    n = rng.standard_normal(int(sec * SR))
    n = highpass(lowpass_fast(n, 3500), 900)
    e = np.zeros_like(n)
    for d in (0, 0.009, 0.018):
        e += np.roll(env(sec, 0.0005, 0.012 if d < 0.018 else 0.07), int(d * SR))
    return vel * 0.9 * n * e / np.max(np.abs(n * e))


def shaker(vel=1.0):
    sec = 0.09
    n = highpass(rng.standard_normal(int(sec * SR)), 6000)
    e = np.clip(t_axis(sec) / 0.012, 0, 1) * np.exp(-t_axis(sec) / 0.025)
    return vel * n * e / 3


def conga(midi, vel=1.0):
    sec = 0.3
    t = t_axis(sec)
    f = hz(midi) * (1 + 0.25 * np.exp(-t / 0.01))
    return vel * np.sin(2 * np.pi * np.cumsum(f) / SR) * env(sec, 0.001, 0.09)


def pad(midis, sec, vel=1.0):
    t = t_axis(sec)
    x = np.zeros_like(t)
    for m in midis:
        for det in (-0.08, 0.0, 0.08):
            f = hz(m) * 2 ** (det / 12)
            x += np.sin(2 * np.pi * f * t) + 0.2 * np.sin(2 * np.pi * 2 * f * t)
    e = np.clip(t / 0.4, 0, 1) * np.clip((sec - t) / 0.4, 0, 1)
    return vel * x * e / (len(midis) * 3)


def place(buf, sig, at):
    i = int(round(at * SR))
    if i >= len(buf):
        return
    j = min(len(buf), i + len(sig))
    buf[i:j] += sig[: j - i]


# ——— Musique : 8 mesures en boucle, ré majeur, I – V – vi – IV ———
CHORDS = [
    ('D', [62, 66, 69], 38), ('A', [61, 64, 69], 33), ('Bm', [62, 66, 71], 35), ('G', [62, 67, 71], 31),
] * 2
# Motif de la marque (mesures 5 à 8), en doubles croches : (position, note)
HOOK = {
    4: [(0, 81), (3, 78), (4, 81), (6, 83), (10, 81)],
    5: [(0, 81), (4, 76), (8, 73), (11, 76)],
    6: [(0, 83), (4, 86), (8, 83), (10, 81), (12, 78)],
    7: [(0, 79), (4, 81), (8, 78), (12, 74)],
}


def music_loop():
    n_bars = len(CHORDS)
    buf = np.zeros(int(n_bars * BAR * SR) + SR)
    for b, (_, tones, root) in enumerate(CHORDS):
        t0 = b * BAR
        # Nappe
        place(buf, 0.10 * pad([m - 12 for m in tones], BAR + 0.3), t0)
        # Arpège de kalimba en croches, avec des silences pour respirer
        arp = [tones[0], tones[1], tones[2], tones[1] + 12 - 12, tones[2], tones[0] + 12, tones[2], tones[1]]
        for k, m in enumerate(arp):
            if k in (3,) and b % 2:
                continue
            vel = 0.30 if k % 2 == 0 else 0.22
            place(buf, kalimba(m + 12, 0.9, vel * (0.9 + 0.2 * rng.random())), t0 + k * 2 * STEP)
        # Basse syncopée
        for pos, dur in ((0, 5), (6, 2), (10, 4), (14, 2)):
            place(buf, 0.42 * bass(root + (12 if pos == 14 else 0), dur * STEP * 0.95), t0 + pos * STEP)
        # Batterie afro-pop légère
        for pos in (0, 8):
            place(buf, 0.75 * kick(), t0 + pos * STEP)
        for pos in (6, 14):
            place(buf, 0.35 * kick(), t0 + pos * STEP)
        for pos in (4, 12):
            place(buf, 0.14 * clap(), t0 + pos * STEP)
        for pos in range(16):
            place(buf, (0.07 if pos % 2 else 0.035) * shaker(), t0 + pos * STEP)
        for pos, m in ((3, 50), (11, 55), (13, 50)):
            place(buf, 0.16 * conga(m), t0 + pos * STEP)
        # Motif de marimba
        for pos, m in HOOK.get(b, []):
            place(buf, 0.30 * marimba(m, 0.5), t0 + pos * STEP)
    loop_len = int(n_bars * BAR * SR)
    tail = buf[loop_len:]
    out = buf[:loop_len].copy()
    out[: len(tail)] += tail  # la fin de la boucle retombe sur son début : boucle sans coupure
    return out


def music(duration, fade_in=0.8, fade_out=1.6):
    loop = music_loop()
    reps = int(np.ceil(duration * SR / len(loop)))
    x = np.tile(loop, reps)[: int(duration * SR)]
    t = t_axis(duration)[: len(x)]
    x *= np.clip(t / fade_in, 0, 1) * np.clip((duration - t) / fade_out, 0, 1)
    return x


# ——— Logo sonore : ré – fa# – la – ré, puis la cloche ———
def sonic_logo():
    buf = np.zeros(int(3.2 * SR))
    for k, m in enumerate((74, 78, 81, 86)):
        place(buf, 0.55 * kalimba(m, 1.4, 1.0), k * 0.11)
        place(buf, 0.25 * marimba(m - 12, 0.5), k * 0.11)
    place(buf, 0.35 * bell(86, 2.6) + 0.18 * bell(93, 2.6), 0.46)
    place(buf, 0.5 * kick(), 0.46)
    place(buf, 0.2 * pad([62, 66, 69], 2.4), 0.46)
    return buf


# ——— Effets ———
def sfx_all():
    fx = {}
    t = t_axis(0.09)
    f = 520 + 900 * (t / 0.09)
    fx['pop'] = 0.6 * np.sin(2 * np.pi * np.cumsum(f) / SR) * env(0.09, 0.002, 0.03)  # bulle de message
    t = t_axis(0.05)
    fx['tap'] = 0.5 * (np.sin(2 * np.pi * 1900 * t) * env(0.05, 0.0005, 0.008) + 0.4 * highpass(rng.standard_normal(len(t)), 3000) * env(0.05, 0.0003, 0.004))
    t = t_axis(0.04)
    fx['key'] = 0.35 * highpass(rng.standard_normal(len(t)), 2500) * env(0.04, 0.0003, 0.006) + 0.15 * np.sin(2 * np.pi * 900 * t) * env(0.04, 0.0005, 0.01)
    sec = 0.45  # transition
    n = rng.standard_normal(int(sec * SR))
    tt = t_axis(sec)
    sweep = np.sin(np.pi * tt / sec) ** 2
    fx['whoosh'] = 0.35 * (highpass(lowpass_fast(n, 2500), 300) * sweep)
    fx['whoosh_soft'] = 0.5 * fx['whoosh']
    fx['ding'] = 0.45 * (bell(88, 0.9, 1) + 0.8 * np.concatenate([np.zeros(int(0.09 * SR)), bell(93, 0.81, 1)]))  # notification
    coin = sum(a * np.sin(2 * np.pi * f * t_axis(0.7)) * env(0.7, 0.001, tau) for f, a, tau in ((2093, 1, 0.25), (3136, 0.6, 0.18), (4186, 0.4, 0.12), (5274, 0.25, 0.08)))
    ka = 0.5 * highpass(rng.standard_normal(int(0.06 * SR)), 2000) * env(0.06, 0.0005, 0.012)
    cash = np.zeros(int(0.85 * SR))
    place(cash, ka, 0)
    place(cash, 0.35 * coin, 0.05)
    place(cash, 0.25 * coin, 0.16)
    fx['cash'] = cash  # encaissement
    fx['coin'] = 0.2 * coin[: int(0.25 * SR)] * env(0.25, 0.001, 0.06)
    ok = np.zeros(int(1.2 * SR))
    for k, m in enumerate((74, 78, 81)):
        place(ok, 0.35 * kalimba(m + 12, 0.9), k * 0.08)
    place(ok, 0.2 * bell(93, 0.9), 0.24)
    fx['success'] = ok  # commande confirmée
    vib = np.zeros(int(0.7 * SR))
    tv = t_axis(0.22)
    buzz = np.sign(np.sin(2 * np.pi * 160 * tv)) * 0.25 + 0.2 * np.sin(2 * np.pi * 80 * tv)
    buzz = lowpass_fast(buzz, 900) * np.clip(tv / 0.01, 0, 1) * np.clip((0.22 - tv) / 0.02, 0, 1)
    place(vib, buzz, 0)
    place(vib, buzz, 0.3)
    fx['vibrate'] = 0.7 * vib
    sec = 0.4
    tt = t_axis(sec)
    fl = 160 * np.exp(-tt / 0.15) + 55
    fx['stamp'] = 0.9 * np.sin(2 * np.pi * np.cumsum(fl) / SR) * env(sec, 0.001, 0.1) + 0.3 * lowpass_fast(rng.standard_normal(len(tt)), 1200) * env(sec, 0.001, 0.03)
    sec = 0.5
    tt = t_axis(sec)
    fe = 330 * np.exp(-tt / 0.6)
    tone = np.sin(2 * np.pi * np.cumsum(fe) / SR) + 0.5 * np.sin(2 * np.pi * np.cumsum(fe * 1.5) / SR)
    fx['error'] = 0.35 * lowpass_fast(tone, 1500) * env(sec, 0.003, 0.18)  # problème
    sec = 0.12
    fx['shutter'] = 0.6 * highpass(rng.standard_normal(int(sec * SR)), 1500) * (env(sec, 0.0005, 0.01) + np.roll(env(sec, 0.0005, 0.015), int(0.05 * SR)))
    sec = 1.2
    tt = t_axis(sec)
    sh = sum(np.sin(2 * np.pi * hz(m) * tt) * env(sec, 0.002, 0.5) for m in (86, 90, 93, 98)) / 4
    fx['shimmer'] = 0.3 * sh * np.clip(tt / 0.05, 0, 1)
    fx['tick'] = 0.25 * marimba(98, 0.15)
    fx['ring'] = 0.25 * np.concatenate([np.sin(2 * np.pi * 440 * t_axis(0.4)) * np.sin(2 * np.pi * 20 * t_axis(0.4)) * env(0.4, 0.01, 0.3), np.zeros(int(0.1 * SR))])
    return fx


def write_wav(path, x, stereo=True):
    x = np.clip(x, -1, 1)
    data = (x * 32767).astype('<i2')
    if stereo:
        data = np.repeat(data[:, None], 2, axis=1)
    with wave.open(str(path), 'wb') as w:
        w.setnchannels(2 if stereo else 1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(data.tobytes())


def read_audio(path):
    raw = subprocess.run(['ffmpeg', '-loglevel', 'error', '-i', str(path), '-f', 's16le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True, check=True).stdout
    return np.frombuffer(raw, '<i2').astype(np.float64) / 32768


def generate(folder):
    folder = Path(folder)
    (folder / 'effets').mkdir(parents=True, exist_ok=True)
    loop = music_loop()
    write_wav(folder / 'musique-boucle.wav', 0.8 * loop / np.max(np.abs(loop)))
    logo = sonic_logo()
    write_wav(folder / 'logo-sonore.wav', 0.8 * logo / np.max(np.abs(logo)))
    for name, sig in sfx_all().items():
        write_wav(folder / 'effets' / f'{name}.wav', sig)


def mix(voice_path, events_path, out_path, duration, logo_at=None):
    duration = float(duration)
    n = int(duration * SR)
    voice = np.zeros(n)
    v = read_audio(voice_path)
    voice[: min(n, len(v))] = v[:n]
    # Musique : fond constant, baissée quand la voix parle (enveloppe lissée de la voix)
    m = music(duration)
    m = m / np.max(np.abs(m))
    # Enveloppe de la voix par blocs de 10 ms, montée rapide et retour lent, puis ramenée à l'échelle des échantillons
    blk = SR // 100
    nb = int(np.ceil(n / blk))
    rms = np.sqrt(np.array([np.mean(voice[i * blk:(i + 1) * blk] ** 2) for i in range(nb)]))
    rms = rms / (np.max(rms) + 1e-9)
    target = np.clip(rms / 0.12, 0, 1)
    sm = np.zeros(nb)
    acc = 0.0
    for i, v in enumerate(target):
        acc += (v - acc) * (0.5 if v > acc else 0.04)  # ~20 ms pour baisser, ~250 ms pour remonter
        sm[i] = acc
    duck = np.interp(np.arange(n), np.arange(nb) * blk, sm)
    music_gain = 0.20 * (1 - 0.6 * duck)
    if logo_at is not None:  # la musique s'efface sous le logo sonore
        t = t_axis(duration)[:n]
        music_gain *= np.clip(1 - (t - float(logo_at)) / 0.6, 0.25, 1)
    out = voice * 0.92 + m * music_gain
    fx = sfx_all()
    for ev in json.loads(Path(events_path).read_text()):
        at, name = ev[0], ev[1]
        gain = ev[2] if len(ev) > 2 else 1.0
        place(out, 0.3 * gain * fx[name], at)
    if logo_at is not None:
        logo = sonic_logo()
        place(out, 0.5 * logo / np.max(np.abs(logo)), float(logo_at))
    peak = np.max(np.abs(out))
    if peak > 0.97:
        out = np.tanh(out / peak * 1.2) / np.tanh(1.2) * 0.97
    write_wav(out_path, out)


if __name__ == '__main__':
    if sys.argv[1] == 'generer':
        generate(sys.argv[2])
    elif sys.argv[1] == 'mixer':
        mix(*sys.argv[2:])
