CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  appointment_id INTEGER NOT NULL REFERENCES appointments(id),
  amount REAL NOT NULL DEFAULT 0,
  status TEXT DEFAULT 'pending',
  method TEXT DEFAULT '',
  paid_at TEXT,
  notes TEXT DEFAULT '',
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_payments_appointment ON payments(appointment_id);
