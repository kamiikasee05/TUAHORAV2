import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import express from 'express';
import request from 'supertest';
import { register } from '../routes/customers';
import { getDb } from '../db';

const app = express();
app.use(express.json());
const api = express.Router();
register(api);
app.use('/api/v1', api);

let createdId: number;

afterAll(() => {
  const db = getDb();
  db.prepare('DELETE FROM customers').run();
});

describe('POST /api/v1/customers', () => {
  it('should create a customer with valid data', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Juan', lastName: 'Perez', phone: '54911111111', email: 'juan@test.com' });

    expect(res.status).toBe(201);
    expect(res.body.firstName).toBe('Juan');
    expect(res.body.phone).toBe('54911111111');
    createdId = res.body.id;
  });

  it('should reject missing firstName', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ phone: '54922222222' });

    expect(res.status).toBe(400);
  });

  it('should reject missing phone', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Ana' });

    expect(res.status).toBe(400);
  });

  it('should return existing customer when phone already exists', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Juan', phone: '54911111111' });

    expect(res.status).toBe(200);
    expect(res.body.id).toBe(createdId);
  });

  it('should update name when existing customer phone is reused with new name', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Juan Carlos', lastName: 'Perez', phone: '54911111111' });

    expect(res.status).toBe(200);
    expect(res.body.firstName).toBe('Juan Carlos');
  });
});

describe('GET /api/v1/customers', () => {
  beforeAll(async () => {
    await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Maria', phone: '54933333333' });
  });

  it('should list all customers', async () => {
    const res = await request(app).get('/api/v1/customers');
    expect(res.status).toBe(200);
    expect(res.body.length).toBeGreaterThanOrEqual(1);
  });

  it('should search customers by phone', async () => {
    const res = await request(app).get('/api/v1/customers?q=1111');
    expect(res.status).toBe(200);
    expect(res.body.some((c: any) => c.phone.includes('1111'))).toBe(true);
  });
});

describe('GET /api/v1/customers/:id', () => {
  it('should return a customer by id', async () => {
    const res = await request(app).get(`/api/v1/customers/${createdId}`);
    expect(res.status).toBe(200);
    expect(res.body.firstName).toBe('Juan Carlos');
  });

  it('should return 404 for non-existent customer', async () => {
    const res = await request(app).get('/api/v1/customers/99999');
    expect(res.status).toBe(404);
  });
});

describe('PUT /api/v1/customers/:id', () => {
  it('should update a customer', async () => {
    const res = await request(app)
      .put(`/api/v1/customers/${createdId}`)
      .send({ lastName: 'Garcia' });

    expect(res.status).toBe(200);
    expect(res.body.lastName).toBe('Garcia');
  });

  it('should return 404 for non-existent customer', async () => {
    const res = await request(app)
      .put('/api/v1/customers/99999')
      .send({ firstName: 'No' });

    expect(res.status).toBe(404);
  });
});

describe('DELETE /api/v1/customers/:id', () => {
  it('should delete a customer without appointments', async () => {
    const res = await request(app)
      .post('/api/v1/customers')
      .send({ firstName: 'Temp', phone: '54999999999' });

    const del = await request(app).delete(`/api/v1/customers/${res.body.id}`);
    expect(del.status).toBe(200);
  });

  it('should return 404 for non-existent customer', async () => {
    const res = await request(app).delete('/api/v1/customers/99999');
    expect(res.status).toBe(404);
  });
});
