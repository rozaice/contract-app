module.exports = async (req, res) => {
  let params = {};

  if (req.query) {
    for (const key of Object.keys(req.query)) {
      params[key] = req.query[key];
    }
  }

  if (req.method === 'POST') {
    const body = await new Promise((resolve) => {
      let data = '';
      req.on('data', chunk => data += chunk);
      req.on('end', () => resolve(data));
    });
    if (body) {
      try {
        const qs = new URLSearchParams(body);
        for (const [key, value] of qs) {
          if (!params[key]) params[key] = value;
        }
      } catch (e) {}
    }
  }

  const accept = (req.headers['accept'] || '').toLowerCase();

  if (accept.includes('text/html') || accept.includes('application/xhtml+xml')) {
    const scheme = (req.headers['x-forwarded-proto'] || 'https').split(',')[0].trim();
    const host = req.headers['x-forwarded-host'] || req.headers.host;
    const base = scheme + '://' + host + '/';
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.status(200).send(
      '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">' +
      '<meta http-equiv="refresh" content="0;url=' + base + '">' +
      '<title>Установка приложения</title></head>' +
      '<body><p>Переход в приложение...</p>' +
      '<script>window.location.replace("' + base + '" + window.location.search);</script>' +
      '</body></html>'
    );
    return;
  }

  const domain = params.domain || params.DOMAIN || '';
  const authToken = params.auth_token || params.AUTH_ID || '';
  const refreshToken = params.refresh_token || params.REFRESH_ID || '';
  const memberId = params.member_id || '';
  const userId = params.user_id || '';

  console.log('APP_INSTALL', JSON.stringify({
    domain, hasAuth: !!authToken, memberId, userId,
    receivedKeys: Object.keys(params)
  }));

  const scheme = (req.headers['x-forwarded-proto'] || 'https').split(',')[0].trim();
  const host = req.headers['x-forwarded-host'] || req.headers.host;
  const redirectUrl = scheme + '://' + host + '/';

  if (!domain || !authToken) {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.status(400).json({
      error: 'invalid request',
      error_description: 'missed params domain or auth_token'
    });
    return;
  }

  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.status(200).json({
    result: 'ok',
    redirect_url: redirectUrl
  });
};
