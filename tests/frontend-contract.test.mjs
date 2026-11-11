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


test('dashboard tables and dialogs expose accessible names and structure', () => {
  assert.match(html, /<table aria-label="Project tracking">/);
  assert.match(html, /<caption class="visually-hidden">/);
  assert.ok(html.includes('<th scope="col">Project</th>'));
  assert.match(html, /<dialog id="projectDialog" aria-labelledby="projectDialogTitle">/);
  assert.match(html, /<dialog id="taskDialog" aria-labelledby="taskDialogTitle">/);
});

test('dynamic actions and task controls have contextual accessible names', () => {
  assert.ok(app.includes('aria-label="Edit project ${esc(p.name)}"'));
  assert.ok(app.includes('aria-label="Mark task complete: ${esc(task.name)}"'));
  assert.ok(app.includes('aria-label="Delete task: ${esc(task.name)}"'));
  assert.ok(app.includes('aria-label="Approve ${esc(item.title)}"'));
});

test('project managers can see their assigned project member list without admin-only user controls', () => {
  assert.match(app, /\$\('teamPanel'\)\.hidden = !\(isAdmin\(\) \|\| selected\?\.access === 'manager'\);/);
  assert.match(app, /\$\('userForm'\)\.hidden = !isAdmin\(\);/);
  assert.match(app, /\$\('memberForm'\)\.hidden = !isAdmin\(\);/);
});


test('project creation and editing controls follow admin and manager roles', () => {
  assert.match(app, /\$\('addProject'\)\.disabled = !isAdmin\(\);/);
  assert.match(app, /const canManageProject = \(project\) => Boolean\(currentUser && currentUser\.role !== 'viewer' && \(isAdmin\(\) \|\| project\?\.access === 'manager'\)\);/);
  assert.match(app, /canManageProject\(p\).*data-edit/);
});


test('first-admin setup explains the required key and distinguishes closed registration', () => {
  assert.match(html, /Setup key \(required; get it from the administrator\)/);
  assert.match(html, /id="registerSetupKey"[^>]*required/);
  assert.match(app, /Registration is closed/i);
  assert.match(app, /Setup key galat hai/);
});
