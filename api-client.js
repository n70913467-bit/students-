/** Same-origin API client. Session cookies are HttpOnly; CSRF tokens stay in memory. */
export function createApiClient(baseUrl = '/api') {
  let csrfToken = null;
  const request = async (route, options = {}) => {
    const method = (options.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }
    const response = await fetch(`${baseUrl.replace(/\/$/, '')}/${route.replace(/^\//, '')}`, {
      ...options, method, headers, credentials: 'same-origin',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.error || `Request failed (${response.status})`);
      error.status = response.status;
      throw error;
    }
    if (data.csrf_token) csrfToken = data.csrf_token;
    return data;
  };
  return Object.freeze({
    health: () => request('health'),
    registerFirstAdmin: (details) => request('auth/register', { method: 'POST', body: details }),
    login: (credentials) => request('auth/login', { method: 'POST', body: credentials }),
    me: () => request('auth/me'),
    logout: () => request('auth/logout', { method: 'POST', body: {} }),
    listProjects: (filters = {}) => {
      const query = new URLSearchParams();
      if (filters.status) query.set('status', filters.status);
      if (filters.q) query.set('q', filters.q);
      return request(`projects${query.size ? `?${query}` : ''}`);
    },
    getProject: (id) => request(`projects/${encodeURIComponent(id)}`),
    createProject: (project) => request('projects', { method: 'POST', body: project }),
    updateProject: (id, changes) => request(`projects/${encodeURIComponent(id)}`, { method: 'PATCH', body: changes }),
    deleteProject: (id) => request(`projects/${encodeURIComponent(id)}`, { method: 'DELETE' }),
    listTasks: (projectId) => request(`projects/${encodeURIComponent(projectId)}/tasks`),
    createActivity: (projectId, message) => request(`projects/${encodeURIComponent(projectId)}/activities`, { method: 'POST', body: { message } }),
    createTask: (projectId, task) => request(`projects/${encodeURIComponent(projectId)}/tasks`, { method: 'POST', body: task }),
    updateTask: (taskId, changes) => request(`tasks/${encodeURIComponent(taskId)}`, { method: 'PATCH', body: changes }),
    deleteTask: (taskId) => request(`tasks/${encodeURIComponent(taskId)}`, { method: 'DELETE' }),
    createApproval: (projectId, approval) => request(`projects/${encodeURIComponent(projectId)}/approvals`, { method: 'POST', body: approval }),
    listApprovals: (projectId) => request(`projects/${encodeURIComponent(projectId)}/approvals`),
    decideApproval: (approvalId, status) => request(`approvals/${encodeURIComponent(approvalId)}`, { method: 'PATCH', body: { status } }),
    listUsers: () => request('users'),
    createUser: (user) => request('users', { method: 'POST', body: user }),
    addProjectMember: (projectId, member) => request(`projects/${encodeURIComponent(projectId)}/members`, { method: 'POST', body: member }),
  });
}
