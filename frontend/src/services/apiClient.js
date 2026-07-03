const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api';

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
    throw new Error(data?.message || 'تعذّر الاتصال بالخادم');
  }

  return data;
}
