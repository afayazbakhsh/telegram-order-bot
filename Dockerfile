FROM python:3.12-slim
WORKDIR /app
COPY bot.py catalog.json ./
RUN useradd --uid 10001 --create-home bot && mkdir /app/data && chown bot:bot /app/data
USER bot
CMD ["python", "bot.py"]
