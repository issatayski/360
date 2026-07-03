"""e2c.py — equirect → 6 граней куба (pixel-часть тайлера, CLAUDE.md §3).

Вызывается из src/tiler/tile.js. Использует py360convert (гномоническая проекция),
чтобы не ловить швы на самодельной проекции (§8.3).

    python src/tiler/e2c.py --in input/01-hall.jpg --out work/faces/01-hall --size 2048

Пишет 6 PNG: <out>/{f,r,b,l,u,d}.png  (буквы граней — как в путях тайлов Marzipano).
"""
import argparse
import os

import numpy as np
from PIL import Image
import py360convert

# py360convert отдаёт грани по ключам F,R,B,L,U,D; Marzipano ждёт буквы f,r,b,l,u,d.
KEYS = ["F", "R", "B", "L", "U", "D"]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="src", required=True)
    ap.add_argument("--out", dest="out", required=True)
    ap.add_argument("--size", type=int, default=2048, help="сторона грани, кратна 512")
    args = ap.parse_args()

    if args.size % 512 != 0:
        raise SystemExit(f"--size {args.size} должен быть кратен 512")

    e = np.asarray(Image.open(args.src).convert("RGB"))
    faces = py360convert.e2c(e, face_w=args.size, mode="bilinear", cube_format="dict")

    os.makedirs(args.out, exist_ok=True)
    for k in KEYS:
        Image.fromarray(faces[k]).save(os.path.join(args.out, k.lower() + ".png"))
    print(f"e2c: {args.src} -> {args.out} ({len(KEYS)} faces @ {args.size})")


if __name__ == "__main__":
    main()
