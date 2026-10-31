'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(0, 'utf8');
const configText = html.match(/<script id="lunixi-config" type="application\/json">(.*?)<\/script>/s)[1];
let calls = 0;
let mounted = false;
const error = { hidden: true };
const program = fs.readFileSync(require('node:path').join(__dirname, '../modules/gateways/lunixi/assets/checkout.js'), 'utf8');
const result = vm.runInNewContext(program, {
  document: { getElementById: id => id === 'lunixi-error' ? error : { textContent: configText } },
  window: { Lunixi: { checkout: { create(config) {
    assert.equal(typeof config, 'object');
    assert.equal(config.type, 'payment');
    assert.equal(config.target, 'lunixi-checkout-form');
    assert.equal(config.options.token, '</script><script>unexpected()</script>');
    calls++;
    return { mount(selector) { assert.equal(selector, '#lunixi-checkout-form'); mounted = true; } };
  } } } }
});
Promise.resolve(result).then(() => {
  assert.equal(calls, 1); assert.equal(mounted, true); assert.equal(error.hidden, true);
  process.stdout.write('embedded checkout mounted');
}).catch(error => { process.stderr.write(error.message); process.exitCode = 1; });
