import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import express from 'express';
import request from 'supertest';
import { registerWhatsAppWebhook } from '../workflows';
import { getDb } from '../db';

const app = express();
app.use(express.json());

registerWhatsAppWebhook(app);

let serviceId: number;
let customerId: number;
let appointmentId: number;

beforeAll(() => {
  const db = getDb();

  // Seed test data — create fresh service + customer for webhook tests
  const svc = db.prepare("INSERT INTO services (name, duration, price) VALUES ('Webhook Service', 60, 500)").run();
  serviceId = svc.lastInsertRowid as number;

  const cust = db.prepare("INSERT INTO customers (first_name, phone) VALUES ('TestUser', '549111111111')").run();
  customerId = cust.lastInsertRowid as number;

  const appt = db.prepare(`
    INSERT INTO appointments (start, end, service_id, customer_id, provider_id, status)
    VALUES ('2099-12-01 10:00:00', '2099-12-01 11:00:00', ?, ?, 5, 'confirmed')
  `).run(serviceId, customerId);
  appointmentId = appt.lastInsertRowid as number;
});

afterAll(() => {
  const db = getDb();
  db.prepare('DELETE FROM appointments').run();
  db.prepare('DELETE FROM customers').run();
  db.prepare('DELETE FROM services').run();
});

describe('POST /webhook/whatsapp — CANCELAR', () => {
  it('should cancel a single appointment when user types CANCELAR', async () => {
    const res = await request(app)
      .post('/webhook/whatsapp')
      .send({ from: '549111111111@c.us', body: 'CANCELAR' });

    expect(res.status).toBe(200);
    expect(res.body.processed).toBe(true);
    expect(res.body.action).toBe('cancelled');

    // Verify cancelled in DB
    const db = getDb();
    const row = db.prepare('SELECT status FROM appointments WHERE id = ?').get(appointmentId) as any;
    expect(row.status).toBe('cancelled');
  });

  it('should return processed=false for unrelated messages', async () => {
    const res = await request(app)
      .post('/webhook/whatsapp')
      .send({ from: '549111111111@c.us', body: 'Hola' });

    expect(res.status).toBe(200);
    expect(res.body.processed).toBe(false);
  });
});

describe('POST /webhook/whatsapp — CAMBIAR', () => {
  let rescheduleId: number;

  beforeAll(() => {
    const db = getDb();
    const appt = db.prepare(`
      INSERT INTO appointments (start, end, service_id, customer_id, provider_id, status)
      VALUES ('2099-12-15 14:00:00', '2099-12-15 15:00:00', ?, ?, 5, 'confirmed')
    `).run(serviceId, customerId);
    rescheduleId = appt.lastInsertRowid as number;
  });

  it('should cancel and flag for reschedule when user types CAMBIAR', async () => {
    const res = await request(app)
      .post('/webhook/whatsapp')
      .send({ from: '549111111111@c.us', body: 'CAMBIAR' });

    expect(res.status).toBe(200);
    expect(res.body.processed).toBe(true);
    expect(res.body.action).toBe('cancelled_for_reschedule');

    const db = getDb();
    const row = db.prepare('SELECT status FROM appointments WHERE id = ?').get(rescheduleId) as any;
    expect(row.status).toBe('cancelled');
  });
});

describe('POST /webhook/whatsapp — no appointments', () => {
  beforeAll(() => {
    const db = getDb();
    db.prepare('DELETE FROM appointments').run();
  });

  it('should return no appointments message', async () => {
    const res = await request(app)
      .post('/webhook/whatsapp')
      .send({ from: '549999999999@c.us', body: 'CANCELAR' });

    expect(res.status).toBe(200);
    expect(res.body.message).toBe('no appointments');
  });
});

describe('POST /webhook/whatsapp — payload variants', () => {
  it('should handle nested payload format (body.data)', async () => {
    const db = getDb();
    const appt = db.prepare(`
      INSERT INTO appointments (start, end, service_id, customer_id, provider_id, status)
      VALUES ('2099-12-20 16:00:00', '2099-12-20 17:00:00', ?, ?, 5, 'confirmed')
    `).run(serviceId, customerId);

    const res = await request(app)
      .post('/webhook/whatsapp')
      .send({ data: { from: '549111111111@c.us', body: 'CANCELAR' } });

    expect(res.status).toBe(200);
    expect(res.body.processed).toBe(true);
  });
});
