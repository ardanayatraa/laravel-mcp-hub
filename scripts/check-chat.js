// Browser-free unit checks for the shipped chat asset: download-link boundary and safe answer rendering.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const asset = fs.readFileSync(path.join(__dirname, '../resources/assets/chat.js'), 'utf8');
const reference = 'exp_' + 'a'.repeat(48);

function element(tag) {
  return {tag, children: [], className: '', textContent: '', appendChild(child) { this.children.push(child); return child; }};
}

// Serializes the fake DOM. Text nodes are escaped, so markup would only appear if the asset built real elements.
function show(node) {
  if (node.tag === '#text') return node.text.replace(/</g, '&lt;');
  return '<' + node.tag + '>' + node.textContent.replace(/</g, '&lt;') + node.children.map(show).join('') + '</' + node.tag + '>';
}

async function check(exports, template = 'http://source.test/mcp-hub/exports/__export__', text = 'Done') {
  let submit;
  const children = [];
  const input = {value: 'Check my report', focus() {}};
  const button = {disabled: false};
  const status = {textContent: ''};
  const form = {querySelector(selector) {
    return selector === 'textarea' ? input : selector === 'button' ? button : {value: 'dummy-csrf'};
  }, addEventListener(event, callback) { submit = callback; }};
  const messages = {appendChild(item) { children.push(item); }, scrollHeight: 100};
  const root = {dataset: {endpoint: 'http://source.test/mcp-hub/chat', export: template,
    approval: 'http://source.test/mcp-hub/proposals/__proposal__', download: 'Download report', error: 'Failed'},
  querySelector(selector) { return selector === '[data-chat-form]' ? form : selector === '[data-messages]' ? messages : status; }};
  vm.runInNewContext(asset, {URL, TextEncoder, Set, document: {
    querySelectorAll() { return [root]; }, createElement: element, createTextNode(value) { return {tag: '#text', text: value}; }},
  window: {location: {href: 'http://source.test/mcp-hub/chat', origin: 'http://source.test'}},
  fetch: async () => ({ok: true, json: async () => ({success: true, data: {text, exports}})})});
  await submit({preventDefault() {}});
  assert.equal(button.disabled, false);
  assert.equal(status.textContent, '');
  check.bubble = children.find(item => item.tag === 'div' && item.className.includes('mcp-rich'));
  return children.filter(item => item.tag === 'a');
}

async function rendered(text) {
  await check([], undefined, text);
  return show(check.bubble);
}

(async () => {
  const row = {export_ref: reference, status: 'ready', format: 'xlsx', download_url: 'https://attacker.test/steal'};
  const links = await check([{data: [row, {...row}, {...row, status: 'pending'}, {...row, format: 'html'},
    {...row, export_ref: '../../private'}, null]}, {data: {bad: true}}, null]);
  assert.equal(links.length, 1);
  assert.equal(links[0].href, 'http://source.test/mcp-hub/exports/' + reference);
  assert.equal(links[0].textContent, 'Download report (XLSX)');
  assert.equal(links[0].rel, 'noopener noreferrer');
  assert.equal((await check([{data: [row]}], 'https://attacker.test/__export__')).length, 0);
  assert.equal((await check({malformed: true})).length, 0);
  console.log('Chat export links: source reference, same origin, no supplied URLs, deduplication and malformed results passed.');

  assert.equal(await rendered('Done'), '<div><p>Done</p></div>');
  assert.equal(await rendered('Produk:\n\n| SKU | Stok |\n| :--- | ---: |\n| TIS-006 | **0** |\n\nSelesai.'),
    '<div><p>Produk:</p><div><table><tr><th>SKU</th><th>Stok</th></tr><tr><td>TIS-006</td><td><strong>0</strong></td></tr></table></div><p>Selesai.</p></div>');
  assert.equal(await rendered('## Ringkasan\n- satu\n- `dua`\n1. pertama\n2) kedua'),
    '<div><p><strong>Ringkasan</strong></p><ul><li>satu</li><li><code>dua</code></li></ul><ol><li>pertama</li><li>kedua</li></ol></div>');
  assert.equal(await rendered('baris satu\nbaris dua'), '<div><p>baris satu\nbaris dua</p></div>');
  const hostile = await rendered('<img src=x onerror=alert(1)> [klik](https://attacker.test) | a |');
  assert.ok(!hostile.includes('<img') && hostile.includes('&lt;img') && !hostile.includes('<a>'));
  assert.equal(await rendered('| tanpa | pemisah |'), '<div><p>| tanpa | pemisah |</p></div>');
  console.log('Chat answers: paragraphs, tables, lists, headings, bold and code render as DOM nodes; markup and links stay text.');
})().catch(error => { console.error(error); process.exitCode = 1; });
