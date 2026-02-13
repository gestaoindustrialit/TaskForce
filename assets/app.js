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

if (window.taskPage) {
    document.querySelectorAll('.js-start-timer').forEach((button) => {
        button.addEventListener('click', () => {
            const taskId = button.dataset.taskId;
            startTaskTimer(taskId);
            button.textContent = 'Contador ativo';
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
