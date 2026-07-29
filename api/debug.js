module.exports = async (req, res) => {
  let authData = {};

  // GET params
  if (req.query) {
    for (const key of Object.keys(req.query)) {
      authData[key] = req.query[key];
    }
  }

  // POST body
  if (req.method === 'POST') {
    const body = await new Promise((resolve) => {
      let data = '';
      req.on('data', chunk => data += chunk);
      req.on('end', () => resolve(data));
    });
    authData['_post_body'] = body || '(empty)';
    authData['_content_type'] = req.headers['content-type'] || '(none)';
  }

  authData['_method'] = req.method;
  authData['_url'] = req.url;
  authData['_headers'] = JSON.stringify(Object.fromEntries(
    Object.entries(req.headers).filter(([k]) => !['cookie', 'x-forwarded-for'].includes(k))
  ));

  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Debug</title></head><body>
    <h1>Debug Auth Params</h1>
    <pre>${JSON.stringify(authData, null, 2)}</pre>
    <script>window.__BX_AUTH__ = ${JSON.stringify(authData)};</script>
  </body></html>`;

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.status(200).send(html);
};
