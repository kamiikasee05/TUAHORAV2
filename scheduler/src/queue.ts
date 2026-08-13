import { openwaSendText } from './openwa';
import { logger } from './logger';

const REDIS_URL = process.env.REDIS_URL || '';

let bullQueue: any = null;

async function getQueue() {
  if (bullQueue) return bullQueue;
  if (!REDIS_URL) return null;

  try {
    const Bull = require('bull');
    bullQueue = new Bull('whatsapp', REDIS_URL, {
      defaultJobOptions: {
        attempts: 3,
        backoff: { type: 'exponential', delay: 2000 },
        removeOnComplete: 100,
        removeOnFail: 50,
      },
    });

    bullQueue.process(async (job: any) => {
      const { phone, message, chatId } = job.data;
      await openwaSendText(phone, message, chatId);
    });

    bullQueue.on('completed', (job: any) => {
      logger.info({ jobId: job.id, phone: job.data.phone?.slice(-4) }, 'Queue job completed');
    });
    bullQueue.on('failed', (job: any, err: Error) => {
      logger.error({ jobId: job.id, err: err.message }, 'Queue job failed');
    });

    logger.info('WhatsApp queue initialized with Redis');
    return bullQueue;
  } catch (err: any) {
    logger.warn({ err: err.message }, 'Redis not available, WhatsApp will use direct HTTP');
    return null;
  }
}

export async function sendWhatsApp(phone: string, message: string, chatId?: string): Promise<void> {
  if (!phone || !message) {
    logger.warn('sendWhatsApp called with empty phone or message');
    return;
  }

  const q = await getQueue();
  if (q) {
    await q.add({ phone, message, chatId });
    logger.debug({ phone: phone.slice(-4) }, 'WhatsApp queued');
  } else {
    await openwaSendText(phone, message, chatId);
  }
}
