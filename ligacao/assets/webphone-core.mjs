const stateViews = {
  disabled: {
    canActivate: true,
    canDial: false,
    canHangup: false,
    canMute: false,
    label: 'Telefone desativado',
  },
  connecting: {
    canActivate: false,
    canDial: false,
    canHangup: false,
    canMute: false,
    label: 'Conectando…',
  },
  ready: {
    canActivate: false,
    canDial: true,
    canHangup: false,
    canMute: false,
    label: 'Disponível',
  },
  dialing: {
    canActivate: false,
    canDial: false,
    canHangup: true,
    canMute: false,
    label: 'Chamando…',
  },
  ringing: {
    canActivate: false,
    canDial: false,
    canHangup: true,
    canMute: false,
    label: 'Chamando…',
  },
  active: {
    canActivate: false,
    canDial: false,
    canHangup: true,
    canMute: true,
    label: 'Em ligação',
  },
  ending: {
    canActivate: false,
    canDial: false,
    canHangup: false,
    canMute: false,
    label: 'Encerrando…',
  },
  error: {
    canActivate: true,
    canDial: false,
    canHangup: false,
    canMute: false,
    label: 'Falha de conexão',
  },
};

export function normalizePhoneInput(input) {
  const value = String(input ?? '').trim();
  const digits = value.replace(/\D/g, '');

  return digits ? `${value.startsWith('+') ? '+' : ''}${digits}` : '';
}

export function formatCallDuration(seconds) {
  const totalSeconds = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
  const minutes = Math.floor(totalSeconds / 60);
  const remainingSeconds = totalSeconds % 60;

  return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
}

export function isApi4ComIntegratedCall(call) {
  try {
    let headers;
    if (typeof call?.getCustomHeaders === 'function') {
      headers = call.getCustomHeaders();
    } else if (typeof call?._getSession === 'function') {
      headers = call._getSession()?._request?.headers;
    }
    if (!headers || typeof headers !== 'object') return false;

    const entry = Object.entries(headers).find(
      ([name]) => name.toLowerCase() === 'x-api4comintegratedcall',
    );
    if (!entry) return false;

    let value = entry[1];
    if (Array.isArray(value)) {
      value = value.map(item => item && typeof item === 'object' ? item.raw : item).join(',');
    } else if (value && typeof value === 'object') {
      value = value.raw;
    }
    return String(value ?? '').trim().toLowerCase() === 'true';
  } catch {
    return false;
  }
}

export function viewForState(state, hasCall) {
  if (!Object.hasOwn(stateViews, state)) {
    throw new RangeError(`Estado de telefone desconhecido: ${state}`);
  }

  const view = stateViews[state];

  if (!hasCall || !['dialing', 'ringing', 'active'].includes(state)) {
    return { ...view, canHangup: false, canMute: false };
  }

  return { ...view };
}
