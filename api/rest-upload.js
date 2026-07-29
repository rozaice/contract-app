const https = require('https');

module.exports = async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.status(200).end();
    return;
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const targetUrl = req.query._url;
  if (!targetUrl) {
    return res.status(400).json({ error: 'Missing _url' });
  }

  const urlObj = new URL(targetUrl);

  // Read multipart body as buffer
  const chunks = [];
  await new Promise((resolve) => {
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', resolve);
  });
  const body = Buffer.concat(chunks);

  const options = {
    hostname: urlObj.hostname,
    path: urlObj.pathname + urlObj.search,
    method: 'POST',
    headers: {
      'Content-Type': req.headers['content-type'] || 'application/octet-stream',
      'Content-Length': body.length,
    },
  };

  try {
    const result = await new Promise((resolve, reject) => {
      const proxyReq = https.request(options, (proxyRes) => {
        let data = '';
        proxyRes.on('data', chunk => data += chunk);
        proxyRes.on('end', () => {
          try {
            resolve(JSON.parse(data));
          } catch (e) {
            reject(new Error('Invalid JSON response'));
          }
        });
      });
      proxyReq.on('error', reject);
      proxyReq.end(body);
    });

    return res.status(200).json(result);
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
};
