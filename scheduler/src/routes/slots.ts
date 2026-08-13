import { Router, Request, Response } from 'express';
import { queryAll, queryGet } from '../db';
import { ServiceRow, ProviderSettingsRow } from '../types';
import { PROVIDER_ID } from '../config';

interface SlotRow {
  start: string;
  end: string;
}

function getSlotsForDate(date: string, serviceId: number, providerId: number): string[] {
  const service = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', serviceId);
  if (!service) return [];

  const dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
  const dt = new Date(date + 'T12:00:00');
  const dayOfWeek = dayNames[dt.getDay()];

  const prov = queryGet<ProviderSettingsRow>('SELECT * FROM provider_settings WHERE provider_id = ?', providerId);
  if (!prov) return [];

  let wp: Record<string, { start: string; end: string; breaks: { start: string; end: string }[] }> = {};
  try { wp = JSON.parse(prov.working_plan); } catch {}
  const day = wp[dayOfWeek];
  if (!day || !day.start || !day.end) return [];

  const dayStart = new Date(`${date}T${day.start}:00`).getTime();
  const dayEnd = new Date(`${date}T${day.end}:00`).getTime();
  const duration = service.duration * 60 * 1000;
  const slotInterval = (service.slot_interval || service.duration) * 60 * 1000;

  const appointments = queryAll<SlotRow>(
    "SELECT start, end FROM appointments WHERE provider_id = ? AND start LIKE ? AND status != 'cancelled'",
    providerId, `${date}%`
  );

  const existing = appointments.map(a => ({
    start: new Date(a.start).getTime(),
    end: new Date(a.end).getTime(),
  }));

  const breaks = (day.breaks || []).filter(b => b.start && b.end).map(b => ({
    start: new Date(`${date}T${b.start}:00`).getTime(),
    end: new Date(`${date}T${b.end}:00`).getTime(),
  }));

  const slots: string[] = [];
  const now = Date.now();
  const isToday = new Date().toISOString().split('T')[0] === date;

  for (let slotStart = dayStart; slotStart + duration <= dayEnd; slotStart += slotInterval) {
    const slotEnd = slotStart + duration;
    if (isToday && slotStart <= now) continue;
    if (breaks.some(b => slotStart < b.end && slotEnd > b.start)) continue;
    if (existing.some(a => slotStart < a.end && slotEnd > a.start)) continue;
    slots.push(new Date(slotStart).toTimeString().slice(0, 5));
  }

  return slots;
}

export function register(router: Router): void {
  router.get('/slots', (req: Request, res: Response) => {
    const serviceId = Number(req.query.serviceId);
    const date = req.query.date as string;
    if (!serviceId || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      return res.status(400).json({ error: 'Faltan serviceId y date (YYYY-MM-DD)' });
    }

    const service = queryGet<ServiceRow>('SELECT * FROM services WHERE id = ?', serviceId);
    if (!service) return res.json({ slots: [], error: 'Servicio no encontrado' });

    const dayOff = queryGet<{ reason: string }>('SELECT reason FROM days_off WHERE provider_id = ? AND date = ?', PROVIDER_ID, date);
    if (dayOff) {
      return res.json({ slots: [], dayOff: true, reason: dayOff.reason });
    }

    const prov = queryGet<ProviderSettingsRow>('SELECT * FROM provider_settings WHERE provider_id = ?', PROVIDER_ID);
    if (!prov) return res.json({ slots: [], error: 'Profesional no configurado' });

    const dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const dt = new Date(date + 'T12:00:00');
    const dayOfWeek = dayNames[dt.getDay()];

    let wp: Record<string, { start: string; end: string; breaks: { start: string; end: string }[] }> = {};
    try { wp = JSON.parse(prov.working_plan); } catch {}
    const day = wp[dayOfWeek];
    if (!day || !day.start || !day.end) {
      return res.json({ slots: [], dayOff: true });
    }

    const dayStart = new Date(`${date}T${day.start}:00`).getTime();
    const dayEnd = new Date(`${date}T${day.end}:00`).getTime();
    const duration = service.duration * 60 * 1000;
    const slotInterval = (service.slot_interval || service.duration) * 60 * 1000;

    const appointments = queryAll<{ start: string; end: string }>(
      "SELECT start, end FROM appointments WHERE provider_id = ? AND start LIKE ? AND status != 'cancelled'",
      PROVIDER_ID, `${date}%`
    );

    const existing = appointments.map(a => ({
      start: new Date(a.start).getTime(),
      end: new Date(a.end).getTime(),
    }));

    const breaks = (day.breaks || []).filter((b): b is { start: string; end: string } => !!b.start && !!b.end).map(b => ({
      start: new Date(`${date}T${b.start}:00`).getTime(),
      end: new Date(`${date}T${b.end}:00`).getTime(),
    }));

    const slots: string[] = [];
    const now = Date.now();
    const isToday = new Date().toISOString().split('T')[0] === date;

    for (let slotStart = dayStart; slotStart + duration <= dayEnd; slotStart += slotInterval) {
      const slotEnd = slotStart + duration;
      if (isToday && slotStart <= now) continue;
      if (breaks.some(b => slotStart < b.end && slotEnd > b.start)) continue;
      if (existing.some(a => slotStart < a.end && slotEnd > a.start)) continue;
      slots.push(new Date(slotStart).toTimeString().slice(0, 5));
    }

    res.json({ slots, date, serviceId, duration: service.duration, dayOff: false });
  });

  router.get('/availabilities', (req: Request, res: Response) => {
    const providerId = Number(req.query.providerId);
    const serviceId = Number(req.query.serviceId);
    const date = req.query.date as string;
    if (!providerId || !serviceId || !date) {
      return res.status(400).json({ success: false, message: 'providerId, serviceId y date requeridos' });
    }

    const dayOff = queryGet<{ reason: string }>('SELECT reason FROM days_off WHERE provider_id = ? AND date = ?', providerId, date);
    if (dayOff) {
      return res.json({ dayOff: true, reason: dayOff.reason });
    }

    const slots = getSlotsForDate(date, serviceId, providerId);
    res.json(slots);
  });
}
