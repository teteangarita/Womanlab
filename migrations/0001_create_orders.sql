CREATE TABLE IF NOT EXISTS payment_orders (
  id TEXT PRIMARY KEY,
  amount INTEGER NOT NULL CHECK (amount > 0),
  currency TEXT NOT NULL CHECK (currency = 'CLP'),
  items_json TEXT NOT NULL,
  customer_email TEXT NOT NULL,
  provider_account_id TEXT NOT NULL,
  provider_transaction_id TEXT,
  status TEXT NOT NULL CHECK (status IN ('pending', 'completed', 'failed')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS payment_orders_status_created_idx
  ON payment_orders (status, created_at);