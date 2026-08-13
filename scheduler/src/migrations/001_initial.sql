CREATE TABLE IF NOT EXISTS services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  duration INTEGER NOT NULL,
  price REAL DEFAULT 0,
  currency TEXT DEFAULT 'ARS',
  description TEXT DEFAULT '',
  slot_interval INTEGER DEFAULT 15,
  attendants_number INTEGER DEFAULT 1,
  category_id INTEGER DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  first_name TEXT NOT NULL,
  last_name TEXT DEFAULT '',
  email TEXT DEFAULT '',
  phone TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS appointments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  start TEXT NOT NULL,
  end TEXT NOT NULL,
  service_id INTEGER NOT NULL REFERENCES services(id),
  customer_id INTEGER NOT NULL REFERENCES customers(id),
  provider_id INTEGER DEFAULT 5,
  status TEXT DEFAULT 'confirmed',
  notes TEXT DEFAULT '',
  hash TEXT DEFAULT '',
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS provider_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider_id INTEGER DEFAULT 5,
  first_name TEXT DEFAULT 'Laura',
  last_name TEXT DEFAULT '',
  email TEXT DEFAULT '',
  phone TEXT DEFAULT '',
  address TEXT DEFAULT '',
  profesional TEXT DEFAULT '',
  timezone TEXT DEFAULT 'America/Argentina/Cordoba',
  working_plan TEXT DEFAULT '{}',
  username TEXT DEFAULT 'laura',
  notifications INTEGER DEFAULT 0,
  calendar_view TEXT DEFAULT 'default'
);

CREATE TABLE IF NOT EXISTS days_off (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider_id INTEGER DEFAULT 5,
  date TEXT NOT NULL,
  reason TEXT DEFAULT '',
  created_at TEXT DEFAULT (datetime('now')),
  UNIQUE(provider_id, date)
);

CREATE INDEX IF NOT EXISTS idx_appointments_start ON appointments(start);
CREATE INDEX IF NOT EXISTS idx_appointments_customer ON appointments(customer_id);
CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone);
