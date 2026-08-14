// NOTA (13/8/2026): scaffolding heredado de n8n (v1). Se conserva POR AHORA:
// es inofensivo (no-op cuando N8N_WEBHOOK_URL no está definida) y lo usan 5 call-sites
// en src/routes/appointments.ts (webhookFire). Removerlo exigiría tocar appointments.ts
// y su test; queda para una limpieza futura. No configurar N8N_WEBHOOK_URL en v2.
import * as http from 'http';
import * as https from 'https';
import { logger } from './logger';

const N8N_WEBHOOK_URL: string = process.env.N8N_WEBHOOK_URL || '';

export function fire(event: string, payload: Record<string, unknown>): void {
  if (!N8N_WEBHOOK_URL) return;

  const url = `${N8N_WEBHOOK_URL.replace(/\/+$/, '')}/${event}`;
  const body = JSON.stringify({ event, ...payload });
  const client = url.startsWith('https') ? https : http;
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Content-Length': String(Buffer.byteLength(body)),
  };
  if (process.env.N8N_WEBHOOK_TOKEN) {
    headers['X-Webhook-Token'] = process.env.N8N_WEBHOOK_TOKEN;
  }

  try {
    const req = client.request(url, {
      method: 'POST',
      headers,
      timeout: 3000,
    });
    req.write(body);
    req.end();
    logger.debug({ event, url: url.slice(0, 60) }, 'Webhook fired');
  } catch (err) {
    logger.warn({ event, err }, 'Webhook fire failed');
  }
}
