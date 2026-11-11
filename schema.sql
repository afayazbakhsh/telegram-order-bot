CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  update_id INTEGER NOT NULL UNIQUE,
  chat_id TEXT NOT NULL,
  telegram_user_id TEXT,
  telegram_file_id TEXT NOT NULL,
  audio_path TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  byte_size INTEGER NOT NULL,
  duration_seconds INTEGER NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('pending','processing','completed','failed')),
  delivery_status TEXT NOT NULL DEFAULT 'pending' CHECK(delivery_status IN ('pending','sending','sent','failed')),
  delivery_next_chunk INTEGER NOT NULL DEFAULT 0,
  lease_token TEXT,
  lease_until INTEGER,
  transcript TEXT,
  language TEXT,
  processing_metadata TEXT,
  failure_reason TEXT,
  delivery_error TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  delivered_at INTEGER
);
CREATE INDEX IF NOT EXISTS orders_status_created ON orders(status, created_at);
CREATE INDEX IF NOT EXISTS orders_retention ON orders(updated_at);
