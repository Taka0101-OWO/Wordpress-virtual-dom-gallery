const sessionKey = 'taka-gallery-session';
let memorySession = '';

function createSessionId(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function sessionId(): string {
  try {
    let value = sessionStorage.getItem(sessionKey);
    if (!value) {
      value = createSessionId();
      sessionStorage.setItem(sessionKey, value);
    }
    return value;
  } catch {
    memorySession ||= createSessionId();
    return memorySession;
  }
}

export async function apiFetch<T>(baseUrl: string, path: string, init: RequestInit = {}, nonce?: string): Promise<T> {
  const response = await fetch(`${baseUrl}${path}`, {
    credentials: 'same-origin',
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-Taka-Session': sessionId(),
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
      ...init.headers,
    },
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(payload.message || `Request failed (${response.status})`);
  }
  return payload as T;
}
