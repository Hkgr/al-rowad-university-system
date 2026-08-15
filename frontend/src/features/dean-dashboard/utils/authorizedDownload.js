const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api'

function authToken() {
  return localStorage.getItem('token') || localStorage.getItem('auth_token')
}

export function resolveApiUrl(pathOrUrl) {
  if (!pathOrUrl) return null
  if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl
  if (pathOrUrl.startsWith('/api/')) {
    const origin = API_BASE_URL.replace(/\/api\/?$/, '')
    return `${origin}${pathOrUrl}`
  }
  const normalized = pathOrUrl.startsWith('/') ? pathOrUrl : `/${pathOrUrl}`
  return `${API_BASE_URL}${normalized}`
}

export async function fetchAuthorizedBlob(pathOrUrl) {
  const url = resolveApiUrl(pathOrUrl)
  if (!url) throw Object.assign(new Error('missing url'), { status: 404 })

  const token = authToken()
  const response = await fetch(url, {
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })

  if (!response.ok) {
    throw Object.assign(new Error('download failed'), { status: response.status })
  }

  return response.blob()
}

export async function downloadAuthorizedFile(pathOrUrl, fileName = 'document') {
  const blob = await fetchAuthorizedBlob(pathOrUrl)
  const objectUrl = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(objectUrl)
}
