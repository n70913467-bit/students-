import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [html, app] = await Promise.all([
  readFile(new URL('../index.html', import.meta.url), 'utf8'),
  readFile(new URL('../app.js', import.meta.url), 'utf8'),
]);

test('every literal DOM selector used by the dashboard exists in index.html', () => {
  const ids = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]));
  const selectors = [...app.matchAll(/\$\('([^']+)'\)/g)].map((match) => match[1]);
  const missing = [...new Set(selectors)].filter((id) => !ids.has(id));
  assert.deepEqual(missing, [], `Missing HTML element IDs: ${missing.join(', ')}`);
});

test('authentication and dashboard are served as a same-origin module app', () => {
  for (const id of ['authPanel', 'loginForm', 'registerForm', 'dashboardMain', 'logoutButton']) {
    assert.match(html, new RegExp(`id="${id}"`));
  }
  assert.match(html, /type="module" src="\.\/app\.js"/);
  assert.match(app, /createApiClient\('\/api'\)/);
  assert.doesNotMatch(app, /localStorage|sessionStorage/);
});

test('dynamic dashboard text is escaped before insertion into HTML templates', () => {
  assert.match(app, /const esc = \(value\) => String\(value \?\? ''\)\.replace/);
  assert.match(app, /\$\{esc\(p\.name\)\}/);
  assert.match(app, /\$\{esc\(item\.title\)\}/);
  assert.match(app, /\$\{esc\(member\.email\)\}/);
});
