import { Router, Request, Response } from 'express';
import { queryAll, queryGet, queryRun } from '../db';
import { logger } from '../logger';
import { PROVIDER_ID } from '../config';

export function register(router: Router): void {
  router.get('/days_off', (_req: Request, res: Response) => {
    const rows = queryAll<Record<string, unknown>>('SELECT * FROM days_off WHERE provider_id = ? ORDER BY date DESC', PROVIDER_ID);
    res.json(rows);
  });

  router.post('/days_off', (req: Request, res: Response) => {
    const { date, reason } = req.body || {};
    if (!date) return res.status(400).json({ success: false, message: 'date requerido' });
    const apptDate = (date as string).split(' ')[0];
    const active = queryGet<{ c: number }>("SELECT COUNT(*) as c FROM appointments WHERE provider_id = ? AND start LIKE ? AND status = 'confirmed'", PROVIDER_ID, apptDate + '%')!;
    if (active.c > 0) {
      return res.status(409).json({ success: false, message: `Hay ${active.c} turno(s) activo(s) en esta fecha. Reagendalos manualmente antes de bloquear el día.` });
    }
    try {
      queryRun('INSERT OR REPLACE INTO days_off (provider_id, date, reason) VALUES (?, ?, ?)', PROVIDER_ID, apptDate, reason || '');
      res.json({ success: true, date: apptDate, reason: reason || '' });
    } catch (e) {
      logger.error({ err: e }, 'Error saving days_off');
      res.status(500).json({ success: false, message: 'Error al guardar' });
    }
  });

  router.delete('/days_off/:date', (req: Request, res: Response) => {
    queryRun('DELETE FROM days_off WHERE provider_id = ? AND date = ?', PROVIDER_ID, req.params.date);
    res.json({ success: true });
  });
}
