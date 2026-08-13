import { Router, Request, Response } from 'express';
import { openwaSendText } from '../openwa';

export function register(router: Router): void {
  const handler = async (req: Request, res: Response) => {
    const phone = (req.body?.phone || req.query?.phone) as string | undefined;
    const message = (req.body?.message || req.query?.message) as string | undefined;
    if (!phone || !message) {
      return res.status(400).json({ success: false, message: 'phone y message requeridos' });
    }
    if (message.length > 4096) {
      return res.status(400).json({ success: false, message: 'Mensaje excede 4096 chars' });
    }

    const result = await openwaSendText(phone, message);
    if (!result.ok) {
      return res.status(502).json({ success: false, message: 'Error al enviar mensaje' });
    }
    return res.status(201).json({ success: true, messageId: result.messageId });
  };

  router.post('/whatsapp/send', handler);
  router.get('/whatsapp/send', handler);
}
