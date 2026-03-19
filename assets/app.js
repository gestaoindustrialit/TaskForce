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

    return Math.max(1, Math.floor(elapsedMs / 1000));
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
            const elapsedSeconds = stopTaskTimer(taskId);
            if (!elapsedSeconds) {
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
                addInput.value = String(elapsedSeconds);
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



function initHrAlertsPage() {
    const scheduleConfigs = Array.from(document.querySelectorAll('[data-alert-schedule-config]'));
    scheduleConfigs.forEach((config) => {
        const modeSelect = config.querySelector('.js-alert-schedule-mode');
        const weeklyWrap = config.querySelector('.js-alert-weekdays-wrap');
        const monthlyWrap = config.querySelector('.js-alert-monthly-day-wrap');

        const syncMode = () => {
            const mode = modeSelect?.value || 'weekly';
            weeklyWrap?.classList.toggle('d-none', mode !== 'weekly');
            monthlyWrap?.classList.toggle('d-none', mode !== 'monthly');
        };

        modeSelect?.addEventListener('change', syncMode);
        syncMode();
    });

    const pickerRoots = Array.from(document.querySelectorAll('[data-hr-alert-picker]'));
    if (!pickerRoots.length || !window.bootstrap?.Modal) {
        return;
    }

    pickerRoots.forEach((pickerRoot) => {
        const hiddenInputsContainer = pickerRoot.querySelector('.js-alert-user-hidden-inputs');
        const teamFilter = pickerRoot.querySelector('.js-alert-team-filter');
        const summary = pickerRoot.querySelector('.js-alert-users-summary');
        const countBadge = pickerRoot.querySelector('.js-alert-users-count');
        const chipsContainer = pickerRoot.querySelector('.js-alert-users-chips');
        const triggerButton = pickerRoot.querySelector('[data-bs-target]');
        const inputName = pickerRoot.dataset.inputName || 'selected_user_ids[]';
        const modalSelector = triggerButton?.getAttribute('data-bs-target') || '';
        const modalElement = modalSelector ? document.querySelector(modalSelector) : null;

        if (!hiddenInputsContainer || !summary || !countBadge || !chipsContainer || !modalElement) {
            return;
        }

        const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
        const searchInput = modalElement.querySelector('.js-alert-user-search');
        const userOptions = Array.from(modalElement.querySelectorAll('[data-user-option]'));
        const applyButton = modalElement.querySelector('.js-alert-apply-users');
        const selectAllButton = modalElement.querySelector('.js-alert-select-all');
        const clearAllButton = modalElement.querySelector('.js-alert-clear-all');
        const selectTeamButton = modalElement.querySelector('.js-alert-select-team');

        const getCheckboxes = () => userOptions
            .map((option) => option.querySelector('.js-alert-user-checkbox'))
            .filter(Boolean);

        const getVisibleOptions = () => userOptions.filter((option) => !option.classList.contains('d-none'));
        const getSelectedOptions = () => userOptions.filter((option) => {
            const checkbox = option.querySelector('.js-alert-user-checkbox');
            return checkbox && checkbox.checked;
        });

        const syncOptionCheckedState = () => {
            userOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-alert-user-checkbox');
                option.classList.toggle('is-selected', Boolean(checkbox?.checked));
            });
        };

        const syncVisibleUsers = () => {
            const term = (searchInput?.value || '').trim().toLowerCase();
            const selectedTeamId = String(teamFilter?.value || '0');

            userOptions.forEach((option) => {
                const userLabel = option.dataset.userLabel || '';
                const teamIds = (option.dataset.teamIds || '').split(',').filter(Boolean);
                const matchesSearch = term === '' || userLabel.includes(term);
                const matchesTeam = selectedTeamId === '0' || teamIds.includes(selectedTeamId);
                option.classList.toggle('d-none', !(matchesSearch && matchesTeam));
            });
        };

        const renderSelectedUsers = () => {
            const selectedOptions = getSelectedOptions();
            hiddenInputsContainer.innerHTML = '';
            chipsContainer.innerHTML = '';
            syncOptionCheckedState();

            selectedOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-alert-user-checkbox');
                if (!checkbox) {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = inputName;
                input.value = checkbox.value;
                hiddenInputsContainer.appendChild(input);
            });

            countBadge.textContent = String(selectedOptions.length);
            if (!selectedOptions.length) {
                summary.textContent = 'Todos os colaboradores ativos';
                return;
            }

            const selectedNames = selectedOptions
                .map((option) => option.querySelector('.fw-semibold')?.textContent?.trim() || '')
                .filter(Boolean);

            summary.textContent = selectedNames.length <= 2
                ? selectedNames.join(', ')
                : `${selectedNames.length} colaboradores selecionados`;

            selectedNames.slice(0, 2).forEach((name) => {
                const chip = document.createElement('span');
                chip.className = 'badge rounded-pill text-bg-light border text-dark';
                chip.textContent = name;
                chipsContainer.appendChild(chip);
            });

            if (selectedNames.length > 2) {
                const extraChip = document.createElement('span');
                extraChip.className = 'badge rounded-pill text-bg-secondary';
                extraChip.textContent = `+${selectedNames.length - 2}`;
                chipsContainer.appendChild(extraChip);
            }
        };

        searchInput?.addEventListener('input', syncVisibleUsers);
        teamFilter?.addEventListener('change', syncVisibleUsers);
        selectAllButton?.addEventListener('click', () => {
            getVisibleOptions().forEach((option) => {
                const checkbox = option.querySelector('.js-alert-user-checkbox');
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            renderSelectedUsers();
        });
        clearAllButton?.addEventListener('click', () => {
            getCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
            });
            renderSelectedUsers();
        });
        selectTeamButton?.addEventListener('click', () => {
            const selectedTeamId = String(teamFilter?.value || '0');
            getCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
            });
            userOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-alert-user-checkbox');
                if (!checkbox) {
                    return;
                }
                const teamIds = (option.dataset.teamIds || '').split(',').filter(Boolean);
                checkbox.checked = selectedTeamId === '0' || teamIds.includes(selectedTeamId);
            });
            renderSelectedUsers();
            syncVisibleUsers();
        });
        getCheckboxes().forEach((checkbox) => checkbox.addEventListener('change', renderSelectedUsers));
        applyButton?.addEventListener('click', () => {
            renderSelectedUsers();
            modal.hide();
        });
        modalElement.addEventListener('shown.bs.modal', () => {
            syncVisibleUsers();
            searchInput?.focus();
        });

        renderSelectedUsers();
        syncVisibleUsers();
    });
}

function initResultsPage() {
    const resultsFilterForm = document.getElementById('resultsFilterForm');
    if (!resultsFilterForm) {
        return;
    }

    const hiddenInputsContainer = resultsFilterForm.querySelector('.js-results-user-hidden-inputs');
    const teamFilter = resultsFilterForm.querySelector('.js-results-team-filter');
    const summary = resultsFilterForm.querySelector('.js-results-users-summary');
    const countBadge = resultsFilterForm.querySelector('.js-results-users-count');
    const chipsContainer = resultsFilterForm.querySelector('.js-results-users-chips');
    const modalElement = document.getElementById('resultsCollaboratorsModal');

    if (hiddenInputsContainer && summary && countBadge && chipsContainer && modalElement && window.bootstrap?.Modal) {
        const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
        const searchInput = modalElement.querySelector('.js-results-user-search');
        const userOptions = Array.from(modalElement.querySelectorAll('[data-user-option]'));
        const applyButton = modalElement.querySelector('.js-results-apply-users');
        const selectAllButton = modalElement.querySelector('.js-results-select-all');
        const clearAllButton = modalElement.querySelector('.js-results-clear-all');
        const selectTeamButton = modalElement.querySelector('.js-results-select-team');

        const getCheckboxes = () => userOptions
            .map((option) => option.querySelector('.js-results-user-checkbox'))
            .filter(Boolean);

        const getVisibleOptions = () => userOptions.filter((option) => !option.classList.contains('d-none'));
        const getSelectedOptions = () => userOptions.filter((option) => {
            const checkbox = option.querySelector('.js-results-user-checkbox');
            return checkbox && checkbox.checked;
        });

        const syncOptionCheckedState = () => {
            userOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-results-user-checkbox');
                option.classList.toggle('is-selected', Boolean(checkbox?.checked));
            });
        };

        const syncVisibleUsers = () => {
            const term = (searchInput?.value || '').trim().toLowerCase();
            const selectedTeamId = String(teamFilter?.value || '0');

            userOptions.forEach((option) => {
                const userLabel = option.dataset.userLabel || '';
                const teamIds = (option.dataset.teamIds || '').split(',').filter(Boolean);
                const matchesSearch = term === '' || userLabel.includes(term);
                const matchesTeam = selectedTeamId === '0' || teamIds.includes(selectedTeamId);
                option.classList.toggle('d-none', !(matchesSearch && matchesTeam));
            });
        };

        const renderSelectedUsers = () => {
            const selectedOptions = getSelectedOptions();
            hiddenInputsContainer.innerHTML = '';
            chipsContainer.innerHTML = '';
            syncOptionCheckedState();

            selectedOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-results-user-checkbox');
                if (!checkbox) {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_ids[]';
                input.value = checkbox.value;
                hiddenInputsContainer.appendChild(input);
            });

            countBadge.textContent = String(selectedOptions.length);
            if (!selectedOptions.length) {
                summary.textContent = 'Todos';
                return;
            }

            if (selectedOptions.length <= 2) {
                summary.textContent = selectedOptions
                    .map((option) => option.querySelector('.fw-semibold')?.textContent?.trim() || '')
                    .filter(Boolean)
                    .join(', ');
            } else {
                summary.textContent = `${selectedOptions.length} selecionados`;
            }

            const chipNames = selectedOptions
                .map((option) => option.querySelector('.fw-semibold')?.textContent?.trim() || '')
                .filter(Boolean);

            chipNames.slice(0, 2).forEach((name) => {
                const chip = document.createElement('span');
                chip.className = 'badge rounded-pill text-bg-light border text-dark';
                chip.textContent = name;
                chipsContainer.appendChild(chip);
            });

            if (chipNames.length > 2) {
                const extraChip = document.createElement('span');
                extraChip.className = 'badge rounded-pill text-bg-secondary';
                extraChip.textContent = `+${chipNames.length - 2}`;
                chipsContainer.appendChild(extraChip);
            }
        };

        searchInput?.addEventListener('input', syncVisibleUsers);
        teamFilter?.addEventListener('change', syncVisibleUsers);
        selectAllButton?.addEventListener('click', () => {
            getVisibleOptions().forEach((option) => {
                const checkbox = option.querySelector('.js-results-user-checkbox');
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            renderSelectedUsers();
        });
        clearAllButton?.addEventListener('click', () => {
            getCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
            });
            renderSelectedUsers();
        });
        selectTeamButton?.addEventListener('click', () => {
            const selectedTeamId = String(teamFilter?.value || '0');
            getCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
            });
            userOptions.forEach((option) => {
                const checkbox = option.querySelector('.js-results-user-checkbox');
                if (!checkbox) {
                    return;
                }
                const teamIds = (option.dataset.teamIds || '').split(',').filter(Boolean);
                checkbox.checked = selectedTeamId === '0' || teamIds.includes(selectedTeamId);
            });
            renderSelectedUsers();
            syncVisibleUsers();
        });
        getCheckboxes().forEach((checkbox) => checkbox.addEventListener('change', renderSelectedUsers));
        applyButton?.addEventListener('click', () => {
            renderSelectedUsers();
            modal.hide();
        });
        modalElement.addEventListener('shown.bs.modal', () => {
            syncVisibleUsers();
            searchInput?.focus();
        });

        renderSelectedUsers();
        syncVisibleUsers();
    }

    const formatHHMM = (totalSeconds, signed = false) => {
        const absoluteSeconds = Math.abs(totalSeconds);
        const hours = Math.floor(absoluteSeconds / 3600);
        const minutes = Math.floor((absoluteSeconds % 3600) / 60);
        const prefix = signed && totalSeconds < 0 ? '-' : '';
        return `${prefix}${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    };

    const getTodayLocalDate = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const getCurrentLocalSeconds = () => {
        const now = new Date();
        return (now.getHours() * 3600) + (now.getMinutes() * 60) + now.getSeconds();
    };

    const updateBhInputState = (input, bhSeconds) => {
        input.classList.remove('text-success', 'text-danger', 'text-muted');
        if (bhSeconds > 0) {
            input.classList.add('text-success');
        } else if (bhSeconds < 0) {
            input.classList.add('text-danger');
        } else {
            input.classList.add('text-muted');
        }
    };

    const bhSecondsFromValue = (value) => {
        const match = String(value || '').trim().match(/^([+-])?(\d{1,3}):(\d{2})$/);
        if (!match) {
            return 0;
        }

        const sign = match[1] === '-' ? -1 : 1;
        return sign * (((Number(match[2]) * 60) + Number(match[3])) * 60);
    };

    const recalculateRow = (row) => {
        if (!row) {
            return;
        }

        const entryInputs = Array.from(row.querySelectorAll('.js-entry-time'));
        const effectiveCell = row.querySelector('.js-results-effective');
        const targetCell = row.querySelector('.js-results-target');
        const bhInput = row.querySelector('.js-results-bh-input');
        if (!effectiveCell || !targetCell || !bhInput) {
            return;
        }

        const sortedEntries = entryInputs
            .map((input) => ({
                slotIndex: Number(input.dataset.slotIndex || '0'),
                time: (input.value || '').trim()
            }))
            .filter((entry) => /^([01]\d|2[0-3]):([0-5]\d)$/.test(entry.time))
            .sort((a, b) => a.slotIndex - b.slotIndex);

        let openSeconds = null;
        let effectiveSeconds = 0;
        sortedEntries.forEach((entry) => {
            const [hours, minutes] = entry.time.split(':').map(Number);
            const currentSeconds = (hours * 3600) + (minutes * 60);
            if (entry.slotIndex % 2 === 1) {
                openSeconds = currentSeconds;
            } else if (openSeconds !== null && currentSeconds > openSeconds) {
                effectiveSeconds += currentSeconds - openSeconds;
                openSeconds = null;
            }
        });

        const rowWorkDate = row.dataset.workDate || '';
        if (openSeconds !== null && rowWorkDate === getTodayLocalDate()) {
            const currentLocalSeconds = getCurrentLocalSeconds();
            if (currentLocalSeconds > openSeconds) {
                effectiveSeconds += currentLocalSeconds - openSeconds;
            }
        }

        effectiveCell.dataset.effectiveSeconds = String(effectiveSeconds);
        effectiveCell.textContent = formatHHMM(effectiveSeconds);

        const targetSeconds = Number(targetCell.dataset.targetSeconds || '0');
        const bhSeconds = effectiveSeconds - targetSeconds;
        const autoBhValue = formatHHMM(bhSeconds, true);
        bhInput.dataset.autoBh = autoBhValue;

        const isManualOverride = bhInput.dataset.isOverride === '1'
            || (bhInput.value || '').trim() !== (bhInput.dataset.defaultValue || '').trim();
        if (!isManualOverride) {
            bhInput.value = autoBhValue;
            bhInput.dataset.defaultValue = autoBhValue;
        }

        updateBhInputState(bhInput, isManualOverride ? bhSecondsFromValue(bhInput.value) : bhSeconds);
    };

    const entryInputs = document.querySelectorAll('.js-entry-time');
    if (!entryInputs.length) {
        return;
    }

    document.querySelectorAll('.js-results-row').forEach((row) => recalculateRow(row));
    document.querySelectorAll('.js-results-bh-input').forEach((input) => {
        input.addEventListener('input', () => {
            input.dataset.isOverride = '1';
            updateBhInputState(input, bhSecondsFromValue(input.value));
        });
        input.addEventListener('focus', () => {
            if (input.dataset.isOverride !== '1') {
                input.dataset.defaultValue = input.value || '';
            }
        });
    });

    entryInputs.forEach((input) => {
        input.addEventListener('blur', async () => {
            const entryTime = (input.value || '').trim();
            if (entryTime === '' || entryTime === '--:--') {
                return;
            }

            const body = new URLSearchParams();
            body.set('action', 'update_entry_time');
            body.set('entry_id', input.dataset.entryId || '0');
            body.set('slot_index', input.dataset.slotIndex || '0');
            body.set('entry_date', input.dataset.entryDate || '');
            body.set('target_user_id', input.dataset.targetUserId || '0');
            body.set('entry_time', entryTime);
            body.set('validate_date', input.dataset.entryDate || '');

            input.disabled = true;
            try {
                const response = await fetch('resultados.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString()
                });

                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.message || 'Erro ao guardar a picagem.');
                }

                if (data.entry_id) {
                    input.dataset.entryId = String(data.entry_id);
                }
                recalculateRow(input.closest('.js-results-row'));
                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
                setTimeout(() => input.classList.remove('is-valid'), 900);
            } catch (error) {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                console.error(error);
            } finally {
                input.disabled = false;
            }
        });
    });

    const refreshRowsWithOpenEntries = () => {
        document.querySelectorAll('.js-results-row').forEach((row) => {
            const entryInputsInRow = Array.from(row.querySelectorAll('.js-entry-time'));
            const validEntries = entryInputsInRow
                .map((input) => ({
                    slotIndex: Number(input.dataset.slotIndex || '0'),
                    time: (input.value || '').trim()
                }))
                .filter((entry) => /^([01]\d|2[0-3]):([0-5]\d)$/.test(entry.time))
                .sort((a, b) => a.slotIndex - b.slotIndex);

            if (validEntries.length && validEntries.length % 2 === 1) {
                recalculateRow(row);
            }
        });
    };

    refreshRowsWithOpenEntries();
    window.setInterval(refreshRowsWithOpenEntries, 60000);
}

initResultsPage();
initHrAlertsPage();
