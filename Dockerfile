FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY bot.py state_store.py catalog.json ./
RUN useradd --uid 10001 --create-home bot && mkdir /app/data && chown bot:bot /app/data
USER bot
CMD ["python", "bot.py"]
