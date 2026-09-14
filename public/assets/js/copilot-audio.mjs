const ENDPOINT = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained?access_token=';
const FAILURE = 'A transcrição ao vivo foi interrompida. A ligação continua normalmente.';

/** Two independent audio taps. The owner must stop on call end/account change
 * and shouldSend(speaker) must enforce current call, mute and hold state. */
export async function startCopilotAudio({ tracks, tokens, model = 'gemini-3.5-transcribe-live', languageCodes = ['pt-BR'],
    workletUrl = new URL('./copilot-pcm-worklet.js', import.meta.url),
    onSegment = () => {}, onPartial = () => {}, onStatus = () => {},
    onError = () => {}, shouldSend = () => true, maxDurationMs = 600000 } = {}) {
    const speakers = ['seller', 'customer'];
    if (!Array.isArray(languageCodes) || languageCodes.length > 8
        || languageCodes.some(code => typeof code !== 'string' || !/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/.test(code)))
        throw new Error('A configuração de idioma da transcrição é inválida.');
    if (speakers.some(s => tracks?.[s]?.kind !== 'audio' || tracks[s].readyState !== 'live'
        || typeof tracks[s].clone !== 'function')) throw new Error('Os dois canais de áudio da chamada precisam estar disponíveis.');
    if (speakers.some(s => typeof tokens?.[s] !== 'string' || !tokens[s].startsWith('auth_tokens/')))
        throw new Error('Não foi possível autorizar a transcrição.');
    const resources = [];
    let stopped = false, context, lifetime, setupDeadline;
    const origin = performance.now();
    let timestamp = 0;
    const notify = (callback, value) => { try { callback(value); } catch { /* UI cannot affect SIP. */ } };
    const stop = () => {
        if (stopped) return;
        stopped = true;
        clearTimeout(lifetime); clearTimeout(setupDeadline);
        for (const r of resources) {
            if (r.node) r.node.port.onmessage = null;
            try { r.source?.disconnect(); } catch {}
            try { r.node?.disconnect(); } catch {}
            try { r.clone?.stop(); } catch {}
            if (r.socket) {
                r.socket.onopen = r.socket.onmessage = r.socket.onerror = r.socket.onclose = null;
                try { r.socket.close(); } catch {}
            }
        }
        try { context?.close()?.catch(() => {}); } catch {}
        notify(onStatus, 'stopped');
    };
    const fail = () => { if (!stopped) { stop(); notify(onError, FAILURE); } };
    const segment = (speaker, value, callback) => {
        if (typeof value?.text !== 'string' || !value.text.trim()) return;
        if (value.text.length > 12000) return fail();
        timestamp = Math.max(timestamp, Math.round(performance.now() - origin));
        notify(callback, { speaker, text: value.text.trim(), start_ms: timestamp });
    };
    try {
        const Audio = globalThis.AudioContext || globalThis.webkitAudioContext;
        context = new Audio();
        await context.audioWorklet.addModule(workletUrl);
        await context.resume();
        for (const speaker of speakers) {
            const r = { speaker, ready: false }; resources.push(r);
            r.clone = tracks[speaker].clone();
            r.source = context.createMediaStreamSource(new MediaStream([r.clone]));
            r.node = new AudioWorkletNode(context, 'copilot-pcm', { numberOfInputs: 1,
                numberOfOutputs: 1, outputChannelCount: [1], channelCount: 1, channelCountMode: 'explicit' });
            r.socket = new WebSocket(ENDPOINT + encodeURIComponent(tokens[speaker]));
            r.socket.binaryType = 'arraybuffer';
            r.socket.onopen = () => {
                if (stopped) return;
                try { r.socket.send(JSON.stringify({ setup: { model: 'models/' + model.replace(/^models\//, ''),
                    generationConfig: { responseModalities: ['TEXT'] }, inputAudioTranscription: { languageCodes } } })); } catch { fail(); }
            };
            r.socket.onmessage = event => {
                if (stopped) return;
                try {
                    const raw = typeof event.data === 'string' ? event.data
                        : event.data instanceof ArrayBuffer && event.data.byteLength <= 65536
                            ? new TextDecoder().decode(event.data) : null;
                    if (raw === null || raw.length > 65536) return fail();
                    const data = JSON.parse(raw);
                    if (!data || typeof data !== 'object' || data.error || data.goAway) return fail();
                    if (data.setupComplete) {
                        r.ready = true;
                        if (resources.length === 2 && resources.every(item => item.ready)) {
                            clearTimeout(setupDeadline); notify(onStatus, 'active');
                        }
                    }
                    segment(speaker, data.serverContent?.interimInputTranscription, onPartial);
                    segment(speaker, data.serverContent?.inputTranscription, onSegment);
                } catch { fail(); }
            };
            r.socket.onerror = r.socket.onclose = fail;
            r.node.port.onmessage = ({data}) => {
                if (stopped) return;
                try {
                    if (tracks[speaker].readyState !== 'live') return fail();
                    if (!r.ready || r.socket.readyState !== 1 || !tracks[speaker].enabled
                        || tracks[speaker].muted || !shouldSend(speaker)) return;
                    if (!(data instanceof ArrayBuffer) || data.byteLength !== 3200) return fail();
                    // Never accumulate an application queue or reconnect billable sessions.
                    if (r.socket.bufferedAmount > 65536) return fail();
                    const bytes = new Uint8Array(data);
                    let binary = ''; for (const byte of bytes) binary += String.fromCharCode(byte);
                    r.socket.send(JSON.stringify({ realtimeInput: { audio: { data: btoa(binary), mimeType: 'audio/pcm;rate=16000' } } }));
                } catch { fail(); }
            };
            r.source.connect(r.node); r.node.connect(context.destination);
        }
        lifetime = setTimeout(fail, Math.min(600000, Math.max(1000, Number(maxDurationMs) || 600000)));
        setupDeadline = setTimeout(fail, 15000);
        notify(onStatus, 'connecting');
        return { stop };
    } catch {
        stop(); throw new Error('Não foi possível iniciar o áudio do copiloto. A ligação continua normalmente.');
    }
}
