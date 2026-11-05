CREATE TABLE IF NOT EXISTS customers (
  id TEXT PRIMARY KEY,
  first_name TEXT NOT NULL CHECK (length(trim(first_name)) BETWEEN 1 AND 80),
  last_name TEXT NOT NULL CHECK (length(trim(last_name)) BETWEEN 1 AND 80),
  email TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(email) <= 160),
  phone TEXT NOT NULL CHECK (length(trim(phone)) BETWEEN 8 AND 30),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS catalog_items (
  id TEXT PRIMARY KEY,
  item_type TEXT NOT NULL CHECK (item_type IN ('membership', 'service', 'product')),
  name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 120),
  price_clp INTEGER NOT NULL CHECK (price_clp >= 0),
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS catalog_items_type_active_idx
  ON catalog_items (item_type, active);

INSERT OR IGNORE INTO catalog_items (id, item_type, name, price_clp) VALUES
  ('plan-membership-monthly', 'membership', 'Membresía Woman Lab (Mensual)', 40000),
  ('plan-membership-quarterly', 'membership', 'Membresía Woman Lab (Trimestral)', 105000),
  ('plan-membership-semiannual', 'membership', 'Membresía Woman Lab (Semestral)', 192000),
  ('plan-membership-annual', 'membership', 'Membresía Woman Lab (Anual)', 336000),
  ('serv-nutricion', 'service', 'Asesoría Nutricionista Deportivo', 30000),
  ('serv-kinesiologia', 'service', 'Sesión Kinesiología & Fisioterapia', 38000);

CREATE TABLE IF NOT EXISTS payment_order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id TEXT NOT NULL REFERENCES payment_orders(id) ON DELETE CASCADE,
  catalog_item_id TEXT NOT NULL,
  item_name TEXT NOT NULL CHECK (length(trim(item_name)) BETWEEN 1 AND 120),
  quantity INTEGER NOT NULL CHECK (quantity BETWEEN 1 AND 10),
  unit_amount_clp INTEGER NOT NULL CHECK (unit_amount_clp >= 0),
  UNIQUE (order_id, catalog_item_id)
);

CREATE INDEX IF NOT EXISTS payment_order_items_order_idx
  ON payment_order_items (order_id);

CREATE TABLE IF NOT EXISTS training_activities (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 100),
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS training_sessions (
  id TEXT PRIMARY KEY,
  activity_id TEXT NOT NULL REFERENCES training_activities(id),
  starts_at TEXT NOT NULL,
  ends_at TEXT NOT NULL CHECK (ends_at > starts_at),
  capacity INTEGER NOT NULL CHECK (capacity > 0),
  status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'cancelled', 'completed')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS training_sessions_activity_start_idx
  ON training_sessions (activity_id, starts_at, status);

CREATE TABLE IF NOT EXISTS bookings (
  id TEXT PRIMARY KEY,
  session_id TEXT NOT NULL REFERENCES training_sessions(id),
  customer_id TEXT NOT NULL REFERENCES customers(id),
  status TEXT NOT NULL DEFAULT 'confirmed' CHECK (status IN ('pending', 'confirmed', 'cancelled', 'attended', 'no_show')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS bookings_one_active_per_customer_idx
  ON bookings (session_id, customer_id)
  WHERE status IN ('pending', 'confirmed');

CREATE INDEX IF NOT EXISTS bookings_customer_status_idx
  ON bookings (customer_id, status, created_at);

CREATE INDEX IF NOT EXISTS bookings_session_status_idx
  ON bookings (session_id, status);

CREATE TRIGGER IF NOT EXISTS training_sessions_capacity_before_update
BEFORE UPDATE OF capacity ON training_sessions
WHEN NEW.capacity < OLD.capacity
BEGIN
  SELECT CASE
    WHEN NEW.capacity < (SELECT COUNT(*) FROM bookings
                         WHERE session_id = OLD.id AND status IN ('pending', 'confirmed'))
      THEN RAISE(ABORT, 'capacity_below_bookings')
  END;
END;

CREATE TRIGGER IF NOT EXISTS bookings_capacity_before_insert
BEFORE INSERT ON bookings
WHEN NEW.status IN ('pending', 'confirmed')
BEGIN
  SELECT CASE
    WHEN (SELECT status FROM training_sessions WHERE id = NEW.session_id) <> 'scheduled'
      THEN RAISE(ABORT, 'session_not_bookable')
    WHEN (SELECT COUNT(*) FROM bookings
          WHERE session_id = NEW.session_id AND status IN ('pending', 'confirmed')) >=
         (SELECT capacity FROM training_sessions WHERE id = NEW.session_id)
      THEN RAISE(ABORT, 'session_full')
  END;
END;

CREATE TRIGGER IF NOT EXISTS bookings_capacity_before_update
BEFORE UPDATE OF session_id, status ON bookings
WHEN NEW.status IN ('pending', 'confirmed')
BEGIN
  SELECT CASE
    WHEN (SELECT status FROM training_sessions WHERE id = NEW.session_id) <> 'scheduled'
      THEN RAISE(ABORT, 'session_not_bookable')
    WHEN (NEW.session_id <> OLD.session_id OR OLD.status NOT IN ('pending', 'confirmed'))
      AND (SELECT COUNT(*) FROM bookings
           WHERE session_id = NEW.session_id AND status IN ('pending', 'confirmed')) >=
          (SELECT capacity FROM training_sessions WHERE id = NEW.session_id)
      THEN RAISE(ABORT, 'session_full')
  END;
END;