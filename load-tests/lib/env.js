import { settings } from '../config/settings.js';

const TRUE_VALUES = ['1', 'true', 'yes', 'on'];
const FALSE_VALUES = ['0', 'false', 'no', 'off'];

// Names are intentionally listed without values so reports can reveal an
// accidental override without ever printing credentials or tokens.
const OVERRIDABLE_ENV_NAMES = [
  'TEST_PROFILE', 'BASE_URL', 'ALLOW_REMOTE_TARGET', 'CONFIRM_AUCTION_MUTATION',
  'REQUEST_TIMEOUT', 'MIN_CHECKS_RATE', 'TEST_DURATION', 'RAMP_UP_DURATION',
  'STAGE_DURATION', 'RAMP_DOWN_DURATION', 'HOME_ENDPOINT', 'HOME_AUTH_TOKEN',
  'AUTH_TOKEN', 'HOME_TARGET_RPS', 'HOME_PREALLOCATED_VUS', 'HOME_MAX_FAILURE_RATE',
  'HOME_P90_MS', 'HOME_P95_MS', 'HOME_P99_MS', 'AUCTION_ID', 'BIDDER_TOKENS_FILE',
  'AUCTION_BROWSE_TARGET_RPS', 'AUCTION_BID_TARGET_RPM',
  'AUCTION_BROWSE_PREALLOCATED_VUS', 'AUCTION_BID_PREALLOCATED_VUS',
  'AUCTION_MAX_FAILURE_RATE', 'AUCTION_P95_MS', 'AUCTION_P99_MS', 'NO_COLOR',
  'DISABLE_SUMMARY_FILE', 'SUMMARY_PATH', 'RUN_ID',
];

export function environmentOverrideNames() {
  return OVERRIDABLE_ENV_NAMES.filter((name) => __ENV[name] !== undefined);
}

export function stringEnv(name, fallback = '') {
  const value = __ENV[name];
  return value === undefined || String(value).trim() === ''
    ? fallback
    : String(value).trim();
}

export function boolEnv(name, fallback = false) {
  const raw = stringEnv(name, fallback ? 'true' : 'false').toLowerCase();
  if (TRUE_VALUES.includes(raw)) return true;
  if (FALSE_VALUES.includes(raw)) return false;
  throw new Error(`${name} must be true or false; received "${raw}".`);
}

export function intEnv(name, fallback, { min = 1, max = Number.MAX_SAFE_INTEGER } = {}) {
  const raw = stringEnv(name, String(fallback));
  if (!/^\d+$/.test(raw)) {
    throw new Error(`${name} must be an integer; received "${raw}".`);
  }

  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value < min || value > max) {
    throw new Error(`${name} must be between ${min} and ${max}; received ${raw}.`);
  }
  return value;
}

export function numberEnv(name, fallback, { min = 0, max = Number.MAX_VALUE } = {}) {
  const raw = stringEnv(name, String(fallback));
  const value = Number(raw);
  if (!Number.isFinite(value) || value < min || value > max) {
    throw new Error(`${name} must be between ${min} and ${max}; received "${raw}".`);
  }
  return value;
}

export function durationEnv(name, fallback) {
  const value = stringEnv(name, fallback);
  if (!/^\d+(?:\.\d+)?(?:ms|s|m|h)$/.test(value)) {
    throw new Error(`${name} must be a k6 duration such as 30s, 3m, or 1h; received "${value}".`);
  }
  return value;
}

export function testProfile() {
  const profile = stringEnv('TEST_PROFILE', settings.profile).toLowerCase();
  if (!['smoke', 'load', 'stress'].includes(profile)) {
    throw new Error(`TEST_PROFILE must be smoke, load, or stress; received "${profile}".`);
  }
  return profile;
}

export function normalizeBaseUrl() {
  const value = stringEnv('BASE_URL', settings.baseUrl).replace(/\/+$/, '');
  const parsed = value.match(/^https?:\/\/(\[[^\]]+\]|[^/:?#]+)(?::\d+)?(?:[/?#]|$)/i);
  if (!parsed) {
    throw new Error(`BASE_URL is not a valid URL: "${value}".`);
  }

  const localHosts = ['127.0.0.1', 'localhost', '::1'];
  const hostname = parsed[1].replace(/^\[|\]$/g, '').toLowerCase();
  if (!localHosts.includes(hostname) && !boolEnv('ALLOW_REMOTE_TARGET', settings.safety.allowRemoteTarget)) {
    throw new Error(
      `Remote target "${hostname}" is blocked. Set ALLOW_REMOTE_TARGET=true only for an approved performance environment.`,
    );
  }

  return value;
}

export function hostnameFromBaseUrl(value) {
  const parsed = value.match(/^https?:\/\/(\[[^\]]+\]|[^/:?#]+)(?::\d+)?(?:[/?#]|$)/i);
  if (!parsed) throw new Error(`Cannot extract hostname from BASE_URL "${value}".`);
  return parsed[1].replace(/^\[|\]$/g, '').toLowerCase();
}

export function normalizeEndpoint(name, fallback) {
  const value = stringEnv(name, fallback);
  return value.startsWith('/') ? value : `/${value}`;
}

export function safeRunId(testName, profile) {
  const fallback = `${testName}-${profile}-${new Date().toISOString().replace(/[:.]/g, '-')}`;
  const value = stringEnv('RUN_ID', fallback);
  const safe = value.replace(/[^A-Za-z0-9_-]/g, '-').slice(0, 80);
  if (!safe) throw new Error('RUN_ID does not contain any safe filename characters.');
  return safe;
}
