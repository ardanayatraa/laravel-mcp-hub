const http = require('http');

http.createServer((req, res) => {
  const send = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
  };
  const path = req.url.split('?')[0];
  if (req.method === 'GET' && path === '/v1/models') {
    return send(200, { object: 'list', data: [{ id: 'fixture-model', object: 'model', owned_by: 'isolated-fixture' }] });
  }
  if (req.method !== 'POST' || path !== '/v1/chat/completions') {
    return send(501, { error: { code: 'endpoint_not_modeled' } });
  }
  const body = req.body || {};
  const messages = body.messages || [];
  const user = [...messages].reverse().find(item => item.role === 'user');
  const text = typeof user?.content === 'string' ? user.content : '';
  let message = { role: 'assistant', content: 'Uji integrasi selesai. Data tetap mengikuti izin pengguna.' };
  let finish = 'stop';
  const previousTool = messages.some(item => item.role === 'tool');
  const report = text.match(/cek ekspor (exp_[a-p]{48})/i);
  const operation = report ? 'export_status' : /buat ekspor percobaan/i.test(text) ? 'export'
    : /buat pesanan percobaan/i.test(text) ? 'create' : /cek pesanan saya/i.test(text) ? 'list' : null;
  const tool = (body.tools || []).find(item => item.function?.name.endsWith('__orders__' + operation));
  if (!previousTool && tool) {
    message = { role: 'assistant', content: null, tool_calls: [{ id: 'call_fixture_a', type: 'function',
      function: { name: tool.function.name, arguments: JSON.stringify(operation === 'create'
        ? { title: 'Chat test order', status: 'pending', total: 15 } : operation === 'export'
          ? { format: 'csv', limit: 100 } : operation === 'export_status' ? { export_ref: report[1] } : {}) } }] };
    finish = 'tool_calls';
  } else if (previousTool) {
    message.content = operation === 'create' ? 'Usulan perubahan sudah dibuat. Silakan tinjau dan setujui di aplikasi.'
      : operation === 'export_status' ? 'Status ekspor sudah diperiksa. Gunakan tautan unduhan jika laporan sudah siap.'
        : operation === 'export' ? 'Permintaan ekspor diterima. Periksa status sesudah antrean memproses laporan.'
          : 'Pesanan yang boleh Anda akses sudah diperiksa.';
  }
  return send(200, { id: 'fixture_completion_a', object: 'chat.completion', created: 1793200000,
    model: 'fixture-model', choices: [{ index: 0, message, finish_reason: finish }],
    usage: { prompt_tokens: 20, completion_tokens: 15, total_tokens: 35 } });
}).listen(process.env.PORT);
