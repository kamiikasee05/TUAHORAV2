import { Router, Request, Response } from 'express';
import { queryAll, queryGet, queryRun } from '../db';
import { PaymentRow } from '../types';

export function register(router: Router): void {
  router.post('/payments', (req: Request, res: Response) => {
    const { appointmentId, amount, method, status, notes } = req.body || {};
    if (!appointmentId || amount === undefined) {
      return res.status(400).json({ success: false, message: 'appointmentId y amount requeridos' });
    }

    const existing = queryGet<PaymentRow>('SELECT * FROM payments WHERE appointment_id = ?', appointmentId);
    if (existing) {
      const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
      queryRun(`
        UPDATE payments SET amount=?, status=?, method=?, paid_at=?, notes=? WHERE appointment_id=?
      `, amount, status || 'paid', method || '', status === 'paid' ? now : null, notes || '', appointmentId);
      const updated = queryGet<PaymentRow>('SELECT * FROM payments WHERE id = ?', existing.id)!;
      return res.json(mapPayment(updated));
    }

    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const result = queryRun(`
      INSERT INTO payments (appointment_id, amount, status, method, paid_at, notes)
      VALUES (?, ?, ?, ?, ?, ?)
    `, appointmentId, amount, status || 'paid', method || '', status === 'paid' ? now : null, notes || '');
    const row = queryGet<PaymentRow>('SELECT * FROM payments WHERE id = ?', result.lastInsertRowid)!;
    res.status(201).json(mapPayment(row));
  });

  router.get('/payments/stats', (req: Request, res: Response) => {
    const month = (req.query.month as string) || new Date().toISOString().slice(0, 7);
    const startDate = month + '-01';
    const year = parseInt(month.slice(0, 4));
    const monthNum = parseInt(month.slice(5, 7));
    const lastDay = new Date(year, monthNum, 0).getDate();
    const endDate = month + '-' + String(lastDay).padStart(2, '0') + ' 23:59:59';

    const cobrado = queryGet<{ total: number }>(`
      SELECT COALESCE(SUM(p.amount), 0) as total
      FROM payments p
      JOIN appointments a ON p.appointment_id = a.id
      WHERE p.status = 'paid' AND a.start >= ? AND a.start <= ?
    `, startDate, endDate);

    const pendiente = queryGet<{ total: number }>(`
      SELECT COALESCE(SUM(s.price), 0) as total
      FROM appointments a
      JOIN services s ON a.service_id = s.id
      LEFT JOIN payments p ON a.id = p.appointment_id AND p.status = 'paid'
      WHERE a.status = 'confirmed' AND a.start >= ? AND a.start <= ?
        AND p.id IS NULL
    `, startDate, endDate);

    res.json({
      cobrado: cobrado?.total || 0,
      pendiente: pendiente?.total || 0,
      mes: month,
    });
  });
}

function mapPayment(r: PaymentRow) {
  return {
    id: r.id,
    appointmentId: r.appointment_id,
    amount: r.amount,
    status: r.status,
    method: r.method,
    paidAt: r.paid_at,
    notes: r.notes,
  };
}
