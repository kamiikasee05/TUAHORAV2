import { Router, Request, Response } from 'express';
import { queryAll, queryGet, queryRun } from '../db';
import { CustomerRow } from '../types';

function mapCustomer(r: CustomerRow) {
  return { id: r.id, firstName: r.first_name, lastName: r.last_name, email: r.email, phone: r.phone };
}

export function register(router: Router): void {
  router.get('/customers', (req: Request, res: Response) => {
    const { q } = req.query;
    let rows: CustomerRow[];
    if (q) {
      rows = queryAll<CustomerRow>('SELECT * FROM customers WHERE phone LIKE ? OR email LIKE ? ORDER BY id', `%${q}%`, `%${q}%`);
    } else {
      rows = queryAll<CustomerRow>('SELECT * FROM customers ORDER BY id');
    }
    res.json(rows.map(mapCustomer));
  });

  router.get('/customers/:id', (req: Request, res: Response) => {
    const row = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Cliente no encontrado' });
    res.json(mapCustomer(row));
  });

  router.post('/customers', (req: Request, res: Response) => {
    const { firstName, lastName, email, phone } = req.body || {};
    if (!firstName || !phone) {
      return res.status(400).json({ success: false, message: 'firstName y phone requeridos' });
    }
    const existing = queryGet<CustomerRow>('SELECT * FROM customers WHERE phone = ?', phone);
    if (existing) {
      if (firstName && firstName !== existing.first_name) {
        queryRun("UPDATE customers SET first_name = ?, last_name = ?, email = COALESCE(NULLIF(?, ''), email) WHERE id = ?",
          firstName, lastName || existing.last_name, email || '', existing.id);
      }
      const updated = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', existing.id)!;
      return res.status(200).json(mapCustomer(updated));
    }
    const result = queryRun(`
      INSERT INTO customers (first_name, last_name, email, phone)
      VALUES (?, ?, ?, ?)
    `, firstName, lastName || '', email || '', phone);
    const row = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', result.lastInsertRowid)!;
    res.status(201).json(mapCustomer(row));
  });

  router.put('/customers/:id', (req: Request, res: Response) => {
    const existing = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', +req.params.id);
    if (!existing) return res.status(404).json({ success: false, message: 'Cliente no encontrado' });
    const d = req.body || {};
    queryRun(`
      UPDATE customers SET first_name=?, last_name=?, email=?, phone=? WHERE id=?
    `, d.firstName ?? existing.first_name, d.lastName ?? existing.last_name,
      d.email ?? existing.email, d.phone ?? existing.phone, +req.params.id);
    const row = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', +req.params.id)!;
    res.json(mapCustomer(row));
  });

  router.delete('/customers/:id', (req: Request, res: Response) => {
    const row = queryGet<CustomerRow>('SELECT * FROM customers WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Cliente no encontrado' });
    const pending = queryGet<{ c: number }>('SELECT COUNT(*) as c FROM appointments WHERE customer_id = ?', +req.params.id)!;
    if (pending.c > 0) {
      return res.status(409).json({ success: false, message: `No se puede eliminar: tiene ${pending.c} turnos asociados` });
    }
    queryRun('DELETE FROM customers WHERE id = ?', +req.params.id);
    res.json(mapCustomer(row));
  });
}
