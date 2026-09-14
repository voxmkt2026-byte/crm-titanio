import { normalizePhoneInput, formatCallDuration, isApi4ComIntegratedCall, viewForState } from './webphone-core.mjs?v=5';

const elements = Object.fromEntries([
    'webphonePanel', 'webphoneStatus', 'callTimer', 'activatePhoneButton',
    'webphoneNumber', 'webphoneError', 'dialpad', 'callButton', 'hangupButton', 'muteButton',
].map(id => [id, document.getElementById(id)]));
const keys = [...elements.dialpad.querySelectorAll('[data-digit]')];
const controls = new AbortController();
let webphone = null;
let state = 'disabled';
let registered = false;
let attempt = null;
let muted = false;
let startedAt = null;
let clock = null;
let registrationTimer = null;
let disposed = false;
let recentlyEndedCall = null;
let account = '1';
let configurationPending = false;
let extension = '';

function phoneState() {
    let session;
    try { session = attempt?.call?._getSession?.(); } catch { /* Unsupported session. */ }
    const available = state === 'active' && !attempt?.ended && !attempt?.cancelled
        && !attempt?.sipPending && attempt?.call?.isPrimary();
    return {
        state, muted, held: Boolean(attempt?.held),
        holdPending: attempt?.sipPending?.kind === 'hold',
        transferPending: attempt?.sipPending?.kind === 'transfer',
        canHold: Boolean(available && session?.hold && session?.unhold && session?.isOnHold),
        canTransfer: Boolean(available && session?.refer),
        phone: attempt?.phone || elements.webphoneNumber.value,
        extension, account, message: elements.webphoneError.textContent,
    };
}

function publishState() {
    window.dispatchEvent(new CustomEvent('vox:phone-state', { detail: phoneState() }));
}

function render(next = state) {
    state = next;
    const view = viewForState(state, Boolean(attempt));
    elements.webphonePanel.dataset.phoneState = state;
    elements.webphoneStatus.textContent = view.label;
    elements.activatePhoneButton.disabled = !view.canActivate;
    elements.callButton.disabled = !view.canDial;
    elements.hangupButton.disabled = !view.canHangup;
    elements.muteButton.disabled = !view.canMute;
    elements.muteButton.setAttribute('aria-pressed', String(muted));
    elements.muteButton.textContent = muted ? 'Ativar microfone' : 'Silenciar';
    elements.webphoneNumber.disabled = !view.canDial;
    keys.forEach(key => { key.disabled = !view.canDial; });
    publishState();
}

function showError(message = '') {
    elements.webphoneError.textContent = message;
    publishState();
}

async function request(url, payload) {
    // The CRM supplies authenticated endpoints; standalone transport is unchanged.
    if (typeof window.VoxPhoneBridge?.request === 'function') {
        return window.VoxPhoneBridge.request(url, payload, account);
    }
    const keepalive = url === 'api/hangup.php';
    if (account !== '1') url += '?account=' + encodeURIComponent(account);
    const controller = new AbortController();
    const deadline = window.setTimeout(() => controller.abort(), 20000);
    try {
        const response = await fetch(url, {
            signal: controller.signal,
            method: payload ? 'POST' : 'GET',
            cache: 'no-store',
            ...(payload ? {
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive,
            } : {}),
        });
        // Never surface response bodies or vendor errors: they can contain secrets.
        if (!response.ok) throw Object.assign(new Error('Request failed'), { status: response.status });
        const result = await response.json();
        if (result?.ok !== true || !result.data) throw new Error('Invalid response');
        return result.data;
    } finally {
        window.clearTimeout(deadline);
    }
}

function resetTimer() {
    window.clearInterval(clock);
    clock = null;
    startedAt = null;
    elements.callTimer.textContent = '00:00';
    muted = false;
}

function finish(operation) {
    if (attempt !== operation) return;
    operation.ended = true;
    operation.sipPending?.cancel();
    resetTimer();
    window.clearTimeout(operation.legTimer);
    // The provider ID may arrive after SIP has already terminated.
    if (operation.terminationFailed) {
        render('dialing');
        return;
    }
    if (operation.pendingDial || operation.hangingUp) {
        render('ending');
        return;
    }
    recentlyEndedCall = operation.call;
    queueMicrotask(() => { recentlyEndedCall = null; });
    operation.call = null;
    operation.id = null;
    attempt = null;
    render(registered ? 'ready' : 'error');
    window.dispatchEvent(new Event('vox:call-ended'));
}

async function hangup(operation = attempt) {
    if (!operation || attempt !== operation || operation.hangingUp) return;
    const hadTerminationFailure = operation.terminationFailed;
    operation.cancelled = true;
    operation.sipPending?.cancel();
    operation.terminationFailed = false;
    operation.hangingUp = true;
    render('ending');
    let localFailed = false;
    let providerFailed = false;
    let recovered = false;
    const providerRequest = operation.id ? request('api/hangup.php', { call_id: operation.id }).catch(error => {
        providerFailed = error.status !== 404 || Boolean(window.VoxPhoneBridge);
    }) : Promise.resolve();
    // Ask the provider while its call ID still exists. Ending SIP first can
    // remove the remote call before the CRM receives its confirmation.
    // Release local media immediately, without waiting for its HTTP response.
    try {
        if (!operation.ended) operation.call?.hangup();
    } catch {
        localFailed = true;
    }
    await providerRequest;
    // SIP may already be gone while the provider cancellation ID returns an
    // error. Only the CRM's verified ended record can release its reservation.
    if (providerFailed && typeof window.VoxPhoneBridge?.confirmEnded === 'function') {
        try {
            recovered = (await window.VoxPhoneBridge.confirmEnded())?.ended === true;
            providerFailed = !recovered;
        } catch { /* Preserve the uncertain attempt if confirmation is unavailable. */ }
    }
    operation.hangingUp = false;
    if (attempt !== operation) return;
    if (providerFailed || (localFailed && !operation.id && !operation.ended)) {
        operation.terminationFailed = true;
        // Keep the ID and controls available so the user can retry termination.
        showError('Não foi possível confirmar o encerramento. Tente desligar novamente.');
        render('dialing');
        return;
    }
    if (recovered || hadTerminationFailure) showError();
    finish(operation);
}

function rejectCall(call) {
    try { call?.reject(); } catch { try { call?.hangup(); } catch { /* Already ended. */ } }
}

function onCallCreated(_phone, call) {
    if (disposed) return rejectCall(call);
    try {
        const operation = attempt;
        if (!call.isPrimary() || !operation || operation.cancelled || operation.ended
            || operation.call || !call.isInProgress()
            || !isApi4ComIntegratedCall(call)) {
            rejectCall(call);
            return;
        }
        operation.call = call;
        render('ringing');
        call.answer();
    } catch {
        showError('Não foi possível atender a conexão do telefone. Tente novamente.');
        if (attempt) void hangup();
        else rejectCall(call);
    }
}

function onPhoneEvent(event, _phone, subject) {
    if (disposed) return;
    if (['userAgent.registered', 'userAgent.registration.registered'].includes(event)) {
        registered = true;
        window.clearTimeout(registrationTimer);
        if (!attempt) { showError(); render('ready'); }
        return;
    }
    if (['userAgent.unregistered', 'userAgent.registration.unregistered',
        'userAgent.registration.failed', 'userAgent.disconnected', 'userAgent.configuration.error'].includes(event)) {
        registered = false;
        window.clearTimeout(registrationTimer);
        showError('Telefone desconectado. Ative o telefone para tentar novamente.');
        if (!attempt) render('error');
        return;
    }
    if (event === 'mediaDevices.getUserMedia.error') {
        showError('Não foi possível usar o microfone. Verifique a permissão e o dispositivo.');
        if (attempt) void hangup();
        return;
    }
    if (event === 'call.failed' && subject && (
        (subject === recentlyEndedCall && !attempt)
        || (subject === attempt?.call && attempt.ended)
    )) {
        showError('A ligação não foi concluída. Tente novamente.');
        return;
    }
    const operation = attempt;
    if (!operation || subject !== operation.call || !subject?.isPrimary()) return;
    switch (event) {
        case 'call.primary.progress':
            if (!operation.cancelled && state !== 'active') render('ringing');
            break;
        // This official artifact maps JsSIP confirmed to established.
        case 'call.primary.confirmed':
        case 'call.primary.established':
            if (operation.cancelled || operation.ended) break;
            window.clearTimeout(operation.legTimer);
            if (startedAt === null) {
                startedAt = Date.now();
                clock = window.setInterval(() => {
                    elements.callTimer.textContent = formatCallDuration((Date.now() - startedAt) / 1000);
                }, 1000);
            }
            render('active');
            break;
        case 'call.primary.failed':
            showError('A ligação não foi concluída. Tente novamente.');
            finish(operation);
            break;
        case 'call.primary.terminated':
            finish(operation);
            break;
    }
}

function instanceId() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    // JsSIP requires UUID syntax, including in the timestamp-based fallback.
    let timestamp = Date.now();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const value = (timestamp + Math.random() * 16) % 16 | 0;
        timestamp = Math.floor(timestamp / 16);
        return (character === 'x' ? value : (value & 3) | 8).toString(16);
    });
}

async function activate() {
    if (configurationPending) return;
    if (disposed || !viewForState(state, Boolean(attempt)).canActivate || attempt) return;
    showError();
    render('connecting');
    try {
        if (!navigator.mediaDevices?.getUserMedia || typeof window.libwebphone !== 'function') {
            throw new Error('Unsupported browser');
        }
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        stream.getTracks().forEach(track => track.stop());
        if (disposed) return;
        if (!webphone) {
            const config = await request('api/webphone-config.php');
            if (disposed) return;
            if (!/^\d{1,10}$/.test(String(config.extension)) || !config.password || !config.domain
                || !String(config.websocket_url).startsWith('wss://')) throw new Error('Invalid config');
            extension = String(config.extension);
            webphone = new window.libwebphone({
                userAgent: {
                    renderTargets: [],
                    transport: { sockets: [config.websocket_url] },
                    authentication: { username: String(config.extension), password: config.password, realm: config.domain },
                    user_agent: { register: true, instance_id: instanceId() },
                    debug: false,
                },
                call: { globalKeyShortcuts: false },
                dialpad: { enabled: false },
                callList: { enabled: false },
                callControl: { enabled: false },
                audioContext: { enabled: false },
                videoCanvas: { enabled: false },
                mediaDevices: { renderTargets: [], videoinput: { enabled: false }, detectDeviceChanges: false },
            });
            webphone.on('call.created', onCallCreated);
            webphone.onAny(onPhoneEvent);
        } else {
            webphone.getUserAgent().stop();
        }
        registered = false;
        render('connecting');
        registrationTimer = window.setTimeout(() => {
            if (!registered && !attempt && !disposed) {
                showError('A conexão demorou mais que o esperado. Ative o telefone para tentar novamente.');
                render('error');
            }
        }, 30000);
        webphone.getUserAgent().start();
    } catch {
        window.clearTimeout(registrationTimer);
        showError('Não foi possível ativar o telefone. Verifique o microfone e a conexão e tente novamente.');
        render('error');
    }
}

async function dial() {
    if (configurationPending) return;
    if (disposed || state !== 'ready' || !registered || attempt) return;
    const phone = normalizePhoneInput(elements.webphoneNumber.value);
    if (!/^\+?\d{8,15}$/.test(phone)) {
        showError('Informe um telefone válido com DDD, entre 8 e 15 dígitos.');
        return;
    }
    showError();
    elements.webphoneNumber.value = phone;
    const operation = { call: null, id: null, phone, held: false, pendingDial: true, ended: false, cancelled: false, hangingUp: false };
    attempt = operation;
    render('dialing');
    try {
        const response = await request('api/dial.php', { phone });
        if (typeof response.id !== 'string' || !/^[A-Za-z0-9._:-]{1,160}$/.test(response.id)) {
            throw new Error('Invalid call ID');
        }
        operation.id = response.id;
        operation.pendingDial = false;
        if (operation.ended || operation.cancelled || disposed) {
            await hangup(operation);
        } else if (state !== 'active') {
            operation.legTimer = window.setTimeout(() => {
                if (attempt !== operation || state === 'active') return;
                showError('A ligação não conectou a tempo. Tente novamente.');
                void hangup(operation);
            }, 60000);
            render(operation.call ? 'ringing' : 'dialing');
        }
    } catch {
        operation.pendingDial = false;
        showError('Não foi possível iniciar a ligação. Verifique a conexão antes de tentar novamente.');
        if (operation.call) await hangup(operation);
        else finish(operation);
    }
}

function toggleMute() {
    if (state !== 'active' || !attempt?.call) return;
    try {
        if (muted) attempt.call.unmute();
        else attempt.call.mute();
        muted = !muted;
        render();
    } catch {
        showError('Não foi possível alterar o microfone. Tente novamente.');
    }
}

// The installed wrapper emits transfer.complete immediately, and its hold event
// is optimistic. Use the underlying JsSIP completion contracts instead.
function sipControl(kind, target) {
    const view = phoneState();
    const invalid = kind === 'transfer' && !/^\d{1,10}$/.test(String(target ?? '').trim());
    if (disposed || invalid || !(kind === 'hold' ? view.canHold : view.canTransfer)) {
        return Promise.resolve({ success: false, message: invalid
            ? 'Informe um ramal numérico com até 10 dígitos.'
            : 'Este controle não está disponível no momento.' });
    }
    const operation = attempt;
    const session = operation.call._getSession();
    const generation = operation.sipGeneration = (operation.sipGeneration || 0) + 1;
    return new Promise(resolve => {
        let settled = false;
        let timedOut = false;
        let holdConfirmed = false;
        let subscriber = null;
        let handlers = {};
        const done = (success, message) => {
            if (settled) return;
            settled = true;
            window.clearTimeout(pending.timer);
            session.removeListener?.('ended', ended);
            session.removeListener?.('failed', ended);
            for (const [name, handler] of Object.entries(handlers)) subscriber?.removeListener?.(name, handler);
            if (operation.sipPending === pending) operation.sipPending = null;
            if (attempt === operation) { showError(message); render(); }
            resolve({ success, message });
        };
        const ended = () => done(false, 'A chamada terminou antes da confirmação da operação.');
        const pending = { kind, cancel: ended, timer: null };
        operation.sipPending = pending;
        pending.timer = window.setTimeout(() => {
            timedOut = true;
            done(false, kind === 'hold'
            ? 'Não foi possível confirmar a espera/retomada. O estado remoto pode ter mudado.'
            : 'Transferência sem confirmação. Verifique o destino antes de tentar novamente.');
        }, 30000);
        session.on?.('ended', ended);
        session.on?.('failed', ended);
        showError();
        render();
        try {
            if (kind === 'hold') {
                const desired = !session.isOnHold().local;
                const accepted = session[desired ? 'hold' : 'unhold']({}, () => {
                    if (holdConfirmed || (settled && !timedOut) || disposed || attempt !== operation
                        || operation.cancelled || operation.ended || state !== 'active'
                        || operation.sipGeneration !== generation || !operation.call?.isPrimary()
                        || operation.call._getSession() !== session) return;
                    holdConfirmed = true;
                    operation.held = desired;
                    const message = desired ? 'Chamada em espera.' : 'Chamada retomada.';
                    // Reconcile a late network confirmation without resolving the
                    // expired Promise again or overwriting a newer SIP operation.
                    if (settled) { showError(message); render(); }
                    else done(true, message);
                });
                if (accepted !== true) done(false, 'Não foi possível alterar a espera. Tente novamente.');
            } else {
                handlers = {
                    accepted: () => done(true, 'Transferência confirmada pelo destino.'),
                    failed: () => done(false, 'O destino recusou ou não concluiu a transferência.'),
                    requestFailed: () => done(false, 'A operadora não aceitou a transferência.'),
                };
                subscriber = session.refer(String(target).trim(), { eventHandlers: handlers });
                if (!subscriber) done(false, 'Não foi possível iniciar a transferência.');
                // A synchronous failure may settle before refer() returns.
                if (settled) for (const [name, handler] of Object.entries(handlers)) subscriber?.removeListener?.(name, handler);
            }
        } catch {
            done(false, 'Não foi possível concluir o controle da chamada.');
        }
    });
}

window.VoxPhone = {
    getState: phoneState,
    async hangup() {
        if (!attempt) return false;
        await hangup(attempt);
        return true;
    },
    // Read-only: callers must clone these tracks and never mutate SIP media.
    getAudioTracks() {
        const empty = { seller: null, customer: null };
        if (disposed || state !== 'active' || attempt?.ended || attempt?.cancelled) return empty;
        try {
            if (!attempt?.call?.isPrimary()) return empty;
            const connection = attempt.call.getPeerConnection?.();
            if (!connection || connection.connectionState === 'closed') return empty;
            const audio = entries => entries.find(entry => entry.track?.kind === 'audio'
                && entry.track.readyState === 'live')?.track || null;
            return { seller: audio(connection.getSenders()), customer: audio(connection.getReceivers()) };
        } catch { return empty; }
    },
    sendDigit(digit) {
        if (state !== 'active' || !/^[0-9*#]$/.test(String(digit)) || !attempt?.call?.isPrimary()) return false;
        try {
            const session = attempt.call._getSession();
            if (typeof session?.sendDTMF !== 'function') return false;
            session.sendDTMF(String(digit)); return true;
        } catch { showError('Não foi possível enviar o tom.'); return false; }
    },
    toggleHold: () => sipControl('hold'),
    transfer: target => sipControl('transfer', target),
    prepareNumber(value) {
        if (disposed || attempt || state === 'connecting' || configurationPending) return false;
        const number = normalizePhoneInput(value);
        if (!/^\+?\d{8,15}$/.test(number)) return false;
        elements.webphoneNumber.value = number; publishState(); return true;
    },
    busy: () => Boolean(attempt) || state === 'connecting',
    setConfigurationPending(value) {
        if (value && (attempt || state === 'connecting')) return false;
        configurationPending = Boolean(value); return true;
    },
    setAccount(next, force = false) {
        if (!['1','2'].includes(next) || disposed || attempt || state === 'connecting' || configurationPending) return false;
        if (next === account && !force) return true;
        window.clearTimeout(registrationTimer);
        resetTimer();
        if (webphone) {
            webphone.off('call.created', onCallCreated);
            webphone.offAny(onPhoneEvent);
            try { webphone.getUserAgent().stop(); } catch { /* No call is active. */ }
            try { webphone.getMediaDevices()?.stopAllStreams(); } catch { /* Some devices have no streams. */ }
        }
        webphone = null; registered = false; account = next; extension = '';
        elements.webphoneNumber.value = ''; showError(); render('disabled');
        return true;
    },
};
elements.activatePhoneButton.addEventListener('click', activate, { signal: controls.signal });
elements.callButton.addEventListener('click', dial, { signal: controls.signal });
elements.hangupButton.addEventListener('click', () => { void hangup(); }, { signal: controls.signal });
elements.muteButton.addEventListener('click', toggleMute, { signal: controls.signal });
elements.webphoneNumber.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); void dial(); }
}, { signal: controls.signal });
keys.forEach(key => key.addEventListener('click', () => {
    if (state !== 'ready') return;
    elements.webphoneNumber.value += key.dataset.digit;
    elements.webphoneNumber.focus();
}, { signal: controls.signal }));
window.addEventListener('pagehide', () => {
    disposed = true;
    controls.abort();
    window.clearTimeout(registrationTimer);
    resetTimer();
    if (attempt) {
        window.clearTimeout(attempt.legTimer);
        void hangup();
    }
    webphone?.off('call.created', onCallCreated);
    webphone?.offAny(onPhoneEvent);
    try { webphone?.getUserAgent().stop(); } catch { /* Page is closing. */ }
    try { webphone?.getMediaDevices()?.stopAllStreams(); } catch { /* Page is closing. */ }
}, { once: true });
window.addEventListener('pageshow', event => {
    if (event.persisted && disposed) window.location.reload();
});
render();
