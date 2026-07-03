"""triage/validate.py — валидация входных панорам (CLAUDE.md §2).

Проверки:
  - aspect   : equirect обязан быть 2:1 (иначе reject — нельзя показать как панораму)
  - sharpness: дисперсия лапласиана (низкая = размыто)
  - exposure : средняя яркость + доля пере/недо-экспонированных пикселей
  - coverage : доля почти-чёрных пикселей (дыры/незаполненная сфера)

Итог на сцену → quality.status: ok | enhance | reject
  reject  — показывать нельзя (не 2:1, почти чёрная, безнадёжно размыто)
  enhance — показать можно, но стоит прогнать enhance (мягко размыто / экспозиция)
  ok      — годно как есть

Пишет статусы обратно в manifest (--manifest, in-place по умолчанию) и JSON-отчёт
в --report. Идемпотентно.

  python src/triage/validate.py --in input/ --manifest input/manifest.json
"""
import argparse
import json
import os
import sys

import cv2
import numpy as np

# Пороги (подобраны под MVP; вынести в конфиг при необходимости)
ASPECT_TOL = 0.02        # |w/h - 2| допустимое отклонение
SHARP_REJECT = 40.0      # var(Laplacian) ниже — безнадёжно размыто
SHARP_ENHANCE = 120.0    # ниже — мягко размыто (кандидат на enhance)
DARK_MEAN = 40           # средняя яркость ниже — недоэкспонировано
BRIGHT_MEAN = 215        # выше — переэкспонировано
CLIP_FRAC = 0.35         # доля клиппированных (0/255) пикселей выше — плохо
BLACK_COVERAGE = 0.20    # доля почти-чёрных пикселей выше — дыры/нет покрытия


def analyze(path):
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        return {"error": "не удалось прочитать файл"}

    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    aspect = w / h
    sharp = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    mean = float(gray.mean())
    clip_lo = float((gray <= 2).mean())
    clip_hi = float((gray >= 253).mean())
    black = float((gray <= 8).mean())

    m = {
        "width": w, "height": h, "aspect": round(aspect, 4),
        "sharpness": round(sharp, 1), "brightness": round(mean, 1),
        "clip_low": round(clip_lo, 3), "clip_high": round(clip_hi, 3),
        "black_coverage": round(black, 3),
    }

    reasons = []
    status = "ok"

    def demote(to, reason):
        nonlocal status
        order = {"ok": 0, "enhance": 1, "reject": 2}
        if order[to] > order[status]:
            status = to
        reasons.append(reason)

    if abs(aspect - 2.0) > ASPECT_TOL:
        demote("reject", f"не 2:1 (aspect={aspect:.3f})")
    if black > BLACK_COVERAGE:
        demote("reject", f"чёрных пикселей {black:.0%} (нет покрытия)")
    if sharp < SHARP_REJECT:
        demote("reject", f"крайне размыто (sharp={sharp:.0f})")
    elif sharp < SHARP_ENHANCE:
        demote("enhance", f"мягко размыто (sharp={sharp:.0f})")

    if mean < DARK_MEAN:
        demote("enhance", f"тёмный кадр (яркость={mean:.0f})")
    elif mean > BRIGHT_MEAN:
        demote("enhance", f"пересвет (яркость={mean:.0f})")
    if clip_lo > CLIP_FRAC or clip_hi > CLIP_FRAC:
        demote("enhance", f"клиппинг (низ={clip_lo:.0%}, верх={clip_hi:.0%})")

    m["status"] = status
    m["reasons"] = reasons
    return m


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="indir", default="input")
    ap.add_argument("--manifest", default="input/manifest.json")
    ap.add_argument("--report", default="work/triage-report.json")
    ap.add_argument("--dry-run", action="store_true", help="не писать статусы в manifest")
    args = ap.parse_args()

    with open(args.manifest, encoding="utf-8") as f:
        manifest = json.load(f)

    report = []
    worst = "ok"
    order = {"ok": 0, "enhance": 1, "reject": 2}

    for scene in manifest.get("scenes", []):
        file = scene.get("file") or f"{scene['id']}.jpg"
        path = os.path.join(args.indir, file)
        if not os.path.exists(path):
            res = {"error": f"файл не найден: {path}", "status": "reject", "reasons": ["нет файла"]}
        else:
            res = analyze(path)
            if "error" in res:
                res["status"] = "reject"
                res["reasons"] = [res["error"]]

        st = res.get("status", "reject")
        if order[st] > order[worst]:
            worst = st
        if not args.dry_run:
            scene.setdefault("quality", {})["status"] = st
            scene["quality"]["reasons"] = res.get("reasons", [])

        report.append({"id": scene["id"], "file": file, **res})
        mark = {"ok": "OK  ", "enhance": "ENH ", "reject": "REJ "}[st]
        why = "" if not res.get("reasons") else "  <- " + "; ".join(res["reasons"])
        print(f"  [{mark}] {scene['id']:<14} "
              f"sharp={res.get('sharpness','?'):>6}  bright={res.get('brightness','?'):>5}"
              f"  aspect={res.get('aspect','?')}{why}")

    if not args.dry_run:
        with open(args.manifest, "w", encoding="utf-8") as f:
            json.dump(manifest, f, ensure_ascii=False, indent=2)

    os.makedirs(os.path.dirname(args.report) or ".", exist_ok=True)
    with open(args.report, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    n = len(report)
    ok = sum(1 for r in report if r.get("status") == "ok")
    enh = sum(1 for r in report if r.get("status") == "enhance")
    rej = sum(1 for r in report if r.get("status") == "reject")
    print(f"triage: {n} сцен — ok={ok} enhance={enh} reject={rej}; отчёт -> {args.report}")

    # ненулевой код, если есть brak — orchestrate решает, останавливаться ли
    sys.exit(2 if rej else 0)


if __name__ == "__main__":
    main()
