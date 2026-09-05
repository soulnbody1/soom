import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';

import { homeWorkload } from './config/profiles.js';
import { settings } from './config/settings.js';
import {
  durationEnv,
  intEnv,
  normalizeBaseUrl,
  normalizeEndpoint,
  numberEnv,
  safeRunId,
  stringEnv,
  testProfile,
} from './lib/env.js';
import { logFailure, requestParams } from './lib/http.js';
import { isJsonResponse, isSuccessfulEnvelope, parseJson } from './lib/response.js';
import { recordActiveVUs } from './lib/runtime.js';
import { summaryHandler } from './lib/summary.js';

const homeRequestFailed = new Rate('home_request_failed');
const homeResponses = new Counter('home_responses');
const homeStatus200 = new Counter('home_status_200');

const profile = testProfile();
const baseUrl = normalizeBaseUrl();
const endpoint = normalizeEndpoint('HOME_ENDPOINT', settings.home.endpoint);
const timeout = durationEnv('REQUEST_TIMEOUT', settings.requestTimeout);
const token = stringEnv('HOME_AUTH_TOKEN', stringEnv('AUTH_TOKEN', settings.home.authToken));
const workload = homeWorkload(profile);
const runId = safeRunId('soom-home', profile);

const maxFailureRate = numberEnv('HOME_MAX_FAILURE_RATE', settings.home.thresholds.maxFailureRate, { min: 0, max: 1 });
const p90Ms = intEnv('HOME_P90_MS', settings.home.thresholds.p90Ms, { min: 1 });
const p95Ms = intEnv('HOME_P95_MS', settings.home.thresholds.p95Ms, { min: 1 });
const p99Ms = intEnv('HOME_P99_MS', settings.home.thresholds.p99Ms, { min: 1 });
const checksRate = numberEnv('MIN_CHECKS_RATE', settings.quality.minChecksRate, { min: 0, max: 1 });

export const options = {
  scenarios: workload.scenarios,
  setupTimeout: '1m',
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)', 'count'],
  systemTags: ['status', 'method', 'name', 'scenario', 'expected_response'],
  thresholds: {
    checks: [`rate>${checksRate}`],
    home_request_failed: [`rate<${maxFailureRate}`],
    'http_reqs{phase:load}': ['count>0'],
    'http_req_failed{phase:load}': [`rate<${maxFailureRate}`],
    'http_req_duration{endpoint:soom_home,phase:load}': [
      `p(90)<${p90Ms}`,
      `p(95)<${p95Ms}`,
      `p(99)<${p99Ms}`,
    ],
    dropped_iterations: ['count==0'],
    observed_active_vus: ['value>=0'],
  },
};

function homeRequest(phase) {
  return http.get(`${baseUrl}${endpoint}`, requestParams({
    token,
    endpoint: 'soom_home',
    operation: 'GET /api/soom/home',
    phase,
    timeout,
    expectedStatuses: [200],
  }));
}

function validHomeResponse(response, body) {
  return response.status === 200
    && isJsonResponse(response)
    && isSuccessfulEnvelope(body);
}

export function setup() {
  const response = homeRequest('preflight');
  const body = parseJson(response);
  if (!validHomeResponse(response, body)) {
    logFailure(response, 'home preflight', body);
    throw new Error('Home preflight failed. No load was generated.');
  }
  return { warmedAt: new Date().toISOString() };
}

export function browseHome() {
  recordActiveVUs();
  const response = homeRequest('load');
  const body = parseJson(response);
  const valid = validHomeResponse(response, body);

  homeResponses.add(1);
  homeStatus200.add(response.status === 200 ? 1 : 0);
  homeRequestFailed.add(!valid);

  check(response, {
    'home status is 200': (res) => res.status === 200,
    'home response is JSON': (res) => isJsonResponse(res),
    'home response envelope is valid': () => isSuccessfulEnvelope(body),
  }, { endpoint: 'soom_home', phase: 'load' });

  if (!valid) logFailure(response, 'home load', body);
}

export const handleSummary = summaryHandler({
  testName: 'soom-home',
  title: 'Home API',
  profile,
  target: `${baseUrl}${endpoint}`,
  workload: workload.description,
  runId,
  kind: 'home',
  thresholdDescription: `checks>${(checksRate * 100).toFixed(2)}%, failures<${(maxFailureRate * 100).toFixed(2)}%, p90<${p90Ms}ms, p95<${p95Ms}ms, p99<${p99Ms}ms, dropped=0`,
  latencyMetrics: [
    { label: 'Home', metricName: 'http_req_duration{endpoint:soom_home,phase:load}' },
  ],
});
