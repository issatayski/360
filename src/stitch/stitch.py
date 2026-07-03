"""stitch.py — склейка серии плоских кадров в equirect по известным позам (IMU).

Путь B (см. обсуждение): приложение съёмки отдаёт кадры + ориентацию каждого
(yaw, pitch, roll, hfov) из гироскопа. Здесь мы НЕ ищем стыки вслепую — позы
известны, поэтому просто репроецируем каждый кадр на equirect-холст и смешиваем
перьевым весом. Чистый Python (OpenCV+numpy+scipy), без системного Hugin (§11).

Конвенции осей/углов — как в py360convert (чтобы совпадать с e2p/e2c):
  камерный луч (x=вправо, y=вверх, z=вперёд); мировой поворот M = Rx(v)·Ry(u)·Ri(roll),
  где u = -deg2rad(yaw), v = deg2rad(pitch); equirect u=atan2(x,z), верх=+pitch.

Вход — manifest со сценами, у которых есть блок `capture`:
  "capture": { "hfov": 67, "frames": [ {"file":"01-hall/f00.jpg","yaw":0,"pitch":0,"roll":0}, ... ] }

    python src/stitch/stitch.py --manifest input/manifest.json --frames input/ \
        --out work/stitched/ --width 4096

Пишет <out>/<sceneId>.jpg (equirect 2:1) → дальше обычный triage → tiler → …
"""
import argparse
import json
import os
import shutil

import cv2
import numpy as np
from scipy.spatial.transform import Rotation


def rot(rad, ax):
    """Матрица поворота как в py360convert.utils.rotation_matrix (scipy from_rotvec)."""
    ax = np.asarray(ax, dtype=float)
    return Rotation.from_rotvec(rad * ax).as_matrix()


def frame_M(yaw_deg, pitch_deg, roll_deg):
    """M такой, что world_row = cam_row @ M (как out.dot(Rx).dot(Ry).dot(Ri) в e2p)."""
    u = -np.deg2rad(yaw_deg)
    v = np.deg2rad(pitch_deg)
    ir = np.deg2rad(roll_deg)
    Rx = rot(v, [1, 0, 0])
    Ry = rot(u, [0, 1, 0])
    axis_i = np.array([0.0, 0.0, 1.0]).dot(Rx).dot(Ry)
    Ri = rot(ir, axis_i)
    return Rx @ Ry @ Ri


def equirect_world(width):
    """Единичные мировые направления для каждого пикселя equirect (H=width/2)."""
    H, W = width // 2, width
    j = np.arange(W)
    i = np.arange(H)
    u = ((j + 0.5) / W - 0.5) * 2 * np.pi            # долгота, 0 = центр
    v = -((i + 0.5) / H - 0.5) * np.pi               # широта, верх = +pi/2
    U, V = np.meshgrid(u, v)
    c = np.cos(V)
    x = c * np.sin(U)
    y = np.sin(V)
    z = c * np.cos(U)
    return np.stack([x, y, z], axis=-1).astype(np.float32)  # (H,W,3)


def stitch_scene(frames, hfov_deg, frames_dir, width):
    H, W = width // 2, width
    world = equirect_world(width)                    # (H,W,3)
    accum = np.zeros((H, W, 3), np.float32)
    wsum = np.zeros((H, W), np.float32)

    for fr in frames:
        img = cv2.imread(os.path.join(frames_dir, fr["file"]), cv2.IMREAD_COLOR)
        if img is None:
            print(f"  warn: не прочитать кадр {fr['file']}")
            continue
        Hf, Wf = img.shape[:2]
        hfov = np.deg2rad(fr.get("hfov", hfov_deg))
        # vfov из аспекта кадра — квадратные пиксели
        vfov = 2 * np.arctan(np.tan(hfov / 2) * Hf / Wf)
        xmax, ymax = np.tan(hfov / 2), np.tan(vfov / 2)

        M = frame_M(fr["yaw"], fr["pitch"], fr.get("roll", 0))
        cam = world @ M.T                            # (H,W,3): мировое → камера
        cz = cam[..., 2]
        valid = cz > 1e-6
        czs = np.where(valid, cz, 1.0)
        xn = cam[..., 0] / czs
        yn = cam[..., 1] / czs
        inside = valid & (np.abs(xn) <= xmax) & (np.abs(yn) <= ymax)

        map_x = ((xn + xmax) / (2 * xmax) * (Wf - 1)).astype(np.float32)
        map_y = ((ymax - yn) / (2 * ymax) * (Hf - 1)).astype(np.float32)
        sampled = cv2.remap(img, map_x, map_y, cv2.INTER_LINEAR,
                            borderMode=cv2.BORDER_CONSTANT)

        # перьевой вес: спад к краям кадра, 0 вне кадра
        wx = np.clip(1 - np.abs(xn) / xmax, 0, 1)
        wy = np.clip(1 - np.abs(yn) / ymax, 0, 1)
        w = (wx * wy) * inside
        accum += sampled.astype(np.float32) * w[..., None]
        wsum += w

    covered = wsum > 1e-6
    out = np.zeros((H, W, 3), np.float32)
    out[covered] = accum[covered] / wsum[covered, None]
    coverage = float(covered.mean())
    return np.clip(out, 0, 255).astype(np.uint8), coverage


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default="input/manifest.json")
    ap.add_argument("--frames", default="input/", help="база путей кадров")
    ap.add_argument("--out", default="work/stitched/")
    ap.add_argument("--width", type=int, default=4096)
    args = ap.parse_args()

    with open(args.manifest, encoding="utf-8") as f:
        manifest = json.load(f)
    os.makedirs(args.out, exist_ok=True)

    stitched = 0
    passed = 0
    for scene in manifest.get("scenes", []):
        dst = os.path.join(args.out, f"{scene['id']}.jpg")
        cap = scene.get("capture")
        if cap and cap.get("frames"):
            img, coverage = stitch_scene(cap["frames"], cap.get("hfov", 67), args.frames, args.width)
            cv2.imwrite(dst, img, [cv2.IMWRITE_JPEG_QUALITY, 92])
            flag = "" if coverage > 0.999 else f"  ⚠ покрытие {coverage:.1%} (есть дыры)"
            print(f"  stitch {scene['id']}: {len(cap['frames'])} кадров -> {dst}{flag}")
            stitched += 1
        else:
            # уже готовая equirect-панорама — переносим как есть, чтобы downstream
            # работал с единой папкой (смешанный manifest).
            src = os.path.join(args.frames, scene.get("file") or f"{scene['id']}.jpg")
            if os.path.exists(src):
                shutil.copyfile(src, dst)
                passed += 1
            else:
                print(f"  warn: у {scene['id']} нет ни capture, ни файла {src}")

    print(f"stitch: склеено {stitched}, перенесено готовых {passed} -> {args.out}")


if __name__ == "__main__":
    main()
