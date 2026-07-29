const fs = require('fs');
const path = require('path');

module.exports = (req, res) => {
  const filePath = path.join(__dirname, '..', 'index.html');
  const html = fs.readFileSync(filePath, 'utf8');
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.status(200).send(html);
};
