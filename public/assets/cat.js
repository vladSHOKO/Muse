// The companion resumes within this tab; hidden tabs do not advance its clock.
(() => {
    const cat = document.querySelector('[data-cat]');
    const toggle = document.querySelector('[data-cat-toggle]');
    if (!cat || !toggle) return;
    const companion = cat.closest('[data-cat-interact]');
    const lane = companion.parentElement;
    const topbar = lane.closest('.topbar');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const mobile = window.matchMedia('(max-width: 720px)');
    const storageKey = 'muse.cat.hidden';
    const stateKey = 'muse.cat.state.v1';
    let hidden = false;
    try { hidden = localStorage.getItem(storageKey) === '1'; } catch { /* Storage is optional. */ }
    let x = 12;
    let target = x;
    let state = 'sit';
    let elapsed = 0;
    let duration = 6000;
    let lastTime = null;
    let animation = null;
    let direction = 1;
    let resting = false;
    let lastClick = -Infinity;
    const random = (min, max) => min + Math.random() * (max - min);
    const limit = () => Math.max(0, lane.clientWidth - 64);
    const position = () => { companion.style.transform = `translateX(${Math.round(x / 2) * 2}px)`; };
    const save = () => {
        try { sessionStorage.setItem(stateKey, JSON.stringify({ x, target, state, elapsed, duration, direction, resting })); } catch { /* Storage is optional. */ }
    };
    const render = () => {
        position();
        cat.dataset.state = state;
        cat.dataset.frame = String(reducedMotion.matches ? 0 : Math.floor(elapsed / (state === 'walk' ? 180 : 650)) % 2);
        cat.style.setProperty('--cat-direction', String(direction));
        const phase = reducedMotion.matches ? 0 : (elapsed % 1200) / 1200;
        cat.style.setProperty('--heart-rise', `${-Math.floor(phase * 2)}px`);
        cat.style.setProperty('--heart-opacity', String(1 - phase * 0.6));
    };
    const enter = (next) => {
        state = next;
        elapsed = 0;
        duration = next === 'sleep' ? random(22000, 42000) : random(5000, 10000);
        if (next === 'love') duration = 4000;
        if (next === 'walk') {
            target = Math.max(0, Math.min(limit(), x + random(60, mobile.matches ? 130 : 300) * (Math.random() < 0.5 ? -1 : 1)));
            direction = target < x ? -1 : 1;
        }
        render();
        save();
    };
    const tick = (time) => {
        const delta = lastTime === null ? 0 : Math.min(time - lastTime, 100);
        lastTime = time;
        if (!resting) elapsed += delta;
        if (state === 'walk') {
            const distance = target - x;
            const step = delta * 0.025;
            x += Math.sign(distance) * Math.min(Math.abs(distance), step);
            position();
            if (Math.abs(target - x) < 1) enter('sit');
        } else if (!resting && elapsed >= duration) {
            if (state === 'sleep' || state === 'groom' || state === 'love') enter('sit');
            else {
                const choice = Math.random();
                enter(choice < (mobile.matches ? 0.15 : 0.3) ? 'walk' : choice < 0.55 ? 'groom' : 'sleep');
            }
        }
        render();
        animation = !resting && (!reducedMotion.matches || state === 'love') ? requestAnimationFrame(tick) : null;
    };
    const sync = () => {
        if (animation !== null) cancelAnimationFrame(animation);
        animation = null;
        lastTime = null;
        lane.hidden = hidden;
        if (!hidden) {
            x = Math.min(x, limit());
            target = Math.min(target, limit());
        }
        topbar.classList.toggle('cat-is-hidden', hidden);
        toggle.textContent = hidden ? 'Показать котика' : 'Скрыть котика';
        toggle.setAttribute('aria-pressed', String(!hidden));
        if (reducedMotion.matches && state !== 'love') enter('sit');
        render();
        if (!hidden && !document.hidden && !resting && (!reducedMotion.matches || state === 'love')) animation = requestAnimationFrame(tick);
    };
    try {
        const saved = JSON.parse(sessionStorage.getItem(stateKey));
        if (saved && ['sit', 'walk', 'groom', 'sleep', 'love'].includes(saved.state)
            && ['x', 'target', 'elapsed', 'duration'].every((key) => Number.isFinite(saved[key]) && saved[key] >= 0)
            && saved.duration <= 60000 && saved.elapsed <= 60000
            && [1, -1].includes(saved.direction) && typeof saved.resting === 'boolean'
            && (!saved.resting || saved.state === 'sit')) {
            ({ x, target, state, elapsed, duration, direction, resting } = saved);
        }
    } catch { /* Invalid or unavailable storage starts a new companion. */ }
    companion.addEventListener('click', () => {
        const now = performance.now();
        const doubleClick = now - lastClick <= 450;
        lastClick = doubleClick ? -Infinity : now;
        resting = !doubleClick;
        enter(doubleClick ? 'love' : 'sit');
        sync();
    });
    companion.addEventListener('dblclick', () => {
        // Honor the system double-click interval as well as touch/keyboard pairs.
        if (state !== 'love') {
            resting = false;
            enter('love');
            sync();
        }
        lastClick = -Infinity;
    });
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        hidden = !hidden;
        try { localStorage.setItem(storageKey, hidden ? '1' : '0'); } catch { /* Keep the current-page preference. */ }
        sync();
    });
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey || event.key === null) {
            hidden = event.newValue === '1';
            sync();
        }
    });
    document.addEventListener('visibilitychange', () => { save(); sync(); });
    window.addEventListener('pagehide', save);
    window.addEventListener('pageshow', sync);
    reducedMotion.addEventListener('change', sync);
    window.addEventListener('resize', () => {
        if (hidden) return;
        x = Math.min(x, limit());
        target = Math.min(target, limit());
        position();
    });
    x = Math.min(x, limit());
    target = Math.min(target, limit());
    position();
    sync();
    companion.disabled = false;
})();
