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

  const domain = params.domain || params.DOMAIN || '';
  const authToken = params.auth_token || params.AUTH_ID || '';
  const refreshToken = params.refresh_token || params.REFRESH_ID || '';
  const memberId = params.member_id || '';
  const userId = params.user_id || '';

  console.log('APP_INSTALL', JSON.stringify({
    domain, hasAuth: !!authToken, memberId, userId,
    receivedKeys: Object.keys(params)
  }));

  if (!domain && !authToken) {
    res.setHeader('Content-Type', 'text/plain; charset=utf-8');
    res.status(400).send('Missing install parameters');
    return;
  }

  const scheme = (req.headers['x-forwarded-proto'] || 'https').split(',')[0].trim();
  const host = req.headers['x-forwarded-host'] || req.headers.host;
  res.redirect(302, scheme + '://' + host + '/');
};
