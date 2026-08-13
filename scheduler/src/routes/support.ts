import { Router, Request, Response } from 'express';
import { queryAll, queryGet } from '../db';
import { openwaHealth } from '../openwa';

interface AppointmentRow_ {
  id: number; start: string; end: string;
  service_id: number; customer_id: number; provider_id: number;
  status: string; notes: string; hash: string;
  c_first_name?: string; c_last_name?: string; c_email?: string; c_phone?: string;
  s_name?: string; s_duration?: number; s_price?: number;
}

export function register(router: Router): void {
  router.get('/support/stats', (req: Request, res: Response) => {
    const now = new Date();
    now.setHours(now.getHours() - 3);
    const todayStr = now.toISOString().split('T')[0];

    const today = queryAll<{ status: string; count: number }>(
      `SELECT status, COUNT(*) as count FROM appointments WHERE date(start) = ? GROUP BY status`, todayStr);

    const dayOfWeek = now.getDay();
    const mondayOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
    const monday = new Date(now);
    monday.setDate(now.getDate() + mondayOffset);
    const mondayStr = monday.toISOString().split('T')[0];
    const sunday = new Date(monday);
    sunday.setDate(monday.getDate() + 6);
    const sundayStr = sunday.toISOString().split('T')[0];

    const week = queryAll<{ status: string; count: number }>(
      `SELECT status, COUNT(*) as count FROM appointments WHERE date(start) >= ? AND date(start) <= ? GROUP BY status`,
      mondayStr, sundayStr);

    const monthStr = todayStr.substring(0, 7);
    const month = queryAll<{ status: string; count: number }>(
      `SELECT status, COUNT(*) as count FROM appointments WHERE date(start) LIKE ? GROUP BY status`, `${monthStr}%`);

    const totalCustomers = queryGet<{ c: number }>('SELECT COUNT(*) as c FROM customers')?.c || 0;
    const totalServices = queryGet<{ c: number }>('SELECT COUNT(*) as c FROM services')?.c || 0;

    const nextAppointments = queryAll<AppointmentRow_>(
      `SELECT a.*, c.first_name AS c_first_name, c.last_name AS c_last_name, c.phone AS c_phone, s.name AS s_name
       FROM appointments a
       LEFT JOIN customers c ON a.customer_id = c.id
       LEFT JOIN services s ON a.service_id = s.id
       WHERE a.status = 'confirmed' AND a.start >= datetime('now', '-3 hours')
       ORDER BY a.start LIMIT 5`);

    res.json({
      today: {
        confirmed: today.find(r => r.status === 'confirmed')?.count || 0,
        cancelled: today.find(r => r.status === 'cancelled')?.count || 0,
      },
      week: {
        confirmed: week.find(r => r.status === 'confirmed')?.count || 0,
        cancelled: week.find(r => r.status === 'cancelled')?.count || 0,
      },
      month: {
        confirmed: month.find(r => r.status === 'confirmed')?.count || 0,
        cancelled: month.find(r => r.status === 'cancelled')?.count || 0,
      },
      totalCustomers,
      totalServices,
      nextAppointments: nextAppointments.map(a => ({
        id: a.id, start: a.start, end: a.end,
        customerName: `${a.c_first_name || ''} ${a.c_last_name || ''}`.trim(),
        phone: a.c_phone || '',
        serviceName: a.s_name || '',
        status: a.status,
      })),
    });
  });

  router.get('/support/health', async (req: Request, res: Response) => {
    const openwa = await openwaHealth();
    res.json({
      scheduler: { status: 'ok', uptime: Math.floor(process.uptime()) },
      openwa: { status: openwa },
      redis: { status: process.env.REDIS_URL ? 'configured' : 'disabled' },
      env: {
        openwaBaseUrl: process.env.OPENWA_BASE_URL || 'default',
        sessionId: process.env.OPENWA_SESSION_ID ? 'set' : 'not set',
        apiKey: process.env.API_KEY ? 'set' : 'not set',
        nodeEnv: process.env.NODE_ENV || 'development',
      },
    });
  });

  router.get('/support/search', (req: Request, res: Response) => {
    const q = (req.query.q as string || '').trim();
    if (!q || q.length < 2) {
      return res.json({ customers: [], appointments: [] });
    }

    const t = `%${q}%`;
    const customers = queryAll<any>(
      `SELECT * FROM customers WHERE first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ? LIMIT 20`,
      t, t, t, t)
      .map(c => ({ id: c.id, firstName: c.first_name, lastName: c.last_name, phone: c.phone, email: c.email }));

    const appointments = queryAll<any>(
      `SELECT a.*, c.first_name AS c_first_name, c.last_name AS c_last_name, c.phone AS c_phone, s.name AS s_name
       FROM appointments a
       LEFT JOIN customers c ON a.customer_id = c.id
       LEFT JOIN services s ON a.service_id = s.id
       WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ?
       ORDER BY a.start DESC LIMIT 20`,
      t, t, t)
      .map(a => ({
        id: a.id, start: a.start, end: a.end,
        customerName: `${a.c_first_name || ''} ${a.c_last_name || ''}`.trim(),
        phone: a.c_phone || '',
        serviceName: a.s_name || '',
        status: a.status,
      }));

    res.json({ customers, appointments });
  });
}
