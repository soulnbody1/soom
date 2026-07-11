import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    browse: {
      executor: 'constant-vus',
      vus: 20,
      duration: '1m',
      exec: 'browseAuctions',
    },
    hot_bid: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '30s', target: 25 },
        { duration: '1m', target: 50 },
        { duration: '30s', target: 0 },
      ],
      exec: 'placeBid',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const auctionId = __ENV.AUCTION_ID;
const token = __ENV.AUTH_TOKEN;

export function browseAuctions() {
  const res = http.get(`${baseUrl}/api/auctions`);
  check(res, { 'auction list ok': (r) => r.status === 200 });
  sleep(1);
}

export function placeBid() {
  if (!auctionId || !token) {
    return;
  }

  const minor = 10000 + Math.floor(Math.random() * 500000);
  const amountText = String(minor).padStart(3, '0');
  const amount = `${amountText.slice(0, -2)}.${amountText.slice(-2)}`;
  const payload = JSON.stringify({
    amount,
    currency_code: __ENV.CURRENCY || 'JOD',
    idempotency_key: `${__VU}-${__ITER}-${Date.now()}`,
    client_request_id: `${__VU}-${__ITER}`,
  });

  const res = http.post(`${baseUrl}/api/soom/auctions/${auctionId}/bids`, payload, {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
  });

  check(res, {
    'bid accepted': (r) => r.status === 201,
  });
}
