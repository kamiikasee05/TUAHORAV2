// Seed script: re-insert default services if DB is empty
// Usage: node scripts/seed.js
const http = require('http');

const API_KEY = process.env.API_KEY || process.env.SCHEDULER_API_KEY || '';
const PORT = process.env.PORT || 3000;

function post(path, body) {
  return new Promise((resolve, reject) => {
    const data = JSON.stringify(body);
    const req = http.request({
      hostname: 'localhost', port: PORT, path, method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key': API_KEY,
        'Content-Length': Buffer.byteLength(data)
      }
    }, (res) => {
      let d = '';
      res.on('data', c => d += c);
      res.on('end', () => {
        if (res.statusCode >= 200 && res.statusCode < 300) resolve(JSON.parse(d));
        else reject(new Error(d));
      });
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}

async function seed() {
  // Check existing services
  const check = await new Promise(r => {
    http.get({ hostname: 'localhost', port: PORT, path: '/api/v1/services' }, res => {
      let d = ''; res.on('data', c => d += c); res.on('end', () => r(JSON.parse(d)));
    });
  });

  if (Array.isArray(check) && check.length > 0) {
    console.log(`${check.length} services exist, skipping seed`);
    return;
  }

  const services = [
    { name: 'Esmaltado semipermanente', duration: 60, price: 4500, description: 'Color a elección, dura hasta 3 semanas' },
    { name: 'Kapping gel', duration: 90, price: 5500, description: 'Uñas esculpidas con gel de alta resistencia' },
    { name: 'Manicura completa', duration: 75, price: 3800, description: 'Incluye limado, cutícula, esmaltado y masaje' },
    { name: 'Soft gel', duration: 60, price: 4200, description: 'Uñas naturales esculpidas con gel suave' },
    { name: 'Belleza de pies', duration: 60, price: 3500, description: 'Pedicura completa con esmaltado' },
  ];

  for (const s of services) {
    try { const r = await post('/api/v1/services', s); console.log('  +', r.name); } catch (e) { console.error('  x', s.name, e.message); }
  }
  console.log('Seed complete');
}

seed().catch(e => { console.error(e); process.exit(1); });
