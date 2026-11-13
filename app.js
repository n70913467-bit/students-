import { createApiClient } from './api-client.js';
import { toCsv } from './csv.js';

const api = createApiClient('/api');
const $ = (id) => document.getElementById(id);
let currentUser = null;
let projects = [];
let users = [];
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
const canManageProject = (project) => Boolean(currentUser && currentUser.role !== 'viewer' && (isAdmin() || project?.access === 'manager'));

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
    const memberResult = (isAdmin() || detail.access === 'manager') ? await api.listProjectMembers(summary.id) : { members: [] };
    const p = detail.project;
    const activity = detail.activity || [];
    return {
      id: String(p.id), name: p.name, status: statusMap[p.status] || 'Planned',
      lastActivity: dateOnly(activity[0]?.created_at || p.updated_at),
      activity: activity[0]?.message || '', next: p.next_action || '',
      approval: approvalResult.approvals.filter((item) => item.status === 'pending').map((item) => item.title).join(', ') || 'None',
      approvals: approvalResult.approvals, members: memberResult.members, access: detail.access,
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
    const actionMarkup = `${canManageProject(p) ? `<button class="secondary" data-edit="${esc(p.id)}" aria-label="Edit project ${esc(p.name)}">Edit</button>` : ''}${canWrite(p) ? `<button class="ghost" data-touch="${esc(p.id)}" aria-label="Log activity for ${esc(p.name)}">Log activity</button>` : ''}${isAdmin() ? `<button class="danger" data-delete-project="${esc(p.id)}" aria-label="Delete project ${esc(p.name)}">Delete</button>` : ''}`;
    const actions = actionMarkup || '<span class="muted">Read only</span>';
    const style = p.status === 'Running' ? 'running' : p.status === 'On Hold' ? 'hold' : p.status === 'Stopped' ? 'stopped' : 'planned';
    return `<tr><td><b>${esc(p.name)}</b><small>${esc(p.activity)}</small></td>
      <td><span class="pill ${style}">${esc(p.status)}</span></td><td>${fmt(p.lastActivity)}</td>
      <td>${esc(p.next)}</td><td>${esc(p.approval)}</td><td><div class="actions">${actions}</div></td></tr>`;
  }).join('') : '<tr><td colspan="6" class="empty">No matching projects.</td></tr>';

  $('addProject').disabled = !isAdmin();
  $('taskProject').innerHTML = projects.map((p) =>
    `<option value="${esc(p.id)}" ${p.id === selectedId ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
  const selected = projects.find((p) => p.id === selectedId);
  const writable = canWrite(selected);
  $('addTask').disabled = !writable;
  $('tasks').innerHTML = selected ? (selected.tasks.length
    ? selected.tasks.map((task) => `<div class="task ${task.done ? 'done' : ''}">
      <input type="checkbox" aria-label="Mark task complete: ${esc(task.name)}" data-task="${esc(task.id)}" ${task.done ? 'checked' : ''} ${writable ? '' : 'disabled'}>
      <span class="body">${esc(task.name)}<small>Priority: ${esc(task.priority || 'medium')}${task.due ? ' · Due: ' + fmt(task.due) : ''}</small></span>
      ${canManageProject(selected) ? `<button class="danger" data-delete-task="${esc(task.id)}" type="button" aria-label="Delete task: ${esc(task.name)}">×</button>` : ''}
    </div>`).join('')
    : '<div class="empty">Abhi koi task nahi. “Add Task” se shuru karein.</div>')
    : '<div class="empty">Project add karein.</div>';
  $('activityHistory').innerHTML = selected?.activityLog?.length
    ? selected.activityLog.map((item) => `<div class="task"><div class="body"><b>${esc(item.message)}</b><small>${fmt(item.date)}</small></div></div>`).join('')
    : '<div class="empty">No activity recorded yet.</div>';
  $('approvalsList').innerHTML = selected?.approvals?.length ? selected.approvals.map((item) => {
    const canDecide = canWrite(selected) && item.status === 'pending' &&
      (isAdmin() || Number(item.approver_id) === Number(currentUser?.id));
    return `<div class="task"><div class="body"><b>${esc(item.title)}</b><small>Status: ${esc(item.status)}${item.details ? ' · ' + esc(item.details) : ''}</small></div>
      ${canDecide ? `<button class="secondary" data-approve="${item.id}" aria-label="Approve ${esc(item.title)}">Approve</button><button class="danger" data-reject="${item.id}" aria-label="Reject ${esc(item.title)}">Reject</button>` : ''}</div>`;
  }).join('') : '<div class="empty">No approval requests.</div>';
  $('membersList').innerHTML = selected?.members?.length
    ? selected.members.map((member) => `<div class="task"><div class="body"><b>${esc(member.name)}</b><small>${esc(member.email)} · ${esc(member.member_role)}</small></div></div>`).join('')
    : '<div class="empty">No project members listed.</div>';
  $('teamPanel').hidden = !(isAdmin() || selected?.access === 'manager');
  $('userForm').hidden = !isAdmin();
  $('memberForm').hidden = !isAdmin();
  $('memberUserId').innerHTML = users.map((user) => `<option value="${user.id}">${esc(user.name)} · ${esc(user.email)} · ${esc(user.role)}</option>`).join('');

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
  try {
    await action();
    await loadProjects();
    return true;
  } catch (error) {
    showError(error, 'appStatus');
    return false;
  }
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
      password: $('registerPassword').value, setup_key: $('registerSetupKey').value,
    });
    await startSession();
  } catch (error) {
    if (error.status === 403 && /Registration is closed/i.test(error.message)) {
      $('authStatus').textContent = 'Admin account pehle se bana hua hai. Login form ka use karein.';
    } else if (error.status === 403) {
      $('authStatus').textContent = 'Setup key galat hai. Local setup ke liye start-local.bat dobara chalakar terminal mein dikhayi gayi key use karein.';
    } else if (error.status === 503 && /First-admin setup is not configured/i.test(error.message)) {
      $('authStatus').textContent = 'First-admin setup configured nahi hai. Server band karein aur project folder se start-local.bat chalayein; terminal mein dikhayi gayi key yahan paste karein.';
    } else {
      $('authStatus').textContent = error.message;
    }
  } finally { setBusy(form, false); }
});
$('logoutButton').addEventListener('click', async () => {
  try { await api.logout(); currentUser = null; projects = []; users = []; showAuth('Aap logout ho gaye hain.'); }
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
  const saved = await refreshAfter(async () => {
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
  if (saved) $('projectDialog').close();
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
  const saved = await refreshAfter(() => api.createTask(selectedId, {
    title: $('taskName').value.trim(), due_date: $('taskDue').value || null, priority: 'medium',
  }));
  if (saved) $('taskDialog').close();
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
$('userForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await api.createUser({
      name: $('newUserName').value.trim(), email: $('newUserEmail').value.trim(),
      password: $('newUserPassword').value, role: $('newUserRole').value,
    });
    $('userForm').reset();
    users = (await api.listUsers()).users;
    $('teamStatus').textContent = 'User account created.';
    render();
  } catch (error) { showError(error, 'teamStatus'); }
});
$('memberForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!selectedId) return;
  const saved = await refreshAfter(() => api.addProjectMember(selectedId, {
    user_id: Number($('memberUserId').value), member_role: $('memberRole').value,
  }));
  if (saved) $('teamStatus').textContent = 'Project membership saved.';
});
$('approvalsList').addEventListener('click', async (event) => {
  const approve = event.target.closest('[data-approve]');
  const reject = event.target.closest('[data-reject]');
  const target = approve || reject;
  if (target) await refreshAfter(() => api.decideApproval(target.dataset.approve || target.dataset.reject, approve ? 'approved' : 'rejected'));
});
$('exportCsv').addEventListener('click', () => {
  const rows = [['Project', 'Status', 'Last Activity', 'Activity', 'Next Action', 'Approval Needed'],
    ...projects.map((p) => [p.name, p.status, p.lastActivity, p.activity, p.next, p.approval])];
  const csv = toCsv(rows);
  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }));
  link.download = 'project-report.csv'; link.click(); URL.revokeObjectURL(link.href);
});

async function startSession() {
  const result = await api.me();
  currentUser = result.user;
  showDashboard();
  $('appStatus').textContent = '';
  users = isAdmin() ? (await api.listUsers()).users : [];
  await loadProjects();
}
startSession().catch((error) => {
  if (error.status === 401) showAuth('Login karein ya pehla Admin account banayein.');
  else showAuth(`API connect nahi ho pa raha: ${error.message}. PHP 8.1+ aur PDO_SQLite server par chalayein.`);
});
