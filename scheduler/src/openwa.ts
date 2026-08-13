import { logger } from './logger';

const API_KEY = process.env.OPENWA_API_KEY;
const SESSION_ID = process.env.OPENWA_SESSION_ID;

function baseUrl(): string {
  const configured = process.env.OPENWA_BASE_URL;
  if (configured) return configured.replace(/\/+$/, '');
  return `http://${process.env.OPENWA_HOST || '127.0.0.1'}:${process.env.OPENWA_PORT || 2785}`;
}

function configured(): boolean {
  return !!API_KEY && !!SESSION_ID;
}

export interface SendResult {
  ok: boolean;
  status?: number;
  messageId?: string;
}

export async function openwaSendText(
  phone: string,
  message: string,
  chatId?: string
): Promise<SendResult> {
  if (!configured()) {
    logger.error('OpenWA config missing (OPENWA_API_KEY / OPENWA_SESSION_ID)');
    return { ok: false };
  }

  const id = chatId || (phone.includes('@c.us') ? phone : phone + '@c.us');

  try {
    const res = await fetch(`${baseUrl()}/api/sessions/${SESSION_ID}/messages/send-text`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': API_KEY!,
      },
      body: JSON.stringify({ chatId: id, text: message }),
      signal: AbortSignal.timeout(15000),
    });

    const data = await res.json().catch(() => null);
    const ok = res.status === 200 || res.status === 201;
    if (!ok) {
      logger.warn({ status: res.status, body: data }, 'OpenWA send rejected');
    }
    return { ok, status: res.status, messageId: data?.messageId };
  } catch (err: any) {
    logger.error({ err: err.message }, 'OpenWA send error');
    return { ok: false };
  }
}

export type HealthStatus = 'ok' | 'error' | 'timeout';

export async function openwaHealth(): Promise<HealthStatus> {
  if (!configured()) return 'error';
  try {
    const res = await fetch(`${baseUrl()}/api/health`, {
      signal: AbortSignal.timeout(3000),
    });
    return res.status === 200 ? 'ok' : 'error';
  } catch {
    return 'error';
  }
}
