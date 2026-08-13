import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import express from 'express';
import request from 'supertest';
import { register } from '../routes/services';
import { getDb } from '../db';

const app = express();
app.use(express.json());
const api = express.Router();
register(api);
app.use('/api/v1', api);

let createdId: number;

afterAll(() => {
  const db = getDb();
  db.prepare('DELETE FROM services').run();
});

describe('GET /api/v1/services', () => {
  it('should return an empty array initially', async () => {
    const res = await request(app).get('/api/v1/services');
    expect(res.status).toBe(200);
    expect(Array.isArray(res.body)).toBe(true);
  });
});

describe('POST /api/v1/services', () => {
  it('should create a service with valid data', async () => {
    const res = await request(app)
      .post('/api/v1/services')
      .send({ name: 'Corte de pelo', duration: 45, price: 2500 });

    expect(res.status).toBe(201);
    expect(res.body.name).toBe('Corte de pelo');
    expect(res.body.duration).toBe(45);
    createdId = res.body.id;
  });

  it('should reject missing name', async () => {
    const res = await request(app)
      .post('/api/v1/services')
      .send({ duration: 30 });

    expect(res.status).toBe(400);
  });

  it('should reject missing duration', async () => {
    const res = await request(app)
      .post('/api/v1/services')
      .send({ name: 'Test' });

    expect(res.status).toBe(400);
  });

  it('should create with default values', async () => {
    const res = await request(app)
      .post('/api/v1/services')
      .send({ name: 'Lavado', duration: 30 });

    expect(res.status).toBe(201);
    expect(res.body.price).toBe(0);
    expect(res.body.currency).toBe('ARS');
    expect(res.body.slotInterval).toBe(15);
  });
});

describe('GET /api/v1/services/:id', () => {
  it('should return a service by id', async () => {
    const res = await request(app).get(`/api/v1/services/${createdId}`);
    expect(res.status).toBe(200);
    expect(res.body.name).toBe('Corte de pelo');
  });

  it('should return 404 for non-existent service', async () => {
    const res = await request(app).get('/api/v1/services/99999');
    expect(res.status).toBe(404);
  });
});

describe('PUT /api/v1/services/:id', () => {
  it('should update a service', async () => {
    const res = await request(app)
      .put(`/api/v1/services/${createdId}`)
      .send({ price: 3000, description: 'Corte moderno' });

    expect(res.status).toBe(200);
    expect(res.body.price).toBe(3000);
    expect(res.body.description).toBe('Corte moderno');
  });

  it('should return 404 for non-existent service', async () => {
    const res = await request(app)
      .put('/api/v1/services/99999')
      .send({ name: 'No' });

    expect(res.status).toBe(404);
  });

  it('should preserve unchanged fields', async () => {
    const res = await request(app).get(`/api/v1/services/${createdId}`);
    expect(res.body.duration).toBe(45);
  });
});

describe('DELETE /api/v1/services/:id', () => {
  it('should delete a service', async () => {
    const res = await request(app)
      .post('/api/v1/services')
      .send({ name: 'Temp service', duration: 15 });

    const del = await request(app).delete(`/api/v1/services/${res.body.id}`);
    expect(del.status).toBe(200);
    expect(del.body.name).toBe('Temp service');
  });

  it('should return 404 for non-existent service', async () => {
    const res = await request(app).delete('/api/v1/services/99999');
    expect(res.status).toBe(404);
  });
});
