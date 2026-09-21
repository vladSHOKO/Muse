document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copy);
        const status = document.getElementById('copy-status');
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Ссылка скопирована.';
        } catch {
            input.focus();
            input.select();
            status.textContent = 'Ссылка выделена. Скопируйте её сочетанием Ctrl+C или через меню браузера.';
        }
    });
});

document.querySelectorAll('[data-checklist-collapse]').forEach((button) => {
    const content = document.getElementById(button.getAttribute('aria-controls'));
    const storageKey = `muse.checklist.collapsed.${button.dataset.checklistCollapse}`;
    const setCollapsed = (collapsed) => {
        content.hidden = collapsed;
        button.setAttribute('aria-expanded', String(!collapsed));
        button.textContent = collapsed ? 'Развернуть' : 'Свернуть';
    };
    try {
        setCollapsed(localStorage.getItem(storageKey) === '1');
    } catch {
        // Collapsing still works when browser storage is unavailable.
    }
    button.hidden = false;
    button.addEventListener('click', () => {
        setCollapsed(!content.hidden);
        try {
            if (content.hidden) localStorage.setItem(storageKey, '1');
            else localStorage.removeItem(storageKey);
        } catch {
            // Storage restrictions must not prevent interaction.
        }
    });
});

// The server-side fallback returns to the checklist fragment. With JavaScript,
// keep the add form at the same visual position instead of jumping to the anchor.
const checklistScrollKey = 'muse.checklist.add-scroll';
document.querySelectorAll('form[data-checklist-add]').forEach((form) => {
    form.addEventListener('submit', () => {
        try {
            sessionStorage.setItem(checklistScrollKey, JSON.stringify({
                path: `${location.pathname}${location.search}`,
                formId: form.id,
                top: form.getBoundingClientRect().top,
                savedAt: Date.now(),
            }));
        } catch {
            // Storage restrictions leave the normal fragment navigation intact.
        }
    });
});
window.addEventListener('load', () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(checklistScrollKey) || 'null');
        sessionStorage.removeItem(checklistScrollKey);
        if (!saved || saved.path !== `${location.pathname}${location.search}` || Date.now() - saved.savedAt > 30000) return;
        const form = document.getElementById(saved.formId);
        if (!form || typeof saved.top !== 'number') return;
        requestAnimationFrame(() => {
            window.scrollBy(0, form.getBoundingClientRect().top - saved.top);
            if (location.hash.startsWith('#checklist-')) history.replaceState(null, '', `${location.pathname}${location.search}`);
        });
    } catch {
        // A failed restoration must not affect adding checklist items.
    }
}, { once: true });

// Submit a desired state (not an inversion), so retrying a request is safe.
document.querySelectorAll('form[data-checklist-complete]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        if (button.disabled) return;
        const checklist = form.closest('.checklist');
        const error = checklist.querySelector('.checklist-error');
        button.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            if (!response.ok) throw new Error('Checklist update failed');
            const state = await response.json();
            if (typeof state.completed !== 'boolean') throw new Error('Invalid checklist response');
            form.closest('.checklist-item').classList.toggle('is-done', state.completed);
            button.setAttribute('aria-checked', String(state.completed));
            button.querySelector('.task-state').textContent = state.completed ? '✓' : '';
            form.querySelector('[name="completed"]').value = state.completed ? '0' : '1';
            checklist.querySelector('.checklist-progress').textContent = `${state.completedCount}/${state.totalCount}`;
        } catch {
            error.textContent = 'Не удалось сохранить отметку. Повторите попытку или обновите страницу.';
            error.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('form[data-checklist-edit]').forEach((form) => {
    const item = form.closest('.checklist-item');
    const complete = item.querySelector('[data-checklist-complete]');
    const actions = item.querySelector('[data-checklist-view-actions]');
    const trigger = actions.querySelector('[data-checklist-edit-start]');
    const cancel = form.querySelector('[data-checklist-edit-cancel]');
    const input = form.querySelector('[name="title"]');
    const title = complete.querySelector('.checklist-title');
    const save = form.querySelector('[type="submit"]');
    const checklist = form.closest('.checklist');
    const error = checklist.querySelector('.checklist-error');
    const close = () => {
        input.value = title.textContent;
        form.hidden = true;
        complete.hidden = false;
        actions.hidden = false;
        trigger.focus();
    };

    trigger.hidden = false;
    trigger.addEventListener('click', () => {
        input.value = title.textContent;
        complete.hidden = true;
        actions.hidden = true;
        form.hidden = false;
        error.hidden = true;
        input.focus();
        input.select();
    });
    cancel.addEventListener('click', close);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (save.disabled) return;
        save.disabled = true;
        cancel.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            let result = {};
            try { result = await response.json(); } catch { /* Non-JSON failures use the generic message below. */ }
            if (!response.ok || typeof result.title !== 'string') {
                throw new Error(typeof result.error === 'string' ? result.error : 'Не удалось сохранить пункт.');
            }
            title.textContent = result.title;
            input.value = result.title;
            input.defaultValue = result.title;
            trigger.setAttribute('aria-label', `Изменить пункт «${result.title}»`);
            actions.querySelector('.checklist-delete').setAttribute('aria-label', `Удалить пункт «${result.title}»`);
            close();
        } catch (requestError) {
            error.textContent = requestError instanceof Error && requestError.message
                ? requestError.message
                : 'Не удалось сохранить пункт. Повторите попытку.';
            error.hidden = false;
            input.focus();
        } finally {
            save.disabled = false;
            cancel.disabled = false;
        }
    });
});

// Keep the native datetime-local field as the no-JavaScript and manual-input fallback.
const deadlineField = document.querySelector('[data-deadline-field]');
const deadlinePicker = document.getElementById('deadline-picker');
if (deadlineField && deadlinePicker && typeof deadlinePicker.showModal === 'function') {
    const input = deadlineField.querySelector('input');
    const trigger = deadlineField.querySelector('[data-deadline-open]');
    const days = deadlinePicker.querySelector('[data-calendar-days]');
    const monthLabel = deadlinePicker.querySelector('[data-calendar-month]');
    const time = deadlinePicker.querySelector('#deadline-picker-time');
    const summary = deadlinePicker.querySelector('[data-deadline-selection]');
    const previous = deadlinePicker.querySelector('[data-calendar-prev]');
    const next = deadlinePicker.querySelector('[data-calendar-next]');
    const pad = (value) => String(value).padStart(2, '0');
    // Calendar dates are represented in UTC to avoid the browser's time zone and DST.
    const makeDate = (year, month, day) => {
        const date = new Date(0);
        date.setUTCFullYear(year, month, day);
        return date;
    };
    const key = (date) => `${String(date.getUTCFullYear()).padStart(4, '0')}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
    const parseDate = (value) => {
        const [year, month, day] = value.split('-').map(Number);
        return makeDate(year, month - 1, day);
    };
    const moscowToday = () => {
        const parts = new Intl.DateTimeFormat('en', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
        const part = (type) => Number(parts.find((item) => item.type === type).value);
        return makeDate(part('year'), part('month') - 1, part('day'));
    };
    const monthFormat = new Intl.DateTimeFormat('ru', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    const dayFormat = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
    let selected;
    let visibleMonth;
    let focused;
    const updateSummary = () => {
        summary.textContent = `${dayFormat.format(selected)}${time.value ? `, ${time.value} МСК` : ' — укажите время'}`;
    };
    const render = (focusDay = false) => {
        const year = visibleMonth.getUTCFullYear();
        const month = visibleMonth.getUTCMonth();
        monthLabel.textContent = monthFormat.format(visibleMonth);
        previous.disabled = year === 1 && month === 0;
        next.disabled = year === 9999 && month === 11;
        days.replaceChildren();
        const offset = (visibleMonth.getUTCDay() + 6) % 7;
        for (let i = 0; i < offset; i++) {
            const spacer = document.createElement('span');
            spacer.setAttribute('aria-hidden', 'true');
            days.append(spacer);
        }
        const count = makeDate(year, month + 1, 0).getUTCDate();
        const today = key(moscowToday());
        for (let day = 1; day <= count; day++) {
            const date = makeDate(year, month, day);
            const value = key(date);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'calendar-day';
            button.textContent = String(day);
            button.dataset.date = value;
            button.setAttribute('aria-label', dayFormat.format(date));
            button.setAttribute('aria-pressed', String(value === key(selected)));
            if (value === today) button.setAttribute('aria-current', 'date');
            button.tabIndex = value === key(focused) ? 0 : -1;
            days.append(button);
        }
        updateSummary();
        if (focusDay) days.querySelector('[tabindex="0"]').focus();
    };
    const choose = (date) => {
        selected = date;
        focused = date;
        visibleMonth = makeDate(date.getUTCFullYear(), date.getUTCMonth(), 1);
        render(true);
    };
    const commit = (value) => {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        deadlinePicker.close();
    };
    trigger.hidden = false;
    trigger.addEventListener('click', () => {
        selected = input.value ? parseDate(input.value.slice(0, 10)) : moscowToday();
        focused = selected;
        visibleMonth = makeDate(selected.getUTCFullYear(), selected.getUTCMonth(), 1);
        time.value = input.value ? input.value.slice(11, 16) : '18:00';
        render();
        deadlinePicker.showModal();
        days.querySelector('[tabindex="0"]').focus();
    });
    deadlinePicker.addEventListener('close', () => trigger.focus());
    deadlinePicker.querySelectorAll('[data-deadline-cancel]').forEach((button) => {
        button.addEventListener('click', () => deadlinePicker.close());
    });
    deadlinePicker.querySelectorAll('[data-deadline-offset]').forEach((button) => {
        button.addEventListener('click', () => {
            const date = moscowToday();
            date.setUTCDate(date.getUTCDate() + Number(button.dataset.deadlineOffset));
            choose(date);
        });
    });
    const changeMonth = (offset) => {
        visibleMonth = makeDate(visibleMonth.getUTCFullYear(), visibleMonth.getUTCMonth() + offset, 1);
        focused = key(selected).slice(0, 7) === key(visibleMonth).slice(0, 7) ? selected : visibleMonth;
        render();
    };
    previous.addEventListener('click', () => changeMonth(-1));
    next.addEventListener('click', () => changeMonth(1));
    days.addEventListener('click', (event) => {
        const button = event.target.closest('[data-date]');
        if (button) choose(parseDate(button.dataset.date));
    });
    days.addEventListener('keydown', (event) => {
        const button = event.target.closest('[data-date]');
        if (!button) return;
        const date = parseDate(button.dataset.date);
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            choose(date);
            return;
        }
        const weekday = (date.getUTCDay() + 6) % 7;
        const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -weekday, End: 6 - weekday };
        if (!(event.key in offsets)) return;
        event.preventDefault();
        date.setUTCDate(date.getUTCDate() + offsets[event.key]);
        if (date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999) return;
        focused = date;
        visibleMonth = makeDate(date.getUTCFullYear(), date.getUTCMonth(), 1);
        render(true);
    });
    time.addEventListener('input', updateSummary);
    deadlinePicker.querySelector('[data-deadline-apply]').addEventListener('click', () => {
        if (time.reportValidity()) commit(`${key(selected)}T${time.value}`);
    });
    deadlinePicker.querySelector('[data-deadline-clear]').addEventListener('click', () => commit(''));
}
