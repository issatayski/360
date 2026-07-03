"""Генератор синтетических 360° equirectangular-фикстур (2:1) для прогона пайплайна.

Реальные панорамы клиента в git не коммитятся (input/*.jpg в .gitignore), поэтому
тестовые панорамы воспроизводимы этим скриптом (CLAUDE.md §11):

    python tools/make-fixtures.py            # 4 годные сцены
    python tools/make-fixtures.py --varied   # + брак/enhance для проверки triage

Каждая панорама несёт ориентиры (N/E/S/W на горизонте, сетка по yaw/pitch, метка
сцены) — так видно yaw/pitch/heading в туре.
"""
import argparse
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 2048, 1024

SCENES = [
    ("01-hall",    "HALL",    (44, 62, 90)),
    ("02-kitchen", "KITCHEN", (90, 62, 44)),
    ("03-living",  "LIVING",  (44, 90, 62)),
    ("04-bedroom", "BEDROOM", (78, 52, 90)),
]


def font(size):
    for path in (r"C:\Windows\Fonts\arialbd.ttf", r"C:\Windows\Fonts\arial.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def base_pano(label, base):
    img = Image.new("RGB", (W, H), base)
    d = ImageDraw.Draw(img)

    # вертикальный градиент sky->floor (виден pitch)
    for y in range(H):
        t = y / H
        d.line([(0, y), (W, y)], fill=tuple(min(int(c * (0.5 + t)), 255) for c in base))

    # сетка по pitch каждые 30°, горизонт жирный
    for pitch in range(-90, 91, 30):
        y = int((90 - pitch) / 180 * H)
        d.line([(0, y), (W, y)],
               fill=(255, 235, 120) if pitch == 0 else (255, 255, 255),
               width=4 if pitch == 0 else 1)

    # сетка по yaw каждые 45°
    for yaw in range(0, 360, 45):
        x = int(yaw / 360 * W)
        d.line([(x, 0), (x, H)], fill=(255, 255, 255), width=1)

    # стороны света: N=0 E=90 S=180 W=270
    fc = font(64)
    for name, yaw in (("N", 0), ("E", 90), ("S", 180), ("W", 270)):
        d.text((int(yaw / 360 * W) + 8, H // 2 - 80), name, fill=(255, 235, 120), font=fc)

    # метка сцены по центру
    fb = font(140)
    bb = d.textbbox((0, 0), label, font=fb)
    d.text((W // 2 - (bb[2] - bb[0]) // 2, H // 2 + 40), label, fill=(255, 255, 255), font=fb)
    return img


def make_capture(out_dir, base_pano_img, scene_id="01-hall", hfov=70.0, wf=800, hf=600):
    """Нарезать equirect на серию плоских кадров под известными углами (эмуляция
    съёмки телефоном) + capture-манифест — тестовые данные для пути B (stitch)."""
    import json
    import numpy as np
    import py360convert

    e = np.asarray(base_pano_img.convert("RGB"))
    vfov = float(np.rad2deg(2 * np.arctan(np.tan(np.deg2rad(hfov) / 2) * hf / wf)))
    poses = []
    for pitch, step in [(0, 45), (40, 45), (-40, 45), (75, 90), (-75, 90)]:
        poses += [(float(y), float(pitch)) for y in range(0, 360, step)]
    poses += [(0.0, 90.0), (0.0, -90.0)]  # зенит/надир

    fdir = os.path.join(out_dir, scene_id)
    os.makedirs(fdir, exist_ok=True)
    frames = []
    for k, (yaw, pitch) in enumerate(poses):
        pers = py360convert.e2p(e, fov_deg=(hfov, vfov), u_deg=yaw, v_deg=pitch,
                                out_hw=(hf, wf), mode="bilinear")
        fn = f"{scene_id}/f{k:02d}.jpg"
        Image.fromarray(pers).save(os.path.join(out_dir, fn), "JPEG", quality=95)
        frames.append({"file": fn, "yaw": yaw, "pitch": pitch, "roll": 0})

    manifest = {"tour": {"id": "captest", "name": "Capture test"},
                "scenes": [{"id": scene_id, "order": 1,
                            "capture": {"hfov": hfov, "frames": frames}}]}
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
    print(f"wrote {len(frames)} кадров + manifest.json (capture) -> {out_dir}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="input")
    ap.add_argument("--varied", action="store_true",
                    help="добавить брак/enhance-кейсы для проверки triage")
    ap.add_argument("--capture", action="store_true",
                    help="сгенерировать серию кадров + capture-манифест (путь B, stitch)")
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)

    for sid, label, base in SCENES:
        img = base_pano(label, base)
        img.save(os.path.join(args.out, sid + ".jpg"), "JPEG", quality=88)
        print("wrote", sid)

    if args.capture:
        make_capture(os.path.join(args.out, "frames"), base_pano("HALL", (44, 62, 90)))

    if args.varied:
        # мягко размыто -> enhance
        base_pano("BLURRY", (60, 60, 60)).filter(ImageFilter.GaussianBlur(6)) \
            .save(os.path.join(args.out, "05-blurry.jpg"), "JPEG", quality=88)
        # тёмный кадр -> enhance
        Image.eval(base_pano("DARK", (30, 30, 30)), lambda p: p // 5) \
            .save(os.path.join(args.out, "06-dark.jpg"), "JPEG", quality=88)
        # не 2:1 -> reject
        base_pano("BADRATIO", (50, 40, 40)).resize((1600, 1024)) \
            .save(os.path.join(args.out, "07-badratio.jpg"), "JPEG", quality=88)
        print("wrote 05-blurry, 06-dark, 07-badratio (varied)")


if __name__ == "__main__":
    main()
