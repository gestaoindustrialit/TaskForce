const timers = {};

function timerStorageKey(taskId) {
    return `task_timer_${taskId}`;
}

function startTaskTimer(taskId) {
    const startedAt = Date.now();
    timers[taskId] = startedAt;
    localStorage.setItem(timerStorageKey(taskId), String(startedAt));
}

function stopTaskTimer(taskId) {
    const stored = timers[taskId] || Number(localStorage.getItem(timerStorageKey(taskId)) || 0);
    if (!stored) {
        return null;
    }

    const elapsedMs = Date.now() - stored;
    delete timers[taskId];
    localStorage.removeItem(timerStorageKey(taskId));

    return Math.max(1, Math.round(elapsedMs / 60000));
}

function setTimerButtonState(taskId, active) {
    document.querySelectorAll(`.js-start-timer[data-task-id="${taskId}"]`).forEach((button) => {
        button.classList.toggle('btn-success', active);
        button.classList.toggle('btn-outline-success', !active);
    });
}

if (window.taskPage) {
    document.querySelectorAll('.js-auto-submit-select').forEach((form) => {
        form.querySelectorAll('.js-auto-submit-trigger').forEach((field) => {
            field.addEventListener('change', () => form.submit());
        });
    });

    document.querySelectorAll('.js-start-timer').forEach((button) => {
        button.addEventListener('click', () => {
            const taskId = button.dataset.taskId;
            startTaskTimer(taskId);
            setTimerButtonState(taskId, true);
        });
    });

    document.querySelectorAll('.js-stop-timer').forEach((button) => {
        button.addEventListener('click', () => {
            const taskId = button.dataset.taskId;
            const elapsedMinutes = stopTaskTimer(taskId);
            if (!elapsedMinutes) {
                window.alert('Inicie o contador antes de parar.');
                return;
            }

            setTimerButtonState(taskId, false);
            const form = document.getElementById(`timerForm${taskId}`);
            if (!form) {
                return;
            }

            const addInput = form.querySelector('.js-add-actual');
            if (addInput) {
                addInput.value = String(elapsedMinutes);
            }
            form.submit();
        });
    });
}

function syncCollapseToggleIcon(button, isShown) {
    const icon = button.querySelector('i.bi');
    if (!icon) {
        return;
    }

    icon.classList.toggle('bi-eye', isShown);
    icon.classList.toggle('bi-eye-slash', !isShown);
}

document.querySelectorAll('.js-collapse-toggle').forEach((button) => {
    const targetSelector = button.getAttribute('data-bs-target');
    if (!targetSelector) {
        return;
    }

    const target = document.querySelector(targetSelector);
    if (!target) {
        return;
    }

    syncCollapseToggleIcon(button, target.classList.contains('show'));
    target.addEventListener('shown.bs.collapse', () => syncCollapseToggleIcon(button, true));
    target.addEventListener('hidden.bs.collapse', () => syncCollapseToggleIcon(button, false));
});
