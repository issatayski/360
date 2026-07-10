"""stitcher.py — серверная склейка кадров в equirect с выравниванием по признакам.

Гиро-позы (матрица вращения R на кадр) — стартовая оценка. Уточняем повороты по
совпадениям ORB-признаков между соседними кадрами (bundle adjustment над
вращениями, с якорем к гиро-позам) → убираем рассинхрон угла (главную причину
мыла/двоения). При нехватке совпадений кадр остаётся на гиро-позе.

Конвенция координат — как в capture.php::projectShot (проверенная на устройстве):
  мир: z — вверх; луч d = [cos(lat)sin(lon), cos(lat)cos(lon), sin(lat)];
  камера смотрит вдоль −Z; cam = world @ R; world = cam @ R.T.
  Проекция: f=(Wf/2)/tan(hfov/2); t=-1/cz; ix=cw+f*cx*t; iy=ch-f*cy*t.
"""
import numpy as np
import cv2
from scipy.optimize import least_squares
from scipy.spatial.transform import Rotation


def equirect_world(width):
    H, W = width // 2, width
    j = np.arange(W); i = np.arange(H)
    lon = ((j + 0.5) / W) * 2 * np.pi - np.pi
    lat = np.pi / 2 - ((i + 0.5) / H) * np.pi
    LON, LAT = np.meshgrid(lon, lat)
    c = np.cos(LAT)
    return np.stack([c * np.sin(LON), c * np.cos(LON), np.sin(LAT)], axis=-1).astype(np.float32)


def pixels_to_cam_rays(pts, Wf, Hf, f):
    """Пиксели (N,2) → единичные лучи камеры (N,3), обратно к проекции projectShot."""
    cw, ch = Wf / 2.0, Hf / 2.0
    a = (pts[:, 0] - cw) / f
    b = (ch - pts[:, 1]) / f
    rays = np.stack([a, b, -np.ones_like(a)], axis=1)
    rays /= np.linalg.norm(rays, axis=1, keepdims=True)
    return rays


def focal(hfov_deg, Wf):
    return (Wf / 2.0) / np.tan(np.deg2rad(hfov_deg) / 2.0)


def cam_forward_world(R):
    """Направление взгляда камеры в мире (для отбора перекрывающихся пар)."""
    return np.array([0.0, 0.0, -1.0]) @ R.T


def refine_poses(imgs, Rs_init, hfov_deg, prior_weight=4.0, max_correction_deg=15.0):
    """Устойчивое уточнение поз: ORB → RANSAC-отсев выбросов → робастный BA.
    Гиро-позы — якорь; неправдоподобные коррекции (> max_correction) отбрасываются.
    """
    n = len(imgs)
    if n < 2:
        return Rs_init, False

    orb = cv2.ORB_create(nfeatures=2000)
    DET_MAX = 1100
    kps, descs, shapes = [], [], []
    for img in imgs:
        h, w = img.shape[:2]
        s = min(1.0, DET_MAX / max(h, w))
        g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        if s < 1.0:
            g = cv2.resize(g, (max(1, int(w * s)), max(1, int(h * s))), interpolation=cv2.INTER_AREA)
        k, d = orb.detectAndCompute(g, None)
        kps.append(k); descs.append(d); shapes.append(g.shape[:2])
    print(f"[refine] ORB done on {n} frames", flush=True)

    fwd = [cam_forward_world(R) for R in Rs_init]
    bf = cv2.BFMatcher(cv2.NORM_HAMMING)
    # шире сеть, чтобы ловить и соседей между кольцами
    cos_thr = np.cos(np.deg2rad(min(85.0, hfov_deg * 1.5)))

    pairs = []
    total_inliers = 0
    for i in range(n):
        for j in range(i + 1, n):
            if descs[i] is None or descs[j] is None:
                continue
            if float(np.dot(fwd[i], fwd[j])) < cos_thr:
                continue
            raw = bf.knnMatch(descs[i], descs[j], k=2)
            good = [m for m, nn in (p for p in raw if len(p) == 2) if m.distance < 0.8 * nn.distance]
            if len(good) < 12:
                continue
            Hi, Wi = shapes[i]; Hj, Wj = shapes[j]
            pi = np.float32([kps[i][m.queryIdx].pt for m in good])
            pj = np.float32([kps[j][m.trainIdx].pt for m in good])
            # RANSAC-отсев ложных совпадений (гомография — хорошая модель для поворота далёкой сцены)
            Hmat, mask = cv2.findHomography(pi, pj, cv2.RANSAC, 3.0)
            if mask is None:
                continue
            inl = mask.ravel().astype(bool)
            if int(inl.sum()) < 10:
                continue
            pi, pj = pi[inl], pj[inl]
            fi, fj = focal(hfov_deg, Wi), focal(hfov_deg, Wj)
            ri = pixels_to_cam_rays(pi, Wi, Hi, fi)
            rj = pixels_to_cam_rays(pj, Wj, Hj, fj)
            pairs.append((i, j, ri, rj))
            total_inliers += int(inl.sum())

    print(f"[refine] {len(pairs)} pairs, {total_inliers} inlier matches", flush=True)
    if len(pairs) < 3:
        print("[refine] too few pairs -> keep gyro poses", flush=True)
        return Rs_init, False

    rv0 = np.array([Rotation.from_matrix(R).as_rotvec() for R in Rs_init])

    def residuals(x):
        rv = x.reshape(n, 3)
        Rs = [Rotation.from_rotvec(rv[k]).as_matrix() for k in range(n)]
        res = []
        for (i, j, ri, rj) in pairs:
            wi = ri @ Rs[i].T
            wj = rj @ Rs[j].T
            res.append((wi - wj).ravel())
        res.append((prior_weight * (rv - rv0)).ravel())
        return np.concatenate(res)

    try:
        # lm — быстрый (trf/soft_l1 не тянет 0.1 CPU free-Render). Выбросы уже
        # отсеяны RANSAC-ом выше, так что робастная потеря не нужна.
        sol = least_squares(residuals, rv0.ravel(), method="lm", max_nfev=80)
        rv = sol.x.reshape(n, 3)
        out, deltas = [], []
        for k in range(n):
            Rk = Rotation.from_rotvec(rv[k]).as_matrix()
            rel = Rk @ Rotation.from_rotvec(rv0[k]).as_matrix().T
            ang = np.degrees(np.linalg.norm(Rotation.from_matrix(rel).as_rotvec()))
            # защита: неправдоподобно большой сдвиг = спорная связь → оставить гиро
            if ang > max_correction_deg:
                out.append(Rs_init[k])
            else:
                out.append(Rk)
                deltas.append(ang)
        avg = float(np.mean(deltas)) if deltas else 0.0
        mx = float(np.max(deltas)) if deltas else 0.0
        print(f"[refine] ok. applied to {len(deltas)}/{n} frames, correction avg {avg:.2f} deg, max {mx:.2f} deg", flush=True)
        return out, True
    except Exception as e:
        print("refine_poses fallback:", e, flush=True)
        return Rs_init, False


def reproject_R(img, R, hfov_deg, world):
    Hf, Wf = img.shape[:2]
    f = focal(hfov_deg, Wf)
    cw, ch = Wf / 2.0, Hf / 2.0
    cam = world @ R                      # (H,W,3)
    cz = cam[..., 2]
    valid = cz < -1e-6
    czs = np.where(valid, cz, -1.0)
    t = -1.0 / czs
    ix = cw + f * cam[..., 0] * t
    iy = ch - f * cam[..., 1] * t
    inside = valid & (ix >= 0) & (ix <= Wf - 1) & (iy >= 0) & (iy <= Hf - 1)
    map_x = np.where(inside, ix, -1).astype(np.float32)
    map_y = np.where(inside, iy, -1).astype(np.float32)
    sampled = cv2.remap(img, map_x, map_y, cv2.INTER_LINEAR, borderMode=cv2.BORDER_CONSTANT)
    wx = np.clip(1 - np.abs(ix - cw) / cw, 0, 1)
    wy = np.clip(1 - np.abs(iy - ch) / ch, 0, 1)
    w = ((wx * wy) * inside).astype(np.float32)
    return sampled.astype(np.float32), w, inside


def reproject_weight(shape, R, hfov_deg, world):
    """Только карта веса + маска покрытия кадра (без сэмплинга цвета) — для выбора
    кадра-победителя на каждый пиксель (дёшево, без remap)."""
    Hf, Wf = shape
    f = focal(hfov_deg, Wf)
    cw, ch = Wf / 2.0, Hf / 2.0
    cam = world @ R
    cz = cam[..., 2]
    valid = cz < -1e-6
    czs = np.where(valid, cz, -1.0)
    t = -1.0 / czs
    ix = cw + f * cam[..., 0] * t
    iy = ch - f * cam[..., 1] * t
    inside = valid & (ix >= 0) & (ix <= Wf - 1) & (iy >= 0) & (iy <= Hf - 1)
    wx = np.clip(1 - np.abs(ix - cw) / cw, 0, 1)
    wy = np.clip(1 - np.abs(iy - ch) / ch, 0, 1)
    return ((wx * wy) * inside).astype(np.float32), inside


def _winner_masks(imgs, Rs, hfov_deg, world):
    """Маски-швы по «победителю» перьевого веса (быстро, но шов может резать объект)."""
    H, W = world.shape[:2]
    best_w = np.full((H, W), -1.0, np.float32)
    best_idx = np.full((H, W), -1, np.int32)
    for idx, R in enumerate(Rs):
        w, _inside = reproject_weight(imgs[idx].shape[:2], R, hfov_deg, world)
        take = w > best_w
        best_w[take] = w[take]; best_idx[take] = idx
    return [((best_idx == idx).astype(np.uint8) * 255) for idx in range(len(imgs))]


def _seam_masks(imgs, Rs, hfov_deg, gains, H, W, seam_width=768):
    """Умный поиск шва (Dp — быстрый) на низком разрешении → маски, где стык проложен
    по гладким местам в обход объектов. Возвращает список полноразмерных масок 0/255
    или None при неудаче (тогда используем winner-маски). GraphCut слишком медленный
    на 22 кадрах, поэтому Dp."""
    try:
        sW = min(seam_width, W)
        world_s = equirect_world(sW)
        imgs_s, masks_s = [], []
        for idx, (img, R) in enumerate(zip(imgs, Rs)):
            s, _w, inside = reproject_R(img, R, hfov_deg, world_s)
            if gains is not None:
                s = np.clip(s * gains[idx], 0, 255)
            imgs_s.append(cv2.UMat(s.astype(np.float32)))
            masks_s.append(cv2.UMat((inside.astype(np.uint8) * 255)))
        corners = [(0, 0)] * len(imgs)
        finder = cv2.detail_DpSeamFinder("COLOR")
        finder.find(imgs_s, corners, masks_s)
        out = []
        for um in masks_s:
            m = um.get()
            out.append(cv2.resize(m, (W, H), interpolation=cv2.INTER_NEAREST))
        print(f"[stitch] seam finder (dp) ok @ {sW}x{sW // 2}", flush=True)
        return out
    except Exception as e:
        print("seam finder failed -> winner masks:", e, flush=True)
        return None


def blend_multiband(imgs, Rs, hfov_deg, world, gains, num_bands=5):
    """Профи-блендинг: умный поиск шва (обходит объекты) + многополосное сглаживание
    стыков. Каждый пиксель из ОДНОГО кадра (нет двоения). Возвращает (uint8, coverage)."""
    H, W = world.shape[:2]

    masks = _seam_masks(imgs, Rs, hfov_deg, gains, H, W)
    if masks is None:
        masks = _winner_masks(imgs, Rs, hfov_deg, world)

    nb = int(max(1, min(num_bands, np.floor(np.log2(min(H, W))))))
    blender = cv2.detail_MultiBandBlender(0, nb)
    blender.prepare((0, 0, W, H))
    for idx, (img, R) in enumerate(zip(imgs, Rs)):
        sampled, w, inside = reproject_R(img, R, hfov_deg, world)
        if gains is not None:
            sampled = np.clip(sampled * gains[idx], 0, 255)
        mask = ((masks[idx] > 127) & inside).astype(np.uint8) * 255
        blender.feed(np.ascontiguousarray(sampled.astype(np.int16)), mask, (0, 0))
    res, res_mask = blender.blend(None, None)
    out = np.clip(res, 0, 255).astype(np.uint8)
    return out, float((res_mask > 0).mean())


def compute_gains(imgs, Rs, hfov_deg, gain_width=1024):
    world = equirect_world(gain_width)
    grays, masks = [], []
    for img, R in zip(imgs, Rs):
        s, _w, inside = reproject_R(img, R, hfov_deg, world)
        grays.append(s.mean(axis=2)); masks.append(inside)
    n = len(imgs)
    A = np.zeros((n, n)); b = np.zeros(n)
    for i in range(n):
        for j in range(i + 1, n):
            ov = masks[i] & masks[j]
            N = int(ov.sum())
            if N < 50:
                continue
            Ii = float(grays[i][ov].mean()); Ij = float(grays[j][ov].mean())
            if Ii < 1 or Ij < 1:
                continue
            d = np.log(Ij) - np.log(Ii)
            A[i, i] += N; A[j, j] += N; A[i, j] -= N; A[j, i] -= N
            b[i] += N * d; b[j] -= N * d
    A += np.eye(n) * 1e-3
    try:
        l = np.linalg.solve(A, b)
    except np.linalg.LinAlgError:
        l = np.zeros(n)
    l -= l.mean()
    return np.clip(np.exp(l), 0.5, 2.0)


def stitch(imgs, Rs_init, hfov_deg, width=4096, blend="best", power=4.0,
           expcomp=True, refine=True):
    """imgs — список BGR; Rs_init — список 3x3 матриц (гиро-позы, конвенция projectShot).

    blend: 'best'  — каждый пиксель из ОДНОГО кадра с макс. весом (нет усреднения →
                     нет двоения; возможны швы, их гасит expcomp);
           'sharp' — усреднение со степенным пером (мягче, но двоит при рассинхроне);
           'linear'— простое усреднение.
    """
    refined = False
    Rs = Rs_init
    if refine:
        Rs, refined = refine_poses(imgs, Rs_init, hfov_deg)

    world = equirect_world(width)
    H, W = width // 2, width
    gains = compute_gains(imgs, Rs, hfov_deg) if expcomp else None
    print(f"[stitch] reprojecting {len(imgs)} frames @ {W}x{H} (blend={blend})", flush=True)

    if blend == "multiband":
        try:
            out, cov = blend_multiband(imgs, Rs, hfov_deg, world, gains)
            return out, cov, refined
        except Exception as e:
            print("multiband failed, fallback to sharp:", e, flush=True)
            blend = "sharp"

    if blend == "best":
        best_w = np.zeros((H, W), np.float32)
        out = np.zeros((H, W, 3), np.float32)
        for idx, (img, R) in enumerate(zip(imgs, Rs)):
            sampled, w, inside = reproject_R(img, R, hfov_deg, world)
            if gains is not None:
                sampled = np.clip(sampled * gains[idx], 0, 255)
            take = w > best_w
            out[take] = sampled[take]
            best_w[take] = w[take]
        covered = best_w > 1e-6
    else:
        accum = np.zeros((H, W, 3), np.float32)
        wsum = np.zeros((H, W), np.float32)
        for idx, (img, R) in enumerate(zip(imgs, Rs)):
            sampled, w, inside = reproject_R(img, R, hfov_deg, world)
            if gains is not None:
                sampled = np.clip(sampled * gains[idx], 0, 255)
            if blend == "sharp":
                w = np.power(w, power) * inside
            accum += sampled * w[..., None]
            wsum += w
        covered = wsum > 1e-6
        out = np.zeros((H, W, 3), np.float32)
        out[covered] = accum[covered] / wsum[covered, None]

    return np.clip(out, 0, 255).astype(np.uint8), float(covered.mean()), refined
