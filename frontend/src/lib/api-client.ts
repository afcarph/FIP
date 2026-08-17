/**
 * Typed HTTP client for the FIP API.
 *
 * Two behaviours matter here and are easy to get wrong:
 *
 * 1. **Token refresh is serialised.** When several queries fire at once and
 *    the access token has expired, a naive implementation sends one refresh
 *    per request — the first succeeds, the rest present an already-rotated
 *    token and fail. A single in-flight refresh promise is shared instead.
 *
 * 2. **Errors keep their shape.** The API always answers with
 *    `{ success, error: { code, message, details } }`, so `ApiError` carries
 *    the machine-readable code through to the UI, which can react to
 *    `report_too_far` differently from `validation_failed`.
 */

export interface ApiEnvelope<T> {
  success: boolean;
  message?: string;
  data: T;
  meta?: {
    request_id?: string;
    timestamp?: string;
    pagination?: Pagination;
    [key: string]: unknown;
  };
}

export interface Pagination {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
}

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
    public readonly details: Record<string, string[]> = {},
    public readonly requestId?: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Field-level errors for wiring straight into a form. */
  get fieldErrors(): Record<string, string> {
    return Object.fromEntries(
      Object.entries(this.details).map(([field, messages]) => [field, messages[0] ?? '']),
    );
  }

  get isValidation(): boolean {
    return this.code === 'validation_failed';
  }

  get isAuth(): boolean {
    return this.status === 401 || this.code === 'unauthenticated';
  }
}

const BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';
const TOKEN_KEY = 'fip.access_token';
const DEVICE_KEY = 'fip.device_uuid';

export const tokenStore = {
  get(): string | null {
    if (typeof window === 'undefined') return null;
    return window.localStorage.getItem(TOKEN_KEY);
  },
  set(token: string): void {
    if (typeof window === 'undefined') return;
    window.localStorage.setItem(TOKEN_KEY, token);
  },
  clear(): void {
    if (typeof window === 'undefined') return;
    window.localStorage.removeItem(TOKEN_KEY);
  },
};

/** Stable per-browser identifier so the API can manage device sessions. */
export function deviceUuid(): string {
  if (typeof window === 'undefined') return 'server';

  let uuid = window.localStorage.getItem(DEVICE_KEY);

  if (!uuid) {
    uuid = crypto.randomUUID();
    window.localStorage.setItem(DEVICE_KEY, uuid);
  }

  return uuid;
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown;
  params?: Record<string, string | number | boolean | undefined | null>;
  skipAuth?: boolean;
  /** Internal: prevents an infinite refresh loop. */
  _retried?: boolean;
}

// Shared across concurrent 401s so only one refresh is ever in flight.
let refreshPromise: Promise<boolean> | null = null;

async function refreshToken(): Promise<boolean> {
  const token = tokenStore.get();
  if (!token) return false;

  try {
    const response = await fetch(`${BASE_URL}/auth/refresh`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });

    if (!response.ok) return false;

    const payload = (await response.json()) as ApiEnvelope<{ access_token: string }>;

    if (payload.data?.access_token) {
      tokenStore.set(payload.data.access_token);
      return true;
    }
  } catch {
    // Network failure — treat as a failed refresh and let the caller sign out.
  }

  return false;
}

function buildUrl(path: string, params?: RequestOptions['params']): string {
  const url = new URL(`${BASE_URL}${path.startsWith('/') ? path : `/${path}`}`);

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value));
      }
    }
  }

  return url.toString();
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<ApiEnvelope<T>> {
  const { body, params, skipAuth, _retried, headers, ...init } = options;

  const requestHeaders = new Headers({
    Accept: 'application/json',
    'X-Device-Id': deviceUuid(),
    ...(headers as Record<string, string>),
  });

  // FormData sets its own multipart boundary; setting Content-Type breaks it.
  const isFormData = body instanceof FormData;

  if (body !== undefined && !isFormData) {
    requestHeaders.set('Content-Type', 'application/json');
  }

  if (!skipAuth) {
    const token = tokenStore.get();
    if (token) requestHeaders.set('Authorization', `Bearer ${token}`);
  }

  const response = await fetch(buildUrl(path, params), {
    ...init,
    headers: requestHeaders,
    body: isFormData ? body : body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (response.status === 204) {
    return { success: true, data: null as T };
  }

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    // One refresh attempt, shared across every concurrent caller.
    if (response.status === 401 && !skipAuth && !_retried) {
      refreshPromise ??= refreshToken().finally(() => {
        refreshPromise = null;
      });

      const refreshed = await refreshPromise;

      if (refreshed) {
        return request<T>(path, { ...options, _retried: true });
      }

      tokenStore.clear();

      if (typeof window !== 'undefined' && !window.location.pathname.startsWith('/login')) {
        window.location.href = '/login';
      }
    }

    throw new ApiError(
      payload?.error?.code ?? 'request_failed',
      payload?.error?.message ?? `Request failed with status ${response.status}.`,
      response.status,
      payload?.error?.details ?? {},
      payload?.meta?.request_id,
    );
  }

  return payload as ApiEnvelope<T>;
}

export const api = {
  get: <T>(path: string, params?: RequestOptions['params'], options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'GET', params }),

  post: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'POST', body }),

  put: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'PUT', body }),

  patch: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'PATCH', body }),

  delete: <T>(path: string, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'DELETE' }),

  /** Multipart upload — used by the OCR scanner. */
  upload: <T>(path: string, formData: FormData, options?: RequestOptions) =>
    request<T>(path, { ...options, method: 'POST', body: formData }),

  /**
   * Fetch a file rather than JSON.
   *
   * Generated reports are streamed by the API behind the bearer token, so a
   * plain link cannot reach them — the browser would send no Authorization
   * header and get a 401. This does the fetch, and hands back the bytes plus
   * the filename the server chose in Content-Disposition.
   *
   * Errors still arrive as the usual JSON envelope, so they are unwrapped into
   * an ApiError like any other call. Token refresh is deliberately not retried
   * here: a download is always user-initiated, and clicking again is clearer
   * than a silent retry of a large transfer.
   */
  download: async (path: string): Promise<{ blob: Blob; filename: string | null }> => {
    const token = tokenStore.get();

    const response = await fetch(buildUrl(path), {
      headers: {
        Accept: 'application/octet-stream',
        'X-Device-Id': deviceUuid(),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => null);

      throw new ApiError(
        payload?.error?.code ?? 'download_failed',
        payload?.error?.message ?? `Download failed with status ${response.status}.`,
        response.status,
        payload?.error?.details ?? {},
        payload?.meta?.request_id,
      );
    }

    const disposition = response.headers.get('Content-Disposition');

    return {
      blob: await response.blob(),
      filename: disposition?.match(/filename="?([^";]+)"?/)?.[1] ?? null,
    };
  },
};
