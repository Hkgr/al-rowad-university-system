const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api';

export class ApiError extends Error {
  constructor(message, status, data) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.data = data
  }
}

export async function apiRequest(path, options = {}) {
  const token = localStorage.getItem('token') || localStorage.getItem('auth_token');
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;

  const response = await fetch(`${API_BASE_URL}${normalizedPath}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers || {}),
    },
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    if (
      normalizedPath !== '/login'
      && (response.status === 401 || (response.status === 403 && data?.errors?.account))
    ) {
      window.dispatchEvent(new Event('auth:unauthorized'))
    }

    throw new ApiError(
      data?.message || 'تعذّر الاتصال بالخادم',
      response.status,
      data,
    )
  }

  return data;
}
