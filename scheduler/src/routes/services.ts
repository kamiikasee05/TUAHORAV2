import { Router, Request, Response } from 'express';
import { queryAll, queryGet, queryRun } from '../db';
import { ServiceRow } from '../types';

function mapService(r: ServiceRow) {
  return {
    id: r.id,
    name: r.name,
    duration: r.duration,
    price: r.price,
    currency: r.currency,
    description: r.description,
    slotInterval: r.slot_interval,
    attendantsNumber: r.attendants_number,
    serviceCategoryId: r.category_id,
  };
}

export function register(router: Router): void {
  router.get('/services', (_req: Request, res: Response) => {
    const rows = queryAll<ServiceRow>('SELECT * FROM services ORDER BY id');
    res.json(rows.map(mapService));
  });

  router.get('/services/:id', (req: Request, res: Response) => {
    const row = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Servicio no encontrado' });
    res.json(mapService(row));
  });

  router.post('/services', (req: Request, res: Response) => {
    const { name, duration, price, currency, description, slotInterval, attendantsNumber, serviceCategoryId } = req.body || {};
    if (!name || !duration) {
      return res.status(400).json({ success: false, message: 'name y duration requeridos' });
    }
    const result = queryRun(`
      INSERT INTO services (name, duration, price, currency, description, slot_interval, attendants_number, category_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `, name, duration, price || 0, currency || 'ARS', description || '', slotInterval || 15, attendantsNumber || 1, serviceCategoryId || null);
    const row = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', result.lastInsertRowid)!;
    res.status(201).json(mapService(row));
  });

  router.put('/services/:id', (req: Request, res: Response) => {
    const existing = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', +req.params.id);
    if (!existing) return res.status(404).json({ success: false, message: 'Servicio no encontrado' });
    const d = req.body || {};
    queryRun(`
      UPDATE services SET name=?, duration=?, price=?, currency=?, description=?, slot_interval=?, attendants_number=?, category_id=?
      WHERE id=?
    `, d.name ?? existing.name, d.duration ?? existing.duration, d.price ?? existing.price,
      d.currency ?? existing.currency, d.description ?? existing.description,
      d.slotInterval ?? existing.slot_interval, d.attendantsNumber ?? existing.attendants_number,
      d.serviceCategoryId ?? existing.category_id, +req.params.id);
    const row = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', +req.params.id)!;
    res.json(mapService(row));
  });

  router.delete('/services/:id', (req: Request, res: Response) => {
    const row = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Servicio no encontrado' });
    queryRun('DELETE FROM services WHERE id = ?', +req.params.id);
    res.json(mapService(row));
  });
}
