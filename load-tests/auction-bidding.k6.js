import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';
import { Counter, Rate } from 'k6/metrics';

import { auctionWorkload } from './config/profiles.js';
import { settings } from './config/settings.js';
import {
  boolEnv,
  durationEnv,
  hostnameFromBaseUrl,
  intEnv,
  normalizeBaseUrl,
  numberEnv,
  safeRunId,
  stringEnv,
  testProfile,
} from './lib/env.js';
import { logFailure, requestParams } from './lib/http.js';
import {
  errorCode,
  isJsonResponse,
  isSuccessfulEnvelope,
  parseJson,
  validMoney,
} from './lib/response.js';
import { recordActiveVUs } from './lib/runtime.js';
import { summaryHandler } from './lib/summary.js';

const auctionBrowseFailed = new Rate('auction_browse_failed');
const auctionFlowFailed = new Rate('auction_flow_failed');
const bidAttempts = new Counter('auction_bid_attempts');
const bidAccepted = new Counter('auction_bid_accepted');
const bidContention = new Counter('auction_bid_contention');
const rateLimited = new Counter('auction_rate_limited');
const unexpectedErrors = new Counter('auction_unexpected_errors');

const profile = testProfile();
const baseUrl = normalizeBaseUrl();
const auctionId = stringEnv('AUCTION_ID', settings.auction.id);
const timeout = durationEnv('REQUEST_TIMEOUT', settings.requestTimeout);
const runId = safeRunId('auction-hot-bid', profile);
const workload = auctionWorkload(profile);
const tokenFile = stringEnv('BIDDER_TOKENS_FILE', settings.auction.biddersFile);
const legacyToken = stringEnv('AUTH_TOKEN', settings.auction.authToken);

if (!auctionId) throw new Error('AUCTION_ID is required.');

const remoteHost = hostnameFromBaseUrl(baseUrl);
if (!['127.0.0.1', 'localhost', '::1'].includes(remoteHost)
  && !boolEnv('CONFIRM_AUCTION_MUTATION', settings.safety.confirmAuctionMutation)) {
  throw new Error('Remote auction writes are blocked. Set CONFIRM_AUCTION_MUTATION=true for an approved test auction.');
}

if (!tokenFile && !(profile === 'smoke' && legacyToken)) {
  throw new Error('BIDDER_TOKENS_FILE is required for load/stress; AUTH_TOKEN is accepted only for smoke.');
}

const bidders = new SharedArray('soom auction bidders', () => {
  let source;
  if (tokenFile) {
    try {
      source = JSON.parse(open(tokenFile));
    } catch (error) {
      const missingFile = /not found|cannot find|no such file|does not exist/i.test(error.message);
      if (profile === 'smoke' && legacyToken && missingFile) {
        source = [{ name: 'legacy-smoke-bidder', token: legacyToken }];
      } else {
        throw new Error(`Unable to parse BIDDER_TOKENS_FILE: ${error.message}`);
      }
    }
  } else {
    source = [{ name: 'legacy-smoke-bidder', token: legacyToken }];
  }

  if (!Array.isArray(source) || source.length === 0) {
    throw new Error('BIDDER_TOKENS_FILE must contain a non-empty JSON array.');
  }

  const normalized = source.map((bidder, index) => {
    if (!bidder || typeof bidder !== 'object') {
      throw new Error(`Bidder at index ${index} must be an object.`);
    }
    const name = typeof bidder.name === 'string' && bidder.name.trim()
      ? bidder.name.trim().slice(0, 80)
      : `bidder-${index + 1}`;
    const token = typeof bidder.token === 'string' ? bidder.token.trim() : '';
    if (!token) throw new Error(`Bidder "${name}" has an empty token.`);
    return { name, token };
  });

  if (new Set(normalized.map((bidder) => bidder.token)).size !== normalized.length) {
    throw new Error('Every bidder must have a unique token.');
  }
  return normalized;
});

if (bidders.length < workload.minimumTokens) {
  throw new Error(
    `${profile} requires at least ${workload.minimumTokens} unique bidder tokens; received ${bidders.length}.`,
  );
}

const maxFailureRate = numberEnv('AUCTION_MAX_FAILURE_RATE', settings.auction.thresholds.maxFailureRate, { min: 0, max: 1 });
const p95Ms = intEnv('AUCTION_P95_MS', settings.auction.thresholds.p95Ms, { min: 1 });
const p99Ms = intEnv('AUCTION_P99_MS', settings.auction.thresholds.p99Ms, { min: 1 });
const checksRate = numberEnv('MIN_CHECKS_RATE', settings.quality.minChecksRate, { min: 0, max: 1 });

export const options = {
  scenarios: workload.scenarios,
  setupTimeout: '2m',
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)', 'count'],
  systemTags: ['status', 'method', 'name', 'scenario', 'expected_response'],
  thresholds: {
    checks: [`rate>${checksRate}`],
    auction_browse_failed: [`rate<${maxFailureRate}`],
    auction_flow_failed: [`rate<${maxFailureRate}`],
    auction_rate_limited: ['count==0'],
    auction_bid_accepted: ['count>0'],
    'http_reqs{phase:load}': ['count>0'],
    'http_req_failed{phase:load}': [`rate<${maxFailureRate}`],
    'http_req_duration{endpoint:auction_list,phase:load}': [
      `p(95)<${p95Ms}`,
      `p(99)<${p99Ms}`,
    ],
    'http_req_duration{endpoint:auction_detail,phase:load}': [
      `p(95)<${p95Ms}`,
      `p(99)<${p99Ms}`,
    ],
    'http_req_duration{endpoint:auction_bid,phase:load}': [
      `p(95)<${p95Ms}`,
      `p(99)<${p99Ms}`,
    ],
    dropped_iterations: ['count==0'],
    observed_active_vus: ['value>=0'],
  },
};

function detailRequest(token, phase) {
  return http.get(`${baseUrl}/api/auctions/${auctionId}`, requestParams({
    token,
    endpoint: 'auction_detail',
    operation: 'GET /api/auctions/:auction',
    phase,
    timeout,
    expectedStatuses: [200],
  }));
}

function validAuctionForBid(response, body) {
  const data = body && body.data;
  return response.status === 200
    && isJsonResponse(response)
    && isSuccessfulEnvelope(body)
    && data.status === 'live'
    && data.my_participation
    && data.my_participation.can_bid === true
    && validMoney(data.minimum_next_bid)
    && typeof data.currency_code === 'string'
    && data.minimum_next_bid.currency === data.currency_code;
}

export function setup() {
  const failures = [];
  for (const bidder of bidders) {
    const response = detailRequest(bidder.token, 'preflight');
    const body = parseJson(response);
    if (!validAuctionForBid(response, body)) {
      failures.push(`${bidder.name}: status=${response.status}, code=${errorCode(body) || 'invalid_contract_or_not_eligible'}`);
    }
  }

  if (failures.length) {
    throw new Error(`Auction preflight failed for ${failures.length} bidder(s): ${failures.slice(0, 5).join('; ')}`);
  }

  return {
    auctionId,
    bidderCount: bidders.length,
    verifiedAt: new Date().toISOString(),
  };
}

export function browseAuctions() {
  recordActiveVUs();
  const response = http.get(`${baseUrl}/api/auctions`, requestParams({
    endpoint: 'auction_list',
    operation: 'GET /api/auctions',
    phase: 'load',
    timeout,
    expectedStatuses: [200],
  }));
  const body = parseJson(response);
  const valid = response.status === 200
    && isJsonResponse(response)
    && isSuccessfulEnvelope(body)
    && Array.isArray(body.data);

  auctionBrowseFailed.add(!valid);
  check(response, {
    'auction list status is 200': (res) => res.status === 200,
    'auction list envelope is valid': () => valid,
  }, { endpoint: 'auction_list', phase: 'load' });

  if (!valid) logFailure(response, 'auction list', body);
}

export function placeBid() {
  recordActiveVUs();
  const iteration = exec.scenario.iterationInTest;
  const bidder = bidders[iteration % bidders.length];
  const detailResponse = detailRequest(bidder.token, 'load');
  const detailBody = parseJson(detailResponse);

  if (!validAuctionForBid(detailResponse, detailBody)) {
    auctionFlowFailed.add(true);
    unexpectedErrors.add(1);
    check(detailResponse, {
      'auction detail is bid-ready': () => false,
    }, { endpoint: 'auction_detail', phase: 'load' });
    logFailure(detailResponse, 'auction bid detail', detailBody);
    return;
  }

  check(detailResponse, {
    'auction detail is bid-ready': () => true,
  }, { endpoint: 'auction_detail', phase: 'load' });

  const money = detailBody.data.minimum_next_bid;
  const requestKey = `${runId.slice(0, 55)}-${exec.vu.idInTest}-${iteration}`;
  const payload = JSON.stringify({
    amount: money.amount,
    currency_code: detailBody.data.currency_code,
    idempotency_key: `k6-${requestKey}`,
    client_request_id: `k6-${requestKey}`,
  });

  const response = http.post(
    `${baseUrl}/api/soom/auctions/${auctionId}/bids`,
    payload,
    requestParams({
      token: bidder.token,
      endpoint: 'auction_bid',
      operation: 'POST /api/soom/auctions/:auction/bids',
      phase: 'load',
      timeout,
      expectedStatuses: [201, 422],
    }),
  );
  const body = parseJson(response);
  const code = errorCode(body);
  const accepted = response.status === 201
    && isJsonResponse(response)
    && isSuccessfulEnvelope(body);
  const contention = response.status === 422 && code === 'bid_below_minimum';
  const limited = response.status === 429;
  const unexpected = !accepted && !contention && !limited;

  bidAttempts.add(1);
  if (accepted) bidAccepted.add(1);
  if (contention) bidContention.add(1);
  if (limited) rateLimited.add(1);
  if (unexpected) unexpectedErrors.add(1);
  auctionFlowFailed.add(limited || unexpected);

  check(response, {
    'bid is accepted or lost to valid contention': () => accepted || contention,
  }, { endpoint: 'auction_bid', phase: 'load' });

  if (limited || unexpected) logFailure(response, 'auction bid', body);
}

export const handleSummary = summaryHandler({
  testName: 'auction-hot-bid',
  title: 'Hot Auction Bidding',
  profile,
  target: `${baseUrl}/api/soom/auctions/${auctionId}/bids`,
  workload: `${workload.description}; bidders=${bidders.length}`,
  runId,
  kind: 'auction',
  thresholdDescription: `checks>${(checksRate * 100).toFixed(2)}%, failures<${(maxFailureRate * 100).toFixed(2)}%, p95<${p95Ms}ms, p99<${p99Ms}ms, 429=0, dropped=0, accepted>0`,
  latencyMetrics: [
    { label: 'Auction list', metricName: 'http_req_duration{endpoint:auction_list,phase:load}' },
    { label: 'Auction detail', metricName: 'http_req_duration{endpoint:auction_detail,phase:load}' },
    { label: 'Place bid', metricName: 'http_req_duration{endpoint:auction_bid,phase:load}' },
  ],
});
