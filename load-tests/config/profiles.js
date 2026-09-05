import { durationEnv, intEnv } from '../lib/env.js';
import { settings } from './settings.js';

function commonScenario(exec, extra) {
  return {
    exec,
    gracefulStop: '30s',
    tags: { phase: 'load' },
    ...extra,
  };
}

function rampTargets(maximum) {
  return {
    quarter: Math.max(1, Math.round(maximum * 0.25)),
    half: Math.max(1, Math.round(maximum * 0.5)),
    maximum,
  };
}

export function homeWorkload(profile) {
  if (profile === 'smoke') {
    return {
      description: '1 request (smoke)',
      scenarios: {
        home: commonScenario('browseHome', {
          executor: 'shared-iterations',
          vus: 1,
          iterations: 1,
          maxDuration: '1m',
        }),
      },
    };
  }

  const profileSettings = settings.home[profile];
  const target = intEnv('HOME_TARGET_RPS', profileSettings.targetRps, { max: 100000 });
  const preAllocatedVUs = intEnv(
    'HOME_PREALLOCATED_VUS',
    profileSettings.preAllocatedVUs,
    { max: 1000000 },
  );

  if (profile === 'load') {
    const duration = durationEnv('TEST_DURATION', profileSettings.duration);
    return {
      description: `${target} req/s for ${duration}`,
      scenarios: {
        home: commonScenario('browseHome', {
          executor: 'constant-arrival-rate',
          rate: target,
          timeUnit: '1s',
          duration,
          preAllocatedVUs,
        }),
      },
    };
  }

  const targets = rampTargets(target);
  const rampUp = durationEnv('RAMP_UP_DURATION', profileSettings.rampUpDuration);
  const stageDuration = durationEnv('STAGE_DURATION', profileSettings.stageDuration);
  const rampDown = durationEnv('RAMP_DOWN_DURATION', profileSettings.rampDownDuration);
  return {
    description: `${targets.quarter} → ${targets.half} → ${targets.maximum} req/s`,
    scenarios: {
      home: commonScenario('browseHome', {
        executor: 'ramping-arrival-rate',
        startRate: 1,
        timeUnit: '1s',
        preAllocatedVUs,
        stages: [
          { duration: rampUp, target: targets.quarter },
          { duration: stageDuration, target: targets.half },
          { duration: stageDuration, target: targets.maximum },
          { duration: rampDown, target: 0 },
        ],
      }),
    },
  };
}

export function auctionWorkload(profile) {
  if (profile === 'smoke') {
    return {
      description: '1 browse + 1 bid flow (smoke)',
      minimumTokens: 1,
      maximumBidRatePerMinute: 1,
      scenarios: {
        auction_browse: commonScenario('browseAuctions', {
          executor: 'shared-iterations', vus: 1, iterations: 1, maxDuration: '1m',
        }),
        hot_bid: commonScenario('placeBid', {
          executor: 'shared-iterations', vus: 1, iterations: 1, maxDuration: '1m',
        }),
      },
    };
  }

  const profileSettings = settings.auction[profile];
  const browseTarget = intEnv('AUCTION_BROWSE_TARGET_RPS', profileSettings.browseTargetRps, {
    max: 100000,
  });
  const bidTarget = intEnv('AUCTION_BID_TARGET_RPM', profileSettings.bidTargetRpm, {
    max: 100000,
  });
  const browseVUs = intEnv(
    'AUCTION_BROWSE_PREALLOCATED_VUS',
    profileSettings.browsePreAllocatedVUs,
    { max: 1000000 },
  );
  const bidVUs = intEnv(
    'AUCTION_BID_PREALLOCATED_VUS',
    profileSettings.bidPreAllocatedVUs,
    { max: 1000000 },
  );

  if (profile === 'load') {
    const duration = durationEnv('TEST_DURATION', profileSettings.duration);
    return {
      description: `${browseTarget} browse req/s + ${bidTarget} bid flows/min for ${duration}`,
      minimumTokens: Math.max(2, Math.ceil(bidTarget / 30)),
      maximumBidRatePerMinute: bidTarget,
      scenarios: {
        auction_browse: commonScenario('browseAuctions', {
          executor: 'constant-arrival-rate', rate: browseTarget, timeUnit: '1s', duration,
          preAllocatedVUs: browseVUs,
        }),
        hot_bid: commonScenario('placeBid', {
          executor: 'constant-arrival-rate', rate: bidTarget, timeUnit: '1m', duration,
          preAllocatedVUs: bidVUs,
        }),
      },
    };
  }

  const browse = {
    quarter: Math.max(1, Math.round(browseTarget * 0.2)),
    half: Math.max(1, Math.round(browseTarget * 0.5)),
    maximum: browseTarget,
  };
  const bid = {
    quarter: Math.max(1, Math.round(bidTarget * 0.3)),
    half: Math.max(1, Math.round(bidTarget * 0.6)),
    maximum: bidTarget,
  };
  const rampUp = durationEnv('RAMP_UP_DURATION', profileSettings.rampUpDuration);
  const stageDuration = durationEnv('STAGE_DURATION', profileSettings.stageDuration);
  const rampDown = durationEnv('RAMP_DOWN_DURATION', profileSettings.rampDownDuration);
  return {
    description: `browse ${browse.quarter} → ${browse.half} → ${browse.maximum} req/s; bids ${bid.quarter} → ${bid.half} → ${bid.maximum}/min`,
    minimumTokens: Math.max(4, Math.ceil(bidTarget / 30)),
    maximumBidRatePerMinute: bidTarget,
    scenarios: {
      auction_browse: commonScenario('browseAuctions', {
        executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s',
        preAllocatedVUs: browseVUs,
        stages: [
          { duration: rampUp, target: browse.quarter },
          { duration: stageDuration, target: browse.half },
          { duration: stageDuration, target: browse.maximum },
          { duration: rampDown, target: 0 },
        ],
      }),
      hot_bid: commonScenario('placeBid', {
        executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1m',
        preAllocatedVUs: bidVUs,
        stages: [
          { duration: rampUp, target: bid.quarter },
          { duration: stageDuration, target: bid.half },
          { duration: stageDuration, target: bid.maximum },
          { duration: rampDown, target: 0 },
        ],
      }),
    },
  };
}
