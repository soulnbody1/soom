import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Counter } from 'k6/metrics';

const homeRequestFailed = new Rate('home_request_failed');
const homeStatus200 = new Counter('home_status_200');
const homeUnexpectedStatus = new Counter('home_unexpected_status');

export const options = {
  scenarios: {
    home_browse: {
      executor: 'ramping-vus',

      startVUs: 1,

      stages: [
        // تسخين بسيط
        { duration: '15s', target: 10 },

        // زيادة تدريجية
        { duration: '30s', target: 25 },
        { duration: '30s', target: 50 },
        { duration: '30s', target: 75 },
        { duration: '30s', target: 100 },

        // تثبيت 100 مستخدم متزامن لمدة دقيقة
        { duration: '1m', target: 100 },

        // نزول تدريجي
        { duration: '30s', target: 50 },
        { duration: '15s', target: 0 },
      ],

      gracefulRampDown: '15s',
      gracefulStop: '30s',

      exec: 'browseHome',
    },
  },

  thresholds: {
    // نسبة نجاح الـ checks يجب أن تكون أعلى من 98%
    checks: ['rate>0.98'],

    // نسبة فشل endpoint نفسها أقل من 2%
    home_request_failed: ['rate<0.02'],

    // نسبة فشل كل HTTP requests أقل من 2%
    http_req_failed: ['rate<0.02'],

    // حدود زمن الاستجابة
    'http_req_duration{endpoint:soom_home}': [
      'p(90)<1000',
      'p(95)<2000',
      'p(99)<4000',
    ],
  },
};

const baseUrl = (
  __ENV.BASE_URL || 'http://127.0.0.1:8000'
).replace(/\/+$/, '');

const endpoint = __ENV.ENDPOINT || '/api/soom/home';
const token = __ENV.AUTH_TOKEN || '';

export function browseHome() {
  const headers = {
    Accept: 'application/json',
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = http.get(`${baseUrl}${endpoint}`, {
    headers,

    tags: {
      endpoint: 'soom_home',
    },

    timeout: '30s',
  });

  const isSuccess = response.status === 200;

  homeRequestFailed.add(!isSuccess);

  if (isSuccess) {
    homeStatus200.add(1);
  } else {
    homeUnexpectedStatus.add(1);

    // طباعة عينات محدودة من الأخطاء فقط
    if (__VU <= 2 && __ITER <= 2) {
      console.error(
        `Unexpected response: status=${response.status}, body=${String(
          response.body
        ).slice(0, 500)}`
      );
    }
  }

  check(response, {
    'home status is 200': (res) => res.status === 200,

    'home response is JSON': (res) =>
      String(res.headers['Content-Type'] || '')
        .toLowerCase()
        .includes('application/json'),

    'home response body is not empty': (res) =>
      typeof res.body === 'string' && res.body.length > 2,
  });

  // كل مستخدم ينتظر ثانية قبل إرسال الطلب التالي
  sleep(1);
}

