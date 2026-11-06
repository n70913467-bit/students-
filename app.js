import { createApiClient } from './api-client.js';

const api = createApiClient('/api');
const $ = (id) => document.getElementById(id);
let currentUser = null;
let projects = [];
let selectedId = null;

const stages = [
  'Requirement Planning', 'Workflow Planning', 'UI / UX Design',
  'Database / Data Model Design', 'Backend API + SQLite Persistence Starter',
  'Frontend Development', 'API Integration', 'Testing & Deployment',
];
const completedStages = 7;
const statusMap = { planned: 'Planned', running: 'Running', on_hold: 'On Hold', stopped: 'Stopped' };
const statusCode = { Planned: 'planned', Running: 'running', 'On Hold': 'on_hold', Stopped: 'stopped' };
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[ch]));
const dateOnly = (value) => value ? String(value).slice(0, 10) : '';
const fmt = (value) => {
  const date = dateOnly(value);
  if (!date) return 'Not recorded';
  const [year, month, day] = date.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
};
const isAdmin = () => currentUser?.role === 'admin';
const canWrite = (project) => Boolean(currentUser && currentUser.role !== 'viewer' &&
  (currentUser.role === 'admin' || project?.access !== 'viewer'));

function showAuth(message = '') {
  $('authPanel').hidden = false;
  $('dashboardMain').hidden = true;
  $('userBar').hidden = true;
  $('authStatus').textContent = message;
}
function showDashboard() {
  $('authPanel').hidden = true;
  $('dashboardMain').hidden = false;
  $('userBar').hidden = false;
  $('userLabel').textContent = currentUser ? `${currentUser.name} · ${currentUser.role}` : '';
}
function setBusy(form, busy) {
  form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = busy; });
}
function showError(error, target = 'authStatus') {
  $(target).textContent = error?.message || 'Kuch galat hua. Dobara koshish karein.';
}

async function loadProjects() {
  const result = await api.listProjects();
  const details = await Promise.all(result.projects.map(async (summary) => {
    const [detail, approvalResult] = await Promise.all([
      api.getProject(summary.id), api.listApprovals(summary.id),
    ]);
    const p = detail.project;
    const activity = detail.activity || [];
    return {
      id: String(p.id), name: p.name, status: statusMap[p.status] || 'Planned',
      lastActivity: dateOnly(activity[0]?.created_at || p.updated_at),
      activity: activity[0]?.message || '', next: p.next_action || '',
      approval: approvalResult.approvals.filter((item) => item.status === 'pending').map((item) => item.title).join(', ') || 'None',
      access: detail.access,
      tasks: (detail.tasks || []).map((task) => ({
        id: String(task.id), name: task.title, done: task.status === 'done',
        due: task.due_date, priority: task.priority,
      })),
      activityLog: activity.map((item) => ({ date: item.created_at, message: item.message })),
    };
  }));
  projects = details;
  if (!projects.some((project) => project.id === selectedId)) selectedId = projects[0]?.id || null;
  render();
}

function render() {
  $('totalProjects').textContent = projects.length;
  $('runningProjects').textContent = projects.filter((p) => p.status === 'Running').length;
  $('holdProjects').textContent = projects.filter((p) => p.status === 'On Hold').length;
  $('pendingTasks').textContent = projects.reduce((sum, p) => sum + p.tasks.filter((t) => !t.done).length, 0);

  const query = $('search').value.trim().toLowerCase();
  const filter = $('statusFilter').value;
  const visible = projects.filter((p) => (filter === 'All' || p.status === filter) &&
    [p.name, p.activity, p.next, ...p.tasks.map((task) => task.name)].join(' ').toLowerCase().includes(query));
  $('projectRows').innerHTML = visible.length ? visible.map((p) => {
    const actions = canWrite(p)
      ? `<button class="secondary" data-edit="${esc(p.id)}">Edit</button><button class="ghost" data-touch="${esc(p.id)}">Log activity</button>${isAdmin() ? `<button class="danger" data-delete-project="${esc(p.id)}">Delete</button>` : ''}`
      : '<span class="muted">Read only</span>';
    const style = p.status === 'Running' ? 'running' : p.status === 'On Hold' ? 'hold' : p.status === 'Stopped' ? 'stopped' : 'planned';
    return `<tr><td><b>${esc(p.name)}</b><small>${esc(p.activity)}</small></td>
      <td><span class="pill ${style}">${esc(p.status)}</span></td><td>${fmt(p.lastActivity)}</td>
      <td>${esc(p.next)}</td><td>${esc(p.approval)}</td><td><div class="actions">${actions}</div></td></tr>`;
  }).join('') : '<tr><td colspan="6" class="empty">No matching projects.</td></tr>';

  $('addProject').disabled = currentUser?.role === 'viewer';
  $('taskProject').innerHTML = projects.map((p) =>
    `<option value="${esc(p.id)}" ${p.id === selectedId ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
  const selected = projects.find((p) => p.id === selectedId);
  const writable = canWrite(selected);
  $('addTask').disabled = !writable;
  $('tasks').innerHTML = selected ? (selected.tasks.length
    ? selected.tasks.map((task) => `<div class="task ${task.done ? 'done' : ''}">
      <input type="checkbox" aria-label="Mark task complete" data-task="${esc(task.id)}" ${task.done ? 'checked' : ''} ${writable ? '' : 'disabled'}>
      <span class="body">${esc(task.name)}<small>Priority: ${esc(task.priority || 'medium')}${task.due ? ' · Due: ' + fmt(task.due) : ''}</small></span>
      ${writable ? `<button class="danger" data-delete-task="${esc(task.id)}" type="button" aria-label="Delete task">×</button>` : ''}
    </div>`).join('')
    : '<div class="empty">Abhi koi task nahi. “Add Task” se shuru karein.</div>')
    : '<div class="empty">Project add karein.</div>';
  $('activityHistory').innerHTML = selected?.activityLog?.length
    ? selected.activityLog.map((item) => `<div class="task"><div class="body"><b>${esc(item.message)}</b><small>${fmt(item.date)}</small></div></div>`).join('')
    : '<div class="empty">No activity recorded yet.</div>';

  $('roadmapBar').style.width = `${completedStages / stages.length * 100}%`;
  $('roadmapProgress').textContent = `${completedStages} / ${stages.length} stages complete`;
  $('roadmap').innerHTML = stages.map((stage, index) =>
    `<div class="step"><b>${index + 1}. ${esc(stage)}</b><small>${index < completedStages ? 'Complete' : 'Pending'}</small></div>`).join('');
}

function openProject(id = '') {
  const project = projects.find((item) => item.id === id);
  $('projectDialogTitle').textContent = project ? 'Edit Project' : 'Add Project';
  $('projectId').value = project?.id || '';
  $('projectName').value = project?.name || '';
  $('projectStatus').value = project?.status || 'Planned';
  $('projectActivity').value = '';
  $('projectNext').value = project?.next || '';
  $('projectApproval').value = '';
  $('projectDialog').showModal();
}
async function refreshAfter(action) {
  $('appStatus').textContent = '';
  try { await action(); await loadProjects(); }
  catch (error) { showError(error, 'appStatus'); }
}

$('loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget; setBusy(form, true);
  try {
    await api.login({ email: $('loginEmail').value.trim(), password: $('loginPassword').value });
    await startSession();
  } catch (error) { showError(error); }
  finally { setBusy(form, false); }
});
$('registerForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget; setBusy(form, true);
  try {
    await api.registerFirstAdmin({
      name: $('registerName').value.trim(), email: $('registerEmail').value.trim(),
      password: $('registerPassword').value,
    });
    await startSession();
  } catch (error) {
    $('authStatus').textContent = error.status === 403
      ? 'Admin account pehle se bana hua hai. Login form ka use karein.'
      : error.message;
  } finally { setBusy(form, false); }
});
$('logoutButton').addEventListener('click', async () => {
  try { await api.logout(); currentUser = null; projects = []; showAuth('Aap logout ho gaye hain.'); }
  catch (error) { showError(error, 'appStatus'); }
});

$('addProject').addEventListener('click', () => openProject());
$('cancelProject').addEventListener('click', () => $('projectDialog').close());
$('search').addEventListener('input', render);
$('statusFilter').addEventListener('change', render);
$('projectForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const id = $('projectId').value;
  const activity = $('projectActivity').value.trim();
  const approval = $('projectApproval').value.trim();
  await refreshAfter(async () => {
    let targetId = id;
    const payload = {
      name: $('projectName').value.trim(), status: statusCode[$('projectStatus').value],
      next_action: $('projectNext').value.trim(),
    };
    if (id) await api.updateProject(id, payload);
    else {
      const created = await api.createProject(payload);
      targetId = String(created.id); selectedId = targetId;
    }
    if (activity) await api.createActivity(targetId, activity);
    if (approval && approval.toLowerCase() !== 'none') await api.createApproval(targetId, { title: approval });
  });
  $('projectDialog').close();
});
$('projectRows').addEventListener('click', async (event) => {
  const edit = event.target.closest('[data-edit]');
  const touch = event.target.closest('[data-touch]');
  const del = event.target.closest('[data-delete-project]');
  if (edit) openProject(edit.dataset.edit);
  if (touch) {
    const project = projects.find((p) => p.id === touch.dataset.touch);
    const note = prompt('Aaj ki activity / progress:', project?.activity || '');
    if (note?.trim()) await refreshAfter(() => api.createActivity(project.id, note.trim()));
  }
  if (del) {
    const project = projects.find((p) => p.id === del.dataset.deleteProject);
    if (project && confirm(`Delete project “${project.name}” and its tasks? This cannot be undone.`)) {
      await refreshAfter(() => api.deleteProject(project.id));
    }
  }
});
$('taskProject').addEventListener('change', (event) => { selectedId = event.target.value; render(); });
$('addTask').addEventListener('click', () => {
  if (!selectedId) return;
  $('taskName').value = ''; $('taskDue').value = ''; $('taskDialog').showModal();
});
$('cancelTask').addEventListener('click', () => $('taskDialog').close());
$('taskForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  await refreshAfter(() => api.createTask(selectedId, {
    title: $('taskName').value.trim(), due_date: $('taskDue').value || null, priority: 'medium',
  }));
  $('taskDialog').close();
});
$('tasks').addEventListener('click', async (event) => {
  const del = event.target.closest('[data-delete-task]');
  if (del) await refreshAfter(() => api.deleteTask(del.dataset.deleteTask));
});
$('tasks').addEventListener('change', async (event) => {
  const id = event.target.dataset.task;
  if (!id) return;
  await refreshAfter(() => api.updateTask(id, { status: event.target.checked ? 'done' : 'todo' }));
});
$('exportCsv').addEventListener('click', () => {
  const rows = [['Project', 'Status', 'Last Activity', 'Activity', 'Next Action', 'Approval Needed'],
    ...projects.map((p) => [p.name, p.status, p.lastActivity, p.activity, p.next, p.approval])];
  const csv = rows.map((row) => row.map((value) => '"' + String(value ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }));
  link.download = 'project-report.csv'; link.click(); URL.revokeObjectURL(link.href);
});

async function startSession() {
  const result = await api.me();
  currentUser = result.user;
  showDashboard();
  $('appStatus').textContent = '';
  await loadProjects();
}
startSession().catch((error) => {
  if (error.status === 401) showAuth('Login karein ya pehla Admin account banayein.');
  else showAuth(`API connect nahi ho pa raha: ${error.message}. PHP 8.1+ aur PDO_SQLite server par chalayein.`);
});
