// Browser-free unit checks for the shipped chat asset's download-link boundary.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const asset = fs.readFileSync(path.join(__dirname, '../resources/assets/chat.js'), 'utf8');
const reference = 'exp_' + 'a'.repeat(48);

async function check(exports, template = 'http://source.test/mcp-hub/exports/__export__') {
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
    querySelectorAll() { return [root]; }, createElement(tag) { return {tag}; }},
  window: {location: {href: 'http://source.test/mcp-hub/chat', origin: 'http://source.test'}},
  fetch: async () => ({ok: true, json: async () => ({success: true, data: {text: 'Done', exports}})})});
  await submit({preventDefault() {}});
  assert.equal(button.disabled, false);
  assert.equal(status.textContent, '');
  return children.filter(item => item.tag === 'a');
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
})().catch(error => { console.error(error); process.exitCode = 1; });
