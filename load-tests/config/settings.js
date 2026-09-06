// Edit this file once, then run:
// k6 run .\load-tests\soom-home.k6.js
//
// Environment variables remain available as optional overrides for CI or
// one-off runs, but they are not required for normal local execution.
export const settings = {
  profile: 'load',
  baseUrl: 'http://127.0.0.1:8000',
  requestTimeout: '30s',

  safety: {
    allowRemoteTarget: false,
    confirmAuctionMutation: false,
  },

  report: {
    noColor: false,
    saveJson: true,
    outputDirectory: 'load-tests/results',
  },

  quality: {
    minChecksRate: 0.98,
  },

  home: {
    endpoint: '/api/soom/home',
    authToken: '',
    thresholds: {
      maxFailureRate: 0.02,
      p90Ms: 1000,
      p95Ms: 2000,
      p99Ms: 4000,
    },
    load: {
      targetRps: 1,
      duration: '1m',
      preAllocatedVUs: 20,
    },
    stress: {
      targetRps: 100,
      preAllocatedVUs: 250,
      rampUpDuration: '30s',
      stageDuration: '1m',
      rampDownDuration: '30s',
    },
  },

  auction: {
    // Required before running the auction test.
    id: '',
    biddersFile: './data/bidders.local.json',
    authToken: '', // Optional smoke-only fallback. Prefer bidders.local.json.
    thresholds: {
      maxFailureRate: 0.02,
      p95Ms: 500,
      p99Ms: 1000,
    },
    load: {
      browseTargetRps: 10,
      bidTargetRpm: 20,
      duration: '3m',
      browsePreAllocatedVUs: 30,
      bidPreAllocatedVUs: 5,
    },
    stress: {
      browseTargetRps: 50,
      bidTargetRpm: 100,
      browsePreAllocatedVUs: 150,
      bidPreAllocatedVUs: 10,
      rampUpDuration: '30s',
      stageDuration: '1m',
      rampDownDuration: '30s',
    },
  },
};
