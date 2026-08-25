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
