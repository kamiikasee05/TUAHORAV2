import cron from 'node-cron';
import { Express, Request, Response } from 'express';
import { queryAll, queryGet } from './db';
import { sendWhatsApp } from './queue';
import { logger } from './logger';
import { PROVIDER_ID } from './config';

interface WorkflowApptRow {
  id: number;
  start: string;
  end: string;
  service_id: number;
  customer_id: number;
  provider_id: number;
  status: string;
  notes: string;
  hash: string;
  created_at: string;
  first_name?: string;
  last_name?: string;
  phone?: string;
  svc_name?: string;
  profesional?: string;
  address?: string;
}

export function startCronJobs(): void {
  cron.schedule('0 0 * * *', () => {
    logger.info('Running daily reminder');
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const dateStr = tomorrow.toISOString().split('T')[0];

    const rows = queryAll<WorkflowApptRow>(`
      SELECT a.*, c.first_name, c.last_name, c.phone, s.name as svc_name,
        p.profesional, p.address
      FROM appointments a
      JOIN customers c ON a.customer_id = c.id
      JOIN services s ON a.service_id = s.id
      LEFT JOIN provider_settings p ON a.provider_id = p.provider_id
      LEFT JOIN days_off d ON d.provider_id = a.provider_id AND d.date = ?
      WHERE a.status = 'confirmed' AND a.start LIKE ? AND d.id IS NULL
    `, dateStr, dateStr + '%');

    for (const r of rows) {
      const time = (r.start || '').split(' ')[1]?.substring(0, 5) || '';
      const phone = (r.phone || '').replace(/\+/g, '').replace(/ /g, '');
      if (!phone || phone.length < 8) continue;
      const prof = r.profesional || 'Cecilia Natali Godoy';
      const addr = r.address || 'Mitre 456, Chamical';
      const msg = `⏰ Recordatorio: tenés un turno mañana, ${r.first_name}!\n\n` +
        `💅 ${r.svc_name}\n📅 ${dateStr} a las ${time}\n👩‍🎨 ${prof}\n📍 ${addr}\n\n` +
        `Para cancelar, respondé CANCELAR a este mensaje.`;
      sendWhatsApp(phone, msg);
    }
    logger.info({ count: rows.length }, 'Reminders sent');
  }, { timezone: 'America/Argentina/Buenos_Aires' });

  logger.info('Cron jobs started');
}

export function registerWhatsAppWebhook(app: Express): void {
  app.post('/webhook/whatsapp', (req: Request, res: Response) => {
    const payload: Record<string, any> = req.body?.data || req.body || {};
    const from: string = (payload.from || '').replace(/@.*$/, '');
    const text: string = ((payload.body || '').toUpperCase());

    if (!text.includes('CANCELAR') && !text.includes('CAMBIAR') && !text.includes('REAGENDAR')) {
      return res.json({ processed: false });
    }

    logger.info({ from, text: text.substring(0, 30) }, 'WhatsApp inbound');

    const brand = queryGet<{ profesional: string; address: string }>(
      'SELECT profesional, address FROM provider_settings WHERE provider_id = ?'
    , PROVIDER_ID) || { profesional: '', address: '' };
    const brandProf = brand.profesional || 'Cecilia Natali Godoy';
    const brandAddr = brand.address || 'Mitre 456, Chamical';

    const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
    let appts = queryAll<WorkflowApptRow>(`
      SELECT a.*, c.first_name, c.last_name, c.phone
      FROM appointments a
      JOIN customers c ON a.customer_id = c.id
      WHERE a.status = 'confirmed' AND a.start > ?
      ORDER BY a.start
    `, now);

    const found = appts.filter(a => {
      const p = (a.phone || '').replace(/\+/g, '').replace(/ /g, '');
      return p.includes(from) || from.includes(p.substring(p.length - 8));
    });

    if (found.length === 0 && appts.length === 0) {
      return res.json({ processed: true, message: 'no appointments' });
    }

    const candidates = found.length > 0 ? found : appts;

    if (candidates.length === 1) {
      const a = candidates[0];
      if (text.includes('CANCELAR')) {
        queryGet('UPDATE appointments SET status = ? WHERE id = ?', 'cancelled', a.id);
        const phone = (a.phone || '').replace(/\+/g, '').replace(/ /g, '');
        sendWhatsApp(phone, `Hola ${a.first_name}, tu turno del ${(a.start||'').split(' ')[0]} fue cancelado.\n\n👩‍🎨 ${brandProf}\n📍 ${brandAddr}\n\nReserva un nuevo turno desde la web.`);
        logger.info({ id: a.id }, 'Cancelled via WhatsApp');
        return res.json({ processed: true, action: 'cancelled', id: a.id });
      } else {
        queryGet('UPDATE appointments SET status = ? WHERE id = ?', 'cancelled', a.id);
        const phone = (a.phone || '').replace(/\+/g, '').replace(/ /g, '');
        sendWhatsApp(phone, `Hola ${a.first_name}, tu turno fue cancelado. Ingresá a nuestra web para reagendar uno nuevo.\n\n👩‍🎨 ${brandProf}\n📍 ${brandAddr}`);
        logger.info({ id: a.id }, 'Cancelled for reschedule via WhatsApp');
        return res.json({ processed: true, action: 'cancelled_for_reschedule', id: a.id });
      }
    } else {
      const list = candidates.map((a, i) =>
        `${i + 1}. ${(a.start||'').split(' ')[0]} ${((a.start||'').split(' ')[1]||'').substring(0,5)} — ID ${a.id}`
      ).join('\n');
      const phone = (candidates[0].phone || '').replace(/\+/g, '').replace(/ /g, '');
      const action = text.includes('CANCELAR') ? 'cancelar' : 'cambiar';
      sendWhatsApp(phone, `Tenés ${candidates.length} turnos activos. ¿Cuál querés ${action}?\n\n${list}\n\n👩‍🎨 ${brandProf}\n📍 ${brandAddr}\n\nRespondé con el número.`);
      logger.info({ count: candidates.length }, 'Sent selection list');
      return res.json({ processed: true, action: 'list_sent', count: candidates.length });
    }
  });

  logger.info('WhatsApp webhook registered at POST /webhook/whatsapp');
}
