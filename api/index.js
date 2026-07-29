const fs = require('fs');
const path = require('path');

module.exports = async (req, res) => {
  let authData = {};

  if (req.method === 'POST') {
    const body = await new Promise((resolve) => {
      let data = '';
      req.on('data', chunk => data += chunk);
      req.on('end', () => resolve(data));
    });

    const params = new URLSearchParams(body);
    for (const [key, value] of params) {
      authData[key] = value;
    }
  }

  const filePath = path.join(__dirname, '..', 'index.html');
  let html = fs.readFileSync(filePath, 'utf8');

  const authScript = `<script>window.__BX_AUTH__ = ${JSON.stringify(authData)};</script>`;

  html = html.replace('<head>', '<head>' + authScript);

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.status(200).send(html);
};
