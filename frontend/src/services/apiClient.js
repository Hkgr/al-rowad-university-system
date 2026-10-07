const API_BASE_URL =
  import.meta.env?.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api';

export async function apiRequest(path, options = {}) {
  const token = localStorage.getItem('token') || localStorage.getItem('auth_token');
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const headers = {
    Accept: 'application/json',
    ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  const response = await fetch(`${API_BASE_URL}${normalizedPath}`, {
    ...options,
    headers,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(data?.message || 'تعذّر الاتصال بالخادم');
    error.status = response.status;
    error.errorCode = data?.error_code;
    error.details = data?.errors ?? data?.data ?? {};
    error.itemFailures = data?.item_failures ?? [];
    error.coverage = data?.coverage ?? null;
    throw error;
  }

  return data;
}

// Authenticated binary download (exports). Same base URL, token and error contract as apiRequest.
export async function apiDownload(path) {
  const token = localStorage.getItem('token') || localStorage.getItem('auth_token');
  const response = await fetch(`${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`, {
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  if (!response.ok) {
    const data = await response.json().catch(() => null);
    const error = new Error(data?.message || 'تعذّر إنشاء الملف');
    error.status = response.status;
    error.errorCode = data?.error_code;
    error.details = data?.errors ?? data?.data ?? {};
    throw error;
  }
  const disposition = response.headers.get('Content-Disposition') || '';
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  return { blob: await response.blob(), filename: match ? decodeURIComponent(match[1]) : null };
}
