import http from 'k6/http';

export function jsonHeaders(token = '') {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  return headers;
}

export function requestParams({
  token = '',
  endpoint,
  operation,
  phase = 'load',
  timeout = '30s',
  expectedStatuses = [200],
}) {
  return {
    headers: jsonHeaders(token),
    timeout,
    tags: {
      endpoint,
      operation,
      phase,
      name: operation,
    },
    responseCallback: http.expectedStatuses(...expectedStatuses),
  };
}

export function logFailure(response, context, parsedBody = null, limit = 2) {
  if (__VU > limit || __ITER > limit) return;

  const code = parsedBody && typeof parsedBody.code === 'string'
    ? parsedBody.code.slice(0, 120)
    : 'unavailable';
  const message = parsedBody && typeof parsedBody.message === 'string'
    ? parsedBody.message.replace(/[\r\n]+/g, ' ').slice(0, 180)
    : 'No safe API error message';

  console.error(`[${context}] status=${response.status} code=${code} message=${message}`);
}
