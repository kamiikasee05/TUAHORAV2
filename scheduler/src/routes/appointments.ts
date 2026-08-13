import { Router, Request, Response } from 'express';
import { queryAll, queryGet, queryRun } from '../db';
import { fire as webhookFire } from '../webhooks';
import { sendWhatsApp } from '../queue';
import { AppointmentRow, Appointment, Customer, ServiceBrief, ProviderBrief } from '../types';
import { logger } from '../logger';
import { PROVIDER_ID } from '../config';

function mapAppointment(r: AppointmentRow, wants: string[]): Appointment {
  const a: Appointment = {
    id: r.id,
    start: r.start,
    end: r.end,
    serviceId: r.service_id,
    providerId: r.provider_id,
    customerId: r.customer_id,
    status: r.status,
    notes: r.notes,
    hash: r.hash,
  };
  if (wants.includes('customer')) {
    a.customer = { id: r.customer_id, firstName: r.c_first_name || '', lastName: r.c_last_name || '', email: r.c_email || '', phone: r.c_phone || '' };
  }
  if (wants.includes('service')) {
    a.service = { id: r.service_id, name: r.s_name || '', duration: r.s_duration || 0, price: r.s_price || 0 };
  }
  if (wants.includes('provider')) {
    a.provider = { id: r.provider_id, firstName: r.p_first_name || '', lastName: r.p_last_name || '', address: r.p_address || '', profesional: r.p_profesional || '' };
  }
  if (r.pm_id) {
    a.payment = { id: r.pm_id, appointmentId: r.id, amount: r.pm_amount || 0, status: r.pm_status || 'pending', method: r.pm_method || '', paidAt: r.pm_paid_at || '' };
  }
  return a;
}

function getFullAppointment(id: number): Appointment {
  const row = queryGet<AppointmentRow>(`
    SELECT a.*,
      c.first_name AS c_first_name, c.last_name AS c_last_name, c.email AS c_email, c.phone AS c_phone,
      s.name AS s_name, s.duration AS s_duration, s.price AS s_price,
      p.first_name AS p_first_name, p.last_name AS p_last_name, p.address AS p_address, p.profesional AS p_profesional,
      pm.id AS pm_id, pm.status AS pm_status, pm.method AS pm_method, pm.amount AS pm_amount, pm.paid_at AS pm_paid_at
    FROM appointments a
    LEFT JOIN customers c ON a.customer_id = c.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN provider_settings p ON a.provider_id = p.provider_id
    LEFT JOIN payments pm ON a.id = pm.appointment_id
    WHERE a.id = ?
  `, id);
  return row ? mapAppointment(row, ['customer', 'service', 'provider']) : { id } as Appointment;
}

function notifyCustomer(full: Appointment, type: string): void {
  const c: Customer = full.customer || { id: 0, firstName: '', lastName: '', email: '', phone: '' };
  const s: ServiceBrief = full.service || { id: 0, name: '', duration: 0, price: 0 };
  const p: ProviderBrief = full.provider || { id: 0, firstName: '', lastName: '', address: '', profesional: '' };
  const phone = (c.phone || '').replace(/\+/g, '').replace(/ /g, '');
  if (!phone || phone.length < 8) return;
  const date = (full.start || '').split(' ')[0];
  const time = ((full.start || '').split(' ')[1] || '').substring(0, 5);
  const prof = p.profesional || (p.firstName ? p.firstName + ' ' + p.lastName : '');
  const addr = p.address || 'Mitre 456, Chamical';
  let msg: string;
  if (type === 'cancel') {
    msg = `Hola ${c.firstName || ''}, tu turno del ${date} a las ${time} (${s.name || ''}) fue cancelado.\n\n` +
      `👩‍🎨 ${prof}\n📍 ${addr}\n\nReserva un nuevo turno desde nuestra web.`;
  } else if (type === 'reschedule') {
    msg = `Hola ${c.firstName || ''}, tu turno fue reagendado.\n\n📅 ${date} a las ${time}\n💅 ${s.name || ''}\n👩‍🎨 ${prof}\n📍 ${addr}`;
  } else {
    msg = `Hola ${c.firstName || ''} ${c.lastName || ''}!\n\nTu turno esta confirmado:\n📅 ${date} a las ${time}\n💅 ${s.name || ''}\n👩‍🎨 ${prof}\n📍 ${addr}`;
  }
  sendWhatsApp(phone, msg);
}

export function register(router: Router): void {
  router.get('/appointments', (req: Request, res: Response) => {
    let sql = `SELECT a.*,
      c.first_name AS c_first_name, c.last_name AS c_last_name, c.email AS c_email, c.phone AS c_phone,
      s.name AS s_name, s.duration AS s_duration, s.price AS s_price,
      p.first_name AS p_first_name, p.last_name AS p_last_name, p.address AS p_address, p.profesional AS p_profesional,
      pm.id AS pm_id, pm.status AS pm_status, pm.method AS pm_method, pm.amount AS pm_amount, pm.paid_at AS pm_paid_at
      FROM appointments a
      LEFT JOIN customers c ON a.customer_id = c.id
      LEFT JOIN services s ON a.service_id = s.id
      LEFT JOIN provider_settings p ON a.provider_id = p.provider_id
      LEFT JOIN payments pm ON a.id = pm.appointment_id`;

    const { sort, length: limit, with: withParam, start, end, hash, customer_id, status } = req.query;
    const conditions: string[] = [];
    const params: unknown[] = [];

    if (hash) { conditions.push('a.hash = ?'); params.push(hash as string); }
    if (customer_id) { conditions.push('a.customer_id = ?'); params.push(+customer_id); }
    if (status) { conditions.push('a.status = ?'); params.push(status as string); }
    if (start) { conditions.push('a.start >= ?'); params.push(start as string); }
    if (end) { conditions.push('a.end <= ?'); params.push(end as string); }
    if (conditions.length) sql += ' WHERE ' + conditions.join(' AND ');

    if (sort) {
      const dir = (sort as string).startsWith('-') ? 'DESC' : 'ASC';
      const col = (sort as string).replace(/^-/, '');
      const colMap: Record<string, string> = { id: 'a.id', start: 'a.start', end: 'a.end' };
      sql += ` ORDER BY ${colMap[col] || 'a.id'} ${dir}`;
    } else {
      sql += ' ORDER BY a.start';
    }

    if (limit) sql += ` LIMIT ${+limit}`;

    const rows = queryAll<AppointmentRow>(sql, ...params);
    const wants = withParam ? (withParam as string).split(',').map(s => s.trim()) : [];
    res.json(rows.map(r => mapAppointment(r, wants)));
  });

  router.get('/appointments/:id/cancel', (req: Request, res: Response) => {
    const row = queryGet<AppointmentRow>('SELECT * FROM appointments WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Turno no encontrado' });
    queryRun('UPDATE appointments SET status = ? WHERE id = ?', 'cancelled', +req.params.id);
    const full = getFullAppointment(+req.params.id);
    webhookFire('appointment-cancelled', full as unknown as Record<string, unknown>);
    notifyCustomer(full, 'cancel');
    res.json({ id: row.id, status: 'cancelled', phone: '549' + (full.customer?.phone || row.c_phone || ''), start: row.start, service: full.service?.name || '' });
  });

  router.get('/appointments/:id', (req: Request, res: Response) => {
    const row = queryGet<AppointmentRow>(`
      SELECT a.*,
        c.first_name AS c_first_name, c.last_name AS c_last_name, c.email AS c_email, c.phone AS c_phone,
        s.name AS s_name, s.duration AS s_duration, s.price AS s_price,
        p.first_name AS p_first_name, p.last_name AS p_last_name, p.address AS p_address, p.profesional AS p_profesional,
        pm.id AS pm_id, pm.status AS pm_status, pm.method AS pm_method, pm.amount AS pm_amount, pm.paid_at AS pm_paid_at
      FROM appointments a
      LEFT JOIN customers c ON a.customer_id = c.id
      LEFT JOIN services s ON a.service_id = s.id
      LEFT JOIN provider_settings p ON a.provider_id = p.provider_id
      LEFT JOIN payments pm ON a.id = pm.appointment_id
      WHERE a.id = ?
    `, +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Turno no encontrado' });
    res.json(mapAppointment(row, ['customer', 'service', 'provider']));
  });

  router.post('/appointments', (req: Request, res: Response) => {
    const { start, end, serviceId, customerId, providerId, notes } = req.body || {};
    if (!start || !end || !serviceId || !customerId) {
      return res.status(400).json({ success: false, message: 'start, end, serviceId y customerId requeridos' });
    }
    const apptDate = (start as string).split(' ')[0];
    const dayOff = queryGet<Record<string, unknown>>('SELECT 1 FROM days_off WHERE provider_id = ? AND date = ?', providerId || PROVIDER_ID, apptDate);
    if (dayOff) {
      return res.status(409).json({ success: false, message: 'Esta fecha no está disponible (día no laborable)' });
    }
    const hash = Math.random().toString(36).substring(2, 10);
    const result = queryRun(`
      INSERT INTO appointments (start, end, service_id, customer_id, provider_id, notes, hash)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    `, start, end, serviceId, customerId, providerId || PROVIDER_ID, notes || '', hash);

    const row = queryGet<AppointmentRow>(`
      SELECT a.*,
        c.first_name AS c_first_name, c.last_name AS c_last_name, c.email AS c_email, c.phone AS c_phone,
        s.name AS s_name, s.duration AS s_duration, s.price AS s_price,
        p.first_name AS p_first_name, p.last_name AS p_last_name, p.address AS p_address, p.profesional AS p_profesional,
        pm.id AS pm_id, pm.status AS pm_status, pm.method AS pm_method, pm.amount AS pm_amount, pm.paid_at AS pm_paid_at
      FROM appointments a
      LEFT JOIN customers c ON a.customer_id = c.id
      LEFT JOIN services s ON a.service_id = s.id
      LEFT JOIN provider_settings p ON a.provider_id = p.provider_id
      LEFT JOIN payments pm ON a.id = pm.appointment_id
      WHERE a.id = ?
    `, result.lastInsertRowid)!;

    const full = mapAppointment(row, ['customer', 'service', 'provider']);
    webhookFire('appointment-created', full as unknown as Record<string, unknown>);

    const cust: Customer = full.customer || { id: 0, firstName: '', lastName: '', email: '', phone: '' };
    const svc: ServiceBrief = full.service || { id: 0, name: '', duration: 0, price: 0 };
    const prov: ProviderBrief = full.provider || { id: 0, firstName: '', lastName: '', address: '', profesional: '' };
    const phone = (cust.phone || '').replace(/\+/g, '').replace(/ /g, '');
    if (phone && phone.length >= 8) {
      const date = (full.start || '').split(' ')[0] || '';
      const time = ((full.start || '').split(' ')[1] || '').substring(0, 5);
      const msg = `¡Hola ${cust.firstName || ''} ${cust.lastName || ''}!\n\n` +
        `Tu turno está confirmado:\n` +
        `📅 ${date} a las ${time}\n` +
        `💅 Servicio: ${svc.name || ''}\n` +
        `👩‍🎨 Profesional: ${prov.profesional || prov.firstName || ''}\n` +
        `📍 ${prov.address || 'Mitre 456, Chamical'}\n\n` +
        `Para cancelar, respondé CANCELAR a este mensaje.`;
      sendWhatsApp(phone, msg);
    }

    res.status(201).json({
      id: row.id, start: row.start, end: row.end,
      serviceId: row.service_id, providerId: row.provider_id,
      customerId: row.customer_id, status: row.status,
      notes: row.notes, hash: row.hash,
    });
  });

  router.put('/appointments/:id', (req: Request, res: Response) => {
    const existing = queryGet<AppointmentRow>('SELECT * FROM appointments WHERE id = ?', +req.params.id);
    if (!existing) return res.status(404).json({ success: false, message: 'Turno no encontrado' });
    const d = req.body || {};
    if (d.start && d.start !== existing.start) {
      const newDate = (d.start as string).split(' ')[0];
      const dayOff = queryGet<Record<string, unknown>>('SELECT 1 FROM days_off WHERE provider_id = ? AND date = ?', d.providerId || existing.provider_id, newDate);
      if (dayOff) {
        return res.status(409).json({ success: false, message: 'La nueva fecha no está disponible (día no laborable)' });
      }
    }
    queryRun(`
      UPDATE appointments SET start=?, end=?, service_id=?, customer_id=?, provider_id=?, status=?, notes=?
      WHERE id=?
    `,
      d.start ?? existing.start, d.end ?? existing.end,
      d.serviceId ?? existing.service_id, d.customerId ?? existing.customer_id,
      d.providerId ?? existing.provider_id, d.status ?? existing.status,
      d.notes ?? existing.notes, +req.params.id
    );
    const row = queryGet<AppointmentRow>('SELECT * FROM appointments WHERE id = ?', +req.params.id)!;
    const full = getFullAppointment(row.id);

    if (d.status === 'cancelled') {
      webhookFire('appointment-cancelled', full as unknown as Record<string, unknown>);
      notifyCustomer(full, 'cancel');
    }
    if (d.start && d.start !== existing.start) {
      webhookFire('appointment-rescheduled', { ...full, oldStart: existing.start, newStart: row.start } as unknown as Record<string, unknown>);
      notifyCustomer(full, 'reschedule');
    }

    res.json({
      id: row.id, start: row.start, end: row.end,
      serviceId: row.service_id, providerId: row.provider_id,
      customerId: row.customer_id, status: row.status,
      notes: row.notes, hash: row.hash,
    });
  });

  router.delete('/appointments/:id', (req: Request, res: Response) => {
    const row = queryGet<AppointmentRow>('SELECT * FROM appointments WHERE id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Turno no encontrado' });
    const full = getFullAppointment(row.id);
    queryRun('DELETE FROM appointments WHERE id = ?', +req.params.id);
    webhookFire('appointment-cancelled', full as unknown as Record<string, unknown>);
    notifyCustomer(full, 'cancel');
    res.json({
      id: row.id, start: row.start, end: row.end,
      serviceId: row.service_id, providerId: row.provider_id,
      customerId: row.customer_id, status: row.status,
      notes: row.notes, hash: row.hash,
    });
  });
}
