const https = require('https');
const http = require('http');
const querystring = require('querystring');

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

  const body = await new Promise((resolve) => {
    let data = '';
    req.on('data', chunk => data += chunk);
    req.on('end', () => resolve(data));
  });

  const params = querystring.parse(body);
  const restMethod = params._method || '';
  const domain = params._domain || '';
  const accessToken = params._token || '';

  if (!restMethod || !domain || !accessToken) {
    return res.status(400).json({ error: 'Missing _method, _domain, or _token' });
  }

  delete params._method;
  delete params._domain;
  delete params._token;

  // Handle file download proxy
  if (restMethod === 'download') {
    const downloadUrl = params._url;
    if (!downloadUrl) {
      return res.status(400).json({ error: 'Missing _url' });
    }

    const client = downloadUrl.startsWith('https') ? https : http;
    const urlObj = new URL(downloadUrl);

    const options = {
      hostname: urlObj.hostname,
      path: urlObj.pathname + urlObj.search + (urlObj.search ? '&' : '?') + 'auth=' + accessToken,
      method: 'GET',
    };

    try {
      return await new Promise((resolve, reject) => {
        client.request(options, (proxyRes) => {
          const chunks = [];
          proxyRes.on('data', chunk => chunks.push(chunk));
          proxyRes.on('end', () => {
            res.setHeader('Content-Type', proxyRes.headers['content-type'] || 'application/octet-stream');
            res.setHeader('Content-Disposition', proxyRes.headers['content-disposition'] || 'attachment');
            res.status(200).end(Buffer.concat(chunks));
            resolve();
          });
        }).on('error', (err) => {
          res.status(500).json({ error: err.message });
          reject(err);
        }).end();
      });
    } catch (err) {
      return res.status(500).json({ error: err.message });
    }
  }

  // Handle file upload via base64
  let postData;
  let contentType;

  if (restMethod === 'disk.folder.uploadfile' && params._file_base64) {
    const fileName = params._file_name || 'file.bin';
    const fileBuffer = Buffer.from(params._file_base64, 'base64');
    delete params._file_base64;
    delete params._file_name;

    const boundary = '----FormBoundary' + Math.random().toString(36).slice(2);
    contentType = 'multipart/form-data; boundary=' + boundary;

    const parts = [];
    for (const key of Object.keys(params)) {
      parts.push(Buffer.from(
        '--' + boundary + '\r\n' +
        'Content-Disposition: form-data; name="' + key + '"\r\n\r\n' +
        params[key] + '\r\n'
      ));
    }

    parts.push(Buffer.from(
      '--' + boundary + '\r\n' +
      'Content-Disposition: form-data; name="fileContent"; filename="' + fileName + '"\r\n' +
      'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\n\r\n'
    ));
    parts.push(fileBuffer);
    parts.push(Buffer.from('\r\n--' + boundary + '--\r\n'));

    postData = Buffer.concat(parts);
  } else {
    contentType = 'application/x-www-form-urlencoded';
    params.auth = accessToken;
    postData = querystring.stringify(params);
  }

  const urlPath = '/rest/' + restMethod + '.json';
  const queryString = querystring.stringify({ auth: accessToken });

  const options = {
    hostname: domain,
    path: urlPath + '?' + queryString,
    method: 'POST',
    headers: {
      'Content-Type': contentType,
      'Content-Length': Buffer.isBuffer(postData) ? postData.length : Buffer.byteLength(postData),
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
      proxyReq.write(postData);
      proxyReq.end();
    });

    return res.status(200).json(result);
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
};
