import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import express from 'express';
import request from 'supertest';
import { register } from '../routes/slots';
import { getDb } from '../db';

const app = express();
app.use(express.json());
const api = express.Router();
register(api);
app.use('/api/v1', api);

let serviceId: number;
let customerId: number;

beforeAll(() => {
  const db = getDb();

  // Create unique service for slot tests
  const svc = db.prepare("INSERT INTO services (name, duration, price) VALUES ('Slots Test', 60, 1000)").run();
  serviceId = svc.lastInsertRowid as number;

  // Create test customer for appointment booking tests
  const cust = db.prepare("INSERT INTO customers (first_name, phone) VALUES ('SlotsCustomer', '54900000001')").run();
  customerId = cust.lastInsertRowid as number;

  // Create provider with working plan for mondays
  const existing = db.prepare('SELECT COUNT(*) as c FROM provider_settings').get() as { c: number };
  if (existing.c === 0) {
    const plan = {
      monday: { start: '09:00', end: '18:00', breaks: [{ start: '13:00', end: '14:00' }] },
    };
    db.prepare('INSERT INTO provider_settings (provider_id, working_plan) VALUES (5, ?)').run(JSON.stringify(plan));
  }
});

afterAll(() => {
  const db = getDb();
  db.prepare('DELETE FROM appointments').run();
  db.prepare('DELETE FROM services').run();
  db.prepare('DELETE FROM customers').run();
});

function nextMonday(): string {
  const d = new Date();
  d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7));
  return d.toISOString().split('T')[0];
}

describe('GET /api/v1/slots', () => {
  it('should return available slots for a valid date', async () => {
    const date = nextMonday();
    const res = await request(app)
      .get(`/api/v1/slots?serviceId=${serviceId}&date=${date}`);

    expect(res.status).toBe(200);
    expect(res.body.slots.length).toBeGreaterThan(0);
    expect(res.body.dayOff).toBe(false);
    expect(res.body.serviceId).toBe(serviceId);
  });

  it('should reject missing serviceId', async () => {
    const res = await request(app)
      .get(`/api/v1/slots?date=2026-12-01`);

    expect(res.status).toBe(400);
  });

  it('should reject invalid date format', async () => {
    const res = await request(app)
      .get(`/api/v1/slots?serviceId=${serviceId}&date=01-12-2026`);

    expect(res.status).toBe(400);
  });

  it('should return dayOff for sunday (non-working day)', async () => {
    const res = await request(app)
      .get(`/api/v1/slots?serviceId=${serviceId}&date=2026-12-06`);

    expect(res.status).toBe(200);
    expect(res.body.dayOff).toBe(true);
    expect(res.body.slots.length).toBe(0);
  });

  it('should exclude booked slots', async () => {
    const db = getDb();
    const date = nextMonday();

    // Book a slot at 10:00-11:00
    db.prepare(`
      INSERT INTO appointments (start, end, service_id, customer_id, provider_id, status)
      VALUES (?, ?, ?, ?, 5, 'confirmed')
    `).run(`${date} 10:00:00`, `${date} 11:00:00`, serviceId, customerId);

    const res = await request(app)
      .get(`/api/v1/slots?serviceId=${serviceId}&date=${date}`);

    expect(res.status).toBe(200);
    expect(res.body.slots.includes('10:00')).toBe(false);

    db.prepare('DELETE FROM appointments').run();
  });

  it('should ignore cancelled appointments', async () => {
    const db = getDb();
    const date = nextMonday();

    // Book and cancel a slot
    const appt = db.prepare(`
      INSERT INTO appointments (start, end, service_id, customer_id, provider_id, status)
      VALUES (?, ?, ?, ?, 5, 'cancelled')
    `).run(`${date} 11:00:00`, `${date} 12:00:00`, serviceId, customerId);

    const res = await request(app)
      .get(`/api/v1/slots?serviceId=${serviceId}&date=${date}`);

    expect(res.status).toBe(200);
    expect(res.body.slots.includes('11:00')).toBe(true);
  });
});

describe('GET /api/v1/availabilities', () => {
  it('should return available slots', async () => {
    const date = nextMonday();
    const res = await request(app)
      .get(`/api/v1/availabilities?providerId=5&serviceId=${serviceId}&date=${date}`);

    expect(res.status).toBe(200);
    expect(Array.isArray(res.body)).toBe(true);
    if (res.body.length > 0) {
      expect(res.body[0]).toMatch(/^\d{2}:\d{2}$/);
    }
  });

  it('should reject missing params', async () => {
    const res = await request(app)
      .get(`/api/v1/availabilities?serviceId=${serviceId}`);

    expect(res.status).toBe(400);
  });
});
