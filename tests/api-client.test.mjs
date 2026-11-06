import test from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient } from '../api-client.js';

test('login stores CSRF token and writes send it with same-origin credentials', async () => {
  const calls = [];
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    if (url.endsWith('/auth/login')) return new Response(JSON.stringify({ user: { role: 'admin' }, csrf_token: 'csrf-123' }), { status: 200 });
    return new Response(JSON.stringify({ id: 7 }), { status: 201 });
  };
  const api = createApiClient('/api/');
  await api.login({ email: 'a@example.test', password: 'long-password' });
  await api.createProject({ name: 'Demo' });
  assert.equal(calls[0].options.credentials, 'same-origin');
  assert.equal(calls[1].options.headers['X-CSRF-Token'], 'csrf-123');
  assert.equal(calls[1].options.body, JSON.stringify({ name: 'Demo' }));
});

test('API errors preserve HTTP status and server message', async () => {
  globalThis.fetch = async () => new Response(JSON.stringify({ error: 'Login required' }), { status: 401 });
  const api = createApiClient();
  await assert.rejects(api.listProjects(), (error) => error.status === 401 && error.message === 'Login required');
});

test('project filters are URL encoded', async () => {
  let requested = '';
  globalThis.fetch = async (url) => {
    requested = url;
    return new Response(JSON.stringify({ projects: [] }), { status: 200 });
  };
  const api = createApiClient('/api');
  await api.listProjects({ status: 'on hold', q: 'Hx Money' });
  assert.match(requested, /status=on%2Bhold|status=on%20hold/);
  assert.match(requested, /q=Hx\+Money/);
});
