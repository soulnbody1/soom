export function parseJson(response) {
  try {
    return response.json();
  } catch (_) {
    return null;
  }
}

export function isJsonResponse(response) {
  return String(response.headers['Content-Type'] || '')
    .toLowerCase()
    .includes('application/json');
}

export function isSuccessfulEnvelope(body) {
  return body !== null
    && typeof body === 'object'
    && body.success === true
    && Object.prototype.hasOwnProperty.call(body, 'data');
}

export function errorCode(body) {
  return body && typeof body.code === 'string' ? body.code : '';
}

export function validMoney(value) {
  return value !== null
    && typeof value === 'object'
    && typeof value.amount === 'string'
    && Number.isInteger(value.minor)
    && typeof value.currency === 'string';
}
