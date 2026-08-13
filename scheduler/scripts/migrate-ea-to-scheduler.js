/**
 * migracion.js — Lee datos desde la API de EasyAppointments
 * y los escribe en SQLite de tetoca-scheduler.
 *
 * Uso: node scripts/migrate-ea-to-scheduler.js
 *
 * Requiere variables de entorno:
 *   EA_URL=http://localhost:8080/index.php/api/v1
 *   EA_USER=admin
 *   EA_PASS=...
 *   SCHEDULER_DB=./data/scheduler.db   (opcional, default)
 */
const { DatabaseSync } = require('node:sqlite');
const http = require('http');
const path = require('path');
const fs = require('fs');

const EA_URL = process.env.EA_URL;
const EA_USER = process.env.EA_USER;
const EA_PASS = process.env.EA_PASS;
const DB_PATH = process.env.SCHEDULER_DB || path.join(__dirname, '..', 'data', 'scheduler.db');

if (!EA_URL || !EA_USER || !EA_PASS) {
  console.error('Error: Requiere EA_URL, EA_USER y EA_PASS como variables de entorno');
  process.exit(1);
}

function eaFetch(endpoint) {
  return new Promise((resolve, reject) => {
    const url = new URL(EA_URL + endpoint);
    const auth = Buffer.from(`${EA_USER}:${EA_PASS}`).toString('base64');
    const opts = {
      hostname: url.hostname,
      port: url.port,
      path: url.pathname + url.search,
      headers: { Authorization: `Basic ${auth}` },
      timeout: 10000,
    };
    http.get(opts, (res) => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => {
        if (res.statusCode !== 200) return reject(new Error(`HTTP ${res.statusCode}: ${data}`));
        try { resolve(JSON.parse(data)); } catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

function initSqlite() {
  fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });
  const db = new DatabaseSync(DB_PATH);
  db.exec('PRAGMA journal_mode = WAL');
  db.exec('PRAGMA foreign_keys = ON');
  db.exec(`
    CREATE TABLE IF NOT EXISTS services (id INTEGER PRIMARY KEY, name TEXT NOT NULL, duration INTEGER NOT NULL, price REAL DEFAULT 0, currency TEXT DEFAULT 'ARS', description TEXT DEFAULT '', slot_interval INTEGER DEFAULT 15, attendants_number INTEGER DEFAULT 1, category_id INTEGER DEFAULT NULL)
  `);
  db.exec(`
    CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT DEFAULT '', email TEXT DEFAULT '', phone TEXT NOT NULL, created_at TEXT DEFAULT (datetime('now')))
  `);
  db.exec(`
    CREATE TABLE IF NOT EXISTS appointments (id INTEGER PRIMARY KEY, start TEXT NOT NULL, end TEXT NOT NULL, service_id INTEGER NOT NULL, customer_id INTEGER NOT NULL, provider_id INTEGER DEFAULT 5, status TEXT DEFAULT 'confirmed', notes TEXT DEFAULT '', hash TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')))
  `);
  db.exec(`
    CREATE TABLE IF NOT EXISTS provider_settings (id INTEGER PRIMARY KEY, provider_id INTEGER DEFAULT 5, first_name TEXT DEFAULT 'Laura', last_name TEXT DEFAULT '', email TEXT DEFAULT '', phone TEXT DEFAULT '', timezone TEXT DEFAULT 'America/Argentina/Cordoba', working_plan TEXT DEFAULT '{}', username TEXT DEFAULT 'laura', notifications INTEGER DEFAULT 0, calendar_view TEXT DEFAULT 'default')
  `);
  return db;
}

async function migrate() {
  console.log('Migrando desde EA API...');
  const db = initSqlite();

  try {
    console.log('→ Servicios...');
    const services = await eaFetch('/services');
    const insSvc = db.prepare(`INSERT OR REPLACE INTO services (id, name, duration, price, currency, description, slot_interval, attendants_number, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    for (const s of services) {
      insSvc.run(s.id, s.name, s.duration, s.price || 0, s.currency || 'ARS', s.description || '', s.slotInterval || 15, s.attendantsNumber || 1, s.serviceCategoryId || null);
    }
    console.log(`  ${services.length} servicios migrados`);

    console.log('→ Clientes...');
    const customers = await eaFetch('/customers');
    const insCust = db.prepare(`INSERT OR REPLACE INTO customers (id, first_name, last_name, email, phone, created_at) VALUES (?, ?, ?, ?, ?, ?)`);
    for (const c of customers) {
      insCust.run(c.id, c.firstName || '', c.lastName || '', c.email || '', c.phone || '', c.created_at || null);
    }
    console.log(`  ${customers.length} clientes migrados`);

    console.log('→ Turnos...');
    const appts = await eaFetch('/appointments');
    const insAppt = db.prepare(`INSERT OR REPLACE INTO appointments (id, start, end, service_id, customer_id, provider_id, status, notes, hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
    for (const a of appts) {
      insAppt.run(a.id, a.start, a.end, a.serviceId, a.customerId, a.providerId || 5, a.status || 'confirmed', a.notes || '', a.hash || '', a.created_at || null);
    }
    console.log(`  ${appts.length} turnos migrados`);

    console.log('→ Profesional (provider ID 5)...');
    try {
      const prov = await eaFetch('/providers/5');
      const insProv = db.prepare(`INSERT OR REPLACE INTO provider_settings (provider_id, first_name, last_name, email, phone, timezone, working_plan, username, notifications, calendar_view) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
      const wp = prov.settings?.workingPlan ? JSON.stringify(prov.settings.workingPlan) : '{}';
      insProv.run(5, prov.firstName || 'Laura', prov.lastName || '', prov.email || '', prov.phone || '', prov.timezone || 'America/Argentina/Cordoba', wp, prov.settings?.username || 'laura', prov.settings?.notifications ? 1 : 0, prov.settings?.calendarView || 'default');
      console.log('  Profesional migrado');
    } catch (e) {
      console.log('  No se pudo migrar provider (se usará default):', e.message);
    }

    console.log('✅ Migración completa.');
    console.log(`   DB: ${DB_PATH}`);
    console.log(`   Tamaño: ${(fs.statSync(DB_PATH).size / 1024).toFixed(1)} KB`);
  } catch (e) {
    console.error('❌ Error en migración:', e.message);
    process.exit(1);
  }
}

migrate();
