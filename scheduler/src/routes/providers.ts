import { Router, Request, Response } from 'express';
import { queryGet, queryRun } from '../db';
import { ProviderSettingsRow } from '../types';

function mapProvider(r: ProviderSettingsRow) {
  let wp = {};
  try { wp = JSON.parse(r.working_plan); } catch {}
  return {
    id: r.provider_id,
    firstName: r.first_name,
    lastName: r.last_name,
    email: r.email,
    phone: r.phone,
    timezone: r.timezone,
    settings: {
      workingPlan: wp,
      username: r.username,
      notifications: !!r.notifications,
      calendarView: r.calendar_view,
    },
    profesional: r.profesional || '',
    address: r.address || '',
  };
}

export function register(router: Router): void {
  router.get('/providers/:id', (req: Request, res: Response) => {
    const row = queryGet<ProviderSettingsRow>('SELECT * FROM provider_settings WHERE provider_id = ?', +req.params.id);
    if (!row) return res.status(404).json({ success: false, message: 'Profesional no encontrado' });
    res.json(mapProvider(row));
  });

  router.put('/providers/:id', (req: Request, res: Response) => {
    const existing = queryGet<ProviderSettingsRow>('SELECT * FROM provider_settings WHERE provider_id = ?', +req.params.id);
    if (!existing) return res.status(404).json({ success: false, message: 'Profesional no encontrado' });

    const d = req.body || {};
    let workingPlan = existing.working_plan;
    let firstName = existing.first_name;
    let lastName = existing.last_name;
    let email = existing.email;
    let phone = existing.phone;
    let timezone = existing.timezone;
    let username = existing.username;
    let notifications = existing.notifications;
    let calendarView = existing.calendar_view;

    if (d.settings) {
      if (d.settings.workingPlan) workingPlan = JSON.stringify(d.settings.workingPlan);
      if (d.settings.username) username = d.settings.username;
      if (d.settings.notifications !== undefined) notifications = d.settings.notifications ? 1 : 0;
      if (d.settings.calendarView) calendarView = d.settings.calendarView;
    }
    if (d.firstName) firstName = d.firstName;
    if (d.lastName) lastName = d.lastName;
    if (d.email) email = d.email;
    if (d.phone) phone = d.phone;
    if (d.timezone) timezone = d.timezone;

    queryRun(`
      UPDATE provider_settings SET first_name=?, last_name=?, email=?, phone=?, timezone=?,
        working_plan=?, username=?, notifications=?, calendar_view=?,
        profesional=COALESCE(NULLIF(?, ''), profesional), address=COALESCE(NULLIF(?, ''), address)
      WHERE provider_id=?
    `, firstName, lastName, email, phone, timezone, workingPlan, username, notifications, calendarView,
      d.profesional || '', d.address || '', +req.params.id);

    const row = queryGet<ProviderSettingsRow>('SELECT * FROM provider_settings WHERE provider_id = ?', +req.params.id)!;
    res.json(mapProvider(row));
  });
}
