FROM python:3.11-slim

# OpenCV headless нужны libGL/glib даже для headless в некоторых базовых образах
RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libgl1 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .

ENV PORT=8080
EXPOSE 8080
# shell-форма, чтобы развернулся $PORT (Render/Cloud Run задают порт сами).
# 1 воркер, длинный таймаут — склейка тяжёлая; масштабируй по нагрузке.
CMD gunicorn -b 0.0.0.0:${PORT} -w 1 -t 180 app:app
