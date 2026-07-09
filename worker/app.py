"""app.py — HTTP-воркер склейки («облако» платформы, как Cortex у Matterport).

Принимает кадры + гиро-позы, отдаёт резкую equirect-панораму.
Работает где угодно, где есть Python+вычисления (дешёвый VPS / Render / Cloud Run /
Fly.io / Hugging Face Space). НЕ на shared-хостинге. PHP-сайт зовёт его по HTTP.

Запрос: POST /stitch  (multipart/form-data)
  manifest : JSON-строка { "hfov":50, "width":4096,
               "frames":[ {"name":"f00","R":[9 чисел row-major]}, ... ] }
             (R — матрица вращения гиро-позы в конвенции capture.php::projectShot)
  <name>   : файл-картинка для каждого frames[].name (напр. поле "f00" = f00.jpg)
Заголовок: X-Worker-Token: <секрет> (если задан env WORKER_TOKEN)
Ответ: image/jpeg (equirect 2:1), заголовки X-Coverage, X-Refined
"""
import io
import os
import json
import time
import hmac
import base64
import hashlib
import numpy as np
import cv2
from flask import Flask, request, Response, jsonify

import stitcher

app = Flask(__name__)

# На бесплатном Render (0.1 CPU / 512 МБ) большие панорамы дают OOM/долго.
# По умолчанию режем до 2048; на мощном инстансе подними env MAX_WIDTH=4096.
MAX_WIDTH = int(os.environ.get("MAX_WIDTH", "2048"))
WORKER_TOKEN = os.environ.get("WORKER_TOKEN", "")


# ---- CORS: телефон шлёт кадры напрямую сюда (другой домен, чем сайт) ----
@app.after_request
def add_cors(resp):
    resp.headers["Access-Control-Allow-Origin"] = "*"
    resp.headers["Access-Control-Allow-Methods"] = "POST, GET, OPTIONS"
    resp.headers["Access-Control-Allow-Headers"] = "Content-Type, X-Worker-Token"
    return resp


# ---- Билет: HMAC-подпись, выданная PHP (общий секрет WORKER_TOKEN) ----
def _b64url_decode(s):
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def verify_ticket(ticket):
    if not ticket or "." not in ticket:
        return None
    try:
        payload, sig = ticket.split(".", 1)
        calc = base64.urlsafe_b64encode(
            hmac.new(WORKER_TOKEN.encode(), payload.encode(), hashlib.sha256).digest()
        ).rstrip(b"=").decode()
        if not hmac.compare_digest(calc, sig):
            return None
        data = json.loads(_b64url_decode(payload))
        if float(data.get("exp", 0)) < time.time():
            return None
        return data
    except Exception:
        return None


def authorized():
    if not WORKER_TOKEN:
        return True  # секрет не задан — открыто (только для отладки)
    if request.headers.get("X-Worker-Token", "") == WORKER_TOKEN:
        return True
    return verify_ticket(request.form.get("ticket", "")) is not None


@app.get("/health")
def health():
    return jsonify(ok=True, service="stitch-worker")


@app.route("/stitch", methods=["OPTIONS"])
def stitch_preflight():
    return ("", 204)


@app.post("/stitch")
def stitch_endpoint():
    if not authorized():
        return jsonify(ok=False, error="bad token/ticket"), 401

    raw = request.form.get("manifest")
    if not raw:
        return jsonify(ok=False, error="no manifest"), 400
    try:
        man = json.loads(raw)
    except Exception as e:
        return jsonify(ok=False, error=f"bad manifest json: {e}"), 400

    frames = man.get("frames") or []
    if len(frames) < 2:
        return jsonify(ok=False, error="need >=2 frames"), 400
    hfov = float(man.get("hfov", 50))
    width = int(man.get("width", 4096))
    width = max(1024, min(MAX_WIDTH, width - (width % 2)))
    blend = man.get("blend", "sharp")   # мягкое перо: непрерывно (лучший из «плохих» на free-tier)

    imgs, Rs = [], []
    for fr in frames:
        name = fr.get("name")
        f = request.files.get(name)
        if f is None:
            return jsonify(ok=False, error=f"missing file for '{name}'"), 400
        buf = np.frombuffer(f.read(), np.uint8)
        img = cv2.imdecode(buf, cv2.IMREAD_COLOR)
        if img is None:
            return jsonify(ok=False, error=f"bad image '{name}'"), 400
        R = fr.get("R")
        if not R or len(R) != 9:
            return jsonify(ok=False, error=f"frame '{name}' needs R[9]"), 400
        imgs.append(img)
        Rs.append(np.array(R, dtype=np.float64).reshape(3, 3))

    import time, traceback
    t0 = time.time()
    print(f"[stitch] start: {len(imgs)} frames, width={width}, hfov={hfov}", flush=True)
    try:
        pano, coverage, refined = stitcher.stitch(
            imgs, Rs, hfov, width=width, blend=blend, expcomp=True, refine=True
        )
    except Exception as e:
        traceback.print_exc()
        return jsonify(ok=False, error=f"stitch failed: {e}"), 500
    print(f"[stitch] done in {time.time()-t0:.1f}s coverage={coverage:.2f} refined={refined}", flush=True)

    ok, enc = cv2.imencode(".jpg", pano, [cv2.IMWRITE_JPEG_QUALITY, 90])
    if not ok:
        return jsonify(ok=False, error="encode failed"), 500

    return Response(
        enc.tobytes(),
        mimetype="image/jpeg",
        headers={"X-Coverage": f"{coverage:.3f}", "X-Refined": "1" if refined else "0"},
    )


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", "8080")))
