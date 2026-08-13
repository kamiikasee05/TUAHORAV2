import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import express from 'express';
import request from 'supertest';
import { register } from '../routes/appointments';
import { register as registerCustomers } from '../routes/customers';
import { register as registerServices } from '../routes/services';
import { getDb } from '../db';
import { authMiddleware } from '../auth';

const app = express();
app.use(express.json());

// Register routes without auth for testing
const api = express.Router();
registerCustomers(api);
registerServices(api);
register(api);
app.use('/api/v1', api);

let serviceId: number;
let customerId: number;

beforeAll(() => {
  // Ensure DB is initialized (uses test data dir)
  process.env.DATA_DIR = process.env.DATA_DIR || __dirname + '/../../data';
  getDb();

  // Create a test service
  const db = getDb();
  const svc = db.prepare("INSERT INTO services (name, duration, price) VALUES ('Test Service', 60, 1000)").run();
  serviceId = svc.lastInsertRowid as number;

  const cust = db.prepare("INSERT INTO customers (first_name, phone) VALUES ('Test', '54912345678')").run();
  customerId = cust.lastInsertRowid as number;
});

afterAll(() => {
  const db = getDb();
  db.prepare('DELETE FROM appointments').run();
  db.prepare('DELETE FROM services').run();
  db.prepare('DELETE FROM customers').run();
});

describe('POST /api/v1/appointments', () => {
  it('should create an appointment with valid data', async () => {
    const res = await request(app)
      .post('/api/v1/appointments')
      .send({
        start: '2026-07-01 10:00:00',
        end: '2026-07-01 11:00:00',
        serviceId,
        customerId,
      });

    expect(res.status).toBe(201);
    expect(res.body).toHaveProperty('id');
    expect(res.body.status).toBe('confirmed');
  });

  it('should reject missing required fields', async () => {
    const res = await request(app)
      .post('/api/v1/appointments')
      .send({ start: '2026-07-01 10:00:00' });

    expect(res.status).toBe(400);
    expect(res.body.success).toBe(false);
  });
});

describe('GET /api/v1/appointments', () => {
  it('should list appointments', async () => {
    const res = await request(app).get('/api/v1/appointments');
    expect(res.status).toBe(200);
    expect(Array.isArray(res.body)).toBe(true);
  });

  it('should filter by status', async () => {
    const res = await request(app).get('/api/v1/appointments?status=confirmed');
    expect(res.status).toBe(200);
    expect(res.body.every((a: any) => a.status === 'confirmed')).toBe(true);
  });
});

describe('GET /api/v1/appointments/:id/cancel', () => {
  it('should cancel an existing appointment', async () => {
    const create = await request(app)
      .post('/api/v1/appointments')
      .send({
        start: '2026-07-02 14:00:00',
        end: '2026-07-02 15:00:00',
        serviceId,
        customerId,
      });

    const res = await request(app).get(`/api/v1/appointments/${create.body.id}/cancel`);
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('cancelled');
  });

  it('should return 404 for non-existent appointment', async () => {
    const res = await request(app).get('/api/v1/appointments/99999/cancel');
    expect(res.status).toBe(404);
  });
});
