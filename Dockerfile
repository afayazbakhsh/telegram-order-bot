FROM php:8.4-cli
WORKDIR /app
RUN useradd --uid 10001 --create-home bot && mkdir /app/data && chown bot:bot /app/data
COPY bot.php orders.php state_store.php ./
USER bot
CMD ["php", "bot.php"]
