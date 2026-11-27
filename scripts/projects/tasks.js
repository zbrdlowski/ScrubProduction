(function () {
  'use strict';
  const table = document.getElementById('projectTasksTable');
  const form = document.getElementById('projectTaskMoveForm');
  const root = document.getElementById('projectTaskRootDrop');
  const error = document.getElementById('projectTaskMoveError');
  if (!table || !form || !root) return;
  let dragged = null;
  function clearTargets() {
    document.querySelectorAll('.project-task-drop-target').forEach(el => el.classList.remove('project-task-drop-target'));
  }
  function validTarget(target) {
    return dragged && target && (target === root || (target !== dragged && target.dataset.parentId === '0'));
  }
  table.addEventListener('dragstart', event => {
    const grip = event.target.closest('.project-task-drag');
    dragged = grip && grip.closest('[data-task-id]');
    if (!dragged || dragged.dataset.hasChildren === '1') { event.preventDefault(); dragged = null; return; }
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragged.dataset.taskId);
    error.hidden = true;
  });
  const targets = [root, ...table.querySelectorAll('[data-task-id]')];
  targets.forEach(target => {
    target.addEventListener('dragover', event => {
      if (!validTarget(target)) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      clearTargets();
      target.classList.add('project-task-drop-target');
    });
    target.addEventListener('dragleave', () => target.classList.remove('project-task-drop-target'));
    target.addEventListener('drop', event => {
      event.preventDefault();
      clearTargets();
      if (!validTarget(target)) {
        error.textContent = 'Drop onto a main task. Subtasks cannot contain more subtasks.';
        error.hidden = false;
        return;
      }
      const parentId = target === root ? '0' : target.dataset.taskId;
      if (dragged.dataset.parentId === parentId) return;
      form.elements.task_id.value = dragged.dataset.taskId;
      form.elements.parent_task_id.value = parentId;
      form.submit();
    });
  });
  document.addEventListener('dragend', () => { dragged = null; clearTargets(); });
})();
